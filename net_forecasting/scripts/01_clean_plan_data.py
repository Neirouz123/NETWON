"""
Step 1: Clean & consolidate Plan_Data.xlsx

- Fix French-formatted numbers (commas as decimals, space thousands, % strings)
- Resolve which capacity applies per site based on Classification
- Cap or drop >150% utilization outliers
- Output: one clean row per site with a trustworthy capacity value
"""

import pandas as pd
import numpy as np
from pathlib import Path

DATA_DIR = Path(__file__).resolve().parent.parent / "data"
RAW_DIR = DATA_DIR / "raw"
PROC_DIR = DATA_DIR / "processed"
PROC_DIR.mkdir(exist_ok=True)


def parse_french_number(s):
    """Parse a French-formatted number string to float.

    Handles: commas as decimals, spaces as thousand separators, % suffix.
    Returns None for missing/invalid values.
    """
    if pd.isna(s) or s == "" or s is None:
        return np.nan
    s = str(s).strip()
    s = s.replace("%", "").replace(" ", "").replace(",", ".")
    try:
        return float(s)
    except ValueError:
        return np.nan


def main():
    # ── Load raw data ──────────────────────────────────────────────────
    raw = pd.read_excel(RAW_DIR / "Plan_Data.xlsx")
    print(f"Raw Plan_Data: {raw.shape[0]} rows, {raw.shape[1]} columns")

    df = raw.copy()

    # ── Parse all French-formatted numeric columns ─────────────────────
    numeric_cols = [
        "Max TDD",
        "Max FDD",
        "Trafic Max",
        "Capacité TDD (Mbps)",
        "Capacité FDD (Mbps)",
        "Taux Utilisation",
        "Taux Utilisation TDD",
        "Taux Utilisation FDD",
    ]
    for col in numeric_cols:
        df[col] = df[col].apply(parse_french_number)

    print("\n── After parsing French numbers ──")
    print(f"Trafic Max range: [{df['Trafic Max'].min():.2f}, {df['Trafic Max'].max():.2f}]")
    print(f"Capacité FDD range: [{df['Capacité FDD (Mbps)'].min():.2f}, {df['Capacité FDD (Mbps)'].max():.2f}]")
    print(f"Capacité TDD range: [{df['Capacité TDD (Mbps)'].min():.2f}, {df['Capacité TDD (Mbps)'].max():.2f}]")

    # ── Resolve capacity per site based on Classification ──────────────
    # Logic:
    #   ONLY FDD  → capacity = Capacité FDD  (Capacité TDD is always 0)
    #   TF        → capacity = Capacité FDD  (Capacité TDD is always 0)
    #   COTRANS   → capacity = Capacité TDD  (both TDD/FDD equal, TDD is canonical)
    #   NO_COTRANS→ capacity = Capacité TDD  (both TDD/FDD equal, TDD is canonical)

    def resolve_capacity(row):
        cls = row["Classification"]
        if cls in ("ONLY FDD", "TF"):
            return row["Capacité FDD (Mbps)"]
        elif cls in ("COTRANS", "NO_COTRANS"):
            return row["Capacité TDD (Mbps)"]
        else:
            return np.nan

    df["capacity_mbps"] = df.apply(resolve_capacity, axis=1)

    # ── Compute utilization from traffic / capacity ────────────────────
    # The stated Taux Utilisation is already trafic/cap_fdd * 100 for ONLY FDD / TF.
    # For COTRANS / NO_COTRANS, Taux Utilisation is NaN — we compute it ourselves.
    # We recalculate everything uniformly so the column is self-consistent.
    df["utilization_pct"] = np.where(
        df["capacity_mbps"] > 0,
        (df["Trafic Max"] / df["capacity_mbps"]) * 100,
        np.nan,
    )

    # ── Flag & handle outliers > 150% ──────────────────────────────────
    # These are bad capacity records, not real overload.
    n_total = len(df)
    outlier_mask = df["utilization_pct"] > 150
    n_outliers = outlier_mask.sum()

    print(f"\n── Outlier analysis ──")
    print(f"Sites with utilization > 150%: {n_outliers} / {n_total} ({100*n_outliers/n_total:.1f}%)")

    if n_outliers > 0:
        print("\nOutlier sites (utilization > 150%):")
        outlier_info = df.loc[outlier_mask, [
            "Site", "Classification", "Trafic Max", "capacity_mbps", "utilization_pct"
        ]].sort_values("utilization_pct", ascending=False)
        print(outlier_info.head(20).to_string(index=False))

        # Show capacity distribution for outliers
        print(f"\nOutlier capacity values (Mbps):")
        print(df.loc[outlier_mask, "capacity_mbps"].describe())

    # Drop outliers: these have unreliable capacity records
    df_clean = df[~outlier_mask].copy()
    print(f"\nDropped {n_outliers} outlier rows → {len(df_clean)} sites remaining")

    # ── Also drop sites with no valid capacity ─────────────────────────
    no_cap = df_clean["capacity_mbps"].isna() | (df_clean["capacity_mbps"] <= 0)
    n_nocap = no_cap.sum()
    if n_nocap > 0:
        print(f"Dropping {n_nocap} sites with no valid capacity")
        df_clean = df_clean[~no_cap].copy()

    # ── Also drop sites with no traffic ────────────────────────────────
    no_traffic = df_clean["Trafic Max"].isna() | (df_clean["Trafic Max"] <= 0)
    n_notraffic = no_traffic.sum()
    if n_notraffic > 0:
        print(f"Dropping {n_notraffic} sites with no valid traffic")
        df_clean = df_clean[~no_traffic].copy()

    # ── Build output ───────────────────────────────────────────────────
    output_cols = [
        "Site",
        "Classification",
        "Type",
        "capacity_mbps",
        "Trafic Max",
        "utilization_pct",
        "Max TDD",
        "Max FDD",
        "Capacité TDD (Mbps)",
        "Capacité FDD (Mbps)",
        "Occurrences",
        "Occurrence TDD",
        "Occurrence FDD",
        "Statut",
        "État",
        "DropCong (tdd)",
        "DropCong (fdd)",
        "DropCong (tf)",
        "LONG",
        "LAT",
        "GOUV",
        "DEL",
    ]
    out = df_clean[output_cols].copy()

    # ── Summary ────────────────────────────────────────────────────────
    print(f"\n{'='*60}")
    print(f"CLEAN PLAN DATA: {len(out)} sites")
    print(f"{'='*60}")
    print(f"\nClassification distribution:")
    print(out["Classification"].value_counts().to_string())
    print(f"\nCapacity (Mbps) stats:")
    print(out["capacity_mbps"].describe().to_string())
    print(f"\nUtilization (%) stats:")
    print(out["utilization_pct"].describe().to_string())
    print(f"\nÉtat distribution:")
    print(out["État"].value_counts().to_string())

    # ── Sanity check: stated vs recalculated utilization for ONLY FDD ──
    fdd_recalc = out.loc[out["Classification"] == "ONLY FDD", ["Site", "utilization_pct"]].copy()
    fdd_raw = raw[raw["Classification"] == "ONLY FDD"][["Site", "Taux Utilisation"]].copy()
    fdd_raw["Taux Utilisation"] = fdd_raw["Taux Utilisation"].apply(parse_french_number)
    merged = fdd_recalc.merge(fdd_raw, on="Site", how="inner")
    valid = merged["Taux Utilisation"].notna() & merged["utilization_pct"].notna()
    if valid.sum() > 0:
        diff = merged.loc[valid, "utilization_pct"].values - merged.loc[valid, "Taux Utilisation"].values
        print(f"\nSanity check (ONLY FDD, stated vs recalculated):")
        print(f"  Mean diff: {diff.mean():.4f} pp  (should be ~0)")
        print(f"  Max abs diff: {np.abs(diff).max():.4f} pp")

    # ── Save ───────────────────────────────────────────────────────────
    out_path = PROC_DIR / "plan_data_clean.csv"
    out.to_csv(out_path, index=False)
    print(f"\nSaved → {out_path}")


if __name__ == "__main__":
    main()
