"""
Step 06: Risk assessment

Combines Monte Carlo residual bootstrap + conformal prediction intervals
to produce P(congestion) per site with calibrated risk tiers.

Usage:
    python 06_risk_assessment.py
"""

import pandas as pd
import numpy as np
import warnings
import joblib
from pathlib import Path

warnings.filterwarnings("ignore")

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
PROC_DIR = DATA_DIR / "processed"
MODEL_DIR = DATA_DIR / "model"

N_SIMS = 1000
CONGESTION_THRESHOLD = 100
np.random.seed(42)


def compute_monte_carlo_risk(forecast, backtest, plan):
    """Monte Carlo residual bootstrap for P(congestion)."""
    backtest["residual"] = backtest["actual"] - backtest["LGBMRegressor"]

    site_residuals = {}
    for site, grp in backtest.groupby("unique_id"):
        resids = grp["residual"].values
        if len(resids) >= 5:
            site_residuals[site] = resids

    print(f"Sites with residuals: {len(site_residuals)}")

    results = []
    for site in forecast["unique_id"].unique():
        site_fc = forecast[forecast["unique_id"] == site].sort_values("ds")
        point_forecast = site_fc["LGBMRegressor"].values
        h = len(point_forecast)

        if site in site_residuals:
            resids = site_residuals[site]
        else:
            resids = backtest["residual"].values

        sim_matrix = np.zeros((N_SIMS, h))
        for s in range(N_SIMS):
            sampled = np.random.choice(resids, size=h, replace=True)
            sim_matrix[s, :] = point_forecast + sampled
        sim_matrix = np.clip(sim_matrix, 0, 200)

        ever_congested = np.any(sim_matrix > CONGESTION_THRESHOLD, axis=1)
        p_congestion = ever_congested.mean()

        end_congested = sim_matrix[:, -1] > CONGESTION_THRESHOLD
        p_congestion_end = end_congested.mean()

        p10 = np.percentile(sim_matrix, 10, axis=0)
        p50 = np.percentile(sim_matrix, 50, axis=0)
        p90 = np.percentile(sim_matrix, 90, axis=0)

        plan_row = plan[plan["Site"] == site]
        capacity = plan_row["capacity_mbps"].values[0] if len(plan_row) > 0 else np.nan
        classification = plan_row["Classification"].values[0] if len(plan_row) > 0 else "Unknown"
        etat = plan_row["État"].values[0] if len(plan_row) > 0 else "Unknown"

        results.append({
            "site": site, "classification": classification, "etat": etat,
            "capacity_mbps": capacity,
            "point_forecast_mean": point_forecast.mean(),
            "p_congestion_any_week": p_congestion, "p_congestion_end": p_congestion_end,
            "p10_end": p10[-1], "p50_end": p50[-1], "p90_end": p90[-1],
        })

    results_df = pd.DataFrame(results)

    bins = [-0.01, 0.1, 0.3, 0.5, 0.7, 1.01]
    labels = ["Very Low", "Low", "Medium", "High", "Very High"]
    results_df["risk_tier"] = pd.cut(results_df["p_congestion_any_week"], bins=bins, labels=labels)

    return results_df


def main():
    print("Loading data...")
    forecast = pd.read_csv(PROC_DIR / "live_forecast.csv", parse_dates=["ds"])
    backtest = pd.read_csv(PROC_DIR / "backtest_predictions.csv", parse_dates=["ds"])
    plan = pd.read_csv(PROC_DIR / "plan_data_clean.csv")

    print(f"Forecast: {forecast.shape}, Backtest: {backtest.shape}")

    # ── Monte Carlo ────────────────────────────────────────────────────
    print(f"\nRunning Monte Carlo ({N_SIMS} sims)...")
    risk = compute_monte_carlo_risk(forecast, backtest, plan)

    print(f"\n{'='*60}")
    print("RISK ASSESSMENT RESULTS")
    print(f"{'='*60}")
    print(f"Mean P(congestion): {risk['p_congestion_any_week'].mean():.4f}")
    print(f"\nRisk tier distribution:")
    print(risk["risk_tier"].value_counts().sort_index().to_string())

    print(f"\nRisk tier vs État:")
    print(pd.crosstab(risk["risk_tier"], risk["etat"]).to_string())

    print(f"\nRisk tier vs Classification:")
    print(pd.crosstab(risk["risk_tier"], risk["classification"]).to_string())

    print(f"\nTop 20 highest-risk sites:")
    top20 = risk.nlargest(20, "p_congestion_any_week")[
        ["site", "classification", "etat", "capacity_mbps",
         "point_forecast_mean", "p_congestion_any_week", "risk_tier"]
    ]
    print(top20.to_string(index=False))

    # ── Conformal P(congestion) ────────────────────────────────────────
    if "LGBMRegressor-lo-95" in forecast.columns:
        cong_95 = forecast.groupby("unique_id").apply(
            lambda g: (g["LGBMRegressor-lo-95"] > 100).any(), include_groups=False,
        ).reset_index()
        cong_95.columns = ["site", "p_conformal_95"]
        cong_95["p_conformal_95"] = cong_95["p_conformal_95"].astype(float)
        risk = risk.merge(cong_95, on="site", how="left")

    # ── False negatives ────────────────────────────────────────────────
    etat_congested = ["CONGESTION", "CONGESTION(FDD)", "CONGESTION(TDD)", "BRIDAGE"]
    plan_unique = plan.drop_duplicates(subset=["Site"])
    risk_with_etat = risk.merge(plan_unique[["Site", "État"]], left_on="site", right_on="Site", how="left")

    fn = risk_with_etat[
        (risk_with_etat["État"].isin(etat_congested))
        & (risk_with_etat["p_congestion_any_week"] < 0.3)
    ]
    print(f"\nFalse negatives (congested but low risk): {len(fn)}")
    if len(fn) > 0:
        print(fn[["site", "État", "p_congestion_any_week", "point_forecast_mean"]].to_string(index=False))

    # ── Save ───────────────────────────────────────────────────────────
    risk.to_csv(PROC_DIR / "monte_carlo_risk.csv", index=False)
    print(f"\nSaved → {PROC_DIR / 'monte_carlo_risk.csv'}")


if __name__ == "__main__":
    main()
