#!/bin/sh
set -eu

role="${1:-serve}"

wait_for_db() {
  echo "[nextsearch] waiting for the database ..."
  i=0
  until php artisan db:monitor --max=1 >/dev/null 2>&1 || [ "$i" -ge 60 ]; do
    i=$((i + 1))
    sleep 2
  done
  if [ "$i" -ge 60 ]; then
    echo "[nextsearch] database not reachable — aborting." >&2
    exit 1
  fi
}

# Nur der `serve`-Container migriert und bootstrapped. Worker und Scheduler
# warten darauf, dass er gesund meldet, und finden das Schema dann vor.
case "$role" in
  serve)
    wait_for_db
    php artisan migrate --force --no-interaction
    php artisan nextsearch:bootstrap
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
    echo "[nextsearch] starting FrankenPHP on ${SERVER_NAME}"
    exec frankenphp run --config /etc/frankenphp/Caddyfile
    ;;
  worker)
    wait_for_db
    # Order is priority: crawl and the light `default` jobs (e.g. folder
    # relabels) run ahead of the heavy `process` extraction backlog, so quick
    # admin actions stay prompt even during a large index.
    exec php artisan queue:work redis \
      --queue=crawl,default,process \
      --tries=3 \
      --backoff=30,120,600 \
      --max-time=3600 \
      --max-jobs=500 \
      --sleep=2
    ;;
  scheduler)
    wait_for_db
    exec php artisan schedule:work
    ;;
  *)
    exec "$@"
    ;;
esac
