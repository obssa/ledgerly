# Backups and restore (SECURITY.md S11)

Every night `.github/workflows/backup.yml` takes a copy of the live database. A month's copies are kept in a private
Cloudflare R2 bucket, and on the 1st of each month `.github/workflows/restore-drill.yml` proves that the newest copy
restores.

## How it works

1. **The dump.** The workflow connects to the server with the deploy key. It hands the owner password over stdin to
   `scripts/deploy/cpanel/backup.sh`, the same way a release does, so the password is never written on the server.
   `pg_dump` streams Ledgerly's schema back in PostgreSQL's custom format.
2. **Encryption.** The stream is encrypted with [age](https://age-encryption.org) for one public key
   (`BACKUP_AGE_RECIPIENT`) before it touches a disk. Nothing unencrypted is kept, either on the server or on the
   runner.
3. **Upload.** The encrypted file goes to `r2://<bucket>/ledgerly/db/YYYY/MM/DD/ledgerly-<time>.dump.age`, with its
   SHA-256 in the object's metadata.
4. **Audit chain heads.** The last hash of each company's audit chain goes beside it, under
   `ledgerly/audit-heads/...`. A database administrator could rewrite history on the server, but not this record of
   where each chain stood.
5. **The drill.** Each month the newest backup is downloaded and decrypted with `BACKUP_AGE_IDENTITY`. It is restored
   into a throwaway PostgreSQL 18, and `ledger_verify` and `audit_log_verify` run for every company. The run fails on
   any problem. Its summary is the record of the drill.

The private key is in two places:
- the GitHub secret `BACKUP_AGE_IDENTITY`, for the drill. That is no weaker than today: anyone who controls the
  repository's secrets already has the database owner password.
- your own offline copy, to restore if GitHub is ever unavailable.

## One-time setup

1. **Create the bucket.** In Cloudflare, go to R2 and create a bucket, for example `ledgerly-backups`. Keep it
   private: no public access and no custom domain.
2. **Keep about a month of copies.** On the bucket, go to Settings, Object lifecycle rules, and add a rule: delete
   objects with the prefix `ledgerly/` 35 days after upload.
3. **Create a token for this bucket only.** In R2, go to Manage API tokens and create a token with "Object Read &
   Write", limited to that one bucket. Note the Access Key ID, the Secret Access Key, and your account ID (shown on
   the R2 page).
4. **Run the setup script.** On your machine, from the repository, run `bash scripts/ops/backup-setup.sh` (on Windows,
   through Git Bash). It needs `gh` signed in and Docker. It:
   - asks for the four values above, the secret ones without showing them;
   - makes an age key pair into `~/ledgerly-backup-key.txt`, unless one is already there;
   - sets the variables `R2_ACCOUNT_ID`, `R2_BUCKET`, `BACKUP_AGE_RECIPIENT` and `BACKUP_ENABLED=true`;
   - sets the secrets `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY` and `BACKUP_AGE_IDENTITY`;
   - starts the first backup.
5. **Keep the key safe.** Move `~/ledgerly-backup-key.txt` to a password manager or another safe offline place.
   Without it, no backup can be opened.
6. **Run the first drill.** Start "restore drill" by hand from the Actions tab once the first backup is in.

## Restoring for real

On a machine with `age`, the AWS CLI (pointed at R2) and PostgreSQL 16 or later:

```bash
aws s3 ls --endpoint-url https://<account>.r2.cloudflarestorage.com --recursive s3://<bucket>/ledgerly/db/ | sort | tail -5
aws s3 cp --endpoint-url https://<account>.r2.cloudflarestorage.com s3://<bucket>/<key> backup.dump.age
age -d -i ledgerly-backup-key.txt backup.dump.age > backup.dump
pg_restore --no-owner --no-privileges --exit-on-error -d <empty database> backup.dump
```

Then:
1. Recreate the roles and grants by running the migrations as the owner (`php artisan migrate --database=owner`). The
   schema files grant the application role again; the data is already in place.
2. Run `ledger_verify` and `audit_log_verify` for each company.
3. Compare each company's last audit hash with the matching `audit-heads` file from the night of the backup.

## Attached files

The database dump does not hold the files attached to documents (SECURITY.md S7). They live on the server in
`~/ledgerly/shared/storage/app/private/attachments/<company id>/`, one file per attachment under its random key; the
`attachments` table says which document each belongs to. When backups go live, copy that folder to the bucket as well
(for example nightly, files only added, never changed), and restore it next to the database.
