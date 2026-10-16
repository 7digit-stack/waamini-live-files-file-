# WAAMINI CI/CD

How code goes from a commit to the live Hostinger site, and what to do when something goes wrong.

## Pipeline at a glance

```
push / pull request to main
        │
        ▼
CI  (.github/workflows/ci.yml)
  ├─ guard     fails if dumps, archives, .env files, runtime storage or debug scripts are tracked
  ├─ secrets   gitleaks over the full git history (.gitleaks.toml)
  ├─ lint      php -l on every PHP file (PHP 8.3) + node --check on assets/js
  └─ unit      tests/ads_unit_test.php + cache self-test (no database needed)
        │ all green, on main only
        ▼
Deploy  (.github/workflows/deploy.yml)   environment: production (optional approval)
  1. checks the required secrets and that DEPLOY_PATH/.env exists on the server
  2. rsync over SSH, skipping everything in .deployignore
     · files the deploy overwrites are copied to ../.deploy_backups/<release>/ (last 10 kept)
     · .env, storage/, uploads/ and assets/uploads/ on the server are never touched
     · nothing is deleted on the server unless the manual run sets prune = true
  3. recreates storage folders and clears storage/page-cache
  4. smoke test: /login.php and / must answer 200/301/302
```

A deploy can also be started by hand: **Actions → Deploy to Hostinger → Run workflow** (pick a branch, tag or commit).

## One-time setup

### 1. Hostinger
1. hPanel → **Advanced → SSH Access**: enable SSH and note the host, port (usually `65002`) and username.
2. Create a deploy key on your computer: `ssh-keygen -t ed25519 -C "github-deploy" -f waamini_deploy`.
3. hPanel → SSH Access → **SSH keys**: add the contents of `waamini_deploy.pub`.
4. Make sure the site folder (normally `/home/<user>/domains/<domain>/public_html`) already has the live `.env`.

### 2. GitHub repository → Settings → Secrets and variables → Actions

| Type | Name | Value |
|---|---|---|
| Secret | `SSH_HOST` | Hostinger SSH host or IP |
| Secret | `SSH_PORT` | SSH port (defaults to 65002 if empty) |
| Secret | `SSH_USER` | Hostinger SSH username |
| Secret | `SSH_PRIVATE_KEY` | contents of the private key file `waamini_deploy` |
| Secret | `DEPLOY_PATH` | absolute path of the site folder, e.g. `/home/u123/domains/example.com/public_html` |
| Secret (optional) | `SSH_KNOWN_HOSTS` | output of `ssh-keyscan -p 65002 <host>`; pins the server key instead of trusting it on first use |
| Variable | `APP_URL` | `https://your-domain` (used for the smoke test) |

### 3. GitHub → Settings → Environments → `production`
Add yourself under **Required reviewers** if every deploy should wait for a click. Restrict deployment branches to `main`.

### 4. Branch protection (Settings → Branches → main)
Require a pull request and the CI checks `Forbidden files guard`, `Secret scan (gitleaks)`, `PHP and JS syntax` and `Unit tests (no database)` before merging.

## Rolling back
Every deploy keeps the files it replaced in `../.deploy_backups/<release>/` next to the site folder.

* **Fast:** re-run the Deploy workflow by hand with `ref` set to the last good commit or tag.
* **Manual (SSH):** `rsync -a ../.deploy_backups/<release>/ ./` from inside the site folder restores the overwritten files.

## Database migrations
Migrations are SQL files in `migrations/` and are **not** run automatically: on shared hosting a failed migration halfway through a deploy is harder to recover from than a manual run. Run new files in phpMyAdmin (or `mysql < migrations/0NN_x.sql` over SSH) before deploying code that needs them, and keep the matching `_down.sql` ready.

## Data cache (Redis)
`includes/cache.php` caches data in Redis when it is available and in `storage/cache` files otherwise:

* `.env` → `CACHE_DRIVER=redis` plus `REDIS_URL=redis://:password@host:6379/0` (or `rediss://` for TLS), or `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` / `REDIS_DB`.
* Works with the phpredis extension and, when it is not installed, with a small built-in client, so a Hostinger VPS or a managed Redis (Upstash, Redis Cloud) can be used from shared hosting too.
* If Redis is unreachable, pages keep working on the file cache and the error is logged once per request.
* Standard Hostinger shared/cloud web hosting does not run a Redis server. Use a VPS or a managed Redis service, or leave `CACHE_DRIVER=file`.

## Security notes from the CI/CD review (October 2026)
* The older repository `7digit-stack/wamini_crm_repositary` has these in its git history:
  * `waamini_production_dump.sql` (production rows from users, whatsapp_config, system_settings, subscriptions and more)
  * a 40 MB `.zip` project snapshot that contains a real `.env` and `waamini_hostinger_export.sql`
  
  This deployment repository was started from a clean commit without them. For the old repository: keep it private, rotate every credential that was in that `.env` (database password, Meta tokens and app secret, SMTP, Razorpay, OpenAI, CRON_SECRET_KEY), and either delete it or rewrite its history.
* `.gitignore` now blocks dumps, archives, `storage/backups/`, runtime storage and `scratch/`. CI fails if any of them are committed again.
* One-off scripts (`setup_db*.php`, `check_*`, `debug_*`, `fix_*`, `process_queue_direct.php`, `system_health_check_and_fix.php`, `api/debug_token.php`) are excluded from deploys. Delete any copies already on the server.
