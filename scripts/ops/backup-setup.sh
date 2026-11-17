#!/usr/bin/env bash
# One-time setup of the nightly encrypted backups to Cloudflare R2 (docs/runbook/backups.md).
# Asks for the R2 details (secret ones without showing them), makes an age key pair, stores everything as GitHub
# variables and secrets of obssa/ledgerly, and starts the first backup. Nothing secret is printed.
set -euo pipefail
REPO=obssa/ledgerly
KEYFILE="$HOME/ledgerly-backup-key.txt"

command -v gh >/dev/null || { echo "Install and sign in to the GitHub CLI (gh) first."; exit 1; }
command -v docker >/dev/null || { echo "Docker is needed to make the age key."; exit 1; }

read -rp "Cloudflare account ID: " ACCOUNT
read -rp "R2 bucket name: " BUCKET
read -rp "R2 access key ID: " ACCESS_KEY
read -rsp "R2 secret access key (not shown): " SECRET_KEY; echo
[ -n "$ACCOUNT" ] && [ -n "$BUCKET" ] && [ -n "$ACCESS_KEY" ] && [ -n "$SECRET_KEY" ] || { echo "All four are needed."; exit 1; }

if [ -f "$KEYFILE" ]; then
  echo "Using the existing key in $KEYFILE"
else
  umask 077
  MSYS_NO_PATHCONV=1 docker run --rm alpine:3.20 sh -c 'apk add -q age >/dev/null && age-keygen 2>/dev/null' > "$KEYFILE"
fi
PUBLIC=$(sed -n 's/^# public key: //p' "$KEYFILE" | tr -d '\r')
case "$PUBLIC" in age1*) ;; *) echo "Could not read the public key from $KEYFILE"; exit 1 ;; esac

gh variable set R2_ACCOUNT_ID -R "$REPO" -b "$ACCOUNT" >/dev/null
gh variable set R2_BUCKET -R "$REPO" -b "$BUCKET" >/dev/null
gh variable set BACKUP_AGE_RECIPIENT -R "$REPO" -b "$PUBLIC" >/dev/null
printf '%s' "$ACCESS_KEY" | gh secret set R2_ACCESS_KEY_ID -R "$REPO" >/dev/null
printf '%s' "$SECRET_KEY" | gh secret set R2_SECRET_ACCESS_KEY -R "$REPO" >/dev/null
gh secret set BACKUP_AGE_IDENTITY -R "$REPO" < "$KEYFILE" >/dev/null
gh variable set BACKUP_ENABLED -R "$REPO" -b true >/dev/null
unset SECRET_KEY ACCESS_KEY

gh workflow run backup.yml -R "$REPO" >/dev/null && echo "The first backup has started: see the Actions tab, workflow \"backup\"."
echo
echo "Public key: $PUBLIC"
echo "Private key: $KEYFILE"
echo "Move the private key file to a password manager or another safe offline place. Without it no backup can be opened."
