# apps/worker

The Cloudflare Worker behind the landing page (Hono). It answers the site's
data requests from Cloudflare's edge and watches the status API from outside.

## Endpoints

| Route | What it answers | Cache |
|---|---|---|
| `GET /api/stats` | Repository stats (stars, forks, contributors, issues) | 1 h |
| `GET /api/versions` | The latest release of the monitoring server | - |
| `GET /api/agents` | Agent versions read from the published scripts | 1 h |
| `GET /api/changelog` | Release history for the site's timeline | 30 min |
| `GET /api/contributors` | The contributor list for the About page | 1 h |
| `GET /api/status` | The status API's public summary | 5 min |
| `GET /api/test?url=` | One HTTP GET from the edge (the Playground) | - |
| `GET /api/watchdog` | The watchdog's verdict now and its alert channel | - |
| `POST /api/watchdog/test` | A test alert; 404 without `WATCHDOG_TEST_TOKEN` | - |

## Watchdog

A cron trigger (`*/5 * * * *` in `wrangler.jsonc`) checks the status API at
`WATCHDOG_TARGET_URL` and sends an alert to `WATCHDOG_DISCORD_WEBHOOK` when it
fails. Without a target it watches bloodkings.eu, so a self-hosted install sets
its own before deploying.

## Configuration

Secrets and variables (`wrangler secret put …`): `GITHUB_TOKEN` (optional, a
higher GitHub rate limit), `WATCHDOG_TARGET_URL`, `WATCHDOG_DISCORD_WEBHOOK`,
`WATCHDOG_TEST_TOKEN`.

## Development

```bash
npm run dev -w apps/worker      # local wrangler dev server
npm run test -w apps/worker     # unit tests
npm run deploy -w apps/worker   # deploy to your Cloudflare account
```
