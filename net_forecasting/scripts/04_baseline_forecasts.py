"""
Step 4: Baseline forecasts — get an honest MAE/RMSE floor

For each site, forecast the next 26 weeks of utilization using:
  1. Seasonal naive (repeat last year's same-week value)
  2. Holt-Winters triple exponential smoothing

Evaluation: rolling-origin backtest with 26-week horizon.
"""

import pandas as pd
import numpy as np
import warnings
from pathlib import Path
from sklearn.metrics import mean_absolute_error, mean_squared_error
from statsmodels.tsa.holtwinters import ExponentialSmoothing

warnings.filterwarnings("ignore")

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
PROC_DIR = DATA_DIR / "processed"
PROC_DIR.mkdir(exist_ok=True)

HORIZON = 26  # weeks


def seasonal_naive_forecast(series: pd.Series, h: int) -> np.ndarray:
    """Repeat the last `h` values from one year ago."""
    n = len(series)
    if n < h:
        # Not enough history — repeat last known value
        return np.full(h, series.iloc[-1])
    return series.iloc[-h:].values.copy()


def holtwinters_forecast(series: pd.Series, h: int) -> np.ndarray:
    """Fit Holt-Winters (additive trend, additive seasonality, period=52 weeks)
    and forecast `h` steps ahead. Falls back to simpler models on failure."""
    # Need at least 2 full seasonal cycles (104 weeks) for triple HW
    # Fall back gracefully for shorter series
    min_len = 104

    try:
        if len(series) >= min_len and series.notna().sum() >= min_len:
            model = ExponentialSmoothing(
                series.values,
                trend="add",
                seasonal="add",
                seasonal_periods=52,
                initialization_method="estimated",
            ).fit(optimized=True, use_brute=False)
            return model.forecast(h)
    except Exception:
        pass

    # Fallback: additive trend, no seasonality
    try:
        model = ExponentialSmoothing(
            series.values,
            trend="add",
            seasonal=None,
            initialization_method="estimated",
        ).fit(optimized=True)
        return model.forecast(h)
    except Exception:
        pass

    # Last fallback: just repeat last value
    return np.full(h, series.dropna().iloc[-1])


def evaluate(y_true: np.ndarray, y_pred: np.ndarray, label: str) -> dict:
    """Compute MAE, RMSE, MAPE (ignoring zeros and NaNs)."""
    valid = np.isfinite(y_true) & np.isfinite(y_pred)
    yt, yp = y_true[valid], y_pred[valid]
    mask = yt > 0
    mae = mean_absolute_error(yt, yp)
    rmse = np.sqrt(mean_squared_error(yt, yp))
    mape = np.mean(np.abs((yt[mask] - yp[mask]) / yt[mask])) * 100 if mask.sum() > 0 else np.nan
    return {"model": label, "MAE": mae, "RMSE": rmse, "MAPE": mape}


def main():
    # ── Load data ──────────────────────────────────────────────────────
    df = pd.read_csv(PROC_DIR / "train_dataset.csv", parse_dates=["ds"])
    print(f"Loaded: {df.shape}")
    print(f"Sites: {df['unique_id'].nunique()}, Weeks: {df['ds'].nunique()}")

    sites = sorted(df["unique_id"].unique())
    n_sites = len(sites)

    # ── Rolling-origin backtest setup ──────────────────────────────────
    # Use last 26 weeks as the test set, everything before as train
    all_dates = sorted(df["ds"].unique())
    cutoff = all_dates[-(HORIZON + 1)]  # last train week
    test_dates = all_dates[-HORIZON:]

    print(f"\nTrain ends: {cutoff.date()}, Test: {test_dates[0].date()} → {test_dates[-1].date()}")

    # ── Run baselines ──────────────────────────────────────────────────
    sn_preds = []
    hw_preds = []
    actuals = []

    print(f"\nRunning baselines on {n_sites} sites...")
    for i, site in enumerate(sites):
        site_df = df[df["unique_id"] == site].sort_values("ds")
        train = site_df[site_df["ds"] <= cutoff]["y"]
        test = site_df[site_df["ds"].isin(test_dates)][["ds", "y"]]

        if len(test) == 0 or train.isna().all():
            continue

        # Fill NaNs in train for HW (interpolate)
        train_filled = train.interpolate(limit_direction="both").fillna(method="bfill").fillna(method="ffill")
        if train_filled.isna().all():
            continue

        # Seasonal naive
        sn_pred = seasonal_naive_forecast(train_filled, HORIZON)

        # Holt-Winters
        hw_pred = holtwinters_forecast(train_filled, HORIZON)

        # Align lengths
        actual = test["y"].values[:HORIZON]
        sn_pred = sn_pred[:len(actual)]
        hw_pred = hw_pred[:len(actual)]

        # Clamp negatives
        sn_pred = np.maximum(sn_pred, 0)
        hw_pred = np.maximum(hw_pred, 0)

        actuals.extend(actual)
        sn_preds.extend(sn_pred)
        hw_preds.extend(hw_pred)

        if (i + 1) % 500 == 0:
            print(f"  {i+1}/{n_sites} sites done")

    # ── Evaluate ───────────────────────────────────────────────────────
    actuals = np.array(actuals)
    sn_preds = np.array(sn_preds)
    hw_preds = np.array(hw_preds)

    sn_metrics = evaluate(actuals, sn_preds, "Seasonal Naive")
    hw_metrics = evaluate(actuals, hw_preds, "Holt-Winters")

    results = pd.DataFrame([sn_metrics, hw_metrics])

    print(f"\n{'='*60}")
    print(f"BASELINE RESULTS (h={HORIZON} weeks, {n_sites} sites)")
    print(f"{'='*60}")
    print(results.to_string(index=False, float_format="{:.4f}".format))

    # ── Per-classification breakdown ───────────────────────────────────
    # Rebuild per-site metrics
    site_class = df.drop_duplicates("unique_id")[["unique_id", "Classification"]].set_index("unique_id")
    site_rows = []
    idx = 0
    for site in sites:
        site_df = df[df["unique_id"] == site].sort_values("ds")
        test = site_df[site_df["ds"].isin(test_dates)]["y"].values[:HORIZON]
        if len(test) == 0:
            idx += HORIZON
            continue
        n = len(test)
        sn_err = np.abs(actuals[idx:idx+n] - sn_preds[idx:idx+n])
        hw_err = np.abs(actuals[idx:idx+n] - hw_preds[idx:idx+n])
        cls = site_class.loc[site, "Classification"] if site in site_class.index else "Unknown"
        site_rows.append({"site": site, "Classification": cls,
                          "sn_mae": sn_err.mean(), "hw_mae": hw_err.mean()})
        idx += n

    site_results = pd.DataFrame(site_rows)
    print(f"\nPer-classification MAE:")
    print(site_results.groupby("Classification")[["sn_mae", "hw_mae"]].mean().to_string(float_format="{:.4f}".format))

    # ── Save ───────────────────────────────────────────────────────────
    results.to_csv(PROC_DIR / "baseline_metrics.csv", index=False)
    print(f"\nSaved → {PROC_DIR / 'baseline_metrics.csv'}")


if __name__ == "__main__":
    main()
