# Telecom Network Congestion Forecasting

## 1. Purpose and scope

This project forecasts the utilization of Tunisian cellular sites 26 weeks into the future. Its planning question is:

> Which sites are likely to exceed 100% utilization during the next six months?

The system predicts a continuous utilization percentage first. It then converts the forecast and its uncertainty into a congestion probability and a risk tier. This preserves more information than training a binary classifier against the one-time operational `État` label.

The project uses aggregated weekly and daily throughput. It does not simulate users, radio propagation, handovers, queueing, or traffic spillover at a physical network level. Neighbor behavior is represented statistically through a geographic k-nearest-neighbor feature.

## 2. Repository layout

```text
data/
  raw/           Source Excel files
  processed/     Clean data, forecasts, risk tables, and evaluation reports
  model/         Trained models, parameters, and calibration model
  export/        App-ready model bundle and JSON metadata
scripts/         Numbered pipeline and analysis scripts
docs/            Project documentation
context.md       Original design discussion and decision history
FINAL_REPORT.md  Results, interpretation, and honest assessment
```

The numbered scripts are pipeline stages, not independent notebooks. Paths are resolved relative to the repository root through `scripts/`.

## 3. Source data contract

Place these files in `data/raw/`:

| File | Role | Important columns |
| --- | --- | --- |
| `Plan_Data.xlsx` | Site metadata, capacity, current state, and coordinates | `Site`, `Classification`, `Capacité TDD (Mbps)`, `Capacité FDD (Mbps)`, `État`, `LAT`, `LONG`, `GOUV`, `DEL` |
| `KPI_weekly.xlsx` | Long historical weekly traffic series | `Date`, `eNodeB Name`, `VS.FEGE.RxMaxSpeed_Mbs(Mbit/s)` |
| `KPI_daily.xlsx` | Recent daily traffic used for short-term signals | `Date`, `eNodeB Name`, `VS.FEGE.RxMaxSpeed_Mbs(Mbit/s)` |

Dates are parsed with `dayfirst=True`. KPI names may contain a cell suffix such as `MON_0040_LM`; the pipeline strips the final suffix and joins on `MON_0040`.

Plan numeric fields may use French formatting: commas are decimal separators, spaces are thousands separators, and percentages may include `%`. Step 1 normalizes these values.

## 4. Pipeline

Run the core stages in this order:

| Stage | Script | Reads | Writes | Purpose |
| ---: | --- | --- | --- | --- |
| 1 | `01_clean_plan_data.py` | `Plan_Data.xlsx` | `plan_data_clean.csv` | Parse numbers, select capacity, calculate utilization, remove invalid/outlier rows |
| 2 | `02_build_utilization_timeseries.py` | Clean plan + weekly/daily KPI | `kpi_utilization.csv` | Compute site-week utilization, spatial context, weekly history, and daily features |
| 3 | `03_assemble_training_dataset.py` | `kpi_utilization.csv` | `train_dataset.csv` | Rename to MLForecast schema and select model columns |
| 4 | `04_baseline_forecasts.py` | Training dataset | `baseline_metrics.csv` | Evaluate seasonal-naive and Holt-Winters baselines at 26 weeks |
| 5 | `05_train_model.py` | Training dataset | `best_params.joblib`, `model_final.joblib`, `backtest_predictions.csv`, `live_forecast.csv` | Tune LightGBM, backtest, fit final model, and create conformal intervals |
| 6 | `06_risk_assessment.py` | Live forecast + backtest + clean plan | `monte_carlo_risk.csv` | Bootstrap residuals and calculate congestion probability |
| 7 | `07_evaluate.py` | Backtest + risk + plan | `evaluation_report.txt`, `site_evaluation.csv` | Produce performance, residual, calibration, and error analyses |
| 8 | `08_inference.py` | Live forecast + calibrated risk | JSON or CSV | Serve site-level forecast records to an application |

Optional analysis stages:

