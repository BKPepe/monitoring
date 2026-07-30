# Blood Kings Monitoring

Self-hosted, distributed monitoring platform. This repository holds the public
landing page (monitoring.bloodkings.eu) and its backend API, plus a reference
self-hosted deployment of the monitoring dashboard itself — the same thing you'd
run on your own server.

The core idea: you own your infrastructure, your agents, and your data. This is
not another hosted uptime-checker SaaS.

## Project structure

This is an NPM workspaces monorepo (`apps/site`, `apps/worker`). `apps/status` is
a standalone PHP application outside the NPM workspaces — the reference
self-hosted implementation of the monitoring dashboard, i.e. what you deploy on
your own hosting:

```
/
├── apps/
│   ├── site/                      # Astro v5 - static landing page (SSG), EN + CS
│   ├── worker/                    # Cloudflare Worker + Hono - backend API for the landing page
│   └── status/                    # PHP - self-hosted monitoring dashboard (reference deployment)
├── .github/
│   └── workflows/
│       └── deploy.yml             # CI/CD to Cloudflare Pages & Workers
└── package.json                   # NPM workspaces config (site, worker)
```

Distributed agents (HTTP probes from GitHub Actions/Cloudflare Worker, and the
VPS host-metrics agents for Python/Bash/PowerShell/Docker) live in a separate
repository, [BKPepe/monitoring-agent](https://github.com/BKPepe/monitoring-agent),
since each self-hoster deploys those independently from the main dashboard.

### apps/status — self-hosted monitoring dashboard

A complete, framework-free PHP application (MySQL) for your own status page and
admin panel: monitors, notifications (email/SMS/WhatsApp/Discord/Slack/Telegram),
VPS agents, and a Prometheus exporter. Installation guide in
[apps/status/README.md](apps/status/README.md).

**Language:** the public landing page (`apps/site`) ships in English and Czech
(`/cs/`). The self-hosted dashboard (`apps/status`) is currently Czech-only;
English support is in progress.

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
*   `GET /api/versions` — Latest release version of the server and agents.
*   `GET /api/changelog` — Version history formatted for a timeline.
*   `GET /api/status` — Proxies status and response times of the main monitoring nodes.
*   `GET /api/test?url=<url>` — Real speed test (ping) of a given address from the Cloudflare network (used by the Playground).

---

## 🚀 Deployment (CI/CD)

Deployment runs automatically on every push to `main` via GitHub Actions
(`.github/workflows/deploy.yml`).

### GitHub secrets required:
1.  `CLOUDFLARE_API_TOKEN` — API token with Pages and Workers deploy permissions.
2.  `CLOUDFLARE_ACCOUNT_ID` — Your Cloudflare account ID.

---

## 📈 Design goals
*   **Performance:** 100/100 on Lighthouse metrics via static Astro compilation with no heavy client-side JS framework, responsive from 360px to 4K.
*   **Premium design:** minimal, dark-mode-first, red used only as an accent, smooth Vercel-style animations.
*   **Interactive elements:** a dashboard preview widget with clearly labeled demo data (`SIMULATED DATA`), an interactive SVG agent map, and a working ping-test console (Playground).
