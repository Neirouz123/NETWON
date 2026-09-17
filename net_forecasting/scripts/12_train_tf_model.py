"""
Separate LightGBM model for TF (TDD) sites.
TF sites have different characteristics and are harder to predict.
"""

import pandas as pd
import numpy as np
import joblib
import warnings
from pathlib import Path
from mlforecast import MLForecast
from mlforecast.utils import PredictionIntervals
from lightgbm import LGBMRegressor
from utilsforecast.losses import mae

warnings.filterwarnings("ignore")

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
PROC_DIR = DATA_DIR / "processed"
MODEL_DIR = DATA_DIR / "model"


FILLABLE_FEATURES = [
    "neighbor_avg_utilization",
    "daily_util_mean_30d", "daily_util_std_30d", "daily_util_slope_30d",
    "daily_util_min_30d", "daily_util_max_30d", "daily_days_since_spike",
]
TARGET_DEPENDENT = [
    "wk_hist_mean", "wk_hist_std", "wk_hist_min", "wk_hist_max", "wk_hist_trend",
]


def load_data():
    """Load and filter training data for TF sites only."""
    df = pd.read_csv(PROC_DIR / "train_dataset.csv", parse_dates=["ds"])

    # Filter to TF sites only
    df = df[df["Classification"] == "TF"].copy()

    # Remove sites with too few observations for conformal (need 53 for n_windows=2, h=26)
    site_counts = df.groupby("unique_id").size()
    valid_sites = site_counts[site_counts >= 53].index
    df = df[df["unique_id"].isin(valid_sites)].copy()

    print(f"TF sites (original): {df['unique_id'].nunique()}")

    # Fill gaps (same as 05_train_model.py)
    df = df.groupby(["unique_id", "ds"], as_index=False).agg(
        {c: "mean" if c == "y" else "first" for c in df.columns if c not in ["unique_id", "ds"]}
    )
    all_dates = sorted(df["ds"].unique())
    all_sites = df["unique_id"].unique()
    mi = pd.MultiIndex.from_product([all_sites, all_dates], names=["unique_id", "ds"])
    df = df.set_index(["unique_id", "ds"]).reindex(mi).reset_index()
    for col in FILLABLE_FEATURES + TARGET_DEPENDENT:
        if col in df.columns:
            df[col] = df.groupby("unique_id")[col].ffill().bfill()
    df["y"] = df.groupby("unique_id")["y"].ffill().bfill()
    for col in ["Classification", "Type", "GOUV"]:
        if col in df.columns:
            df[col] = df[col].astype("category").cat.codes.astype("int32")

    print(f"TF sites (after fill): {df['unique_id'].nunique()}")
    print(f"TF rows: {len(df)}")

    return df


def train_tf_model(df):
    """Train LightGBM for TF sites with conformal intervals."""
    print("\nTraining TF model...")

    # Best params from full model (we'll use similar params)
    params = {
        "n_estimators": 855,
        "learning_rate": 0.053,
        "max_depth": 9,
        "num_leaves": 121,
        "min_child_samples": 40,
        "subsample": 0.77,
        "colsample_bytree": 0.91,
        "reg_alpha": 0.048,
        "reg_lambda": 0.075,
        "verbose": -1,
        "random_state": 42,
        "dropna": False,
    }

    lgbm = LGBMRegressor(**params)

    # Use same lags as full model
    lags = [1, 2, 4, 13]

    # Train with cross-validation
    mlf = MLForecast(
        models=[lgbm],
        freq="W-MON",
        lags=lags,
        lag_transforms={},
        date_features=["month", "week"],
    )

    # Fit with prediction intervals
    pi = PredictionIntervals(n_windows=2, h=26, method="conformal_distribution")
    mlf.fit(
        df,
        id_col="unique_id",
        time_col="ds",
        target_col="y",
        static_features=[],
        dropna=False,
        prediction_intervals=pi,
    )

    print("TF model trained successfully")

    return mlf


def evaluate_tf_model(mlf, df):
    """Cross-validate TF model."""
    print("\nCross-validating TF model...")

    from mlforecast import MLForecast

    # Run cross-validation
    cv_results = mlf.cross_validation(
        df=df,
        h=26,
        n_windows=2,
        id_col="unique_id",
        time_col="ds",
        target_col="y",
        static_features=[],
        dropna=False,
    )

    # Calculate metrics
    if isinstance(cv_results, dict):
        for model_name, preds in cv_results.items():
            mae_score = mae(df["y"], preds)
            print(f"TF Model MAE: {mae_score:.4f}")
    else:
        print(f"CV results shape: {cv_results.shape}")

    return cv_results


def main():
    # Load TF data
    df_tf = load_data()

    # Train model
    mlf = train_tf_model(df_tf)

    # Evaluate
    cv_results = evaluate_tf_model(mlf, df_tf)

    # Save
    output_path = MODEL_DIR / "model_tf.joblib"
    joblib.dump(mlf, output_path)
    print(f"\nSaved: {output_path}")


if __name__ == "__main__":
    main()
