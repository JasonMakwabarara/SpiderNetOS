#!/usr/bin/env bash
# Move this site into a repository of its own, keeping nothing behind.
#
#   1. Create an empty repository on GitHub, for example charity-sports.
#      Do NOT let GitHub add a README, a .gitignore or a licence: the repo
#      must be empty or the first push will be refused.
#   2. Run this from inside the charity-sports folder:
#
#        ./tools/move-to-own-repo.sh https://github.com/YOUR-NAME/charity-sports.git
#
#   3. On GitHub: Settings → Pages → Source → GitHub Actions.
#
# The site is then live at https://YOUR-NAME.github.io/charity-sports/ within
# a minute or two.
set -euo pipefail

REMOTE="${1:-}"
if [ -z "$REMOTE" ]; then
  echo "Usage: $0 <git remote url>" >&2
  echo "Example: $0 https://github.com/JasonMakwabarara/charity-sports.git" >&2
  exit 1
fi

HERE="$(cd "$(dirname "$0")/.." && pwd)"
cd "$HERE"

if [ -d .git ]; then
  echo "There is already a .git folder here. Remove it first if you meant to start over." >&2
  exit 1
fi

echo "Creating a repository in $HERE"
git init -b main
git add .
git commit -m "Charity Sports website and admin panel"
git remote add origin "$REMOTE"
git push -u origin main

cat <<'DONE'

Pushed.

Next, on GitHub:
  Settings → Pages → Source → GitHub Actions

Then check the Actions tab. When the deploy finishes, the address is shown
there and on the Pages settings screen.

Remember to update these to the real address once you know it:
  data/site-data.js   org.siteUrl
  robots.txt          the Sitemap line
  sitemap.xml         the loc line
  index.html          the canonical and og:url tags
DONE
