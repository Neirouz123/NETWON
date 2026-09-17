# Telecom Network Congestion Simulator

Predicts which Tunisian cell sites will cross 100% utilization in 6 months.

## Quick Start

```bash
pip install -r requirements.txt
python scripts/01_clean_plan_data.py
python scripts/02_build_utilization_timeseries.py
python scripts/03_assemble_training_dataset.py
python scripts/05_train_model.py --skip-tune
python scripts/06_risk_assessment.py
python scripts/10_calibrate_risk.py

# Run the app
streamlit run app.py
```

## Model Performance

| Metric | Value |
|--------|-------|
| MAE | 3.17 (65% better than baseline) |
| Very High Risk Accuracy | 91.6% actually congested |
| Sites at Risk (P > 50%) | ~250 |

## Project Structure

```
trafic_model/
├── app.py                          # Streamlit simulator
├── scripts/
│   ├── 01_clean_plan_data.py       # Clean site metadata
│   ├── 02_build_utilization_timeseries.py  # Build time series
│   ├── 03_assemble_training_dataset.py     # Create features
│   ├── 04_baseline_forecasts.py    # Holt-Winters baseline
│   ├── 05_train_model.py           # LightGBM training
│   ├── 06_risk_assessment.py       # Monte Carlo risk
│   ├── 07_evaluate.py              # Model evaluation
│   ├── 08_inference.py             # CLI predictions
│   ├── 09_export.py                # Bundle export
│   ├── 10_calibrate_risk.py        # Isotonic calibration
│   └── 11_feature_importance.py    # Feature analysis
├── data/
│   ├── raw/                        # Original Excel files
│   ├── processed/                  # Cleaned CSVs
│   ├── model/                      # Trained models
│   └── export/                     # App-ready bundle
├── context.md                      # Original roadmap
├── FINAL_REPORT.md                 # Detailed results
└── requirements.txt
```

## App Features

1. **Current Risk** — See which sites are at risk right now
2. **Capacity Upgrade** — What-if: "What if I double capacity on site X?"
3. **Traffic Growth** — What-if: "What if traffic grows 20%?"
4. **Stress Test** — "What happens during a football match?"
5. **Site Details** — View any site's forecast

## Calibration

The risk layer uses isotonic regression to convert point forecasts into calibrated congestion probabilities:

| Risk Tier | Sites | Actually Congested |
|-----------|-------|-------------------|
| Very Low | 1,722 | 0.9% |
| Low | 43 | 18.6% |
| Medium | 42 | 45.2% |
| High | 48 | 64.6% |
| Very High | 215 | 91.6% |

## Key Files

- `data/export/model_bundle.joblib` — Self-contained bundle
- `data/processed/monte_carlo_risk_calibrated.csv` — Calibrated risk scores
- `data/processed/calibration.joblib` — Isotonic regression model
- `data/processed/feature_importance.csv` — Feature analysis
