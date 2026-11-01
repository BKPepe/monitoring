# Blood Kings - Status & Telemetry Monitoring System

An open-source, ultra-fast server and service status monitoring application built with PHP and MySQL. It features active uptime probing (HTTP/S with cPanel hosting stats, TCP, Minecraft, TeamSpeak 3, Discord) and passive system resource telemetry pushed from remote agents (Linux, Windows, Docker, OpenWrt).

---

## Folder Structure
* `index.php` - Public status dashboard with responsive dark glassmorphic design.
* `admin.php` - Admin control panel for monitor CRUD, 2FA setup, webhooks, and system configuration.
* `cron.php` - Active probing runner executed via server CLI cron.
* `cron_check.php` - `bk_cron_check_monitor()`, cron's check of one monitor, and the active checks it runs.
* `node_api.php` - API endpoint for remote probes that measure from other locations.
* `agent_api.php` - Ingestion API for VPS and OpenWrt system telemetry agents.
* `agent_report.php` - `bk_agent_report()`, what `agent_api.php` does with an agent's full report once its key named a monitor.
* `agent.sh` / `agent.py` - Native Bash and Python telemetry agents for Linux hosts.
* `agent_openwrt.sh` - Lightweight OpenWrt router agent with Wi-Fi, conntrack, and signed Remote Actions.
* `agent.ps1` - PowerShell telemetry agent for Windows hosts.
* `badge.php` - Dynamic vector SVG badge generator (`badge.php?id=12&type=status|uptime`).
* `widget.php` - Deprecated alias: a 302 to the 30-day availability badge (`api.php?action=badge&type=uptime`).
* `db.php` - PDO database connection initializer; runs the migrations when the schema version changes.
* `migrations.php` - The schema migrations (one ordered list and the runner that applies it) and the one-time data fixes, each run once per database.
* `functions.php` - Core measurement library, alert routing (Pushover, PagerDuty, Telegram, Discord, Slack), and TOTP engine. It only defines functions; the tests require it without a database.
* `bootstrap.php` - What every page and endpoint that uses `functions.php` loads first: `db.php`, the library, and the check of a signed-in account's role.
* `schema.sql` - Complete MySQL database structure.

---

## 1. Quick Installation (Central Server)
1. Upload the `status/` and `app/` directories side by side to your web server
   document root (e.g., `public_html/status/` and `public_html/app/`).
2. Create an empty MySQL/MariaDB database and a user with all privileges on it
   (in cPanel: **MySQL Databases**).
3. Open `https://YOUR-DOMAIN/app/setup` (any page under `/status/` sends you
   there while the install is unfinished). The installer:
   * tests the database connection and writes `config.php` from
     `config.sample.php` - or, when PHP cannot write to `status/`, shows its
     text for you to save (your password is not in it; you put it in);
   * imports `schema.sql`;
   * creates the first administrator (`schema.sql` creates no account and
     there is no default password);
   * prints the exact cron line for this directory and waits for its first run.

   Once the database connects and an account exists, every installer step is
   refused. A `config.php` you write by hand from `config.sample.php` works too;
   the installer never replaces one with real credentials.

---

## 2. Automated Deployment via GitHub Actions
To deploy automatically without committing passwords:
1. In your GitHub repository, go to **Settings** -> **Secrets and variables** -> **Actions**.
2. Add a secret named `STATUS_CONFIG_PHP` containing your production `config.php` code.
3. The GitHub Actions workflow will automatically deploy updates to your hosting server on every `git push`.
4. A directory this workflow deploys to keeps the installer locked even if
   `config.php` goes missing there (FTP-Deploy-Action leaves
   `.ftp-deploy-sync-state.json` in it): upload `config.php` again over FTP.

---

## 3. Active Probing Cron Setup
To execute active probes every 1–5 minutes, set up a server CLI cron job. The
installer's last step prints this line with the real path and PHP binary:
```bash
* * * * * php -q /path/to/status/cron.php >/dev/null 2>&1
```

---

## 4. Remote Probes from Other Locations
`node_api.php` takes measurements from outside the hosting, so "the service is
dead" can be told apart from "our server cannot see it". The probe is the
Cloudflare Worker in the agents repository (`cloudflare-agent.js`): it fetches
the monitors with `action=get_monitors`, checks them from a Cloudflare location
and posts the results with `action=post_results`. Both calls carry the
**Cron / Node API Key** from `admin.php` -> **System Settings** (`cron_key`);
with the key empty the endpoint answers 403. The protocol is in `docs/api.md`.

---

## 5. VPS & Router System Telemetry Agents
Install lightweight telemetry agents on target machines:

### Linux Agent (`agent.sh` / `agent.py`)
```bash
python3 agent.py --interval 60 --url https://your-server.com/status/agent_api.php --key YOUR_AGENT_KEY
```
Or run auto-registration (positional arguments, as implemented in the script):
```bash
./agent.sh --register YOUR_REGISTRATION_TOKEN https://your-server.com/status/agent_api.php
```

> **If an agent disappears from your `/status/` directory**, a server-side
> malware scanner has quarantined it. cPGuard reports it as `BAD_SHELL_SCRIPT`
> under "Suspicious File", and the heuristic is not wrong about the
> capabilities: the agent downloads a replacement of itself over HTTP when
> `AUTO_UPDATE=1`, and it runs `systemctl restart` when the server sends an
> HMAC-signed action. Both are opt-in and both are what a dropper does too, so
> a generic rule cannot tell them apart. It took `agent.sh` on 2026-09-02 and
> `agent_openwrt.sh` 0.1.9 on 2026-09-24 - the version whose lock takeover
> walks `/proc` and kills a hung run. Any version bump can trip it, so
> whitelist all four paths in the scanner, not only the one it took
> (cPGuard: quarantine entry -> restore, then add `public_html/status/agent.sh`,
> `agent.py`, `agent.ps1` and `agent_openwrt.sh` to the ignore list) - the
> agents are also downloadable straight from
> [BKPepe/monitoring-agent](https://github.com/BKPepe/monitoring-agent). The
> deploy checks all four agent URLs after every upload, so a quarantined agent
> fails the deploy instead of silently breaking self-update. While one is
> missing the server offers no update for it (it reads the version from the
> file), so agents stay on their old version rather than fetch an error page.

### OpenWrt Router Agent (`agent_openwrt.sh`)
Supports Wi-Fi client tracking, conntrack table %, per-interface traffic, and HMAC-signed Remote Actions (`restart_wan`, `restart_wireguard`, `reboot_router`, `renew_dhcp`).

### Windows Agent (`agent.ps1`)
Execute via Windows Task Scheduler every 5 minutes:
```powershell
powershell.exe -ExecutionPolicy Bypass -File C:\bloodkings\agent.ps1
```

---

## 6. Integrations & Exporters
* **Prometheus Exporter**: Endpoint `/status/metrics.php?token=YOUR_TOKEN` exposes metrics for Grafana and Prometheus scrapers.
* **SVG Badges**: Embed shields.io vector status badges in READMEs: `<img src="https://your-server.com/status/badge.php?id=12&type=status">`
* **Notifications**: Configure Pushover, PagerDuty, Discord, Telegram, Slack, Webhooks, or SMTP Email alerts in `admin.php`.

---

## License
Distributed under the MIT License.
