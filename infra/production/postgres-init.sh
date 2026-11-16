#!/bin/sh
set -eu
: "${APP_DB_USER:?Set the application database user}"
: "${APP_DB_PASSWORD:?Set the application database password}"
# psql quoting separates SQL identifiers and secrets from executable SQL.
psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --set=ON_ERROR_STOP=1 \
    --set=app_user="$APP_DB_USER" --set=app_password="$APP_DB_PASSWORD" --set=app_database="$POSTGRES_DB" <<'SQL'
CREATE ROLE :"app_user" LOGIN PASSWORD :'app_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
ALTER DATABASE :"app_database" OWNER TO :"app_user";
SQL
