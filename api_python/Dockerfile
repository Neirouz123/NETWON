FROM python:3.12-slim-bookworm

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

# System dependencies. libgomp1 provides libgomp.so.1 which is required at
# runtime by the LightGBM wheel (loaded via ctypes). libpq5 is the runtime
# dependency for psycopg2.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libgomp1 \
        libpq5 \
    && rm -rf /var/lib/apt/lists/* \
    && ldconfig

# Create a non-root user before installing packages so we benefit from
# Docker layer caching on the (expensive) pip step.
RUN addgroup --system app \
    && adduser --system --ingroup app app

# Install Python dependencies first (cached independently of source changes).
COPY api_python/requirements.production.txt ./requirements.txt
RUN pip install --no-cache-dir -r requirements.txt

COPY --chown=app:app api_python/ ./

USER app

EXPOSE 8001
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8001", "--proxy-headers"]
