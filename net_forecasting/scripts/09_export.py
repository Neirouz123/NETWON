"""
Step 7: Export final model bundle

Packages the best trained model (v3 tuned + conformal), plan data,
residuals, risk scores, and metadata into a single deployable bundle.
"""

import json
import pandas as pd
import numpy as np
import joblib
from pathlib import Path

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
PROC_DIR = DATA_DIR / "processed"
MODEL_DIR = DATA_DIR / "model"
EXPORT_DIR = DATA_DIR / "export"
EXPORT_DIR.mkdir(exist_ok=True)


def main():
    print("Loading artifacts...")
    mlf = joblib.load(MODEL_DIR / "mlforecast_model_conformal.joblib")
    plan = pd.read_csv(PROC_DIR / "plan_data_clean.csv")
    best_params = joblib.load(MODEL_DIR / "best_params.joblib")

    # Use v2 backtest predictions (with external regressors) for residuals
    backtest = pd.read_csv(PROC_DIR / "lgbm_backtest_predictions_v2.csv", parse_dates=["ds"])
    risk = pd.read_csv(PROC_DIR / "monte_carlo_risk.csv")

    # Comparison metrics
    comparison = pd.read_csv(PROC_DIR / "comparison_metrics_v2.csv")

    # ── Compute per-site residuals for Monte Carlo ─────────────────────
    backtest["residual"] = backtest["actual"] - backtest["LGBMRegressor"]
    site_residuals = {}
    for site, grp in backtest.groupby("unique_id"):
        resids = grp["residual"].values
        if len(resids) >= 5:
            site_residuals[site] = {
                "mean": float(np.mean(resids)),
                "std": float(np.std(resids)),
                "values": resids.tolist(),
            }

    # ── Build metadata ─────────────────────────────────────────────────
    metadata = {
        "model_type": "MLForecast + LGBMRegressor (tuned + conformal)",
        "forecast_horizon": 26,
        "frequency": "W-MON",
        "n_sites": int(plan["Site"].nunique()),
        "n_sites_with_residuals": len(site_residuals),
        "backtest_metrics": comparison.to_dict(orient="records"),
        "best_params": best_params,
        "external_features": [
            "capacity_mbps", "LAT", "LONG", "Classification", "Type", "GOUV",
            "neighbor_avg_utilization",
            "daily_util_mean_30d", "daily_util_std_30d", "daily_util_slope_30d",
            "daily_util_min_30d", "daily_util_max_30d", "daily_days_since_spike",
            "wk_hist_mean", "wk_hist_std", "wk_hist_min", "wk_hist_max", "wk_hist_trend",
        ],
        "congestion_threshold_pct": 100,
        "conformal_levels": [80, 95],
    }

    # ── Save bundle ────────────────────────────────────────────────────
    bundle = {
        "model": mlf,
        "plan_data": plan,
        "site_residuals": site_residuals,
        "risk_scores": risk,
        "metadata": metadata,
    }
    bundle_path = EXPORT_DIR / "model_bundle.joblib"
    joblib.dump(bundle, bundle_path, compress=3)
    print(f"Bundle saved → {bundle_path} ({bundle_path.stat().st_size / 1e6:.1f} MB)")

    # ── Save readable JSON metadata ────────────────────────────────────
    meta_path = EXPORT_DIR / "model_metadata.json"
    with open(meta_path, "w") as f:
        json.dump(metadata, f, indent=2, default=str)
    print(f"Metadata saved → {meta_path}")

    # ── Summary ────────────────────────────────────────────────────────
    print(f"\n{'='*60}")
    print(f"EXPORTED BUNDLE CONTENTS")
    print(f"{'='*60}")
    print(f"  model              — tuned LGBMRegressor with conformal intervals")
    print(f"  plan_data          — {len(plan)} rows × {len(plan.columns)} cols")
    print(f"  site_residuals     — {len(site_residuals)} sites with error distributions")
    print(f"  risk_scores        — {len(risk)} sites with P(congestion)")
    print(f"  metadata           — model config + best params + backtest metrics")
    print(f"\nBacktest metrics:")
    print(comparison.to_string(index=False, float_format="{:.4f}".format))
    print(f"\nBest params:")
    for k, v in best_params.items():
        print(f"  {k}: {v}")


if __name__ == "__main__":
    main()
