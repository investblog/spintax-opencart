#!/usr/bin/env bash
#
# Idempotent dev-stand provisioning for Spintax OpenCart testing.
#   - enables SEO URLs (.htaccess + config_seo_url)
#   - clones the en-gb language pack into a second `ru-ru` slot (dev fixture)
#   - duplicates catalog content into the new language (via dev-provision.php)
#
# Safe to re-run. Requires the Docker stack up (docker compose up -d).
#
set -euo pipefail
export MSYS_NO_PATHCONV=1   # keep in-container /var/www/... and /tmp/... paths intact under Git Bash

echo "== 1/3  SEO URLs: .htaccess =="
docker compose exec -T web sh -c \
  '[ -f /var/www/html/.htaccess ] || cp /var/www/html/.htaccess.txt /var/www/html/.htaccess; echo "  .htaccess ready"'

echo "== 2/3  Clone en-gb language pack -> ru-ru (admin + catalog) =="
docker compose exec -T web sh -c '
  for base in admin/language catalog/language; do
    d="/var/www/html/$base"
    if [ ! -d "$d/ru-ru" ]; then cp -r "$d/en-gb" "$d/ru-ru"; echo "  cloned $base/ru-ru"; else echo "  $base/ru-ru exists"; fi
  done'

echo "== 3/3  DB: SEO setting + language row + content duplication =="
docker compose cp scripts/dev-provision.php web:/tmp/dev-provision.php
docker compose exec -T web php /tmp/dev-provision.php

echo "== flush OpenCart cache =="
docker compose exec -T web sh -c 'rm -f /var/www/html/system/storage/cache/cache.* 2>/dev/null; echo "  cache cleared"'

echo "Done."
