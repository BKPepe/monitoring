# Blood Kings Monitoring

Self-hosted, distributed monitoring platform. This repository holds the public
landing page (monitoring.bloodkings.eu) and its backend API, plus a reference
self-hosted deployment of the monitoring dashboard itself — the same thing you'd
run on your own server.

The core idea: you own your infrastructure, your agents, and your data. This is
not another hosted uptime-checker SaaS.

## Project structure

This is an NPM workspaces monorepo (`apps/site`, `apps/worker`, `apps/monitor`).
`apps/status` is a standalone PHP application outside the NPM workspaces — the
reference self-hosted implementation of the monitoring dashboard, i.e. what you
deploy on your own hosting. `apps/server` is a Go rewrite of that backend, not
yet deployed:

```
/
├── apps/
│   ├── site/                      # Astro v5 - static landing page (SSG), EN + CS
│   ├── worker/                    # Cloudflare Worker + Hono - backend API for the landing page
│   ├── monitor/                   # React SPA - monitoring app, served at bloodkings.eu/app/
│   ├── status/                    # PHP - self-hosted monitoring dashboard (live backend)
│   └── server/                    # Go + Postgres - future backend replacing apps/status (not deployed)
├── agents/                        # git submodule -> BKPepe/monitoring-agent
├── .github/
│   └── workflows/                 # deploy.yml (site+worker), deploy-hosting.yml (FTP:
│                                  # status, app, root in one queue), release.yml, codeql.yml
└── package.json                   # NPM workspaces config (site, worker, monitor)
```

