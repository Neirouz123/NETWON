"""
Step 3: Assemble the final training dataset

One row per site per week with:
  - unique_id, ds, y (target = utilization)
  - Static features: capacity, classification, type, region
  - Engineered features: neighbor avg, weekly history stats, daily-derived features
"""

import pandas as pd
import numpy as np
from pathlib import Path

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
PROC_DIR = DATA_DIR / "processed"


def main():
    # ── Load Step 2 output ─────────────────────────────────────────────
    df = pd.read_csv(PROC_DIR / "kpi_utilization.csv")
    df["Date"] = pd.to_datetime(df["Date"])
    print(f"Loaded kpi_utilization.csv: {df.shape}")

    # ── Rename to standard forecast format ─────────────────────────────
    out = df.rename(columns={
        "site": "unique_id",
        "Date": "ds",
        "utilization": "y",
    })

    # ── Cast categoricals ──────────────────────────────────────────────
    cat_cols = ["Classification", "Type", "GOUV"]
    for col in cat_cols:
        out[col] = out[col].astype("category")

    # ── Drop columns the model doesn't need ────────────────────────────
    drop_cols = [
        "rx_max_speed",   # raw KPI — replaced by y (utilization)
        "DEL",            # high cardinality (265), GOUV already captures region
    ]
    out = out.drop(columns=[c for c in drop_cols if c in out.columns])

    # ── Reorder columns ────────────────────────────────────────────────
    id_cols = ["unique_id", "ds"]
    target = ["y"]
    static_cols = ["capacity_mbps", "Classification", "Type", "LAT", "LONG", "GOUV"]
    neighbor = ["neighbor_avg_utilization"]
    weekly_hist = ["wk_hist_mean", "wk_hist_std", "wk_hist_min", "wk_hist_max", "wk_hist_trend"]
    daily_feats = [
        "daily_util_mean_30d", "daily_util_std_30d", "daily_util_slope_30d",
        "daily_util_min_30d", "daily_util_max_30d", "daily_days_since_spike",
    ]
    ordered = id_cols + target + static_cols + neighbor + weekly_hist + daily_feats
    ordered = [c for c in ordered if c in out.columns]
    out = out[ordered]

    # ── Sort ───────────────────────────────────────────────────────────
    out = out.sort_values(["unique_id", "ds"]).reset_index(drop=True)

    # ── Summary ────────────────────────────────────────────────────────
    print(f"\n{'='*60}")
    print(f"FINAL TRAINING DATASET")
    print(f"{'='*60}")
    print(f"Shape:    {out.shape}")
    print(f"Sites:    {out['unique_id'].nunique()}")
    print(f"Weeks:    {out['ds'].nunique()}")
    print(f"Date:     {out['ds'].min().date()} → {out['ds'].max().date()}")
    print(f"\nColumns:")
    for c in out.columns:
        nulls = out[c].isnull().sum()
        pct = 100 * nulls / len(out)
        print(f"  {c:35s}  dtype={str(out[c].dtype):10s}  nulls={nulls:6d} ({pct:5.1f}%)")
    print(f"\nTarget (y) stats:")
    print(out["y"].describe().to_string())
    print(f"\nCategoricals:")
    for col in cat_cols:
        print(f"  {col}: {out[col].cat.categories.tolist()}")

    # ── Save ───────────────────────────────────────────────────────────
    out_path = PROC_DIR / "train_dataset.csv"
    out.to_csv(out_path, index=False)
    print(f"\nSaved → {out_path}")


if __name__ == "__main__":
    main()
