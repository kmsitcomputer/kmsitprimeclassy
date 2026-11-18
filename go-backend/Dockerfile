# PrimeClassy Go API (IMP-010)
#
# Multi-stage build: the toolchain stage never ships, and the runtime image carries only the static binary,
# CA roots and a non-root user. The service is a stateless HTTP process — the database is the only state.

FROM golang:1.27-alpine AS build
WORKDIR /src

# Dependency layer first so a source-only change does not re-download modules.
COPY go.mod go.sum ./
RUN go mod download

COPY . .
# -trimpath removes the build machine's paths; CGO is off so the binary is fully static and cannot be
# broken by a different libc on the target host.
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/api ./cmd/api \
 && CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/migrate ./cmd/migrate

FROM alpine:3.21
RUN apk add --no-cache ca-certificates tzdata \
 && addgroup -S app && adduser -S -G app app
WORKDIR /app
COPY --from=build /out/api /app/api
COPY --from=build /out/migrate /app/migrate
USER app
EXPOSE 8081

# Liveness only: /healthz reports "degraded" with 503 when the database is unreachable.
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD wget -qO- http://127.0.0.1:8081/healthz >/dev/null 2>&1 || exit 1

ENTRYPOINT ["/app/api"]
