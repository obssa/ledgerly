# Running Evenbooks on the cPanel account

Staging and early access run on Evenbooks' own cPanel account, `evenbojk` on `vesper.hostns.io`. It moved there on
25 Nov 2026 from an account shared with nine other sites, which sat at its process limit. Shared hosting is fine
until real customers sign in, but it cannot meet SECURITY.md **S11** (encrypted off-site backups with point-in-time
restore) or **S12** (database on a private network, connection pooling). Move the API and database to a VPS or
managed PostgreSQL before the first paying customer; `deploy.yml` already publishes container images for that.

## What runs where

| Address | What | On the server |
|---|---|---|
| https://evenbooks.net | The public website. Until there is one, a temporary (302) redirect to the app | `~/public_html/.htaccess`; `/.well-known/` stays reachable for certificates |
| https://app.evenbooks.net | Web app (Next.js server) | *Setup Node.js App*: Node 22, app root `~/ledgerly-web`, startup file `app.js`, document root `~/app.evenbooks.net` |
| https://api.evenbooks.net | API (Laravel) | subdomain, document root `~/ledgerly/current/api/public`, PHP 8.3 through `shared/api.htaccess` |
| PostgreSQL 13 | `evenbojk_ledgerly`, schema `ledgerly` | owner login `evenbojk_lowner` (deploy only), app login `evenbojk_lweb` |
| cron | `schedule:run` and `queue:work` every minute, each under `flock -n` (one at a time) and `timeout` (55 and 10 minutes at most) | no Redis or long-running workers on shared hosting; a run that never ends must not be joined by another every minute |
| Email | confirmation and password-reset links, from `no-reply@evenbooks.net` | the server's `sendmail` (`MAIL_MAILER=sendmail` in `shared/api.env`); SPF and DKIM for `evenbooks.net` from cPanel, *Email Deliverability* |
| DNS | `evenbooks.net` and its subdomains | the host's nameservers (`ns1/ns2.hostns.io`), edited in cPanel, *Zone Editor*. No Cloudflare proxy, so `api.env` trusts no proxy (`TRUSTED_PROXIES` unset) |
| Demo sign-in | one button per role on the sign-in page, into a shared demo company | repository variable `DEMO_LOGINS` (`true`/`false`): each deploy writes it to `shared/api.env` and, when on, runs `ledgerly:demo-setup` as the owner (SECURITY.md, Demo sign-in) |

```
~/ledgerly/releases/<sha>/{api,web}    one folder per release (newest 5 kept)
~/ledgerly/current -> releases/<sha>   what is live
~/ledgerly/shared/api.env              API settings: restricted login only, mode 600
~/ledgerly/shared/web.env              LEDGERLY_API_URL, LEDGERLY_WEB_URL, LEDGERLY_WEB_KEY, LEDGERLY_BEHIND_CLOUDFLARE
~/ledgerly/shared/api.htaccess         PHP 8.3 handler, put in front of every release's public/.htaccess
~/ledgerly/shared/storage/             uploads and logs, kept across releases
~/ledgerly/deploy/                     scripts, refreshed by every deploy
~/ledgerly-web/app.js                  Node.js entry: starts current/web/server.js
```

Nothing else runs on the account. `cron.sh` still changes only Evenbooks' own crontab lines, so another app can be
added later without either one overwriting the other's.

## How a deploy works

`ci` passes on `main` → `deploy.yml` builds one archive (API with production packages, web app as a standalone
server) → uploads it over SSH (pinned host key) → `release.sh` on the server:

1. unpacks into `releases/<sha>` and links the shared settings and storage;
2. creates the `ledgerly` schema if needed and migrates **as the owner**. The owner password arrives on stdin
   from the GitHub secret and is never written on the server;
3. runs `ledgerly:check-database-roles` **as the app login**: if that login could bypass row-level security,
   the release stops here and the old one stays live;
4. caches config and routes, switches `current`, restarts the Node.js app and the queue workers.

Then `smoke.sh` checks both addresses from outside. If it fails, `rollback.sh` puts the previous release back.

## One-time setup

Done once; the deploy needs all of it. **Status (25 Nov 2026): steps 1 to 6 done on `evenbojk@vesper.hostns.io`.**

> The server's firewall (LFD) blocks an IP for a while after connections that never log in. `ssh-keyscan`
> against the server is one of those; so is probing ports. Read the host key from inside the server instead
> (step 2), then only make real logins. If you are blocked, every port times out from your IP. Only the host can
> unblock you (client area or a support ticket).
>
> On Windows, run the shell commands below in Git Bash, not PowerShell.

1. **Check the account** (cPanel, *Terminal*): PostgreSQL answers on `127.0.0.1:5432`, `psql` and `pg_dump`
   exist, PHP 8.3 has `pdo_pgsql`, `bcmath`, `mbstring` and `gd` (`zip`, `intl` and `sodium` are not needed),
   Node 22 is under `/opt/alt/alt-nodejs22`, and `flock`, `timeout`, `crontab` and `openssl` exist.
2. **SSH key for GitHub Actions**: a key used only for this server. Add its public half to
   `~/.ssh/authorized_keys`. Read the host key fingerprints from inside the server, in cPanel's *Terminal*:
   `ssh-keyscan -t ed25519,rsa localhost | ssh-keygen -lf -` (the `/etc/ssh` key files are not readable from the
   account). Make the first real login with `StrictHostKeyChecking=accept-new` into a known-hosts file of its own,
   compare its fingerprint with those, and use that file as `DEPLOY_KNOWN_HOSTS`.
