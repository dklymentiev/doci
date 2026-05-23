#!/bin/sh
# DOCI container entrypoint.
#
# Ensures /var/www/html/files is a git repository before Apache starts.
# Every save in DOCI shells out to `git add && git commit` from that
# directory, so it must be initialised on first run.
#
# Idempotent: if .git already exists, this is a no-op.

set -e

FILES_DIR=/var/www/html/files
DOMAIN="${DOCI_DOMAIN:-doci.local}"

if [ ! -d "$FILES_DIR" ]; then
    mkdir -p "$FILES_DIR"
    chown www-data:www-data "$FILES_DIR"
fi

if [ ! -d "$FILES_DIR/.git" ]; then
    echo "[doci-entrypoint] Initialising git repo at $FILES_DIR"
    cd "$FILES_DIR"
    git init -q -b main
    git config user.name "DOCI"
    git config user.email "doci@$DOMAIN"
    if [ -n "$(ls -A . 2>/dev/null | grep -v '^.git$' || true)" ]; then
        git add -A
        git -c user.name=DOCI -c user.email="doci@$DOMAIN" commit -q -m "Initial DOCI content" || true
    fi
    chown -R www-data:www-data .git
    cd /
else
    # Existing repo. Pick up any seed content added between starts (host
    # bind-mounted new files into files/ since last container run, etc.)
    # so 'History:' actually reflects the file state.
    cd "$FILES_DIR"
    if ! git diff --quiet HEAD 2>/dev/null || [ -n "$(git ls-files --others --exclude-standard 2>/dev/null)" ]; then
        git add -A 2>/dev/null || true
        git -c user.name=DOCI -c user.email="doci@$DOMAIN" \
            commit -q -m "Sync seed content on container start" 2>/dev/null || true
    fi
    cd /
fi

# Apache (and the PHP code) runs as www-data, but the user's HOME isn't
# set by default. Git config files live at /var/www/.config/git/config.
export HOME=/var/www

exec docker-php-entrypoint "$@"
