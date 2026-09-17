"""
Telecom Network Congestion Simulator
Predicts which sites will cross 100% utilization in 6 months.
"""

import streamlit as st
import pandas as pd
import numpy as np
import joblib
from pathlib import Path
from sklearn.isotonic import IsotonicRegression
import plotly.express as px
import plotly.graph_objects as go

# ── Paths ──────────────────────────────────────────────────────────────
DATA_DIR = Path(__file__).resolve().parent / "data"
PROC_DIR = DATA_DIR / "processed"
MODEL_DIR = DATA_DIR / "model"

# ── Page config ────────────────────────────────────────────────────────
st.set_page_config(
    page_title="Telecom Congestion Simulator",
    page_icon="📡",
    layout="wide",
)

# ── Load data (cached) ─────────────────────────────────────────────────
@st.cache_data
def load_all():
    plan = pd.read_csv(PROC_DIR / "plan_data_clean.csv")
    risk_cal = pd.read_csv(PROC_DIR / "monte_carlo_risk_calibrated.csv")
    live = pd.read_csv(PROC_DIR / "live_forecast.csv")
    backtest = pd.read_csv(PROC_DIR / "backtest_predictions.csv")
    importance = pd.read_csv(PROC_DIR / "feature_importance.csv")
    cal_model = joblib.load(MODEL_DIR / "calibration.joblib")

    live.columns = [c.replace("LGBMRegressor-", "").replace("LGBMRegressor", "yhat") for c in live.columns]

    # Get residuals for Monte Carlo
    residuals = backtest["actual"].values - backtest["LGBMRegressor"].values

    return plan, risk_cal, live, residuals, importance, cal_model


def run_simulation(forecast_values, residuals, n_sims=1000):
    """Run Monte Carlo simulation on modified forecasts."""
    sims = np.zeros((n_sims, len(forecast_values)))
    for i in range(n_sims):
        noise = np.random.choice(residuals, size=len(forecast_values))
        sims[i] = forecast_values + noise

    p_congestion = (sims > 100).mean(axis=0)
    max_forecast = sims.max(axis=0)
    mean_forecast = sims.mean(axis=0)

    return p_congestion, max_forecast, mean_forecast


def calibrate(max_forecast, cal_model):
    """Calibrate risk using isotonic regression."""
    ir = cal_model["ir"]
    return ir.predict([max_forecast])[0]


def get_risk_tier(p_cal):
    if p_cal < 0.1:
        return "Very Low", "#2ecc71"
    elif p_cal < 0.3:
        return "Low", "#f1c40f"
    elif p_cal < 0.5:
        return "Medium", "#e67e22"
    elif p_cal < 0.7:
        return "High", "#e74c3c"
    else:
        return "Very High", "#8e44ad"


def make_forecast_chart(dates, yhat, lo, hi, title="Forecast"):
    fig = go.Figure()
    fig.add_trace(go.Scatter(x=dates, y=hi, mode="lines", line=dict(width=0), showlegend=False))
    fig.add_trace(go.Scatter(x=dates, y=lo, fill="tonexty", mode="lines", line=dict(width=0),
                             fillcolor="rgba(52,152,219,0.2)", name="80% Interval"))
    fig.add_trace(go.Scatter(x=dates, y=yhat, mode="lines+markers", name="Forecast",
                             line=dict(color="#2980b9", width=2)))
    fig.add_hline(y=100, line_dash="dash", line_color="red", annotation_text="Congestion Threshold")
    fig.update_layout(title=title, xaxis_title="Week", yaxis_title="Utilization %",
                      height=350, template="plotly_white")
    return fig


# ── Load data ──────────────────────────────────────────────────────────
plan, risk_cal, live, residuals, importance, cal_model = load_all()

# ── Header ─────────────────────────────────────────────────────────────
st.title("📡 Telecom Congestion Simulator")
st.markdown("**Which sites will cross 100% utilization in 6 months?**")

