#!/usr/bin/env bash
# Forced-command entry point for CI: fast-forward to origin/main, build, back up, migrate, start, health-check, roll back on failure.
set -Eeuo pipefail
umask 077
root=$(cd "$(dirname "$0")/.." && pwd)
mkdir -p "$root/.local/releases" "$root/.local/backups"
exec 9>"$root/.local/deploy.lock"
flock 9
cd "$root"
test -f .env
test -z "$(git status --porcelain)"
git fetch --quiet origin main
release=$(git rev-parse origin/main)
dir="$root/.local/releases/$release"
mkdir -p "$dir"
git archive "$release" | tar -x -C "$dir"
install -m 600 "$root/.env" "$dir/.env"
export RELEASE_TAG="$release"
compose() { docker compose -p sorthouse --project-directory "$dir" --env-file "$root/.env" -f "$dir/compose.yaml" -f "$dir/compose.vps.yaml" --profile app "$@"; }
previous=$(cat "$root/.local/current-release" 2>/dev/null || true)
rollback() {
  status=$?; trap - ERR
  if [[ "$previous" =~ ^[0-9a-f]{40}$ ]] && [[ -d "$root/.local/releases/$previous" ]]; then
    RELEASE_TAG="$previous" docker compose -p sorthouse --project-directory "$root/.local/releases/$previous" --env-file "$root/.env" -f "$root/.local/releases/$previous/compose.yaml" -f "$root/.local/releases/$previous/compose.vps.yaml" --profile app up -d --no-build app worker scheduler web || true
    echo 'Restored previous application images; migrations were not reversed' >&2
  else
    compose stop app worker scheduler web || true
  fi
  exit "$status"
}
trap rollback ERR
compose config --quiet
compose build --quiet app web
compose up -d --wait --wait-timeout 120 db redis
backup="$root/.local/backups/predeploy-$(date -u +%Y%m%dT%H%M%SZ).dump"
compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$backup"
ls -1t "$root"/.local/backups/predeploy-*.dump | tail -n +8 | xargs -r rm -f
compose --profile maintenance run --rm --no-deps migrate
compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -v runtime_password="$DB_RUNTIME_PASSWORD"' <<'SQL'
SELECT format('CREATE ROLE sorthouse_app LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION', :'runtime_password') WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname='sorthouse_app') \gexec
SELECT format('ALTER ROLE sorthouse_app PASSWORD %L', :'runtime_password') \gexec
GRANT USAGE ON SCHEMA public TO sorthouse_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO sorthouse_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO sorthouse_app;
SQL
compose up -d --wait app worker scheduler web
compose exec -T app php artisan horizon:status || true
# measured evaluation numbers for this deployment (idempotent: once per policy version and provider)
compose exec -T -e QUEUE_CONNECTION=sync app php artisan eval:ensure
curl -fsS "http://127.0.0.1:$(sed -n 's/^WEB_PORT=//p' "$root/.env")/api/health" >/dev/null
printf '%s\n' "$release" > "$root/.local/current-release"
git merge --ff-only "$release" --quiet
trap - ERR
printf 'Deployed %s\n' "$release"
