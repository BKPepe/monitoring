# Blood Kings - Status & Telemetry Monitoring System

An open-source, ultra-fast server and service status monitoring application built with PHP and MySQL. It features active uptime probing (HTTP/S, TCP, Minecraft, TeamSpeak 3, Discord, cPanel) and passive system resource telemetry pushed from remote agents (Linux, Windows, Docker, OpenWrt).

---

## Folder Structure
* `index.php` - Public status dashboard with responsive dark glassmorphic design.
* `admin.php` - Admin control panel for monitor CRUD, 2FA setup, webhooks, and system configuration.
* `cron.php` - Active probing runner executed via server CLI cron.
* `node_api.php` - API endpoint for distributed multi-location probing nodes.
* `node_client.php` - Standalone client script deployed to remote hostings for regional probing.
* `agent_api.php` - Ingestion API for VPS and OpenWrt system telemetry agents.
* `agent.sh` / `agent.py` - Native Bash and Python telemetry agents for Linux hosts.
* `agent_openwrt.sh` - Lightweight OpenWrt router agent with Wi-Fi, conntrack, and signed Remote Actions.
* `agent.ps1` - PowerShell telemetry agent for Windows hosts.
* `badge.php` - Dynamic vector SVG badge generator (`badge.php?id=12&type=status|uptime`).
* `widget.php` - Compact iFrame status card for embedding on external websites.
* `report.php` - SLA report generator (HTML/CSV format).
* `db.php` - PDO database connection initializer with auto-migrations.
* `functions.php` - Core measurement library, alert routing (Pushover, PagerDuty, Telegram, Discord, Slack), and TOTP engine.
* `schema.sql` - Complete MySQL database structure.

---

## 1. Quick Installation (Central Server)
1. Upload the `status/` directory to your web server document root (e.g., `public_html/status/`).
2. Import `schema.sql` into your MySQL/MariaDB database.
3. Configure `config.php`:
   * Copy `config.sample.php` to `config.php`.
   * Fill in your database connection credentials:
     ```php
     define('DB_HOST', '127.0.0.1');
     define('DB_NAME', 'bloodkings_status');
     define('DB_USER', 'bloodkings_user');
     define('DB_PASS', 'YourStrongPassword123!');
     ```

---

## 2. Automated Deployment via GitHub Actions
To deploy automatically without committing passwords:
1. In your GitHub repository, go to **Settings** -> **Secrets and variables** -> **Actions**.
2. Add a secret named `STATUS_CONFIG_PHP` containing your production `config.php` code.
3. The GitHub Actions workflow will automatically deploy updates to your hosting server on every `git push`.

---

## 3. Active Probing Cron Setup
To execute active probes every 1–5 minutes, set up a server CLI cron job:
```bash
* * * * * php -q /path/to/status/cron.php >/dev/null 2>&1
```

---

## 4. Distributed Multi-Location Probing Nodes
To measure uptime and latency from multiple global geographic locations (e.g. Frankfurt, New York, Prague):
1. In `admin.php` -> **System Settings**, configure a secret **Cron / Node API Key**.
2. Upload `node_client.php` to your secondary hosting location.
3. Edit `node_client.php` header:
   ```php
   $central_api_url = 'https://monitoring.bloodkings.eu/status/node_api.php';
   $node_key        = 'YourSecretAPIKey';
   $node_location   = 'Frankfurt, DE';
   ```
4. Run `node_client.php` via cron on the secondary host. The main dashboard will group response times under the "Response from geographic nodes" grid.

---

## 5. VPS & Router System Telemetry Agents
Install lightweight telemetry agents on target machines:

### Linux Agent (`agent.sh` / `agent.py`)
```bash
python3 agent.py --interval 60 --url https://your-server.com/status/agent_api.php --key YOUR_AGENT_KEY
```
Or run auto-registration:
```bash
./agent.sh --register --token="YOUR_REGISTRATION_TOKEN" --url="https://your-server.com/status/agent_api.php"
```

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
* **iFrame Card**: Embed live status cards: `<iframe src="https://your-server.com/status/widget.php?id=12"></iframe>`
* **Notifications**: Configure Pushover, PagerDuty, Discord, Telegram, Slack, Webhooks, or SMTP Email alerts in `admin.php`.

---

## License
Distributed under the MIT License.
