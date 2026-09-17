"""
Step 05: Train final model

Consolidated training pipeline:
  - Loads and prepares data with external regressors
  - Runs Optuna hyperparameter tuning (50 trials)
  - Trains final model with conformal prediction intervals
  - Saves model + best params + backtest predictions

Usage:
    python 05_train_model.py              # full pipeline (tune + train)
    python 05_train_model.py --skip-tune  # train with saved params only
"""

import argparse
import pandas as pd
import numpy as np
import warnings
import joblib
import optuna
from pathlib import Path
from sklearn.metrics import mean_absolute_error, mean_squared_error
from lightgbm import LGBMRegressor
from mlforecast import MLForecast
from mlforecast.lag_transforms import RollingMean
from mlforecast.conformal_prediction import PredictionIntervals

warnings.filterwarnings("ignore")
optuna.logging.set_verbosity(optuna.logging.WARNING)

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
PROC_DIR = DATA_DIR / "processed"
MODEL_DIR = DATA_DIR / "model"
MODEL_DIR.mkdir(exist_ok=True)

HORIZON = 26
N_TRIALS = 50

FILLABLE_FEATURES = [
    "neighbor_avg_utilization",
    "daily_util_mean_30d", "daily_util_std_30d", "daily_util_slope_30d",
    "daily_util_min_30d", "daily_util_max_30d", "daily_days_since_spike",
]
TARGET_DEPENDENT = [
    "wk_hist_mean", "wk_hist_std", "wk_hist_min", "wk_hist_max", "wk_hist_trend",
]


def load_data():
    df = pd.read_csv(PROC_DIR / "train_dataset.csv", parse_dates=["ds"])
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
    return df


def get_external_cols(df):
    return ["capacity_mbps", "LAT", "LONG", "Classification", "Type", "GOUV"] + \
           [c for c in FILLABLE_FEATURES + TARGET_DEPENDENT if c in df.columns]


def make_mlf(lgbm_params, lags, lag_transforms):
    return MLForecast(
        models=[LGBMRegressor(**lgbm_params)],
        freq="W-MON",
        lags=lags,
        lag_transforms=lag_transforms,
        date_features=["month", "week"],
    )


def backtest_mae(df, external_cols, lgbm_params, lags, lag_transforms):
    all_dates = sorted(df["ds"].dropna().unique())
    cutoffs = [all_dates[-(HORIZON * (i + 1) + 1)] for i in range(2)]
    cutoffs = sorted(cutoffs)
    maes = []
    for cutoff in cutoffs:
        test_start_idx = list(all_dates).index(cutoff) + 1
        test_dates = all_dates[test_start_idx:test_start_idx + HORIZON]
        train_df = df[df["ds"] <= cutoff].copy()
        test_df = df[df["ds"].isin(test_dates)].copy()
        mlf = make_mlf(lgbm_params, lags, lag_transforms)
        mlf.fit(train_df, id_col="unique_id", time_col="ds", target_col="y",
                static_features=[], dropna=False)
        x_df = test_df[["unique_id", "ds"] + external_cols].copy()
        pred = mlf.predict(HORIZON, X_df=x_df)
        actuals = test_df[["unique_id", "ds", "y"]].rename(columns={"y": "actual"})
        merged = pred.merge(actuals, on=["unique_id", "ds"], how="inner")
        maes.append(mean_absolute_error(merged["actual"], merged["LGBMRegressor"]))
    return np.mean(maes)


def objective(trial, df, external_cols):
    lgbm_params = {
        "n_estimators": trial.suggest_int("n_estimators", 200, 1000),
        "learning_rate": trial.suggest_float("learning_rate", 0.01, 0.2, log=True),
        "max_depth": trial.suggest_int("max_depth", 3, 10),
        "num_leaves": trial.suggest_int("num_leaves", 15, 127),
        "min_child_samples": trial.suggest_int("min_child_samples", 5, 50),
        "subsample": trial.suggest_float("subsample", 0.5, 1.0),
        "colsample_bytree": trial.suggest_float("colsample_bytree", 0.5, 1.0),
        "reg_alpha": trial.suggest_float("reg_alpha", 1e-3, 10.0, log=True),
        "reg_lambda": trial.suggest_float("reg_lambda", 1e-3, 10.0, log=True),
        "random_state": 42, "verbosity": -1,
    }
    lag_options = {"small": [1, 2, 4], "medium": [1, 2, 4, 13], "full": [1, 2, 4, 13, 26, 52]}
    lags = lag_options[trial.suggest_categorical("lags", ["small", "medium", "full"])]
    lag_transforms = {}
    if trial.suggest_categorical("use_rolling", [True, False]):
        w = trial.suggest_categorical("rolling_window", [4, 8, 13, 26])
        lag_transforms = {w: [RollingMean(window_size=w)]}
    return backtest_mae(df, external_cols, lgbm_params, lags, lag_transforms)


def build_lgbm_params(best_params):
    return {
        "n_estimators": best_params["n_estimators"],
        "learning_rate": best_params["learning_rate"],
        "max_depth": best_params["max_depth"],
        "num_leaves": best_params["num_leaves"],
        "min_child_samples": best_params["min_child_samples"],
        "subsample": best_params["subsample"],
        "colsample_bytree": best_params["colsample_bytree"],
        "reg_alpha": best_params["reg_alpha"],
        "reg_lambda": best_params["reg_lambda"],
        "random_state": 42, "verbosity": -1,
    }


