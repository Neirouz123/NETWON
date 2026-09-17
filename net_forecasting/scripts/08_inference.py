"""
Inference script for LightGBM v3 model.
Generates predictions with conformal intervals for app consumption.
"""

import pandas as pd
import numpy as np
import joblib
import argparse
import json
import sys
from pathlib import Path

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
PROC_DIR = DATA_DIR / "processed"
MODEL_DIR = DATA_DIR / "model"
EXPORT_DIR = DATA_DIR / "export"


def load_model():
    """Load the trained MLForecast model."""
    model = joblib.load(MODEL_DIR / "model_final.joblib")
    return model


def load_calibration():
    """Load calibration model if available."""
    cal_path = MODEL_DIR / "calibration.joblib"
    if cal_path.exists():
        cal = joblib.load(cal_path)
        return cal
    return None


def prepare_features(df, model):
    """Prepare features for prediction."""
    # This is a simplified version - in production, you'd want to ensure
    # all features are properly computed
    return df


def predict(model, sites, horizon=26):
    """Generate predictions for specified sites."""
    # Load live forecast data (already computed by 05_train_model.py)
    live_forecast = pd.read_csv(PROC_DIR / "live_forecast.csv", parse_dates=["ds"])

    if sites:
        live_forecast = live_forecast[live_forecast["unique_id"].isin(sites)]

    # Load risk scores
    risk = pd.read_csv(PROC_DIR / "monte_carlo_risk_calibrated.csv")

    # Rename columns for consistency
    live_forecast = live_forecast.rename(columns={
        "LGBMRegressor": "point_forecast",
        "LGBMRegressor-lo-95": "lo_95",
        "LGBMRegressor-lo-80": "lo_80",
        "LGBMRegressor-hi-80": "hi_80",
        "LGBMRegressor-hi-95": "hi_95",
    })

    # Merge
    result = live_forecast.merge(
        risk[["site", "p_calibrated", "risk_tier_calibrated", "classification"]],
        left_on="unique_id",
        right_on="site",
        how="left"
    )

    return result


def main():
    parser = argparse.ArgumentParser(description="Generate predictions with LightGBM v3")
    parser.add_argument("--sites", nargs="+", help="Specific site IDs to predict")
    parser.add_argument("--output", choices=["json", "csv"], default="json", help="Output format")
    parser.add_argument("--output-file", help="Output file path")
    args = parser.parse_args()

    print("Loading model...")
    model = load_model()

    print("Generating predictions...")
    result = predict(model, args.sites)

    # Format output
    output = []
    for _, row in result.iterrows():
        site_data = {
            "site": row["unique_id"],
            "classification": row.get("classification", ""),
            "date": str(row["ds"].date()),
            "forecast": {
                "point_forecast": round(row.get("point_forecast", 0), 2),
            },
            "intervals": {
                "lo_80": round(row.get("lo_80", 0), 2),
                "hi_80": round(row.get("hi_80", 0), 2),
                "lo_95": round(row.get("lo_95", 0), 2),
                "hi_95": round(row.get("hi_95", 0), 2),
            },
            "risk": {
                "p_congestion_calibrated": round(row.get("p_calibrated", 0), 4),
                "risk_tier": row.get("risk_tier_calibrated", "Unknown"),
            }
        }
        output.append(site_data)

    # Output
    if args.output_file:
        if args.output == "json":
            with open(args.output_file, "w") as f:
                json.dump(output, f, indent=2)
        else:
            pd.DataFrame(output).to_csv(args.output_file, index=False)
        print(f"Saved to {args.output_file}")
    else:
        if args.output == "json":
            print(json.dumps(output, indent=2))
        else:
            print(pd.DataFrame(output).to_string(index=False))


if __name__ == "__main__":
    main()
