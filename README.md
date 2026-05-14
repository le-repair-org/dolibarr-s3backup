# S3 Backup module for [Dolibarr ERP & CRM](https://www.dolibarr.org)

Schedules nightly backups of the Dolibarr database and documents directory to an S3-compatible bucket (tested with OVH Object Storage).

Each backup run creates a timestamped folder `YYYYMMDD_HH_MM_SS/` containing `db.bz2` (mysqldump + bzip2) and `docs.tar.gz` (tar + gzip of DOL_DATA_ROOT).

A separate prune job applies a two-tier retention policy: all backups within the last N days are kept, older ones are kept at one per calendar month.

Configure credentials and retention via **Admin > Modules > S3 Backup > Setup**, then enable the two cron jobs in **Admin > Cron**.

Only tested with Dolibarr v22 and MariaDB 11.

## Restore

Restoration is handled by `scripts/restore.php`, a standalone PHP CLI script. It uses the module's vendored AWS SDK — no additional dependencies required.

```
php /path/to/custom/s3backup/scripts/restore.php YYYYMMDD_HH_MM_SS
```

The script downloads `db.bz2` and `docs.tar.gz` from S3, restores the documents directory, and saves the decompressed SQL to `/tmp/s3restore_<timestamp>/db.sql` for the caller to inject into the database.

**Required environment variables:**

| Variable | Description |
|---|---|
| `S3BACKUP_ENDPOINT` | S3-compatible endpoint URL |
| `S3BACKUP_REGION` | Region |
| `S3BACKUP_BUCKET` | Bucket name |
| `S3BACKUP_ACCESS_KEY` | Access key |
| `S3BACKUP_SECRET_KEY` | Secret key |

**Optional:**

| Variable | Default |
|---|---|
| `DOL_DATA_ROOT` | `/var/www/documents` |

These credentials are read from environment variables at runtime, independently of the credentials configured in the Dolibarr admin interface.
