#!/usr/bin/env bash
# Every functional suite, with HPOS on and then off (spec section 9). Run from the plugin
# folder after `npm run env:start` and `npm run env:seed`. Restores HPOS to on at the end.
# One suite: bash tests/functional/run.sh p2-address-cod
# The wp-env tests site instead (for example set to the minimum WordPress and WooCommerce
# in .wp-env.override.json): EO_CONTAINER=tests-cli bash tests/functional/run.sh
set -u
export MSYS_NO_PATHCONV=1
WPENV="npx -y @wordpress/env@11"
CONTAINER="${EO_CONTAINER:-cli}"
DIR="wp-content/plugins/edit-orders-for-woocommerce/tests/functional"
SUITES="${1:-p1-engine p2-address-cod m1-admin m2-customer m3-settings m4-hardening m5-matrix}"
status=0

for hpos in enable disable; do
	$WPENV run "$CONTAINER" wp wc hpos "$hpos" >/dev/null 2>&1
	for suite in $SUITES; do
		echo "=== $suite (HPOS ${hpos}d)"
		$WPENV run "$CONTAINER" wp eval-file "$DIR/$suite.php" || status=1
	done
done

$WPENV run "$CONTAINER" wp wc hpos enable >/dev/null 2>&1
exit $status
