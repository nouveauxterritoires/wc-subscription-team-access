#!/usr/bin/env bash
# Suite d'intégration de WC Subscription Team Access.
#
# À lancer sur un site de recette disposant de WooCommerce, WooCommerce Subscriptions et
# WooCommerce Memberships, avec l'extension activée. Aucun e-mail n'est envoyé (capture).
#
#   WP="wp --path=/chemin/vers/wordpress" bash tests/integration/run.sh
#
# Variables : WP (commande WP-CLI), KEEP=1 pour conserver les données de test.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP="${WP:-wp}"
FIXTURES="${FIXTURES:-$(mktemp -t wcsta-it-XXXXXX).json}"
STATUS=0

echo "== Préparation des fixtures"
$WP eval-file "$DIR/setup.php" "$FIXTURES" || exit 1

echo "== Scénarios"
$WP eval-file "$DIR/scenarios.php" "$FIXTURES" || STATUS=1

echo "== Invitations concurrentes (8 processus pour 1 place libre)"
PIDS=()
for n in 1 2 3 4 5 6 7 8; do
	$WP eval-file "$DIR/concurrency.php" "$FIXTURES" "$n" &
	PIDS+=($!)
done
for pid in "${PIDS[@]}"; do wait "$pid"; done
$WP eval-file "$DIR/concurrency-check.php" "$FIXTURES" || STATUS=1

if [ "${KEEP:-0}" != "1" ]; then
	echo "== Nettoyage"
	$WP eval-file "$DIR/cleanup.php" "$FIXTURES" || STATUS=1
else
	echo "Données conservées : $FIXTURES"
fi

exit $STATUS
