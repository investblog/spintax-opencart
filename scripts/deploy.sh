#!/usr/bin/env bash
#
# Deploy the extension source into the running OpenCart docroot (dev).
# extension/upload/* -> opencart/upload/ (== /var/www/html in the web container).
# Both are mounted into the web container, so we copy in-container.
#
set -euo pipefail
export MSYS_NO_PATHCONV=1

echo "Deploying extension/upload -> docroot ..."
docker compose exec -T web sh -c 'cp -r /opt/spintax/upload/. /var/www/html/ && echo "  done"'
