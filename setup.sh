#!/bin/sh
# One-time setup. Needs Docker with Compose v2. See README, "Run it".
set -e
# Git Bash on Windows would rewrite container paths like /app/vendor; harmless elsewhere.
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")"

[ -f .env ] || cp .env.example .env

docker compose pull --quiet postgres redis
docker compose up -d postgres redis

# vendor/ is a named volume (fast on Docker Desktop); hand it to the container
# user, then install the locked dependencies into it.
docker compose run --rm --no-deps -u root app chown www-data:www-data /app/vendor
docker compose run --rm --no-deps app composer install --no-interaction

grep -q '^APP_KEY=base64' .env || docker compose run --rm --no-deps app php artisan key:generate

docker compose up -d
docker compose exec app php artisan migrate --seed --force

echo
echo "Ready: http://localhost:8000 (profiles) and http://localhost:8000/horizon"
echo "Put your Fans API key in .env (FANSAPI_KEY=...), then:"
echo "  docker compose exec app php artisan profiles:fetch madison420ivy"