| Script | Purpose | Output |
| --- | --- | --- |
| `09_export.py` | Package model and supporting data | `model_bundle.joblib`, `model_metadata.json` |
| `10_calibrate_risk.py` | Fit logistic/Platt calibration to raw risk scores | `monte_carlo_risk_calibrated.csv`, `calibration.joblib` |
| `11_feature_importance.py` | Extract LightGBM feature importance | `feature_importance.csv` |
| `12_train_tf_model.py` | Train a TF-only challenger model | `model_tf.joblib` |

Run stage 10 before stage 8 because the current inference script reads `monte_carlo_risk_calibrated.csv`.

## 5. Data processing

### Capacity and target

Step 1 chooses the capacity used for the target:

| Classification | Capacity column |
| --- | --- |
| `ONLY FDD`, `TF` | `Capacité FDD (Mbps)` |
| `COTRANS`, `NO_COTRANS` | `Capacité TDD (Mbps)` |

The continuous target is:

```text
utilization_pct = (traffic_max_or_rx_max_speed / capacity_mbps) * 100
```

Rows above 150% utilization are treated as unreliable capacity records or data glitches and removed during plan cleaning. Remaining time-series values above 150% are set to missing in step 2. Sites without positive capacity or valid traffic are excluded.

### Engineered features

`02_build_utilization_timeseries.py` creates:

- `neighbor_avg_utilization`: mean utilization of the five nearest sites by haversine distance, excluding the site itself.
- `wk_hist_mean`, `wk_hist_std`, `wk_hist_min`, `wk_hist_max`: expanding weekly statistics per site.
- `wk_hist_trend`: slope of the latest four weekly observations.
- `daily_util_mean_30d`, `daily_util_std_30d`, `daily_util_min_30d`, `daily_util_max_30d`: recent 30-day daily summaries.
- `daily_util_slope_30d`: linear slope across daily observations in that window.
- `daily_days_since_spike`: observations since the last daily value above 100%.

Static inputs include capacity, coordinates, classification, type, and governorate. `DEL` is excluded because its cardinality is high and `GOUV` is retained as the regional categorical.

## 6. Forecast model and validation

The main model is a global `MLForecast` wrapper around `LGBMRegressor`:

- Frequency: `W-MON`
- Horizon: 26 weeks
- Date features: month and week
- Candidate lags: `[1, 2, 4]`, `[1, 2, 4, 13]`, or `[1, 2, 4, 13, 26, 52]`
- Optional rolling mean transform with a 4, 8, 13, or 26 week window
- 50 Optuna trials unless `--skip-tune` is supplied
- Two rolling-origin backtest windows, each 26 weeks long
- 80% and 95% conformal prediction intervals from two calibration windows

The backtest residual is:

```text
residual = actual_utilization - predicted_utilization
```

MAE and RMSE are measured in utilization percentage points. MAPE is unstable for sites with near-zero utilization and should not be the primary decision metric.

## 7. Monte Carlo risk layer

Monte Carlo belongs after model backtesting and before reporting or application serving. It is an uncertainty layer, not a second forecasting model:

1. Read the 26-week point forecast from `live_forecast.csv`.
2. Calculate held-out residuals from `backtest_predictions.csv`.
3. Prefer each site's residual distribution when at least five residuals exist; otherwise use all backtest residuals.
4. For each site, sample 500 residual paths with replacement, one residual per forecast week.
5. Add each path to the point forecast and clip simulated values to 0-200%.
6. Estimate `p_congestion_any_week` as the fraction of paths that exceed 100% in at least one week.
7. Also report `p_congestion_end`, end-of-horizon percentiles, and a risk tier.

Risk tiers are based on `p_congestion_any_week`:

| Tier | Probability |
| --- | ---: |
| Very Low | < 10% |
| Low | 10-30% |
| Medium | 30-50% |
| High | 50-70% |
| Very High | > 70% |

This is a planning probability conditional on model assumptions and historical residuals. Independent weekly residual sampling does not model serial correlation or changing variance. Conformal intervals provide a separate uncertainty view and are not the same quantity as Monte Carlo probability.

