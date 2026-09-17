FROM python:3.11-slim-bookworm

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

# libgomp1 is required at runtime by the LightGBM wheel (loaded via ctypes),
# same as the api-python service in this repo.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libgomp1 \
    && rm -rf /var/lib/apt/lists/*

RUN addgroup --system app \
    && adduser --system --ingroup app app

COPY net_forecasting/requirements.txt ./requirements.txt
RUN pip install --no-cache-dir -r requirements.txt

COPY --chown=app:app net_forecasting/ ./

# data/ is intentionally NOT baked into the image: raw datasets and trained
# model artifacts are proprietary and are provided at runtime via the
# `net_forecasting_data` volume mounted in compose.yaml. Run the pipeline
# scripts (01..10, see README.md) once inside the container, or mount a
# pre-populated data/ directory, before the app has anything to show.
RUN chown -R app:app /app

USER app

EXPOSE 8501

HEALTHCHECK --interval=30s --timeout=5s --retries=5 --start-period=30s \
    CMD python -c "from urllib.request import urlopen; urlopen('http://127.0.0.1:8501/_stcore/health', timeout=3)"

CMD ["streamlit", "run", "app.py", "--server.port=8501", "--server.address=0.0.0.0", "--server.headless=true"]