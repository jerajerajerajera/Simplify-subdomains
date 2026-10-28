#!/usr/bin/env bash
# Runs every check that needs no panel: php -l, the unit tests, the translations and the Pelican Hub checks.
set -euo pipefail
cd "$(dirname "$0")/.."
find src tests resources lang database -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
php tests/units.php
php tests/lang.php
python3 tests/hub-check.py
