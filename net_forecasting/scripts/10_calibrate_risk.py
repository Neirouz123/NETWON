"""
Step 10: Calibrate P(congestion) with Isotonic Regression

Uses max point forecast from backtest to calibrate risk scores.
Isotonic regression learns the mapping from predicted utilization to
actual congestion probability.
"""

import pandas as pd
import numpy as np
import warnings
import joblib
from pathlib import Path
from sklearn.isotonic import IsotonicRegression

warnings.filterwarnings("ignore")

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
PROC_DIR = DATA_DIR / "processed"
MODEL_DIR = DATA_DIR / "model"


def main():
    print("Loading data...")
    backtest = pd.read_csv(PROC_DIR / "backtest_predictions.csv", parse_dates=["ds"])
    risk = pd.read_csv(PROC_DIR / "monte_carlo_risk.csv")

    # ── Build calibration data from backtest ───────────────────────────
    # Define "congested" as: any week in test period had actual > 100%
    per_site = backtest.groupby("unique_id").agg(
        max_actual=("actual", "max"),
        mean_actual=("actual", "mean"),
        mean_forecast=("LGBMRegressor", "mean"),
        max_forecast=("LGBMRegressor", "max"),
        n_weeks=("ds", "count"),
    ).reset_index().rename(columns={"unique_id": "site"})

    per_site["congested_in_test"] = (per_site["max_actual"] > 100).astype(int)

    print(f"Calibration data: {len(per_site)} sites")
    print(f"Congested in test: {per_site['congested_in_test'].sum()} ({per_site['congested_in_test'].mean():.1%})")

    # ── Fit isotonic regression on max_forecast ────────────────────────
    X = per_site["max_forecast"].values
    y = per_site["congested_in_test"].values

    ir = IsotonicRegression(y_min=0, y_max=1, out_of_bounds="clip")
    ir.fit(X, y)

    per_site["p_calibrated"] = ir.predict(X)

    # ── Assign risk tiers ──────────────────────────────────────────────
    bins = [-0.01, 0.1, 0.3, 0.5, 0.7, 1.01]
    labels = ["Very Low", "Low", "Medium", "High", "Very High"]
    per_site["risk_tier_calibrated"] = pd.cut(per_site["p_calibrated"], bins=bins, labels=labels)

    # ── Evaluate calibration ───────────────────────────────────────────
    print(f"\n{'='*60}")
    print("CALIBRATION RESULTS")
    print(f"{'='*60}")

    print(f"\n{'Tier':<12} {'Sites':>6} {'Actual Congested':>16} {'Expected':>12}")
    print("-" * 50)

    for tier in labels:
        tier_df = per_site[per_site["risk_tier_calibrated"] == tier]
        if len(tier_df) > 0:
            actual_rate = tier_df["congested_in_test"].mean()
            print(f"{tier:<12} {len(tier_df):>6} {actual_rate:>15.1%} {'<' if labels.index(tier) < 4 else '>'}{[10,30,50,70,100][labels.index(tier)]}%")

    # ── Apply to full risk data ────────────────────────────────────────
    # Merge per-site calibration with risk data
    risk_merged = risk.merge(
        per_site[["site", "max_forecast", "p_calibrated", "risk_tier_calibrated"]],
        on="site",
        how="left",
        suffixes=("", "_cal"),
    )

    # For sites not in backtest, use raw risk as fallback
    mask = risk_merged["p_calibrated"].isna()
    if mask.sum() > 0:
        print(f"\n{mask.sum()} sites not in backtest, using raw risk scores")
        risk_merged.loc[mask, "p_calibrated"] = risk_merged.loc[mask, "p_congestion_any_week"]
        risk_merged.loc[mask, "risk_tier_calibrated"] = risk_merged.loc[mask, "risk_tier"]

    # ── Save ───────────────────────────────────────────────────────────
    risk_merged.to_csv(PROC_DIR / "monte_carlo_risk_calibrated.csv", index=False)
    joblib.dump({"ir": ir}, MODEL_DIR / "calibration.joblib")

    # Also save per_site calibration data for reference
    per_site.to_csv(PROC_DIR / "calibration_data.csv", index=False)

    print(f"\nSaved:")
    print(f"  {PROC_DIR / 'monte_carlo_risk_calibrated.csv'}")
    print(f"  {MODEL_DIR / 'calibration.joblib'}")
    print(f"  {PROC_DIR / 'calibration_data.csv'}")


if __name__ == "__main__":
    main()
