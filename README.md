# S3 Backup module for [Dolibarr ERP & CRM](https://www.dolibarr.org)

Schedules nightly backups of the Dolibarr database and documents directory to an S3-compatible bucket (tested with OVH Object Storage).

Each backup run creates a timestamped folder `YYYYMMDD_HH_MM_SS/` containing `db.bz2` (mysqldump + bzip2) and `docs.tar.gz` (tar + gzip of DOL_DATA_ROOT).

A separate prune job applies a two-tier retention policy: all backups within the last N days are kept, older ones are kept at one per calendar month.

Configure credentials and retention via **Admin > Modules > S3 Backup > Setup**, then enable the two cron jobs in **Admin > Cron**.

Only tested with Dolibarr v22 and MariaDB 11.
