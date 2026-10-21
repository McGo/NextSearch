#!/bin/sh
set -eu

role="${1:-serve}"

# php-fpm serves requests as www-data, so every artisan call runs as www-data
# too. Otherwise a log file or cache entry created by root (migrate, worker)
# would be unwritable for the web requests sharing the same storage volume.
artisan() {
  su-exec www-data php artisan "$@"
}

# Volumes created by images up to 0.8.0 belong to root (FrankenPHP ran as
# root). Hand them over once; skipped when the top level already belongs to
# www-data, so a large storage volume is not walked on every start.
own_storage() {
  for dir in storage bootstrap/cache; do
    if [ "$(stat -c %U "$dir")" != www-data ]; then
      echo "[nextsearch] handing $dir over to www-data ..."
      chown -R www-data:www-data "$dir"
    fi
  done
}

wait_for_db() {
  echo "[nextsearch] waiting for the database ..."
  i=0
  until artisan db:monitor --max=1 >/dev/null 2>&1 || [ "$i" -ge 60 ]; do
    i=$((i + 1))
    sleep 2
  done
  if [ "$i" -ge 60 ]; then
    echo "[nextsearch] database not reachable — aborting." >&2
    exit 1
  fi
}

boot() {
  artisan migrate --force --no-interaction
  artisan nextsearch:bootstrap
  artisan config:cache
  artisan route:cache
  artisan event:cache
}

# Nur der `serve`-Container migriert und bootstrapped. Worker und Scheduler
# warten darauf, dass er gesund meldet, und finden das Schema dann vor.
case "$role" in
  serve)
    own_storage
    wait_for_db
    boot
    echo "[nextsearch] starting php-fpm + nginx on port ${PORT}"
    exec start-web
    ;;
  worker)
    own_storage
    wait_for_db
    # Order is priority: crawl and the light `default` jobs (e.g. folder
    # relabels) run ahead of the heavy `process` extraction backlog, so quick
    # admin actions stay prompt even during a large index.
    exec su-exec www-data php artisan queue:work redis \
      --queue=crawl,default,process \
      --tries=3 \
      --backoff=30,120,600 \
      --max-time=3600 \
      --max-jobs=500 \
      --sleep=2
    ;;
  scheduler)
    own_storage
    wait_for_db
    exec su-exec www-data php artisan schedule:work
    ;;
  all)
    # Single-container mode for small installs (e.g. Unraid): php-fpm, nginx,
    # the queue worker and the scheduler run side by side under supervisord.
    # The boot steps run once here; supervisord then launches the `worker` and
    # `scheduler` roles, so their commands stay defined in exactly one place.
    own_storage
    wait_for_db
    boot
    render-nginx
    echo "[nextsearch] starting all-in-one (php-fpm + nginx + worker + scheduler)"
    exec supervisord -c /etc/supervisor/nextsearch.conf
    ;;
  *)
    exec "$@"
    ;;
esac
