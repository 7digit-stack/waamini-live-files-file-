## Waamini Production Deployment Guide (Hostinger Apache + PHP)

### Server requirements
- **PHP**: 8.1+ recommended (8.0 minimum)
- **MySQL/MariaDB**: 10.4+ recommended
- **Apache modules**: `mod_rewrite`, `mod_headers`, `mod_deflate`, `mod_expires`
- **PHP extensions**: `mysqli`, `curl`, `openssl`, `mbstring` (recommended), `json`

### 1) Upload code
- Upload the project so the web root points to the `waamini/` folder (or ensure URLs use `/waamini/` as in `.htaccess`).
- Ensure `storage/` is writable by PHP:
  - `storage/logs/`
  - `storage/sessions/`
  - `storage/cache/`

### 2) Configure environment
- Copy `.env.example` → `.env`
- Set at minimum:
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `APP_URL=https://your-domain.com/waamini`
  - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
  - `ENCRYPTION_KEY` (strong unique secret)
  - `CRON_SECRET_KEY` (strong unique secret)

### 3) Database setup
- Create the database.
- Run migrations in order, easiest path:
  - Import `migrations/000_run_all.sql`
  - Then import newer migrations if present (e.g. `migrations/010_queue_jobs_and_api_logs.sql`)
  - For WAAMINI Bot provider/model switching, import `migrations/029_waamini_bot_model_providers.sql` after migration 028.
  - For longer provider model IDs, also import `migrations/030_waamini_bot_model_id_length.sql`.
  - Import `migrations/032_flow_builder_graph.sql` to enable the graph-backed Flow Builder, flow sessions, run logs, and delayed flow jobs.

### 4) Cron / workers
Run these via cron (CLI):
- **WhatsApp automation queue worker** (sends queued chat messages and resumes Flow Builder Delay nodes):
  - `php workers/automation_queue_worker.php`
- **Automation dispatcher** (enqueues due CRM automation work):
  - `php cron_automation_sender.php`
- **Generic DB job queue worker** (executes jobs enqueued by cron dispatchers):
  - `php queue/worker.php`

Suggested schedule (shared hosting-safe):
- Every minute:
  - `* * * * * php /home/USER/public_html/waamini/workers/automation_queue_worker.php`
  - `* * * * * php /home/USER/public_html/waamini/cron_automation_sender.php`
  - `* * * * * php /home/USER/public_html/waamini/queue/worker.php`

### 4b) Supervisor (daemon mode — no cron dependency)
If your host supports Supervisor (VPS/dedicated), prefer daemons:
- `php workers/automation_queue_daemon.php`
- `php queue/daemon.php`

Example `supervisord.conf` programs:

```ini
[program:waamini_automation_queue]
command=/usr/bin/php /var/www/waamini/workers/automation_queue_daemon.php
autostart=true
autorestart=true
stopsignal=TERM
stopasgroup=true
killasgroup=true
stdout_logfile=/var/www/waamini/storage/logs/supervisor-automation.log
stderr_logfile=/var/www/waamini/storage/logs/supervisor-automation.err.log
user=www-data

[program:waamini_queue_jobs]
command=/usr/bin/php /var/www/waamini/queue/daemon.php
autostart=true
autorestart=true
stopsignal=TERM
stopasgroup=true
killasgroup=true
stdout_logfile=/var/www/waamini/storage/logs/supervisor-queue.log
stderr_logfile=/var/www/waamini/storage/logs/supervisor-queue.err.log
user=www-data
```

Graceful stop:
- create `storage/STOP_AUTOMATION_DAEMON` or `storage/STOP_QUEUE_DAEMON`, or let Supervisor send `TERM`.

### Log rotation
Application logs are auto-rotated by size (default ~5MB, keep 7 archives) for:
- `storage/logs/daemon.log`
- `storage/logs/queue-failures.log`
- `storage/logs/slow-queries.log`

On VPS, also configure OS `logrotate` for Supervisor stdout logs.

### 5) Apache hardening
- `.env` and hidden files are denied by `.htaccess`.
- `storage/` is blocked from direct web access by `.htaccess`.
- Keep HTTPS redirect disabled until SSL is active, then enable it in `.htaccess`.

### 5b) Queue health monitoring
Secure health endpoint:
- `api/health_queue.php`
- Provide secret via `X-HEALTH-KEY: <CRON_SECRET_KEY>` header (or `?key=...`).

### 6) Smoke test
- Visit `login.php` and log in.
- Open client `whatsapp.php`, send a message:
  - It should show **queued** instantly
  - The background worker should mark it **sent/failed** later