## 8. Calibration and interpretation

`10_calibrate_risk.py` applies logistic, Platt-style calibration using raw Monte Carlo probability and mean point forecast. It saves `p_calibrated` and `risk_tier_calibrated`.

The `État` field is a current operational label used for comparison, false-negative analysis, and sanity checking. It is not a historical target. A site marked `CONGESTION` or `BRIDAGE` can receive low future risk when its utilization trend is declining: current state and future state are different questions.

Recommended operational use:

1. Rank sites by calibrated congestion probability.
2. Inspect point forecast and 80%/95% intervals together.
3. Compare classification and current `État` for context.
4. Use high-risk results to prioritize engineering review, not to trigger an automatic upgrade without validation.

## 9. Generated artifacts

| Path | Contents |
| --- | --- |
| `data/processed/plan_data_clean.csv` | Clean one-row-per-site plan data |
| `data/processed/kpi_utilization.csv` | Site-week utilization and engineered features |
| `data/processed/train_dataset.csv` | Final model input with `unique_id`, `ds`, and `y` |
| `data/processed/baseline_metrics.csv` | Baseline MAE, RMSE, and MAPE |
| `data/processed/backtest_predictions.csv` | Held-out actuals, predictions, and fold IDs |
| `data/processed/live_forecast.csv` | 26-week point forecasts and conformal interval columns |
| `data/processed/monte_carlo_risk.csv` | Raw site-level risk and percentiles |
| `data/processed/monte_carlo_risk_calibrated.csv` | Calibrated risk scores used by inference |
| `data/processed/evaluation_report.txt` | Six-part evaluation report |
| `data/processed/site_evaluation.csv` | Per-site error and bias summary |
| `data/model/model_final.joblib` | Full fitted MLForecast model |
| `data/model/calibration.joblib` | Scaler and logistic calibration model |
| `data/export/model_bundle.joblib` | Intended self-contained deployment bundle |
| `data/export/model_metadata.json` | Human-readable model configuration and metrics |

## 10. Inference contract

```bash
python scripts/08_inference.py \
  --sites ARI_0003 TUN_0010 \
  --output json \
  --output-file predictions.json
```

Each output record contains the site, classification, forecast date, point forecast, 80% and 95% interval bounds, calibrated congestion probability, and calibrated risk tier. Forecast values are utilization percentages, not Mbps.

The script currently reads precomputed forecasts and risk tables. It does not recompute features or forecast from an incoming request. A production service would need a refresh job, input validation, model/version metadata, monitoring, and an explicit policy for missing or stale KPI data.

## 11. Reproducibility and known issues

- Run from the repository root so data paths resolve correctly.
- Preserve the exact source Excel extracts used for every model release.
- Pin package versions for byte-level reproducibility; the current requirements file specifies packages but not versions.
- `07_evaluate.py` and `09_export.py` contain legacy filename references. They expect `lgbm_backtest_predictions.csv`, `lgbm_backtest_predictions_v2.csv`, `comparison_metrics_v2.csv`, and `mlforecast_model_conformal.joblib`, while current training writes `backtest_predictions.csv` and `model_final.joblib`. Align these paths before using stages 7 or 9 as an automated release pipeline.
- `12_train_tf_model.py` is a TF challenger experiment. It does not replace the main model or merge its forecasts into inference.

## 12. Current results and limitations

The current report records MAE 3.17 for the tuned LightGBM model, compared with 9.11 for Holt-Winters, across two rolling-origin folds. It also reports materially worse performance for TF sites and underprediction at high utilization. Risk tiers are not yet perfectly calibrated, and the project has only about 100 weekly observations per site.

See [FINAL_REPORT.md](../FINAL_REPORT.md) for the complete result tables, false-negative examples, and production-readiness assessment. The next valuable improvements are more history, stronger temporal validation, specialized TF modeling, calibration assessed on untouched data, and drift monitoring.