# ── Tabs ───────────────────────────────────────────────────────────────
tabs = st.tabs([
    "📊 Current Risk",
    "⬆️ Capacity Upgrade",
    "📈 Traffic Growth",
    "⚡ Stress Test",
    "🔍 Site Details",
])

# ═══════════════════════════════════════════════════════════════════════
# TAB 1: Current Risk — The main view
# ═══════════════════════════════════════════════════════════════════════
with tabs[0]:
    st.header("📊 Sites at Risk (Current State)")

    # Summary metrics
    col1, col2, col3, col4 = st.columns(4)
    total_sites = len(risk_cal)
    high_risk = len(risk_cal[risk_cal["p_calibrated"] > 0.5])
    very_high = len(risk_cal[risk_cal["p_calibrated"] > 0.7])
    col1.metric("Total Sites", f"{total_sites:,}")
    col2.metric("At Risk (P > 50%)", f"{high_risk:,}")
    col3.metric("Very High Risk (P > 70%)", f"{very_high:,}")
    col4.metric("Model MAE", "3.17")

    # Risk distribution
    st.subheader("Risk Distribution")
    tier_order = ["Very Low", "Low", "Medium", "High", "Very High"]
    tier_colors = {"Very Low": "#2ecc71", "Low": "#f1c40f", "Medium": "#e67e22",
                   "High": "#e74c3c", "Very High": "#8e44ad"}

    tier_counts = risk_cal["risk_tier_calibrated"].value_counts().reindex(tier_order, fill_value=0)
    fig = px.bar(x=tier_counts.index, y=tier_counts.values,
                 color=tier_counts.index,
                 color_discrete_map=tier_colors)
    fig.update_layout(xaxis_title="Risk Tier", yaxis_title="Number of Sites",
                      template="plotly_white", height=300, showlegend=False)
    st.plotly_chart(fig, width="stretch")

    # THE KEY TABLE: Sites that will get problems
    st.subheader("🚨 Sites Likely to Congest (P > 50%)")
    at_risk = risk_cal[risk_cal["p_calibrated"] > 0.5].copy()
    at_risk = at_risk.sort_values("p_calibrated", ascending=False)
    at_risk = at_risk[["site", "classification", "point_forecast_mean", "p_calibrated", "risk_tier_calibrated"]]
    at_risk.columns = ["Site", "Type", "Avg Forecast %", "P(Congestion)", "Risk Tier"]
    at_risk["Avg Forecast %"] = at_risk["Avg Forecast %"].round(1)
    at_risk["P(Congestion)"] = at_risk["P(Congestion)"].apply(lambda x: f"{x:.1%}")

    st.dataframe(at_risk, width="stretch", height=min(400, len(at_risk) * 35 + 40))

    # Top 20 worst
    st.subheader("🔴 Top 20 Worst Sites")
    top20 = risk_cal.nlargest(20, "p_calibrated")
    fig = px.bar(top20, x="site", y="p_calibrated", color="classification",
                 color_discrete_map={"ONLY FDD": "#3498db", "TF": "#e74c3c",
                                     "NO_COTRANS": "#2ecc71", "COTRANS": "#f39c12"})
    fig.update_layout(xaxis_title="Site", yaxis_title="P(Congestion)",
                      template="plotly_white", height=350, xaxis_tickangle=45)
    st.plotly_chart(fig, width="stretch")


