FROM debian:trixie-slim AS libxml2-builder
WORKDIR /build
COPY infra/toolchain/libxml2/ ./
RUN sh build-package.sh

FROM debian:trixie-slim AS krb5-builder
WORKDIR /build
COPY infra/toolchain/krb5/prepare-source.sh ./
RUN sh prepare-source.sh
COPY infra/toolchain/krb5/build-package.sh infra/toolchain/krb5/*.patch ./
RUN sh build-package.sh

FROM golang:1.27.3-alpine3.24 AS gosu-builder

RUN apk add --no-cache git
WORKDIR /source
RUN git init \
    && git remote add origin https://github.com/tianon/gosu.git \
    && git fetch --depth 1 origin 29b939aa5bd4dc588af765b8248f6e39150d2565 \
    && git checkout --detach FETCH_HEAD
COPY infra/toolchain/gosu/go.mod infra/toolchain/gosu/go.sum ./
RUN go mod download \
    && go mod verify \
    && CGO_ENABLED=0 go build -mod=readonly -trimpath -o /out/gosu . \
    && go install golang.org/x/vuln/cmd/govulncheck@v1.9.0 \
    && govulncheck -mode=binary /out/gosu

FROM postgres:18.6-trixie AS patched-runtime

# Keep the PostgreSQL distribution and data layout; replace only its helper.
RUN --mount=type=bind,from=libxml2-builder,source=/out,target=/packages \
    --mount=type=bind,from=krb5-builder,source=/out,target=/kerberos \
    set -eu; \
    apt-get update; \
    apt-get upgrade -y --no-install-recommends; \
    apt-get install -y --no-install-recommends /packages/libxml2.deb /kerberos/*.deb; \
    rm -rf /var/lib/apt/lists/*; \
    rm -f /usr/local/bin/gosu
COPY --from=gosu-builder --chmod=755 /out/gosu /usr/local/bin/gosu
ENV GOSU_VERSION=1.20
RUN gosu --version \
    && gosu nobody true \
    && gosu postgres id -u | grep -q '^999$'

FROM patched-runtime AS production
COPY --chmod=755 infra/production/postgres-init.sh /docker-entrypoint-initdb.d/10-application-role.sh

FROM patched-runtime AS development
