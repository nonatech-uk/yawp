# YAWP — Yet Another WordPress (Backup) Plugin

Incremental S3 backups with Object Lock for WordPress sites running in containers. Optimised for slow-moving sites — after an initial full backup, incremental backups only run if someone logged in that day.

## Features

- **Full & incremental backups** — tar.gz archive of files + full database dump
- **S3 Object Lock** — COMPLIANCE mode for immutable, ransomware-proof storage
- **Login-triggered incrementals** — no login, no backup, no wasted storage
- **Scheduled full backups** — configurable interval (or manual-only)
- **No SDK dependencies** — minimal S3 client with SigV4 signing using PHP curl
- **Encrypted credentials** — AWS secret key stored with XSalsa20-Poly1305 (libsodium)
- **GitHub auto-updater** — updates via GitHub releases, no WordPress.org listing needed

## Requirements

- PHP 7.4+
- `curl` and `sodium` extensions
- `exec()` enabled (for `tar`)
- S3 bucket with Object Lock enabled

## Installation

```bash
cd /path/to/wp-content/plugins/
git clone git@github.com:nonatech-uk/yawp.git
```

Activate in WordPress admin, then configure under **Settings → YAWP Backup**.

## S3 Bucket Setup

```bash
aws s3api create-bucket --bucket yawp-pitstop --region eu-central-1 \
  --create-bucket-configuration LocationConstraint=eu-central-1 \
  --object-lock-enabled-for-bucket

aws s3api put-bucket-versioning --bucket yawp-pitstop \
  --versioning-configuration Status=Enabled

aws s3api put-object-lock-configuration --bucket yawp-pitstop \
  --object-lock-configuration '{"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"COMPLIANCE","Days":90}}}'
```

IAM policy needs: `s3:PutObject`, `s3:GetObject`, `s3:ListBucket`, `s3:AbortMultipartUpload`, `s3:ListMultipartUploadParts`. No `s3:DeleteObject` — COMPLIANCE mode objects can't be deleted anyway.

## How It Works

1. **Activation** — schedules a daily WP-Cron event at 03:00 UTC
2. **Login hook** — any `wp_login` sets a date flag in `wp_options`
3. **Daily check** — if no full backup exists, runs full; if login flag is set, runs incremental; otherwise skips
4. **Backup** — exports DB via `$wpdb`, creates tar.gz, uploads to S3 with Object Lock headers
5. **Incremental** — uses `tar --newer` to include only files changed since last backup (always includes full DB)

## License

GPL v2 or later. See [LICENSE](LICENSE).
