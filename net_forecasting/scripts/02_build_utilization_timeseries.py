"""
Step 2: Build the utilization time series

- Weekly KPI × Plan_Data → utilization per site-week
- k-NN neighbor-average utilization (haversine, k=5)
- Long-history weekly stats (mean/std/min/max/trend) on utilization
- Daily-derived short-term features (30-day slope, 30-day std, days-since-spike)
- Output: kpi_utilization.csv (one row per site per week)
"""

import pandas as pd
import numpy as np
from pathlib import Path
from math import radians, sin, cos, sqrt, atan2

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
RAW_DIR = DATA_DIR / "raw"
PROC_DIR = DATA_DIR / "processed"
PROC_DIR.mkdir(exist_ok=True)


# ── Helpers ────────────────────────────────────────────────────────────

def strip_site_suffix(name: str) -> str:
    """Extract site code from eNodeB name: MON_0040_LM → MON_0040."""
    return name.rsplit("_", 1)[0] if "_" in name else name


def haversine(lat1, lon1, lat2, lon2):
    """Distance in km between two (lat, lon) points."""
    R = 6371.0
    dlat = radians(lat2 - lat1)
    dlon = radians(lon2 - lon1)
    a = sin(dlat / 2) ** 2 + cos(radians(lat1)) * cos(radians(lat2)) * sin(dlon / 2) ** 2
    return R * 2 * atan2(sqrt(a), sqrt(1 - a))


def build_balltree(lats, lons):
    """Build a simple brute-force k-NN structure (good enough for ~2.5k points).
    Returns indices and distances of k nearest neighbors for each point."""
    n = len(lats)
    dists = np.zeros((n, n))
    for i in range(n):
        for j in range(i + 1, n):
            d = haversine(lats[i], lons[i], lats[j], lons[j])
            dists[i, j] = d
            dists[j, i] = d
    return dists


def knn_avg(dists, values, k=5):
    """Compute k-NN average for each point, excluding itself."""
    n = len(values)
    result = np.full(n, np.nan)
    for i in range(n):
        order = np.argsort(dists[i])
        # skip self (dist=0), take next k
        neighbors = order[1:k + 1]
        neighbor_vals = values[neighbors]
        valid = neighbor_vals[~np.isnan(neighbor_vals)]
        result[i] = np.mean(valid) if len(valid) > 0 else np.nan
    return result


# ── Main ───────────────────────────────────────────────────────────────

