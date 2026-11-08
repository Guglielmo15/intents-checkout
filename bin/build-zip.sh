#!/bin/sh
# Builds dist/intents-checkout.zip for upload in WordPress > Plugins > Add New > Upload Plugin.
set -eu
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/intents-checkout
cp -R plugin/. dist/intents-checkout/
cp LICENSE dist/intents-checkout/LICENSE
(cd dist && zip -qr intents-checkout.zip intents-checkout)
rm -rf dist/intents-checkout
echo "dist/intents-checkout.zip"
