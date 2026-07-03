#!/usr/bin/env bash
#
# Run the Spintax engine PHPUnit harness inside the web container.
# Any args are forwarded to phpunit, e.g.:
#   scripts/test.sh --filter Parser
#   scripts/test.sh --testdox
#
set -euo pipefail
export MSYS_NO_PATHCONV=1   # Git Bash on Windows would otherwise mangle /opt/... paths

if [ ! -d extension/vendor ]; then
    echo "vendor/ missing — installing dev dependencies..." >&2
    docker run --rm -v "$(pwd)/extension:/app" -w /app composer:2 install --no-interaction --no-progress
fi

exec docker compose exec -T web php /opt/spintax/vendor/bin/phpunit -c /opt/spintax/phpunit.xml.dist "$@"
