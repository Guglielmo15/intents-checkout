#!/bin/sh
# Installs and configures the demo store. Safe to run more than once.
set -eu

STORE_URL="${STORE_URL:-http://localhost:8080}"
INTENTS_MODE="${INTENTS_MODE:-sandbox}"
STORE_CURRENCY="${STORE_CURRENCY:-EUR}"
WC_VERSION="10.6.2"

# Sandbox quotes are still real 1Click dry-run quotes, and 1Click rejects a recipient account that
# does not exist on NEAR mainnet, so the demo uses the author's account; in sandbox mode no funds move.
if [ -z "${MERCHANT_ACCOUNT:-}" ]; then
  if [ "$INTENTS_MODE" = "live" ]; then
    echo "MERCHANT_ACCOUNT must be set in .env for live mode" >&2
    exit 1
  fi
  MERCHANT_ACCOUNT="netrunner15.near"
fi
RECIPIENT_TYPE="${RECIPIENT_TYPE:-INTENTS}"

echo "Waiting for WordPress files and database..."
until [ -f /var/www/html/wp-config.php ] && wp db check >/dev/null 2>&1; do sleep 3; done

if ! wp core is-installed >/dev/null 2>&1; then
  wp core install --url="$STORE_URL" --title="Intents Checkout Demo Store" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
fi
wp option update home "$STORE_URL" >/dev/null
wp option update siteurl "$STORE_URL" >/dev/null

if ! wp plugin is-installed woocommerce; then
  wp plugin install woocommerce --version="$WC_VERSION"
fi
wp plugin activate woocommerce intents-checkout
wp rewrite structure '/%postname%/' >/dev/null   # The WordPress image already ships the .htaccess rules.

# Demo only: the containers have no mail server, so skip sending e-mails instead of printing sendmail errors.
mkdir -p /var/www/html/wp-content/mu-plugins
cat > /var/www/html/wp-content/mu-plugins/demo-no-mail.php <<'PHP'
<?php
// Docker demo only (created by docker/setup.sh): there is no mail server, so e-mails are skipped.
add_filter( 'pre_wp_mail', '__return_true' );
PHP

# Store basics: no "coming soon" page, no onboarding wizard, currency, hold stock longer than a quote lives.
wp option update woocommerce_coming_soon no >/dev/null
wp option update woocommerce_store_pages_only no >/dev/null
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null
wp option update woocommerce_currency "$STORE_CURRENCY" >/dev/null
wp option update woocommerce_default_country "NL" >/dev/null
wp option update woocommerce_calc_taxes no >/dev/null
wp option update woocommerce_hold_stock_minutes 90 >/dev/null
wp wc tool run install_pages --user=admin >/dev/null

if [ "$(wp wc product list --user=admin --format=count)" = "0" ]; then
  wp wc product create --user=admin --name="NEAR Hackathon Sticker" --type=simple --virtual=true \
    --regular_price=1.00 --description="A sticker that proves you paid with any crypto from any chain." >/dev/null
fi

wp option update woocommerce_intents_checkout_settings "{
  \"enabled\": \"yes\",
  \"mode\": \"$INTENTS_MODE\",
  \"title\": \"Pay with any crypto\",
  \"description\": \"Pay from 30+ blockchains (Ethereum, Base, Solana, Bitcoin, Tron, NEAR, ...). Settled through NEAR Intents.\",
  \"merchant_account\": \"$MERCHANT_ACCOUNT\",
  \"destination_asset\": \"nep141:17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1\",
  \"recipient_type\": \"$RECIPIENT_TYPE\",
  \"quote_minutes\": \"30\",
  \"slippage_bps\": \"100\",
  \"visibility\": \"everyone\",
  \"debug\": \"yes\"
}" --format=json >/dev/null

echo
wp intents-checkout check
echo
echo "setup done -> $STORE_URL/shop  (admin: $STORE_URL/wp-admin, user admin / admin)"