# ═══════════════════════════════════════════════════════════════════════
# TAB 2: Capacity Upgrade Simulation
# ═══════════════════════════════════════════════════════════════════════
with tabs[1]:
    st.header("⬆️ Capacity Upgrade Simulation")

    st.markdown("Select sites and see how upgrading capacity reduces congestion risk.")

    col1, col2 = st.columns([1, 1])
    with col1:
        cap_sites = st.multiselect(
            "Select sites to upgrade",
            options=sorted(risk_cal["site"].unique()),
            default=[],
        )
    with col2:
        cap_increase = st.number_input(
        "Capacity increase (Mbps)", min_value=0, max_value=10000, value=500, step=50,
        help="Fixed amount of extra capacity (in Mbps) to add to the current capacity."
    )
    if st.button("Run Simulation", key="cap_sim"):
        if not cap_sites:
            st.warning("Please select at least one site.")
        else:
            with st.spinner("Running simulation..."):
                results = []
                for site in cap_sites:
                    site_plan = plan[plan["Site"] == site]
                    if len(site_plan) == 0:
                        continue
                    site_plan = site_plan.iloc[0]

                    site_risk = risk_cal[risk_cal["site"] == site]
                    if len(site_risk) == 0:
                        continue
                    site_risk = site_risk.iloc[0]

                    site_live = live[live["unique_id"] == site].sort_values("ds")
                    if len(site_live) == 0:
                        continue

                    old_cap = site_plan["capacity_mbps"]
                    new_cap = old_cap + cap_increase
                    scale = old_cap / new_cap

                    # Run simulation on scaled forecast
                    new_forecast = site_live["yhat"].values * scale
                    p_cong, max_fc, mean_fc = run_simulation(new_forecast, residuals * scale)
                    p_cal = calibrate(max_fc.mean(), cal_model)
                    tier, _ = get_risk_tier(p_cal)

                    old_p = site_risk["p_calibrated"]
                    results.append({
                        "Site": site,
                        "Type": site_risk["classification"],
                        "Old Capacity": f"{old_cap:.0f} Mbps",
                        "New Capacity": f"{new_cap:.0f} Mbps",
                        "Before Risk": f"{old_p:.1%}",
                        "After Risk": f"{p_cal:.1%}",
                        "Risk Reduction": f"{old_p - p_cal:.1%}",
                        "New Tier": tier,
                    })

                df_results = pd.DataFrame(results)
                st.success(f"Simulation complete! {len(df_results)} sites analyzed.")

                # Show results
                st.subheader("Results")
                st.dataframe(df_results, width="stretch", height=min(400, len(df_results) * 35 + 40))

                # Before/after chart
                if len(df_results) > 0:
                    st.subheader("Risk Before vs After")
                    df_plot = pd.DataFrame({
                        "Site": df_results["Site"],
                        "Before": [float(x.strip('%')) for x in df_results["Before Risk"]],
                        "After": [float(x.strip('%')) for x in df_results["After Risk"]],
                    })
                    fig = go.Figure()
                    fig.add_trace(go.Bar(name="Before", x=df_plot["Site"], y=df_plot["Before"],
                                         marker_color="#e74c3c"))
                    fig.add_trace(go.Bar(name="After", x=df_plot["Site"], y=df_plot["After"],
                                         marker_color="#2ecc71"))
                    fig.update_layout(barmode="group", yaxis_title="P(Congestion) %",
                                      template="plotly_white", height=400)
                    st.plotly_chart(fig, width="stretch")


