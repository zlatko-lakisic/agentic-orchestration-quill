# AO Quill worker. Small, CPU only, no volumes. Build from the repository root:
#   docker buildx build --platform linux/amd64,linux/arm64 -f deploy/Dockerfile -t REGISTRY/ao-quill-worker:0.1.0 --push .

FROM python:3.12-slim AS build
RUN apt-get update && apt-get install -y --no-install-recommends git && rm -rf /var/lib/apt/lists/*
WORKDIR /src
COPY worker/ worker/
RUN pip wheel --no-cache-dir --wheel-dir /wheels ./worker

FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    AOQ_OVERLAY_DIR=/app/overlays
COPY --from=build /wheels /wheels
# Every dependency was built into /wheels, so no resolution (and no git) is needed here.
RUN pip install --no-cache-dir --no-index --no-deps /wheels/*.whl && rm -rf /wheels \
 && useradd --system --uid 10001 --home-dir /app aoq
COPY overlays/ /app/overlays/
USER 10001
WORKDIR /app
ENTRYPOINT ["aoq-worker"]
CMD ["run"]