def main():
    # ── 1. Load data ───────────────────────────────────────────────────
    print("Loading data...")
    plan = pd.read_csv(PROC_DIR / "plan_data_clean.csv")
    weekly = pd.read_excel(RAW_DIR / "KPI_weekly.xlsx")
    daily = pd.read_excel(RAW_DIR / "KPI_daily.xlsx")

    # Parse dates
    weekly["Date"] = pd.to_datetime(weekly["Date"], dayfirst=True)
    daily["Date"] = pd.to_datetime(daily["Date"], dayfirst=True)

    # Strip suffixes to get site codes
    weekly["site"] = weekly["eNodeB Name"].apply(strip_site_suffix)
    daily["site"] = daily["eNodeB Name"].apply(strip_site_suffix)

    print(f"  Weekly: {len(weekly)} rows, {weekly['site'].nunique()} sites, "
          f"{weekly['Date'].min().date()} → {weekly['Date'].max().date()}")
    print(f"  Daily:  {len(daily)} rows, {daily['site'].nunique()} sites, "
          f"{daily['Date'].min().date()} → {daily['Date'].max().date()}")
    print(f"  Plan:   {len(plan)} sites")

    # ── 2. Merge weekly KPI with plan ──────────────────────────────────
    plan_site_set = set(plan["Site"])
    weekly = weekly[weekly["site"].isin(plan_site_set)].copy()
    print(f"\nAfter filtering to plan sites: {weekly['site'].nunique()} sites, {len(weekly)} rows")

    # Rename KPI column
    weekly = weekly.rename(columns={"VS.FEGE.RxMaxSpeed_Mbs(Mbit/s)": "rx_max_speed"})

    # Merge with plan to get capacity
    weekly = weekly.merge(
        plan[["Site", "capacity_mbps", "Classification", "Type", "LONG", "LAT", "GOUV", "DEL"]],
        left_on="site", right_on="Site", how="left",
    )

    # ── 3. Compute utilization ─────────────────────────────────────────
    weekly["utilization"] = np.where(
        weekly["capacity_mbps"] > 0,
        (weekly["rx_max_speed"] / weekly["capacity_mbps"]) * 100,
        np.nan,
    )

    # Cap any remaining >150% outliers in the time series (data glitches)
    n_over150 = (weekly["utilization"] > 150).sum()
    if n_over150 > 0:
        print(f"  Capping {n_over150} utilization values >150% to NaN")
        weekly.loc[weekly["utilization"] > 150, "utilization"] = np.nan

    print(f"\nUtilization stats:")
    print(weekly["utilization"].describe().to_string())

    # ── 4. k-NN neighbor-average utilization ────────────────────────────
    print("\nBuilding k-NN neighbor averages (k=5)...")
    plan_lookup = plan.set_index("Site")[["LAT", "LONG"]].dropna()
    plan_lookup = plan_lookup[~plan_lookup.index.duplicated(keep="first")]
    sites_with_coords = weekly["site"].unique()
    site_list = [s for s in sites_with_coords if s in plan_lookup.index]

    coords = plan_lookup.loc[site_list, ["LAT", "LONG"]].values
    lats = coords[:, 0]
    lons = coords[:, 1]
    n_sites = len(site_list)
    dists = build_balltree(lats, lons)

    # Compute neighbor avg utilization per site (use overall mean per site)
    site_mean_util = weekly.groupby("site")["utilization"].mean()
    site_mean_util_arr = np.array([site_mean_util.get(s, np.nan) for s in site_list])
    assert len(site_mean_util_arr) == n_sites, f"Length mismatch: {len(site_mean_util_arr)} vs {n_sites}"
    neighbor_avg = knn_avg(dists, site_mean_util_arr, k=5)

    neighbor_map = dict(zip(site_list, neighbor_avg))
    weekly["neighbor_avg_utilization"] = weekly["site"].map(neighbor_map)

    print(f"  Neighbor avg utilization: mean={weekly['neighbor_avg_utilization'].mean():.2f}%, "
          f"std={weekly['neighbor_avg_utilization'].std():.2f}%")

    # ── 5. Long-history weekly stats per site ──────────────────────────
    print("Computing long-history weekly stats...")
    weekly = weekly.sort_values(["site", "Date"])

    def rolling_stats(group):
        u = group["utilization"]
        result = pd.DataFrame(index=group.index)
        result["wk_hist_mean"] = u.expanding().mean()
        result["wk_hist_std"] = u.expanding().std()
        result["wk_hist_min"] = u.expanding().min()
        result["wk_hist_max"] = u.expanding().max()
        # Trend: slope of last 4 points (or fewer if not available)
        result["wk_hist_trend"] = u.rolling(4, min_periods=2).apply(
            lambda x: np.polyfit(range(len(x)), x, 1)[0] if len(x) >= 2 else np.nan,
            raw=False,
        )
        return result

    stats = weekly.groupby("site", group_keys=False).apply(rolling_stats)
    weekly = pd.concat([weekly, stats], axis=1)

    # ── 6. Daily-derived short-term features ───────────────────────────
    print("Computing daily short-term features...")
    daily = daily[daily["site"].isin(plan_site_set)].copy()
    daily = daily.rename(columns={"VS.FEGE.RxMaxSpeed_Mbs(Mbit/s)": "rx_max_speed"})
    daily = daily.merge(
        plan[["Site", "capacity_mbps"]],
        left_on="site", right_on="Site", how="left",
    )
    daily["utilization"] = np.where(
        daily["capacity_mbps"] > 0,
        (daily["rx_max_speed"] / daily["capacity_mbps"]) * 100,
        np.nan,
    )
    daily.loc[daily["utilization"] > 150, "utilization"] = np.nan
    daily = daily.sort_values(["site", "Date"])

    # For each weekly date, compute features from the preceding 30 days of daily data
    weekly_dates = sorted(weekly["Date"].unique())
    daily_features = []

    for wk_date in weekly_dates:
        window_start = wk_date - pd.Timedelta(days=30)
        window = daily[(daily["Date"] > window_start) & (daily["Date"] <= wk_date)]

        if len(window) == 0:
            continue

        grouped = window.groupby("site")["utilization"]
        feats = grouped.agg(["mean", "std", "min", "max"]).rename(columns={
            "mean": "daily_util_mean_30d",
            "std": "daily_util_std_30d",
            "min": "daily_util_min_30d",
            "max": "daily_util_max_30d",
        })

        # Slope: linear trend over the 30-day window (per site)
        slopes = {}
        days_since_spike = {}
        for site, grp in window.groupby("site"):
            grp_sorted = grp.sort_values("Date")
            vals = grp_sorted["utilization"].dropna().values
            if len(vals) >= 2:
                x = np.arange(len(vals))
                slopes[site] = np.polyfit(x, vals, 1)[0]
                # Days since last spike (>100%)
                spike_mask = vals > 100
                if spike_mask.any():
                    last_spike_idx = np.where(spike_mask)[0][-1]
                    days_since_spike[site] = len(vals) - 1 - last_spike_idx
                else:
                    days_since_spike[site] = len(vals)
            else:
                slopes[site] = np.nan
                days_since_spike[site] = np.nan

        feats["daily_util_slope_30d"] = pd.Series(slopes)
        feats["daily_days_since_spike"] = pd.Series(days_since_spike)
        feats["weekly_date"] = wk_date
        feats = feats.reset_index().rename(columns={"site": "site"})
        daily_features.append(feats)

    daily_feats_df = pd.concat(daily_features, ignore_index=True) if daily_features else pd.DataFrame()
    print(f"  Daily features computed for {len(daily_feats_df)} site-week combinations")

    # ── 7. Merge daily features into weekly ────────────────────────────
    if len(daily_feats_df) > 0:
        weekly = weekly.merge(
            daily_feats_df,
            left_on=["site", "Date"],
            right_on=["site", "weekly_date"],
            how="left",
        )
        weekly = weekly.drop(columns=["weekly_date"], errors="ignore")

    # ── 8. Final output ────────────────────────────────────────────────
    output_cols = [
        "site",
        "Date",
        "rx_max_speed",
        "capacity_mbps",
        "utilization",
        "neighbor_avg_utilization",
        "wk_hist_mean",
        "wk_hist_std",
        "wk_hist_min",
        "wk_hist_max",
        "wk_hist_trend",
        "daily_util_mean_30d",
        "daily_util_std_30d",
        "daily_util_slope_30d",
        "daily_util_min_30d",
        "daily_util_max_30d",
        "daily_days_since_spike",
        "Classification",
        "Type",
        "LONG",
        "LAT",
        "GOUV",
        "DEL",
    ]
    # Only include columns that exist
    output_cols = [c for c in output_cols if c in weekly.columns]
    out = weekly[output_cols].copy()
    out = out.sort_values(["site", "Date"]).reset_index(drop=True)

    # ── Summary ────────────────────────────────────────────────────────
    print(f"\n{'='*60}")
    print(f"UTILIZATION TIME SERIES")
    print(f"{'='*60}")
    print(f"Shape: {out.shape}")
    print(f"Sites: {out['site'].nunique()}")
    print(f"Weeks: {out['Date'].nunique()}")
    print(f"Date range: {out['Date'].min().date()} → {out['Date'].max().date()}")
    print(f"\nUtilization (%) stats:")
    print(out["utilization"].describe().to_string())
    print(f"\nSites per classification:")
    print(out.groupby("Classification")["site"].nunique().to_string())

    # ── Save ───────────────────────────────────────────────────────────
    out_path = PROC_DIR / "kpi_utilization.csv"
    out.to_csv(out_path, index=False)
    print(f"\nSaved → {out_path}")


if __name__ == "__main__":
    main()
