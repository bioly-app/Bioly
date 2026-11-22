#!/bin/sh
# Installs a Bioly zip into the site of this container the way a user does it by FTP:
# all program files are copied over the old ones, data/ is never touched.
# The next request to the site runs the rest (migrations, new page) through AfterUpdate.
set -e

zip="$1"
site="${SITE:-/var/www/html}"

[ -f "$zip" ] || { echo "No zip found: $zip (build one with: make zip)" >&2; exit 1; }

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
unzip -q "$zip" -d "$tmp"
[ -f "$tmp/bioly/VERSION" ] || { echo "$zip is not a Bioly zip" >&2; exit 1; }

before="$(cat "$site/VERSION" 2>/dev/null || echo none)"

# Everything except data/; only a missing data/.htaccess is added
tar -C "$tmp/bioly" --exclude=./data -cf - . | tar -C "$site" -xf -
mkdir -p "$site/data"
[ -f "$site/data/.htaccess" ] || cp "$tmp/bioly/data/.htaccess" "$site/data/.htaccess"
chown -R www-data:www-data "$site" 2>/dev/null || true

echo "Bioly $before -> $(cat "$site/VERSION")"
echo "Open http://localhost:8081: the first request finishes the update (or starts the installer on a new site)."
