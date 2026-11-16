FROM node:24.22.2-trixie-slim AS frontend-build
WORKDIR /app
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0
COPY frontend/package.json frontend/pnpm-lock.yaml frontend/pnpm-workspace.yaml ./
RUN corepack pnpm install --frozen-lockfile
COPY frontend/ ./
ENV VITE_API_BASE_URL=/api/v1
RUN corepack pnpm run build

FROM nginx:1.30.6-alpine3.24-slim
RUN apk upgrade --no-cache \
    && rm /etc/nginx/conf.d/default.conf
COPY infra/production/nginx.conf /etc/nginx/nginx.conf
COPY infra/production/site.conf.template /etc/nginx/templates/site.conf.template
COPY --from=frontend-build /app/dist /usr/share/nginx/html
USER nginx
