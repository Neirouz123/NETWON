"""
Step 9: Comprehensive model evaluation

Performs 6 analyses on the LightGBM backtest predictions:
  1. Per-classification MAE
  2. Per-utilization-band MAE
  3. Residual analysis (bias, heteroscedasticity, distribution)
  4. P(congestion) calibration
  5. False negatives/positives vs État labels
  6. Backtest fold consistency

Outputs a single evaluation report to data/processed/evaluation_report.txt
and per-site metrics to data/processed/site_evaluation.csv
"""

import pandas as pd
import numpy as np
from pathlib import Path
from sklearn.metrics import mean_absolute_error, mean_squared_error

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
PROC_DIR = DATA_DIR / "processed"


def section(title, lines):
    """Format a report section."""
    sep = "=" * 60
    return f"\n{sep}\n{title}\n{sep}\n" + "\n".join(lines) + "\n"


def main():
    # ── Load data ──────────────────────────────────────────────────────
    backtest = pd.read_csv(PROC_DIR / "lgbm_backtest_predictions.csv", parse_dates=["ds"])
    plan = pd.read_csv(PROC_DIR / "plan_data_clean.csv")
    risk = pd.read_csv(PROC_DIR / "monte_carlo_risk.csv")

    backtest["residual"] = backtest["actual"] - backtest["LGBMRegressor"]
    backtest["abs_error"] = backtest["residual"].abs()
    backtest["sq_error"] = backtest["residual"] ** 2

    # Merge plan info
    plan_unique = plan.drop_duplicates(subset=["Site"])
    backtest = backtest.merge(
        plan_unique[["Site", "Classification", "État", "capacity_mbps", "LAT", "LONG"]],
        left_on="unique_id", right_on="Site", how="left",
    ).drop(columns=["Site"])

    report = []

    # ── 1. Per-classification MAE ──────────────────────────────────────
    lines = []
    lines.append(f"{'Classification':<20} {'Sites':>6} {'MAE':>8} {'RMSE':>8} {'Median_AE':>10} {'P90_AE':>8}")
    lines.append("-" * 62)

    for cls, grp in backtest.groupby("Classification"):
        n_sites = grp["unique_id"].nunique()
        mae = mean_absolute_error(grp["actual"], grp["LGBMRegressor"])
        rmse = np.sqrt(mean_squared_error(grp["actual"], grp["LGBMRegressor"]))
        med_ae = grp["abs_error"].median()
        p90_ae = grp["abs_error"].quantile(0.90)
        lines.append(f"{cls:<20} {n_sites:>6} {mae:>8.4f} {rmse:>8.4f} {med_ae:>10.4f} {p90_ae:>8.4f}")

    overall_mae = mean_absolute_error(backtest["actual"], backtest["LGBMRegressor"])
    overall_rmse = np.sqrt(mean_squared_error(backtest["actual"], backtest["LGBMRegressor"]))
    lines.append("-" * 62)
    lines.append(f"{'OVERALL':<20} {backtest['unique_id'].nunique():>6} {overall_mae:>8.4f} {overall_rmse:>8.4f}")
    report.append(section("1. PER-CLASSIFICATION PERFORMANCE", lines))

    # ── 2. Per-utilization-band MAE ────────────────────────────────────
    bins = [0, 20, 40, 60, 80, 100, 150]
    labels = ["0-20", "20-40", "40-60", "60-80", "80-100", "100+"]
    backtest["util_band"] = pd.cut(backtest["actual"], bins=bins, labels=labels, include_lowest=True)

    lines = []
    lines.append(f"{'Util Band':<12} {'Rows':>8} {'Sites':>6} {'MAE':>8} {'RMSE':>8} {'Bias':>8}")
    lines.append("-" * 52)

    for band, grp in backtest.groupby("util_band", observed=True):
        n_sites = grp["unique_id"].nunique()
        mae = mean_absolute_error(grp["actual"], grp["LGBMRegressor"])
        rmse = np.sqrt(mean_squared_error(grp["actual"], grp["LGBMRegressor"]))
        bias = grp["residual"].mean()
        lines.append(f"{str(band):<12} {len(grp):>8} {n_sites:>6} {mae:>8.4f} {rmse:>8.4f} {bias:>8.4f}")

    report.append(section("2. PER-UTILIZATION-BAND PERFORMANCE", lines))

    # ── 3. Residual analysis ───────────────────────────────────────────
    resid = backtest["residual"]
    lines = []
    lines.append(f"  Mean residual (bias):     {resid.mean():>10.4f}")
    lines.append(f"  Std residual:             {resid.std():>10.4f}")
    lines.append(f"  Skewness:                 {resid.skew():>10.4f}")
    lines.append(f"  Kurtosis:                 {resid.kurtosis():>10.4f}")
    lines.append(f"  Median residual:          {resid.median():>10.4f}")
    lines.append(f"  P5 residual:              {resid.quantile(0.05):>10.4f}")
    lines.append(f"  P95 residual:             {resid.quantile(0.95):>10.4f}")
    lines.append("")
    lines.append("  Interpretation:")
    if abs(resid.mean()) < 1.0:
        lines.append("    - Bias is negligible (< 1.0) — model is well-calibrated on average")
    elif resid.mean() > 0:
        lines.append(f"    - Positive bias ({resid.mean():.2f}) — model UNDERPREDICTS on average")
    else:
        lines.append(f"    - Negative bias ({resid.mean():.2f}) — model OVERPREDICTS on average")

    if resid.skew() > 1:
        lines.append("    - Right-skewed: more large positive errors (underpredictions)")
    elif resid.skew() < -1:
        lines.append("    - Left-skewed: more large negative errors (overpredictions)")

    # Check heteroscedasticity: does residual variance change with actual?
    backtest["actual_band"] = pd.cut(backtest["actual"], bins=bins, labels=labels, include_lowest=True)
    lines.append("")
    lines.append("  Heteroscedasticity check (residual std by utilization band):")
    for band, grp in backtest.groupby("actual_band", observed=True):
        lines.append(f"    {str(band):<12} residual_std = {grp['residual'].std():.4f}")

    report.append(section("3. RESIDUAL ANALYSIS", lines))

    # ── 4. Calibration of P(congestion) ────────────────────────────────
    # For each risk tier, what fraction actually crossed 100% in the backtest?
    # We use the backtest predictions to check what actually happened
    backtest["is_congested"] = backtest["actual"] > 100

    # Per-site max actual in the backtest window
    site_max_actual = backtest.groupby("unique_id")["actual"].max().reset_index()
    site_max_actual.columns = ["site", "max_actual"]

    risk_merged = risk.merge(site_max_actual, on="site", how="left")
    risk_merged["actually_congested"] = risk_merged["max_actual"] > 100

    lines = []
    lines.append(f"{'Risk Tier':<12} {'Sites':>6} {'Actually Congested':>18} {'Rate':>8}")
    lines.append("-" * 50)

    for tier in ["Very Low", "Low", "Medium", "High", "Very High"]:
        tier_df = risk_merged[risk_merged["risk_tier"] == tier]
        n = len(tier_df)
        n_cong = tier_df["actually_congested"].sum()
        rate = n_cong / n if n > 0 else 0
        lines.append(f"{tier:<12} {n:>6} {n_cong:>18} {rate:>8.2%}")

    # Overall
    total = len(risk_merged)
    total_cong = risk_merged["actually_congested"].sum()
    lines.append("-" * 50)
    lines.append(f"{'TOTAL':<12} {total:>6} {total_cong:>18} {total_cong/total:>8.2%}")
    lines.append("")
    lines.append("  If calibration is good, 'Rate' should roughly match the tier's risk level:")
    lines.append("    Very Low ~0-10%, Low ~10-30%, Medium ~30-50%, High ~50-70%, Very High >70%")

    report.append(section("4. P(CONGESTION) CALIBRATION", lines))

    # ── 5. False negatives/positives vs État ────────────────────────────
    etat_congested = ["CONGESTION", "CONGESTION(FDD)", "CONGESTION(TDD)", "BRIDAGE"]
    etat_ok = ["OK", "A_VERIFIER_CAPACITE"]

    risk_with_etat = risk.merge(
        plan_unique[["Site", "État"]], left_on="site", right_on="Site", how="left",
    ).drop(columns=["Site"])

    lines = []

    # False negatives: currently congested but model says low risk
    fn = risk_with_etat[
        (risk_with_etat["État"].isin(etat_congested))
        & (risk_with_etat["p_congestion_any_week"] < 0.3)
    ]
    lines.append(f"FALSE NEGATIVES (congested but low predicted risk): {len(fn)}")
    lines.append(f"{'Site':<15} {'État':<20} {'P(congest)':>10} {'Pt Forecast':>12}")
    lines.append("-" * 60)
    for _, row in fn.iterrows():
        lines.append(f"{row['site']:<15} {row['État']:<20} {row['p_congestion_any_week']:>10.4f} {row['point_forecast_mean']:>12.2f}")

    lines.append("")

    # False positives: OK but model says high risk
    fp = risk_with_etat[
        (risk_with_etat["État"].isin(etat_ok))
        & (risk_with_etat["p_congestion_any_week"] > 0.5)
    ]
    lines.append(f"FALSE POSITIVES (OK but high predicted risk): {len(fp)}")
    lines.append(f"  (Sites marked OK that the model flags as high-risk — may be early warnings)")
    lines.append(f"{'Site':<15} {'État':<20} {'P(congest)':>10} {'Pt Forecast':>12}")
    lines.append("-" * 60)
    for _, row in fp.head(20).iterrows():
        lines.append(f"{row['site']:<15} {row['État']:<20} {row['p_congestion_any_week']:>10.4f} {row['point_forecast_mean']:>12.2f}")

    lines.append("")

    # Summary confusion-style table
    lines.append("SUMMARY (risk vs current state):")
    lines.append(f"{'':>20} {'Predicted Low Risk':>20} {'Predicted High Risk':>20}")
    lines.append("-" * 60)
    n_ok_low = len(risk_with_etat[(risk_with_etat["État"].isin(etat_ok)) & (risk_with_etat["p_congestion_any_week"] < 0.3)])
    n_ok_high = len(risk_with_etat[(risk_with_etat["État"].isin(etat_ok)) & (risk_with_etat["p_congestion_any_week"] > 0.5)])
    n_cong_low = len(risk_with_etat[(risk_with_etat["État"].isin(etat_congested)) & (risk_with_etat["p_congestion_any_week"] < 0.3)])
    n_cong_high = len(risk_with_etat[(risk_with_etat["État"].isin(etat_congested)) & (risk_with_etat["p_congestion_any_week"] > 0.5)])
    lines.append(f"{'Currently OK':>20} {n_ok_low:>20} {n_ok_high:>20}")
    lines.append(f"{'Currently Congested':>20} {n_cong_low:>20} {n_cong_high:>20}")

    report.append(section("5. FALSE NEGATIVES / FALSE POSITIVES", lines))

    # ── 6. Backtest fold consistency ────────────────────────────────────
    lines = []
    lines.append(f"{'Fold':>6} {'Train Until':<15} {'Test Window':<25} {'MAE':>8} {'RMSE':>8} {'Sites':>6}")
    lines.append("-" * 70)

    for fold, grp in backtest.groupby("fold"):
        test_start = grp["ds"].min().date()
        test_end = grp["ds"].max().date()
        n_sites = grp["unique_id"].nunique()
        mae = mean_absolute_error(grp["actual"], grp["LGBMRegressor"])
        rmse = np.sqrt(mean_squared_error(grp["actual"], grp["LGBMRegressor"]))
        lines.append(f"{fold:>6} {'':15} {f'{test_start} to {test_end}':<25} {mae:>8.4f} {rmse:>8.4f} {n_sites:>6}")

    # Check fold-overall consistency
    fold_maes = backtest.groupby("fold").apply(
        lambda g: mean_absolute_error(g["actual"], g["LGBMRegressor"]),
        include_groups=False,
    )
    lines.append("")
    lines.append(f"  Fold MAE range: {fold_maes.min():.4f} to {fold_maes.max():.4f}")
    if fold_maes.max() - fold_maes.min() < 2:
        lines.append("  → Folds are consistent — model generalizes well across time periods")
    else:
        lines.append("  → Fold gap is large — model may be sensitive to temporal distribution shift")

    report.append(section("6. BACKTEST FOLD CONSISTENCY", lines))

    # ── Write report ───────────────────────────────────────────────────
    report_text = "\n".join(report)
    print(report_text)

    report_path = PROC_DIR / "evaluation_report.txt"
    with open(report_path, "w") as f:
        f.write(report_text)

    # ── Per-site evaluation CSV ────────────────────────────────────────
    site_eval = backtest.groupby("unique_id").agg(
        n_weeks=("ds", "count"),
        mae=("abs_error", "mean"),
        bias=("residual", "mean"),
        max_actual=("actual", "max"),
        mean_actual=("actual", "mean"),
        classification=("Classification", "first"),
        etat=("État", "first"),
    ).reset_index().rename(columns={"unique_id": "site"})
    site_eval["rmse"] = backtest.groupby("unique_id")["sq_error"].mean().apply(np.sqrt).values

    site_eval.to_csv(PROC_DIR / "site_evaluation.csv", index=False)

    print(f"\nSaved:")
    print(f"  {report_path}")
    print(f"  {PROC_DIR / 'site_evaluation.csv'}")


if __name__ == "__main__":
    main()