3. **Database**, over SSH with cPanel's own API, passwords made on the server and never shown:
   `uapi Postgresql create_database name=<user>_ledgerly`, `create_user` for `<user>_lowner` and `<user>_lweb`
   (`openssl rand -hex 24` each), `grant_all_privileges` for both, and both passwords written to
   `~/.ledgerly-db-secrets` (mode 600) as `DB_PASSWORD=` and `DB_OWNER_PASSWORD=`. **Do not revoke the app
   login's privileges**: a login can connect only as a member of the database-owner role (pg_hba `samerole`).
   `shared/api.env` sets `DB_ALLOW_OWNER_ROLE_MEMBERSHIP=true`, so the role check accepts that membership and
   still refuses anything that would get past row-level security (SECURITY.md finding 23).
4. **Placeholder, then addresses.** Create `~/ledgerly/current` (a link to a placeholder release, as
   `provision.sh` does) **before** the API subdomain, or cPanel makes a real folder where the link must go.
   - `uapi SubDomain addsubdomain domain=api rootdomain=evenbooks.net dir=ledgerly/current/api/public disallowdot=1`,
     and the same for `app` with `dir=app.evenbooks.net`. The host's Cloudflare hook prints
     `entrap_domain_hook.sh ... Permission denied`; that does not matter while the domain's DNS is the host's own
     nameservers (check with `nslookup api.evenbooks.net ns1.hostns.io`).
   - `cloudlinux-selector create --json --interpreter nodejs --version 22 --app-root ledgerly-web
     --domain app.evenbooks.net --app-uri / --app-mode production --startup-file app.js`
   - LiteSpeed serves a real file ahead of the Node.js app: keep `index.html` out of the app's document root.
   - `uapi SSL start_autossl_check` for the certificates.
5. **Provision**: copy `scripts/deploy/cpanel/` to `~/ledgerly/deploy/` and run
   `bash ~/ledgerly/deploy/provision.sh`. Set `BEHIND_CLOUDFLARE=true` only if Cloudflare's proxy is in front.
   It creates the folders, `shared/api.env` (new `APP_KEY`, restricted login), `shared/web.env`, the web-server
   key and the two cron lines. Safe to run again: it never overwrites existing settings.
6. **Main domain**: until there is a website, `~/public_html/.htaccess` sends everything except `/.well-known/`
   to `https://app.evenbooks.net` with a 302.
7. **GitHub secrets** (repository, Settings, Secrets and variables, Actions): `DEPLOY_HOST`, `DEPLOY_PORT`,
   `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, and `DEPLOY_DB_OWNER_PASSWORD`, piped from the server's
   `~/.ledgerly-db-secrets` straight into `gh secret set` so it is never displayed. Then delete that file: the
   owner password exists only in the GitHub secret, and the app password only in `shared/api.env`.
8. **Turn the deploy on**: repository variables `DEPLOY_ENABLED=true`, `DEPLOY_WEB_URL` and `DEPLOY_API_URL`
   (defaults: the addresses above), and optionally `DEPLOY_PHP_BIN` (default `/opt/alt/php83/usr/bin/php`).

## Day to day

| Task | How |
|---|---|
| Deploy | merge to `main`; or Actions → deploy → Run workflow |
| Roll back the code | `bash ~/ledgerly/deploy/rollback.sh` (migrations are never reversed) |
| Is it healthy? | `curl https://api.evenbooks.net/up`; `cd ~/ledgerly/current/api && /opt/alt/php83/usr/bin/php artisan ledgerly:check-database-roles` |
| Logs | `~/ledgerly/shared/storage/logs/laravel-*.log`; Node.js app log in cPanel → Setup Node.js App |
| Which release is live? | `cat ~/ledgerly/current/REVISION` |
| Change a setting | edit `~/ledgerly/shared/api.env`, then `php artisan config:cache` in `current/api` |
| Make a test company | Actions → test-company → Run workflow: the company id, and owners as `email|Name;email|Name`. New accounts' passwords go to `~/ledgerly/shared/test-accounts.txt` (mode 600) only: read it once, hand them over, delete it |
| Demo sign-in on or off | repository variable `DEMO_LOGINS` (`true`/`false`), applied by the next deploy |

### When the account runs out of processes

Symptom: the deploy fails with `fork: Resource temporarily unavailable` or `Connection reset by peer` right after
connecting, and SSH logs in but cannot run anything. The site may keep answering (its processes are already running).
On the old shared account the cause was other sites; on this one it can only be Evenbooks.

1. cPanel → *Resource Usage*: the *Number of processes* (NPROC) and *Entry processes* graphs show when it filled up;
   its snapshot lists the processes at the time.
2. Free the account. Either ask the host to end this account's stuck processes, or in cPanel turn off the two
   Evenbooks lines under *Cron Jobs* for a few minutes and restart the app under *Setup Node.js App*.
3. Re-run the failed deploy (Actions → deploy → Re-run failed jobs). Each release prints the process count by program
   name at its start (`== Processes on this account`), and `cron.sh` puts the cron lines back as they should be.