def build_lags_transforms(best_params):
    lag_options = {"small": [1, 2, 4], "medium": [1, 2, 4, 13], "full": [1, 2, 4, 13, 26, 52]}
    lags = lag_options[best_params["lags"]]
    lag_transforms = {}
    if best_params["use_rolling"]:
        lag_transforms = {best_params["rolling_window"]: [RollingMean(window_size=best_params["rolling_window"])]}
    return lags, lag_transforms


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--skip-tune", action="store_true", help="Skip Optuna, use saved params")
    args = parser.parse_args()

    df = load_data()
    external_cols = get_external_cols(df)
    all_sites = df["unique_id"].unique()
    print(f"Data loaded: {df.shape}, {len(external_cols)} external features")

    # ── Hyperparameter tuning ──────────────────────────────────────────
    if args.skip_tune and (MODEL_DIR / "best_params.joblib").exists():
        best_params = joblib.load(MODEL_DIR / "best_params.joblib")
        print(f"Loaded saved params: MAE ~{best_params.get('best_mae', 'unknown')}")
    else:
        print(f"\nRunning Optuna ({N_TRIALS} trials)...")
        study = optuna.create_study(direction="minimize")
        study.optimize(lambda t: objective(t, df, external_cols), n_trials=N_TRIALS, show_progress_bar=True)
        best_params = study.best_params.copy()
        best_params["best_mae"] = study.best_value
        print(f"Best MAE: {study.best_value:.4f}")
        joblib.dump(best_params, MODEL_DIR / "best_params.joblib")

    lgbm_params = build_lgbm_params(best_params)
    lags, lag_transforms = build_lags_transforms(best_params)

    # ── Backtest with best params ──────────────────────────────────────
    print(f"\n{'='*60}")
    print("BACKTEST (2 folds x 26-week horizon)")
    print(f"{'='*60}")

    all_dates = sorted(df["ds"].dropna().unique())
    cutoffs = [all_dates[-(HORIZON * (i + 1) + 1)] for i in range(2)]
    cutoffs = sorted(cutoffs)
    all_preds = []

    for fold_idx, cutoff in enumerate(cutoffs):
        test_start_idx = list(all_dates).index(cutoff) + 1
        test_dates = all_dates[test_start_idx:test_start_idx + HORIZON]
        print(f"\nFold {fold_idx+1}: train <= {cutoff.date()}, test {test_dates[0].date()} -> {test_dates[-1].date()}")

        train_df = df[df["ds"] <= cutoff].copy()
        test_df = df[df["ds"].isin(test_dates)].copy()

        mlf_fold = make_mlf(lgbm_params, lags, lag_transforms)
        mlf_fold.fit(train_df, id_col="unique_id", time_col="ds", target_col="y",
                     static_features=[], dropna=False)

        x_df = test_df[["unique_id", "ds"] + external_cols].copy()
        pred = mlf_fold.predict(HORIZON, X_df=x_df)
        actuals = test_df[["unique_id", "ds", "y"]].rename(columns={"y": "actual"})
        merged = pred.merge(actuals, on=["unique_id", "ds"], how="inner")
        merged["fold"] = fold_idx + 1
        all_preds.append(merged)

        fold_mae = mean_absolute_error(merged["actual"], merged["LGBMRegressor"])
        fold_rmse = np.sqrt(mean_squared_error(merged["actual"], merged["LGBMRegressor"]))
        print(f"  Fold {fold_idx+1} -- MAE: {fold_mae:.4f}, RMSE: {fold_rmse:.4f}")

    preds_df = pd.concat(all_preds, ignore_index=True)
    overall_mae = mean_absolute_error(preds_df["actual"], preds_df["LGBMRegressor"])
    overall_rmse = np.sqrt(mean_squared_error(preds_df["actual"], preds_df["LGBMRegressor"]))
    print(f"\nOverall -- MAE: {overall_mae:.4f}, RMSE: {overall_rmse:.4f}")

    # ── Full fit with conformal intervals ──────────────────────────────
    print(f"\n{'='*60}")
    print("FULL MODEL FIT + CONFORMAL INTERVALS")
    print(f"{'='*60}")

    mlf = make_mlf(lgbm_params, lags, lag_transforms)
    pi = PredictionIntervals(n_windows=2, h=HORIZON, method="conformal_distribution")
    mlf.fit(df, id_col="unique_id", time_col="ds", target_col="y",
            static_features=[], dropna=False, prediction_intervals=pi)
    print("Model fitted with conformal intervals.")

    # ── Live forecast ──────────────────────────────────────────────────
    last_known = df.groupby("unique_id")[external_cols].last().reset_index()
    future_dates = pd.date_range(start=df["ds"].max() + pd.Timedelta(weeks=1), periods=HORIZON, freq="W-MON")
    x_df_parts = []
    for site in all_sites:
        site_x = pd.DataFrame({"unique_id": site, "ds": future_dates})
        for col in external_cols:
            site_x[col] = last_known.loc[last_known["unique_id"] == site, col].values[0]
        x_df_parts.append(site_x)
    x_df = pd.concat(x_df_parts, ignore_index=True)

    forecast = mlf.predict(HORIZON, X_df=x_df, level=[80, 95])
    print(f"Forecast: {forecast.shape}")

    # ── Save ───────────────────────────────────────────────────────────
    joblib.dump(mlf, MODEL_DIR / "model_final.joblib")
    preds_df.to_csv(PROC_DIR / "backtest_predictions.csv", index=False)
    forecast.to_csv(PROC_DIR / "live_forecast.csv", index=False)

    print(f"\nSaved:")
    print(f"  {MODEL_DIR / 'model_final.joblib'}")
    print(f"  {MODEL_DIR / 'best_params.joblib'}")
    print(f"  {PROC_DIR / 'backtest_predictions.csv'}")
    print(f"  {PROC_DIR / 'live_forecast.csv'}")


if __name__ == "__main__":
    main()
