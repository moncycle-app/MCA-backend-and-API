#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Renders every mail of lib/mail.php to tests/mail_preview/out/ (nothing is sent, no database is touched) and checks the language file.
#
#   tests/mail_preview/run.sh        then open tests/mail_preview/out/index.html: each mail in light, dark and phone width
#
# Runs in the dev container (macbook_moncycle_app, see CLAUDE.md), whose www_data holds the code. out/ is not committed.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
C=macbook_moncycle_app
OUT="$HERE/out"

docker ps >/dev/null 2>&1 || { echo "Docker is not running" >&2; exit 3; }

# The language file and the code agree: every key a mail asks for is defined, every key defined is asked for. A key with {$kind} in
# it stands for the four mails of a link; a {@key} in fr.php counts as asking for it.
kinds="verify_email account_exists reset_password confirm_email"
used=$( { grep -oE 'mail_t\("[^"]+"' "$ROOT/www_data/lib/mail.php" | sed -E 's/mail_t\("//; s/"$//' | grep -v '{\$kind}'
  for k in $kinds; do grep -oE 'mail_t\("\{\$kind\}\.[a-z_]+"' "$ROOT/www_data/lib/mail.php" | sed -E "s/mail_t\\(\"\\{\\\$kind\\}/$k/; s/\"\$//"; done; } | sort -u)
defined=$(grep -oE '^[[:space:]]*"[a-z_.]+" =>' "$ROOT/www_data/mail/lang/fr.php" | sed -E 's/^[[:space:]]*"//; s/" =>$//' | sort -u)
refs=$(grep -oE '\{@[a-z_.]+\}' "$ROOT/www_data/mail/lang/fr.php" | sed -E 's/^\{@//; s/\}$//')
literals=$( { echo "$used"; echo "$refs"; grep -oE '"[a-z_]+\.[a-z_]+"' "$ROOT/www_data/lib/mail.php" | tr -d '"'; } | sort -u)
missing=$(comm -23 <(echo "$used") <(echo "$defined"))
unused=$(comm -13 <(echo "$literals") <(echo "$defined") | grep -v '^months$')
status=0
[ -n "$missing" ] && { echo "KEYS ASKED FOR BUT NOT IN fr.php:"; echo "$missing"; status=1; }
[ -n "$unused" ] && { echo "KEYS IN fr.php THAT NOTHING ASKS FOR:"; echo "$unused"; status=1; }

rm -rf "$OUT" && mkdir -p "$OUT"
docker exec -i -e MAIL_PREVIEW_DIR=/tmp/mail_preview "$C" php -d open_basedir= < "$HERE/render.php" | grep -v '^{"ts"'
docker cp "$C:/tmp/mail_preview/." "$OUT/" >/dev/null && docker exec "$C" rm -rf /tmp/mail_preview

{
cat <<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8" /><title>Mail preview</title>
<style>
body{font-family:system-ui,sans-serif;margin:24px;background:#888;color:#111}
.row{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap}
figure{margin:0}figcaption{font-weight:600;margin-bottom:6px}
iframe{border:0;border-radius:8px;background:transparent;height:1100px}
.light{width:660px;color-scheme:light}.dark{width:660px;color-scheme:dark}.mobile{width:375px;height:1400px}
</style></head><body>
<p>Every mail of <code>lib/mail.php</code> on <code>mail/layout.html</code>. The dark frames use <code>color-scheme: dark</code> on the iframe (Chrome, Firefox, Safari 17+); the <code>.txt</code> beside each mail holds its subject, addresses and text part.</p>
HTML
while read -r kind; do
  echo "<h2>$kind</h2><div class=\"row\"><figure><figcaption>Light</figcaption><iframe src=\"$kind.html\" class=\"light\"></iframe></figure><figure><figcaption>Dark</figcaption><iframe src=\"$kind.html\" class=\"dark\"></iframe></figure><figure><figcaption>Phone</figcaption><iframe src=\"$kind.html\" class=\"mobile\"></iframe></figure></div>"
done < "$OUT/kinds.txt"
echo "</body></html>"
} > "$OUT/index.html"
echo "open $OUT/index.html"
exit $status
