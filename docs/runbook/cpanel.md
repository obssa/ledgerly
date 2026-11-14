# Running Ledgerly on the cPanel account

Staging and early access run on the cPanel account behind Cloudflare. This is fine until real customers sign
in: shared hosting cannot meet SECURITY.md **S11** (encrypted off-site backups with point-in-time restore) or
**S12** (database on a private network, connection pooling). Move the API and database to a VPS or managed
PostgreSQL before the first paying customer; `deploy.yml` already publishes container images for that.

## What runs where

| Address | What | On the server |
|---|---|---|
| https://ledgerly.orcamind.dev | Web app (Next.js server) | cPanel *Setup Node.js App*: Node 22, app root `~/ledgerly-web`, startup file `app.js` |
| https://ledgerly-api.orcamind.dev | API (Laravel) | subdomain, document root `~/ledgerly/current/api/public`, PHP 8.3 (the account's version) |
| PostgreSQL 13 | `orcamiib_ledgerly`, schema `ledgerly` | owner login `orcamiib_lowner` (deploy only), app login `orcamiib_lweb` |
| cron | `schedule:run` and `queue:work` every minute | no Redis or long-running workers on shared hosting |

```
~/ledgerly/releases/<sha>/{api,web}    one folder per release (newest 5 kept)
~/ledgerly/current -> releases/<sha>   what is live
~/ledgerly/shared/api.env              API settings: restricted login only, mode 600
~/ledgerly/shared/web.env              LEDGERLY_API_URL
~/ledgerly/shared/storage/             uploads and logs, kept across releases
~/ledgerly/deploy/                     scripts, refreshed by every deploy
~/ledgerly-web/app.js                  Node.js entry: starts current/web/server.js
```

## Living next to bakery-ops

Both apps run under the same cPanel account (`orcamiib`). What keeps them apart, and what they share:

| | Ledgerly | bakery-ops | Shared? |
|---|---|---|---|
| Address | `ledgerly.orcamind.dev`, `ledgerly-api.orcamind.dev` | `bakery-ops.orcamind.dev` | no |
| Files | `~/ledgerly`, `~/ledgerly-web` | `~/bakery-ops.orcamind.dev` | no: every Ledgerly script works only inside its own folders |
| Database | `orcamiib_ledgerly` (logins `_lowner`, `_lweb`) | its own | no: separate database and logins; nothing in Ledgerly names another database |
| Deploy | this repository, its own SSH key and secrets | its own repository and key | no: either key can be revoked alone |
| PHP version | 8.3 | 8.3 | **yes, account-wide** on CloudLinux. Ledgerly is pinned to PHP 8.3 (`composer.json` platform) so the account setting never has to change |
| Crontab | 2 lines appended | its own lines | **yes, one file**. `provision.sh` only appends, keeps a copy in `shared/crontab.before-ledgerly`, and touches nothing if the crontab cannot be read |
| CPU, memory, processes | Node.js app, PHP requests, cron | the same | **yes, one account quota** (CloudLinux LVE). Check cPanel → *Resource Usage* after the first week; if either app hits the limits, that is the moment to move Ledgerly to its own server |
| PostgreSQL server | one database on it | possibly one too | the server, not the data; advisory locks and row-level security are per database |

## How a deploy works

`ci` passes on `main` → `deploy.yml` builds one archive (API with production packages, web app as a standalone
server) → uploads it over SSH (pinned host key) → `release.sh` on the server:

1. unpacks into `releases/<sha>` and links the shared settings and storage;
2. creates the `ledgerly` schema if needed and migrates **as the owner**. The owner password arrives on stdin
   from the GitHub secret and is never written on the server;
3. runs `ledgerly:check-database-roles` **as the app login**: if that login could bypass row-level security
   or drop the database, the release stops here and the old one stays live;
4. caches config and routes, switches `current`, restarts the Node.js app and the queue workers.

Then `smoke.sh` checks both addresses from outside. If it fails, `rollback.sh` puts the previous release back.

## One-time setup

Done once; the deploy needs all of it.

1. **Database** (done 14 Nov 2026): database `orcamiib_ledgerly`, logins `orcamiib_lowner` and `orcamiib_lweb`,
   passwords in `~/.ledgerly-db-secrets`. Then take the app login **out** of the database-owner role, which
   cPanel's "grant all privileges" put it in:
   ```bash
   uapi Postgresql revoke_all_privileges user=$(whoami)_lweb database=$(whoami)_ledgerly
   ```
2. **SSH key for GitHub Actions**: add the public key to `~/.ssh/authorized_keys` (cPanel → SSH Access).
3. **GitHub secrets** (repository → Settings → Secrets and variables → Actions):
   `DEPLOY_HOST=gin.hostns.io`, `DEPLOY_PORT=22`, `DEPLOY_USER=orcamiib`, `DEPLOY_SSH_KEY` (private key),
   `DEPLOY_KNOWN_HOSTS` (`ssh-keyscan gin.hostns.io`, compared with the fingerprints from
   `for f in /etc/ssh/ssh_host_*_key.pub; do ssh-keygen -lf $f; done` on the server), and
   `DEPLOY_DB_OWNER_PASSWORD` (the `DB_OWNER_PASSWORD` line of `~/.ledgerly-db-secrets`).
4. **Provision**: copy `scripts/deploy/` to `~/ledgerly/deploy/` and run `bash ~/ledgerly/deploy/provision.sh`.
   It creates the folders, a placeholder release, `shared/api.env` (new `APP_KEY`, restricted login) and
   `shared/web.env`, and the two cron lines. Safe to run again.
5. **Delete the password file**: `rm ~/.ledgerly-db-secrets`. From now on the owner password exists only in
   the GitHub secret, and the app password only in `shared/api.env`.
6. **Domains**:
   - cPanel → Domains → create `ledgerly-api.orcamind.dev`, document root `ledgerly/current/api/public`.
     **Do not change the PHP version.** On CloudLinux *Select PHP Version* applies to the whole account, so it
     would change bakery-ops too. Ledgerly runs on PHP 8.3, the version the account already uses.
   - Cloudflare → DNS → `ledgerly-api` record like the existing `ledgerly` one, proxied. SSL mode *Full (strict)*.
   - cPanel → Setup Node.js App → create: Node 22, production, app root `ledgerly-web`, app URL
     `ledgerly.orcamind.dev`, startup file `app.js`.
7. **Turn the deploy on**: repository variable `DEPLOY_ENABLED=true`. Optional variables: `DEPLOY_PHP_BIN`
   (default `/opt/alt/php83/usr/bin/php`), `DEPLOY_WEB_URL`, `DEPLOY_API_URL`.

## Day to day

| Task | How |
|---|---|
| Deploy | merge to `main`; or Actions → deploy → Run workflow |
| Roll back the code | `bash ~/ledgerly/deploy/rollback.sh` (migrations are never reversed) |
| Is it healthy? | `curl https://ledgerly-api.orcamind.dev/up`; `cd ~/ledgerly/current/api && /opt/alt/php83/usr/bin/php artisan ledgerly:check-database-roles` |
| Logs | `~/ledgerly/shared/storage/logs/laravel-*.log`; Node.js app log in cPanel → Setup Node.js App |
| Which release is live? | `cat ~/ledgerly/current/REVISION` |
| Change a setting | edit `~/ledgerly/shared/api.env`, then `php artisan config:cache` in `current/api` |
