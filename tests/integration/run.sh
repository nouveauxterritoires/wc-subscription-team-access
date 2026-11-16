#!/usr/bin/env bash
# Integration suite for WC Subscription Team Access.
#
# Run on a staging site with WooCommerce, WooCommerce Subscriptions and
# WooCommerce Memberships, with the plugin active. No email is sent (captured).
#
#   WP="wp --path=/path/to/wordpress" bash tests/integration/run.sh
#
# Variables: WP (WP-CLI command), KEEP=1 to keep the test data.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP="${WP:-wp}"
FIXTURES="${FIXTURES:-$(mktemp -t wcsta-it-XXXXXX).json}"
STATUS=0

echo "== Preparing fixtures"
$WP eval-file "$DIR/setup.php" "$FIXTURES" || exit 1

echo "== Scenarios"
$WP eval-file "$DIR/scenarios.php" "$FIXTURES" || STATUS=1

echo "== Concurrent invitations (8 processes for 1 free seat)"
PIDS=()
for n in 1 2 3 4 5 6 7 8; do
	$WP eval-file "$DIR/concurrency.php" "$FIXTURES" "$n" &
	PIDS+=($!)
done
for pid in "${PIDS[@]}"; do wait "$pid"; done
$WP eval-file "$DIR/concurrency-check.php" "$FIXTURES" || STATUS=1

if [ "${KEEP:-0}" != "1" ]; then
	echo "== Cleanup"
	$WP eval-file "$DIR/cleanup.php" "$FIXTURES" || STATUS=1
else
	echo "Data kept: $FIXTURES"
fi

exit $STATUS
