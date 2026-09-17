"""
Feature importance analysis for LightGBM model.
"""

import pandas as pd
import numpy as np
import joblib
from pathlib import Path

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
PROC_DIR = DATA_DIR / "processed"
MODEL_DIR = DATA_DIR / "model"


def main():
    print("Loading model and data...")
    model = joblib.load(MODEL_DIR / "model_final.joblib")
    df = pd.read_csv(PROC_DIR / "train_dataset.csv", parse_dates=["ds"])

    # Extract LightGBM model from MLForecast
    lgbm = model.models_["LGBMRegressor"]

    # Get feature names
    feature_names = lgbm.feature_name_

    # Get feature importances
    importance = pd.DataFrame({
        "feature": feature_names,
        "importance": lgbm.feature_importances_,
    }).sort_values("importance", ascending=False)

    # Calculate percentage
    importance["pct"] = importance["importance"] / importance["importance"].sum() * 100

    print(f"\n{'='*60}")
    print("FEATURE IMPORTANCE (Top 30)")
    print(f"{'='*60}")
    print(f"{'Rank':<6}{'Feature':<30}{'Importance':>12}{'%':>8}")
    print("-" * 56)

    for i, row in importance.head(30).iterrows():
        rank = importance.index.get_loc(i) + 1
        print(f"{rank:<6}{row['feature']:<30}{row['importance']:>12.0f}{row['pct']:>7.1f}%")

    # Group by feature type
    print(f"\n{'='*60}")
    print("FEATURE GROUP IMPORTANCE")
    print(f"{'='*60}")

    groups = {
        "Lag features": [f for f in feature_names if f.startswith("lag")],
        "Rolling features": [f for f in feature_names if f.startswith("rolling_") or f.startswith("expanding_")],
        "Calendar features": [f for f in feature_names if f in ["year", "month", "week", "dayofweek", "is_month_start", "is_month_end", "is_quarter_start", "is_quarter_end"]],
        "Capacity features": [f for f in feature_names if "capacity" in f or "Classification" in f or "Type" in f],
        "Neighbor features": [f for f in feature_names if "neighbor" in f],
        "Weekly history features": [f for f in feature_names if "wk_hist" in f],
        "Daily features": [f for f in feature_names if "daily" in f],
        "Other": [],
    }

    # Assign ungrouped features to "Other"
    grouped = set()
    for group_features in groups.values():
        grouped.update(group_features)
    groups["Other"] = [f for f in feature_names if f not in grouped]

    for group_name, group_features in sorted(groups.items(), key=lambda x: -sum(importance[importance["feature"].isin(x[1])]["importance"])):
        if group_features:
            group_imp = importance[importance["feature"].isin(group_features)]["importance"].sum()
            group_pct = importance[importance["feature"].isin(group_features)]["pct"].sum()
            print(f"{group_name:<25}{group_imp:>12.0f}{group_pct:>7.1f}%  ({len(group_features)} features)")

    # Save full importance
    importance.to_csv(PROC_DIR / "feature_importance.csv", index=False)
    print(f"\nSaved: {PROC_DIR / 'feature_importance.csv'}")


if __name__ == "__main__":
    main()
