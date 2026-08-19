#!/bin/bash
# Install smtp_choice into cPanel Roundcube. Run as root.
# curl -fsSL https://raw.githubusercontent.com/darkxiider/roundcube-smtp-choice/main/install.sh | bash

set -euo pipefail

REPO_SLUG="darkxiider/roundcube-smtp-choice"
BRANCH="main"
RC="/usr/local/cpanel/base/3rdparty/roundcube"
DEST="$RC/plugins/smtp_choice"
ENABLE="$RC/config/inc.d/990-smtp_choice.inc.php"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this as root."
  exit 1
fi

if [[ ! -d "$RC/plugins" ]]; then
  echo "cPanel Roundcube not found at $RC"
  exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "curl is required."
  exit 1
fi
if ! command -v tar >/dev/null 2>&1; then
  echo "tar is required."
  exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "Downloading $REPO_SLUG ..."
curl -fsSL "https://codeload.github.com/${REPO_SLUG}/tar.gz/refs/heads/${BRANCH}" -o "$TMP/src.tar.gz"
mkdir -p "$TMP/src"
tar -xzf "$TMP/src.tar.gz" -C "$TMP/src" --strip-components=1

if [[ ! -f "$TMP/src/smtp_choice.php" ]]; then
  echo "Download did not contain smtp_choice.php"
  exit 1
fi

echo "Installing plugin ..."
rm -rf "$DEST"
mkdir -p "$DEST/localization" "$DEST/skins/elastic" "$RC/config/inc.d"

cp -a "$TMP/src/smtp_choice.php" "$DEST/"
cp -a "$TMP/src/smtp_choice.js" "$DEST/"
cp -a "$TMP/src/composer.json" "$DEST/"
cp -a "$TMP/src/config.inc.php.dist" "$DEST/"
cp -a "$TMP/src/localization/." "$DEST/localization/"
cp -a "$TMP/src/skins/." "$DEST/skins/"

printf '%s\n' '<?php' "array_push(\$config['plugins'], 'smtp_choice');" > "$ENABLE"

chown -R root:root "$DEST" "$ENABLE"
find "$DEST" -type d -exec chmod 755 {} \;
find "$DEST" -type f -exec chmod 644 {} \;
chmod 644 "$ENABLE"

php -l "$DEST/smtp_choice.php" >/dev/null

echo
echo "Installed. Every Roundcube mailbox gets Settings -> SMTP sending."
echo "Saved SMTP applies only to the mailbox that saved it."
