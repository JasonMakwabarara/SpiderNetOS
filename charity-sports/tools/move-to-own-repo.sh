#!/usr/bin/env bash
# Put this site into a repository of its own.
#
#   1. Create an empty repository on GitHub, for example charity-sports.
#      Do NOT let GitHub add a README, a .gitignore or a licence: the repo must
#      be empty or the first push is refused.
#   2. Run this from inside the charity-sports folder:
#
#        ./tools/move-to-own-repo.sh https://github.com/YOUR-NAME/charity-sports.git
#
#   3. On GitHub: Settings -> Pages -> Source -> GitHub Actions.
#
# The site works out to about 4.5 MB, so the push takes a few seconds.
#
# If this folder currently sits inside another git repository, the script does
# NOT create a repo in place: that would leave the same files tracked by two
# repositories at once, which goes wrong quietly and later. Instead it copies
# the tracked files out to a sibling folder and builds the new repository
# there, leaving the original untouched.
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
  echo "There is already a .git folder in $HERE." >&2
  echo "Remove it first if you meant to start over." >&2
  exit 1
fi

# Is this folder inside somebody else's repository?
PARENT_REPO=""
if git rev-parse --show-toplevel >/dev/null 2>&1; then
  PARENT_REPO="$(git rev-parse --show-toplevel)"
fi

if [ -n "$PARENT_REPO" ] && [ "$PARENT_REPO" != "$HERE" ]; then
  # Beside the parent repository, not inside it: a copy left within the parent's
  # working tree is the same trap one level along.
  TARGET="$(dirname "$PARENT_REPO")/$(basename "$HERE")"
  echo "This folder is inside the repository at $PARENT_REPO."
  echo "Copying its tracked files to $TARGET and building the new repository there."
  echo

  if [ -e "$TARGET" ]; then
    echo "$TARGET already exists. Move or delete it first." >&2
    exit 1
  fi
  mkdir -p "$TARGET"

  # Only files the parent repository tracks: no node_modules, no .env, no
  # runtime content, nothing generated.
  REL="$(git rev-parse --show-prefix)"
  git -C "$PARENT_REPO" ls-files -z -- "$REL" | while IFS= read -r -d '' file; do
    dest="$TARGET/${file#"$REL"}"
    mkdir -p "$(dirname "$dest")"
    cp -p "$PARENT_REPO/$file" "$dest"
  done

  cd "$TARGET"
  echo "Copied $(find . -type f | wc -l | tr -d ' ') files."
  echo
else
  TARGET="$HERE"
fi

echo "Creating a repository in $(pwd)"
git init -b main
git add .
git commit -m "Charity Sports website and admin panel"
git remote add origin "$REMOTE"
git push -u origin main

cat <<DONE

Pushed from $(pwd)

Next, on GitHub:
  Settings -> Pages -> Source -> GitHub Actions

Then check the Actions tab. When the deploy finishes, the address is shown
there and on the Pages settings screen.

Remember to set the real address once you know it. It lives in one place:
  data/site-data.js   org.siteUrl

The page head, robots.txt and sitemap.xml are generated from that value when
you publish, so there is nothing else to edit.
DONE

if [ "$TARGET" != "$HERE" ]; then
  cat <<NOTE

The original folder at $HERE was left alone. Once you are happy with the new
repository, remove it from the repository it currently lives in so the two do
not drift apart.
NOTE
fi
