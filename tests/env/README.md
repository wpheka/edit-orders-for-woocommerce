# Development environment (wp-env)

A Docker-based WordPress store for building and testing Edit Orders for WooCommerce. Not shipped: `tests/` is excluded from the release build.

## What you get

| Item | Value |
|---|---|
| Store | http://localhost:8890 (admin: `admin` / `password`) |
| Test site | http://localhost:8891, a separate site for automated tests |
| WordPress | Latest release |
| PHP | 8.2 |
| Theme | Storefront (latest) |
| Plugins | WooCommerce (the synced `../woocommerce` folder, so the same version as the main dev site), WooCommerce Stripe Gateway, Query Monitor, Plugin Check, and this plugin |
| HPOS | On. Switch off with `npm run wp -- wc hpos disable` to test the legacy order tables |

The seed script (`tests/env/seed.php`) sets up the store for the spec's functional cases:
- Toronto, CAD, taxes on and based on the shipping address
- tax rates: Ontario 13% HST, Alberta 5% GST
- shipping zones: Ontario flat rate $10, rest of Canada flat rate $20
- Cash on delivery and Direct bank transfer enabled (neither refunds automatically); Stripe installed but off until test keys are added
- a dev-only **test gateway** with refunds (`tests/env/mu-plugins/`, mounted as mu-plugins). Set the option `edit_orders_test_gateway_fail_refunds` to `yes` to make its refunds fail
- products: T-Shirt $20, Mug $12, Poster $8, Hoodie XS $35 / S $40 / M $40 / L $45, all stock-managed
- coupon `save20` (20% off)
- customer `customer` / `password`, with an Ontario address

British Columbia has no tax rate on purpose: it is in the $20 zone with Alberta, which gives a "same shipping, less tax" address change for the tests.

The functional suites run with `bash tests/functional/run.sh` (all suites, HPOS on, then off), or one suite with `bash tests/functional/run.sh p2-address-cod`.

Email is captured, not sent: a dev-only mu-plugin stores every message in the option `edit_orders_mail_log`, which the suites read.

The browser test (`tests/e2e/editor.cjs`) drives the installed Chrome with Playwright. Install the `playwright` package somewhere outside the synced plugins folder (for example `npm install playwright` in a scratch folder, with `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1`), then run `NODE_PATH=/that/folder/node_modules node tests/e2e/editor.cjs [screenshot-folder]` for the store-owner editor and `... node tests/e2e/customer.cjs [screenshot-folder]` for the customer panel (it switches to Twenty Twenty-Five for the block theme check and back to Storefront).

## Requirements

- Docker Desktop, running
- Node.js 18 or later (wp-env runs through `npx`, so nothing is installed in this folder)

## Commands

Run from this plugin's folder:

| Command | What it does |
|---|---|
| `npm run env:start` | Start the store (the first run downloads everything, about 3 minutes) |
| `npm run env:seed` | Set up the store. Safe to run again |
| `npm run env:stop` | Stop the containers; data is kept |
| `npm run env:clean` | Empty the database, then run `env:seed` again |
| `npm run env:destroy` | Remove the containers and data completely |
| `npm run wp -- <command>` | Run a WP-CLI command, for example `npm run wp -- plugin list` |

## Release zip, Plugin Check and screenshots

| Command | What it does |
|---|---|
| `php bin/build-zip.php` | Builds `build/edit-orders-for-woocommerce-<version>.zip`, leaving out everything in `.distignore` |
| `npm run wp -- plugin check /tmp/eofw/edit-orders-for-woocommerce` | Plugin Check on the built copy. Extract the zip to `/tmp/eofw` in the cli container first; checking this folder flags the dev-only hidden files |
| `NODE_PATH=/path/to/node_modules node tests/e2e/screenshots.cjs` | Recreates the six wordpress.org screenshots in `.wordpress-org/` (store must be running; the orders it makes are deleted). Look at every image before uploading |
| `npx -y @wordpress/env@10 run cli wp i18n make-pot wp-content/plugins/edit-orders-for-woocommerce wp-content/plugins/edit-orders-for-woocommerce/languages/edit-orders-for-woocommerce.pot --exclude=tests,bin,build` | Regenerates the translation template |

## Known issues on Windows

- **Git Bash rewrites paths.** An argument such as `/%postname%/` becomes `C:/Program Files/Git/%postname%/`. Prefix the command with `MSYS_NO_PATHCONV=1`, or use PowerShell.
- **Query Monitor's `db.php` symlink** breaks `wp-env start --update` on Windows (`EACCES ... db.php`). `QM_DB_SYMLINK` is set to false in `.wp-env.json` to stop it being created.
- **Email is not delivered.** The containers have no mail server; the mail-log mu-plugin captures every message instead (see above).