# ═══════════════════════════════════════════════════════════════════════
# TAB 3: Traffic Growth Simulation
# ═══════════════════════════════════════════════════════════════════════
with tabs[2]:
    st.header("📈 Traffic Growth Simulation")

    st.markdown("What happens if traffic increases across the network?")

    col1, col2 = st.columns([1, 1])
    with col1:
        growth_type = st.radio("Apply to:", ["All sites", "By governorate", "Select sites"])
    with col2:
        growth_pct = st.slider("Traffic increase %", -50, 200, 20, 5)

    if growth_type == "By governorate":
        gov_list = sorted(plan["GOUV"].dropna().unique())
        growth_govs = st.multiselect("Select governorates", gov_list)
    elif growth_type == "Select sites":
        growth_sites = st.multiselect("Select sites", sorted(plan["Site"].unique()))

    if st.button("Run Simulation", key="growth_sim"):
        with st.spinner("Running simulation..."):
            # Determine which sites
            if growth_type == "All sites":
                sim_sites = plan["Site"].unique()
            elif growth_type == "By governorate":
                sim_sites = plan[plan["GOUV"].isin(growth_govs)]["Site"].unique()
            else:
                sim_sites = growth_sites

            scale = 1 + (growth_pct / 100)
            results = []

            for site in sim_sites:
                site_plan = plan[plan["Site"] == site]
                if len(site_plan) == 0:
                    continue
                site_plan = site_plan.iloc[0]

                site_risk = risk_cal[risk_cal["site"] == site]
                if len(site_risk) == 0:
                    continue
                site_risk = site_risk.iloc[0]

                site_live = live[live["unique_id"] == site].sort_values("ds")
                if len(site_live) == 0:
                    continue

                new_forecast = site_live["yhat"].values * scale
                p_cong, max_fc, mean_fc = run_simulation(new_forecast, residuals * scale)
                p_cal = calibrate(max_fc.mean(), cal_model)
                tier, _ = get_risk_tier(p_cal)

                results.append({
                    "Site": site,
                    "Type": site_risk["classification"],
                    "Gov": site_plan.get("GOUV", ""),
                    "Capacity": f"{site_plan['capacity_mbps']:.0f}",
                    "Before Risk": site_risk["p_calibrated"],
                    "After Risk": p_cal,
                    "New Tier": tier,
                })

            df_growth = pd.DataFrame(results).sort_values("After Risk", ascending=False)
            st.success(f"Simulation complete! {len(df_growth)} sites analyzed.")

            # Summary
            col1, col2, col3 = st.columns(3)
            newly_high = len(df_growth[(df_growth["After Risk"] > 0.5) & (df_growth["Before Risk"] <= 0.5)])
            col1.metric("Newly at risk", f"{newly_high}")
            col2.metric("Avg P(Congestion)", f"{df_growth['After Risk'].mean():.1%}")
            worst_site = df_growth.iloc[0]["Site"] if len(df_growth) > 0 else "N/A"
            col3.metric("Worst site", worst_site)

            # Show sites that will get problems
            st.subheader("🚨 Sites That Will Get Problems (P > 50%)")
            at_risk = df_growth[df_growth["After Risk"] > 0.5].copy()
            at_risk["Before Risk"] = at_risk["Before Risk"].apply(lambda x: f"{x:.1%}")
            at_risk["After Risk"] = at_risk["After Risk"].apply(lambda x: f"{x:.1%}")
            st.dataframe(at_risk, width="stretch", height=min(400, len(at_risk) * 35 + 40))

            # Top 20
            st.subheader("Top 20 Most Affected")
            top20 = df_growth.head(20)
            fig = px.bar(top20, x="Site", y="After Risk", color="Type",
                         color_discrete_map={"ONLY FDD": "#3498db", "TF": "#e74c3c",
                                             "NO_COTRANS": "#2ecc71", "COTRANS": "#f39c12"})
            fig.update_layout(yaxis_title="P(Congestion)", template="plotly_white",
                              height=350, xaxis_tickangle=45)
            st.plotly_chart(fig, width="stretch")


