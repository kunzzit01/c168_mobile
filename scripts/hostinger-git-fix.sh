#!/usr/bin/env sh
# Hostinger: "fatal: Need to specify how to reconcile divergent branches."
#
# Automated Deploy runs `git pull` on the server WITHOUT your repo's scripts — fix ONCE via SSH:
#
# --- Copy/paste (SSH terminal), two blocks in order ---------------------------
#
# 1) Tell Git how to pull (required since Git 2.27+). Use --global so Hostinger's
#    Deploy button also picks this up for future pulls:
#
#    git config --global pull.rebase false
#
# 2) cd into Hostinger's deploy folder (must contain .git — same path GIT uses),
#    then force server tree = GitHub main:
#
#    cd ~/YOUR_DEPLOY_PATH_HERE
#    git fetch origin
#    git reset --hard origin/main
#
# 3) hPanel → GIT → Deploy again.
#
# If you don't know the path: hPanel → GIT → your repo → often shown as Install path,
# or run: find ~ -maxdepth 5 -name ".git" -type d 2>/dev/null
#
# This script does step (2) locally when you're already inside the repo clone:
# ---------------------------------------------------------------------------

set -e

BRANCH="${GIT_BRANCH:-main}"

echo "git config (local repo): pull.rebase false, pull.ff false"
git config pull.rebase false
git config pull.ff false

echo "git fetch + reset --hard origin/${BRANCH}"
git fetch origin
git reset --hard "origin/${BRANCH}"

echo "OK: matches origin/${BRANCH}. Configure global once: git config --global pull.rebase false"