Host-metrics agents ship in two places: the reference scripts deployed together
with the dashboard live in `apps/status/` (`agent.sh`, `agent.py`, `agent.ps1`,
`agent_openwrt.sh`), and the standalone distribution lives in the
[BKPepe/monitoring-agent](https://github.com/BKPepe/monitoring-agent) repository
(the `agents/` submodule here).

### apps/status — self-hosted monitoring dashboard

A complete, framework-free PHP application (MySQL) for your own status page and
admin panel: monitors, notifications (email/SMS/WhatsApp/Discord/Slack/Telegram),
VPS agents, and a Prometheus exporter. Installation guide in
[apps/status/README.md](apps/status/README.md).

**Install on your own hosting:** PHP 8.2+, MySQL/MariaDB and a cron job every
minute. The step-by-step guide is at
[monitoring.bloodkings.eu/download/#server](https://monitoring.bloodkings.eu/download/#server)
(the same guide as the site's docs Quick Start: one component,
`apps/site/src/components/InstallServer.astro`, with its words in
`apps/site/src/i18n`). Each release attaches
`bloodkings-monitoring.zip` (`status/` with the agents + the built `app/`, no
`config.php`) and `SHA256SUMS`; the newest one is always at
`https://github.com/BKPepe/monitoring/releases/latest/download/bloodkings-monitoring.zip`.

**Language:** the public landing page (`apps/site`) ships in English and Czech
(`/cs/`). The self-hosted dashboard (`apps/status`) and the React app
(`apps/monitor`) are fully bilingual too — Czech and English (`?lang=cs|en` on
the status page, a language switcher in the app).

---

## ⚡ Local development

### Install dependencies
From the project root:
```bash
npm install
```

### Run the dev servers
Run both projects at once, or separately:

*   **Astro site:**
    ```bash
    npm run dev:site
    ```
    Available at `http://localhost:4321`.

*   **Cloudflare Worker API:**
    ```bash
    npm run dev:worker
    ```
    Runs locally on port 8787.

---

## ☁️ Cloudflare Worker API endpoints

The worker aggregates, filters and caches (1 hour) requests to the GitHub API to
stay under rate limits:

*   `GET /api/stats` — Aggregated repo stats (stars, forks, contributors, issues).
*   `GET /api/versions` — Latest release of the monitoring server (`monitoring: null` before the first release).
*   `GET /api/agents` — Agent versions read from the published scripts (null + 503 when unreadable).
*   `GET /api/changelog` — Version history formatted for a timeline.
*   `GET /api/status` — Proxies status and response times of the main monitoring nodes.
*   `GET /api/test?url=<url>` — One HTTP GET to the given address from Cloudflare's edge: status code, response time and redirect target (used by the Playground).

---

## 🚀 Deployment (CI/CD)

Deployment runs automatically on every push to `main` via GitHub Actions
(`.github/workflows/deploy.yml`). The hosting parts (`status`, `app`, `root`)
go over FTPS through `.github/workflows/deploy-hosting.yml`: one run per push
deploys every part changed since the last successful run, one part after
another, so FTP logins never overlap and no part is dropped from the queue.

### GitHub secrets required:
1.  `CLOUDFLARE_API_TOKEN` — API token with Pages and Workers deploy permissions.
2.  `CLOUDFLARE_ACCOUNT_ID` — Your Cloudflare account ID.

---

## 🔢 Versioning

After the history squash (2026-08) both the app and the agents restarted at
**0.0.1** and version independently:

- **App** (`apps/monitor/package.json`): bump **patch** for fixes, **minor**
  for features. The footer shows `v<version> (<git hash>)` linking to the
  exact deployed commit - the hash is the precise identity, the number is the
  human-readable milestone.
- **Agents** (`AGENT_VERSION` in each `agents/vps-agent/*` file): each of the
  four versions independently, same bump rule. Self-update triggers on any
  version *difference* (not ordering), so a bump - or even a reset - reaches
  the fleet on its next report cycle.
- **Releases** (`.github/workflows/release.yml`): pushing a `v*` tag builds the
  app, copies the agents into `status/`, and publishes
  `bloodkings-monitoring.zip` + `SHA256SUMS` (plus the agent scripts) as the
  latest release - never as a pre-release, because `releases/latest` (and so
  the site's download button and "latest release" line) skips those. Tags are
  pushed by hand; no workflow creates one.

## 🔍 Static analysis

CodeQL scans **only `javascript-typescript` and `go`** — GitHub CodeQL has no
PHP support at all. Its green tick therefore says nothing about the PHP backend,
where every authorisation, CSRF and token check lives. A security audit on
2026-08-23 found two PHP-side issues that CodeQL was structurally incapable of
seeing. The `phpstatic` job in the Quality Gate covers that blind spot:

- **PHPStan** (`phpstan.neon`, level 0, no baseline) — catches what `php -l`
  cannot: calls to functions, classes or methods that do not exist, and wrong
  argument counts. That is the bug that once left `badge.php` returning 500 for
  months. Level 0 reports zero false positives here, so every failure is real.
- **Psalm taint analysis** (`psalm.xml`) — follows untrusted input
  (`$_GET`/`$_POST`/`$_COOKIE`/`$_SERVER`) to dangerous sinks (echo into HTML,
  SQL, `include`). Its first run found a real XSS in `admin.php`.
  `psalm-taint-baseline.xml` holds the findings review judged false — chiefly
  `echo json_encode()` on JSON endpoints, plus allowlist and `isset()` guards
  Psalm cannot read. **The baseline is a list of reviewed non-issues, not a list
  of accepted bugs**; anything new is unbaselined and fails the build.

Run them locally (tools are not vendored — this app ships without Composer):

```bash
phpstan analyse --memory-limit=1G     # or: php phpstan.phar analyse …
psalm --taint-analysis --no-cache
```

## 📈 Design goals
*   **Performance:** 100/100 on Lighthouse metrics via static Astro compilation with no heavy client-side JS framework, responsive from 360px to 4K.
*   **Premium design:** minimal, dark-mode-first, red used only as an accent, smooth Vercel-style animations.
*   **Interactive elements:** a dashboard widget fed by live data from the status API (with an honest error state when the API is unreachable), an interactive SVG agent map, and a playground that runs one real HTTP check from Cloudflare's edge and shows only what it measured (status, time, redirect target).

## License

MIT, see [LICENSE](LICENSE). The agents in the `agents/` submodule
(BKPepe/monitoring-agent) carry the same license in their own LICENSE file.
Third-party code kept in the repository under its own license (PHPMailer,
LGPL-2.1, in `apps/status/lib/`) is listed in
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