# ═══════════════════════════════════════════════════════════════════════
# TAB 4: Stress Test
# ═══════════════════════════════════════════════════════════════════════
with tabs[3]:
    st.header("⚡ Stress Test")

    st.markdown("Simulate traffic spikes from events (concerts, sports, festivals).")

    col1, col2 = st.columns([1, 1])
    with col1:
        stress_type = st.selectbox("Event type:", [
            "Football match (3x spike, 4h)",
            "Concert/Festival (5x spike, 6h)",
            "Ramadan evening (2x spike, 8h)",
            "Custom",
        ])
    with col2:
        if stress_type == "Custom":
            spike_mult = st.slider("Spike multiplier", 1.0, 10.0, 3.0, 0.5)
        elif "3x" in stress_type:
            spike_mult = 3.0
        elif "5x" in stress_type:
            spike_mult = 5.0
        else:
            spike_mult = 2.0

    stress_govs = st.multiselect(
        "Affected governorates",
        sorted(plan["GOUV"].dropna().unique()),
        default=["Tunis"],
    )

    if st.button("Run Stress Test", key="stress_sim"):
        with st.spinner("Simulating stress scenario..."):
            affected_sites = plan[plan["GOUV"].isin(stress_govs)]["Site"].unique()
            results = []

            for site in affected_sites:
                site_plan = plan[plan["Site"] == site]
                if len(site_plan) == 0:
                    continue
                site_plan = site_plan.iloc[0]

                site_risk = risk_cal[risk_cal["site"] == site]
                if len(site_risk) == 0:
                    continue
                site_risk = site_risk.iloc[0]

                site_live = live[live["unique_id"] == site].sort_values("ds")
                if len(site_live) == 0:
                    continue

                # Spike only affects first week
                new_forecast = site_live["yhat"].values.copy()
                new_forecast[:1] *= spike_mult

                p_cong, max_fc, mean_fc = run_simulation(new_forecast, residuals)
                p_cal = calibrate(max_fc.mean(), cal_model)
                peak = new_forecast.max()

                results.append({
                    "Site": site,
                    "Type": site_risk["classification"],
                    "Capacity": f"{site_plan['capacity_mbps']:.0f}",
                    "Normal Risk": f"{site_risk['p_calibrated']:.1%}",
                    "Stress Risk": f"{p_cal:.1%}",
                    "Peak Util": f"{peak:.1f}%",
                })

            df_stress = pd.DataFrame(results).sort_values("Stress Risk", ascending=False)
            st.success(f"Stress test complete! {len(df_stress)} sites affected.")

            # Show sites that will get problems
            st.subheader("🚨 Sites That Will Get Problems Under Stress")
            at_risk = df_stress[pd.to_numeric(df_stress["Stress Risk"].str.strip('%')) > 50]
            st.dataframe(at_risk, width="stretch", height=min(400, len(at_risk) * 35 + 40))


# ═══════════════════════════════════════════════════════════════════════
# TAB 5: Site Details
# ═══════════════════════════════════════════════════════════════════════
with tabs[4]:
    st.header("🔍 Site Details")

    site_list = sorted(risk_cal["site"].unique())
    selected_site = st.selectbox("Select a site", site_list)

    if selected_site:
        site_plan = plan[plan["Site"] == selected_site]
        site_risk = risk_cal[risk_cal["site"] == selected_site].iloc[0]
        site_live = live[live["unique_id"] == selected_site].sort_values("ds")

        col1, col2, col3, col4 = st.columns(4)
        col1.metric("Classification", site_risk["classification"])
        col2.metric("Capacity", f"{site_plan.iloc[0]['capacity_mbps']:.0f} Mbps" if len(site_plan) > 0 else "N/A")
        tier, color = get_risk_tier(site_risk["p_calibrated"])
        col3.metric("Risk Tier", tier)
        col4.metric("P(Congestion)", f"{site_risk['p_calibrated']:.1%}")

        if len(site_live) > 0:
            st.subheader("26-Week Forecast")
            fig = make_forecast_chart(
                site_live["ds"], site_live["yhat"],
                site_live["lo-80"], site_live["hi-80"],
                title=f"{selected_site} — Utilization Forecast"
            )
            st.plotly_chart(fig, width="stretch")

            st.subheader("Weekly Forecast")
            st.dataframe(site_live[["ds", "yhat", "lo-80", "hi-80", "lo-95", "hi-95"]].reset_index(drop=True),
                         width="stretch")


# ── Footer ─────────────────────────────────────────────────────────────
st.divider()
st.caption("Model: LightGBM v3 (MAE 3.17) | Calibration: Isotonic Regression | Risk: 91.6% accuracy at Very High tier")
