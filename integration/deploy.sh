#!/bin/bash
# Pull the latest `deploy` branch and put its artefacts live on www.
#
# Runs on the cPanel host as the site user: from the once-a-minute cron
# (`poll`, acting on the webhook receiver's trigger file), the 30-minute
# unconditional cron (`cron`), or by hand. Idempotent: if nothing changed it
# exits quietly.
#
#   ~/deploy/aob-directory-web        clone of aob-directory-web-deploy's `deploy` branch (outside docroot)
#   ~/public_html/directorycore/app   <- dist/ (hashed assets + .vite/manifest.json)
#   ~/public_html/directorycore/aob-directory.php  <- integration/aob-directory.php
#   ~/public_html/directorycore/app-deploy.php     <- integration/app-deploy.php
#
# Order matters for a zero-downtime swap: new assets first (hashed names never
# collide), then the manifest, then the PHP that reads it. A page rendered
# mid-deploy references either the old or the new set, never a mix.
set -euo pipefail

CHECKOUT="${AOB_DEPLOY_CHECKOUT:-$HOME/deploy/aob-directory-web}"
DOCROOT="${AOB_DEPLOY_DOCROOT:-$HOME/public_html}"
TARGET="$DOCROOT/directorycore/app"
LOG="${AOB_DEPLOY_LOG:-$HOME/deploy/aob-directory-web.log}"
TRIGGER="${AOB_DEPLOY_TRIGGER:-$HOME/deploy/pending}"
MODE="${1:-manual}"

# `poll` (the once-a-minute cron) only does work when the webhook receiver has
# left a trigger file; every other mode always pulls.
if [[ "$MODE" == "poll" ]]; then
  [[ -f "$TRIGGER" ]] || exit 0
fi
rm -f "$TRIGGER"

mkdir -p "$(dirname "$LOG")"
exec >>"$LOG" 2>&1
echo "== $(date -u +%FT%TZ) deploy start ($MODE)"

if [[ ! -d "$CHECKOUT/.git" ]]; then
  echo "no checkout at $CHECKOUT" >&2
  exit 1
fi

cd "$CHECKOUT"
before=$(git rev-parse HEAD)
git fetch --quiet origin deploy
git reset --quiet --hard origin/deploy
after=$(git rev-parse HEAD)

if [[ "$before" == "$after" && -f "$TARGET/.vite/manifest.json" && "${FORCE:-0}" != "1" ]]; then
  echo "up to date at $after"
  exit 0
fi

mkdir -p "$TARGET"
# Keep old hashed assets around (--delete off) so a page already loaded in a
# browser can still fetch its lazy chunks; prune them separately.
rsync -a --exclude '.vite' "$CHECKOUT/dist/assets/" "$TARGET/assets/"
rsync -a "$CHECKOUT/dist/.vite/" "$TARGET/.vite/"
install -m 0644 "$CHECKOUT/integration/aob-directory.php" "$DOCROOT/directorycore/aob-directory.php"
install -m 0644 "$CHECKOUT/integration/app-deploy.php" "$DOCROOT/directorycore/app-deploy.php"

# MODX Evolution caches rendered documents under assets/cache/. The AobDirectory
# snippet is called uncached, so today the manifest lookup is live -- but a
# cached call ([[...]] instead of [!...!]) would pin the old hashed bundle until
# someone clears the cache by hand. Drop the page caches; MODX rebuilds them on
# the next hit. siteCache.idx.php (settings/snippet source) is left alone.
if [[ -d "$DOCROOT/assets/cache" ]]; then
  find "$DOCROOT/assets/cache" -maxdepth 1 -name '*.pageCache.php' -delete
  echo "cleared MODX page cache"
fi

# Prune assets older than 7 days that the current manifest no longer names.
find "$TARGET/assets" -type f -mtime +7 | while IFS= read -r f; do
  name=$(basename "$f")
  grep -q "$name" "$TARGET/.vite/manifest.json" || rm -f "$f"
done

echo "deployed $before -> $after"
