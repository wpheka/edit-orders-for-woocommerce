#!/usr/bin/env bash
# Every functional suite, with HPOS on and then off (spec section 9). Run from the plugin
# folder after `npm run env:start` and `npm run env:seed`. Restores HPOS to on at the end.
# One suite: bash tests/functional/run.sh p2-address-cod
set -u
export MSYS_NO_PATHCONV=1
WPENV="npx -y @wordpress/env@10"
DIR="wp-content/plugins/edit-orders-for-woocommerce/tests/functional"
SUITES="${1:-p1-engine p2-address-cod m1-admin m2-customer m3-settings m4-hardening}"
status=0

for hpos in enable disable; do
	$WPENV run cli wp wc hpos "$hpos" >/dev/null 2>&1
	for suite in $SUITES; do
		echo "=== $suite (HPOS ${hpos}d)"
		$WPENV run cli wp eval-file "$DIR/$suite.php" || status=1
	done
done

$WPENV run cli wp wc hpos enable >/dev/null 2>&1
exit $status
