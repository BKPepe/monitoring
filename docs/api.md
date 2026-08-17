# Blood Kings Monitoring API

> 🇬🇧 English version (this page) · 🇨🇿 [Česká verze](api.cs.md)

Reference for the HTTP interface of `apps/status`. Written from the code, not
from intent - for every endpoint, what counts is what `api.php` does, not what
it ought to do. Where the two differ, it is marked **Careful**.

- **Base:** `https://bloodkings.eu/status/`
- **Format:** JSON (`Content-Type: application/json; charset=utf-8`); exceptions
  are noted per endpoint (SVG, Prometheus text, HTML).
- **Error message language:** Czech - they are addressed to the administrator,
  not to an end user.

---

## The rule that holds across the whole API

**What was not measured is `null`. Never zero, never a placeholder string.**

This is not a style note, it is a contract. `"cpu": null` means "we do not have
this value", not "the processor is idle". The client has to tell them apart - in
our interface `null` renders as a dash.

Likewise `"uptime": null` on a freshly created monitor is **not** 100 %. An
average of zero measurements does not exist; computing it as 100 % manufactures
a figure nobody measured.

Linters in CI enforce this (`run_honesty_lint.php` and friends), so a regression
of that kind does not slip through review - it fails the gate.

---

## Authentication

The application has no API tokens for third parties. There are four modes:

| Mode | How it is proven | Who uses it |
|---|---|---|
| **Public** | nothing | status page, watchdog |
| **Logged in** | session cookie (`action=login`) | React SPA |
| **Administrator** | session cookie + role `admin` | configuration management |
| **Device key** | `agent_key` / `token` in the body or URL | agents, probes, heartbeat |

Login:

```http
POST /status/api.php?action=login
Content-Type: application/json

{"username": "admin", "password": "…"}
```

The response sets a session cookie. Every further call has to send it
(`credentials: 'include'` in `fetch`). Logout: `action=logout`.

Session state: `GET /status/api.php?action=session`.

### What an anonymous visitor sees

Public responses pass through a filter that strips everything carrying network
identity out of the `details` structure - IP addresses, MACs, SSIDs, hostnames,
endpoints, serial numbers, tokens and passwords. The filter works from the shape
of the key name rather than an enumeration, so it also catches a metric that
does not exist yet. Aggregates (`cpu`, `ram`, client counts) stay.

---

## Errors

| Code | Meaning |
|---|---|
| 400 | A parameter is missing or invalid |
| 401 | Not logged in |
| 403 | Logged in, but without the `admin` role |
| 404 | The object does not exist (or must not be revealed - see heartbeat) |
| 405 | Wrong HTTP method |
| 500 | Server-side error |
| 503 | Database or a downstream service is unavailable |

Error body: `{"error": "Description in Czech"}`. Agent endpoints return
`{"success": false, "message": "…"}` instead - a historical difference;
unifying it would break deployed agents.

---

## Data collection health

### `GET api.php?action=collection_health`

**Public.** Answers a single question: is the cron still running?

It exists because when data collection stops, the application does not break -
it keeps showing the last known states and looks healthy. Of all the ways
monitoring can fail, this is the worst, because it does not announce itself.

```json
{
  "lastRunAt": "2026-08-10T19:42:11+02:00",
  "ageSecs": 62,
  "maxAgeSecs": 900,
  "stale": false,
  "lastDurationMs": 4180,
  "monitorsChecked": 14,
  "serverTime": "2026-08-10T19:43:13+02:00"
}
```

| Field | Meaning |
|---|---|
| `lastRunAt` | End of the last **completed** run; `null` = a cron writing this has never run |
| `ageSecs` | Age in seconds; `null` when `lastRunAt` is `null` |
| `maxAgeSecs` | Limit from the `collection_max_age_secs` setting (default 900) |
| `stale` | `true` when the age is past the limit **or** the cron never ran |
| `lastDurationMs` | Run duration; `null` = not measured |

The endpoint is public on purpose - the watchdog runs elsewhere and has nothing
to log in with. Nothing sensitive is exposed here.

It is watched by a Cloudflare Worker (`apps/worker`) on a 5-minute cron. That
runs outside cPanel, so it works even when the whole server is dead. Configuring
the alert channel:

```sh
cd apps/worker && npx wrangler secret put WATCHDOG_DISCORD_WEBHOOK
```

Without it the watchdog keeps checking but only logs - and admits as much on
`GET /api/watchdog`, where `alertChannelConfigured: false`.

### Does that channel actually work?

"Configured" and "working" are not the same thing. A deleted channel, a
regenerated token or a typo in the URL look identical from the outside to a
correctly configured webhook, so the watchdog can report
`alertChannelConfigured: true` for months while having nowhere to send an alert.
`GET /api/watchdog` therefore returns two more fields:

| Field | Meaning |
|---|---|
| `alertChannelValid` | `true` = Discord confirms the webhook, `false` = invalid, `null` = could not be verified |
| `alertChannelDetail` | The reason when `false` or `null`; otherwise `null` |

Verification is a GET to the webhook address (Discord returns the webhook object
there), so nothing is posted to the channel. The result is cached for an hour -
otherwise hammering `/api/watchdog` would make the worker hammer Discord.

### `POST /api/watchdog/test`

The GET check spots a deleted webhook, but not whether a message actually
arrives **in the channel** - missing permissions only show up on send. This
endpoint walks the whole path of a real alert:

```sh
cd apps/worker && npx wrangler secret put WATCHDOG_TEST_TOKEN   # once
curl -X POST -H "Authorization: Bearer $TOKEN" https://api.bloodkings.eu/api/watchdog/test
```

The response is `{"delivered": true, "detail": null}` (HTTP 200), or HTTP 502
with the reason in `detail`. A message marked as a test arrives in the channel.

Without `WATCHDOG_TEST_TOKEN` set, the endpoint answers 404 as if it did not
exist - otherwise anyone could flood the channel. The token is compared in
constant time so it cannot be guessed character by character.

---

## Heartbeat: the job reports itself

The opposite direction from the rest of the monitoring. An active check can only
see what it can reach over the network - a backup that starts at three in the
morning and fails quietly is invisible to it. So the job reports itself.

### `GET|POST heartbeat.php?token=…`

**Token authentication.** The token is 48 hexadecimal characters from a CSPRNG
and is the only thing the endpoint authorises.

| Parameter | Required | Meaning |
|---|---|---|
| `token` | yes | May also be passed in the path: `heartbeat.php/TOKEN` |
| `status` | no | `fail` = the job is reporting its own failure. Anything else (including a typo) is success |
| `msg` | no | Description, max. 255 characters |

```sh
# at the end of the backup script
curl -fsS -m 10 "https://bloodkings.eu/status/heartbeat.php?token=TOKEN"

# when the job fails
curl -fsS -m 10 "https://bloodkings.eu/status/heartbeat.php?token=TOKEN&status=fail&msg=tar%20exited%20with%202"
```

Response: `{"ok": true, "monitor": "Nightly backup", "result": "ok", "receivedAt": "…"}`

An invalid token returns **404**, and so does a malformed one - token validity
cannot be discovered by probing.

The endpoint only records the signal. The state is evaluated by the cron on its
next run, so there is a delay of up to one cycle (1-5 minutes) between a
reported failure and a notification.

### How the state is evaluated

| State | When |
|---|---|
| `up` | The signal arrived within `interval + grace` and the job reports success |
| `down` | The job did not report in time, **or** reported a failure |
| `unknown` | It has never reported, or has no interval configured |

The difference between `down` and `unknown` is essential: a monitor that never
received a signal **is not down** - we know nothing about it. Alerting on an
outage that did not happen is the same lie as a fabricated zero in a chart.

A reported failure takes precedence over signal age. Otherwise a quietly failing
backup would look healthy just because the cron is running.

### `GET api.php?action=heartbeat_info&monitor_id=…`

**Admin.** Returns the URL to configure in the job, the current state and the
time of the last signal. `regenerate=1` produces a new token - the old URL stops
working immediately.

The token is returned **only here**. It is not in the regular monitor listing:
if it leaked, a stranger could send heartbeats on your behalf and the monitor
would stay green long after the backup stopped running.

---

## Feeds and escalation

### `GET rss.php[?page=slug]`

**Public.** An RSS 2.0 feed of outages and their resolutions. Without a
parameter it covers all monitors; with `page`, only those on that status page.

A hidden page returns **404** just like a nonexistent slug - RSS cannot be used
to bypass the visibility a page has on the web.

An incident opening and its resolution are **two separate items** with different
`guid`s (`incident-12-opened`, `incident-12-resolved`). If the resolution were
appended to the original item, a reader would never show it to a subscriber - a
`guid` shown once is never listed again.

The resolution item only exists when `resolved_at` is actually set. For an
ongoing incident it is not computed from "now".

The feed is linked from the status page head (`<link rel="alternate">`), so
readers find it on their own.

### Escalation of unacknowledged outages

Not an endpoint but cron behaviour. An outage alert used to be sent once and
that was that; if nobody saw it, the outage carried on.

Settings (admin → Notifications):

| Key | Meaning |
|---|---|
| `escalation_enabled` | `1` enables it; off by default |
| `escalation_after_mins` | Time to acknowledge, default 15 |
| `escalation_webhook_url` | The escalation channel - deliberately different from regular alerts |

An incident escalates when it is **unresolved and unacknowledged**
(`acknowledged_at` is empty) and the deadline has passed since it started. Each
incident at most once - the `escalated_at` stamp prevents repeats on every cron
run.

Without a channel configured, the stamp is **not** written. If it were, the
incident would look escalated and would never speak up again once a channel was
added - a silent failure exactly where the backstop is supposed to work.

---

## Endpoints that used to be missing

The actions below were called from the UI but did not exist in `api.php`.
Because an unknown action returned the default service overview with code 200,
every such call looked like a success. **Today an unknown action returns 400**
and a lint guards it (`run_api_action_lint.php`).

| Endpoint | Access | Description |
|---|---|---|
| `action=export_csv&monitor_id=&days=` | public | Check history of a monitor as CSV. The error-message column is only included for a logged-in user - the monitor page is public and the messages carry internal names |
| `action=save_annotation` | admin | A note on a chart (`monitor_id`, `metric_key`, `timestamp`, `note`) |
| `action=annotations&monitor_id=&metric=&hours=` | logged in | Notes for rendering. An anonymous caller gets an empty list, not a 403 - a chart without notes is not an error |
| `action=forgot_password` | public, POST | Sends a password reset link. The response is identical for existing and nonexistent addresses |
| `action=setup` | public, POST | Creates the first administrator. **Only into an empty users table**, otherwise 409 |
| `action=user_audit_log&limit=` | admin | The actual audit log (who logged in, who changed what) |

> **Careful with the names:** `audit_logs` (with an "s") returns **check results
> from the cron**, not user actions. The user log is `user_audit_log`. React
> used to show the former under a heading promising logins, so the security and
> configuration filters could never find anything.

`action=session` now also returns `installed` (at least one user exists) and the
real email of the logged-in user - it used to return a hardcoded
`admin@bloodkings.eu` regardless of who was signed in.

---

## Monitors

### `GET api.php?action=monitors`

**Logged in.** List of monitors with their last state, response time and agent
metrics.

Response time comes from `monitor_logs`, the CPU/RAM/HDD values from
`vps_metrics` - they are not columns of the `monitors` table. A missing value is
`null`.

### `POST api.php?action=save_monitor`

**Admin.** Creates (`id: 0`) or edits a monitor. The body is JSON.

Types: `web`, `port`, `vps`, `openwrt`, `minecraft`, `teamspeak`, `discord`,
`heartbeat`, `agent_service`.

Selected parameters:

| Parameter | Applies to | Note |
|---|---|---|
| `target` | everything except `vps`, `openwrt`, `heartbeat` | Required where it applies |
| `body_keyword` | `web` | The response body must contain this string |
| `heartbeat_interval` | `heartbeat` | **Seconds.** Required, minimum 60 |
| `heartbeat_grace` | `heartbeat` | Seconds; `null` = checked exactly on the interval |
| `latency_threshold_ms` | all | `null` = slowdown alerts disabled |
| `preset_id` | all | `null` = the monitor keeps its own metric selection |
| `enabled_metrics` | all | Array of keys; empty = recommended defaults |
| `allowed_actions` | `openwrt` | Only with `remote_actions_enabled` |

Passwords (`sq_password`, `rcon_password`) are only overwritten when a new value
is supplied - an empty field does not erase a stored password. The heartbeat
token is **not regenerated** on edit: the job has it hardcoded in its curl
command.

### `POST api.php?action=delete_monitor`

**Admin.** Body `{"id": 12}`.

---

## Metrics and history

| Endpoint | Access | Description |
|---|---|---|
| `action=metric_series&monitor_id=&metric=&period=` | public | One metric over time |
| `action=metric_series_batch` | public | Several metrics in one query |
| `action=metric_detail&monitor_id=&metric=` | public | Context for the metric detail page |
| `action=process_history&monitor_id=&kind=&at=&radius=` | public | Which processes were running around a point in time |
| `action=metrics_history&monitor_id=&period=` | public | Agent metric history |
| `action=daily_uptime&days=` | public | Daily availability from `uptime_daily` |
| `action=uptime_windows` | public | Per-monitor availability for 24 h / 7 d / 30 d / 90 d in one pass; an unmeasured window is `null`, never 100 |
| `action=check_stages&monitor_id=` | public | Check breakdown (DNS/TCP/TLS/HTTP, ServerQuery) |
| `action=regions&days=` | public | Availability by measurement location (`checked_from`) |
| `action=public_status` | public | Summary for the public page (counts, average availability) |
| `action=badge[&monitor_id=][&type=uptime][&lang=en]` | public | Embeddable SVG badge (60 s cache): live state, or 30-day availability with `type=uptime`; without `monitor_id` it summarises the fleet, an unknown monitor is 404 |
| `action=websites_overview` | public | Sites with certificates and availability in the window |
| `action=monitor_insights&monitor_id=` | public | Derived observations for one monitor |
| `action=dashboard_insights&limit=` | public | The same across monitors, for the overview |
| `action=ui_config` | public | Appearance settings for the frontend (logo, names) |
| `action=alerts_read_state` | logged in | Read-alert watermark (`readUpToId`) |
| `action=convert_to_agent_check` | admin | Turns an agent-watched process into a monitor of its own |

**A note on long-range data:** raw logs are purged after 30 days. A yearly SLA is
therefore computed from the `uptime_daily` rollup, not from logs. Responses
always state which period a value really covers - they never pass a thirty-day
window off as a year.

### Values for `period`

| Value | Window | Source |
|---|---|---|
| `15m`, `1h`, `6h`, `12h`, `24h` | 15 minutes to a day | `vps_metrics` / `monitor_logs` |
| `7d`, `30d` | week, month | the same |
| `90d`, `180d`, `1y` | quarter to year | `metrics_daily` (daily average) |

An unknown value falls back to a day. For long periods the response carries
`resolution: "daily"` - a point is a daily average, not an individual
measurement, and the client has to admit that, or the user would read a
precision out of the chart that the data does not have.

> Until 12 Aug 2026 the window was computed in hours and two periods came out
> wrong: `15m` returned an hour and `6h` returned 24 hours. The label in the UI
> claimed something other than the chart showed. It is now guarded by
> `run_tests.php` (unit) and `run_api_tests.php` (against a real database).

### `action=metric_detail`

Context for the metric detail page - what the metric means, which thresholds the
monitor has, which related metrics it reports at all and what happened around it:

```json
{
  "monitor": { "id": 6, "name": "Turris", "type": "openwrt", "assetId": 6 },
  "metric": { "key": "cpu", "label": "CPU usage", "unit": "%", "counter": false },
  "thresholds": { "warning": 75, "critical": 90 },
  "related": [{ "key": "ram", "label": "Memory usage", "unit": "%", "latest": 41.2 }],
  "events": [{ "t": 1755000000000, "type": "status_change", "label": "Recovered" }]
}
```

Statistics (current, average, peak) are **deliberately not sent** - the client
computes them from the very points it draws, so after switching the period they
cannot describe a different window than the chart. `thresholds.critical: null`
means no threshold is set and no band is drawn; `related` only contains metrics
the monitor actually reported in its latest measurement, so a link never leads
into an empty chart.

### `action=process_history`

Answers the question a chart cannot: CPU hit 90 % at 19:40, but because of what?

| Parameter | Meaning |
|---|---|
| `monitor_id` | Required |
| `kind` | `cpu` (default) or `ram` - which ranking to read |
| `at` | Centre of the window, unix seconds. Required |
| `radius` | Half-width in minutes, default 10, max 180 |

```json
{
  "samples": [{ "at": "2026-08-14 19:40:02", "name": "hostapd", "pid": 1234, "cpuPct": 87.5, "ramMb": 12.5 }],
  "from": "2026-08-14 19:30:02",
  "to": "2026-08-14 19:50:02",
  "enabled": true,
  "pruned": false
}
```

An empty `samples` array has three different causes and the client must tell
them apart: `enabled: false` means collection is switched off, `pruned: true`
means the window was thinned down to peaks and none fell here, and otherwise
there simply are no samples for that moment. Collapsing them into "no data"
would let a disabled feature look like an idle machine.

Retention is configurable (`process_history_days`, and optionally
`process_history_peak_after_days` with `process_history_peak_pct`), because this
is the fastest-growing table in the database: ten rows per monitor per minute.
Measured: 1 728 000 rows occupy 253 MB and this lookup takes 0.089 ms, because
the covering index narrows it to 60 rows. No page queries the table on load.

---

## Incidents and reports

| Endpoint | Access | Description |
|---|---|---|
| `action=incidents` | public | List of incidents |
| `action=create_incident` | logged in | Manual creation |
| `action=incident_action` | logged in | `op`: acknowledge / resolve / postmortem |
| `action=events&monitor_id=&limit=` | public | Monitor events |
| `action=sla_report&days=` | public | SLA overview |
| `action=audit_logs&limit=` | public | Latest checks across monitors |

> **Careful:** `audit_logs` and `sla_report` are currently unauthenticated and
> return monitor names and error message texts. Those can contain internal
> hostnames or infrastructure detail. It is not a design decision, it is the
> state of the code - worth deciding whether to put them behind a login.
>
> Checked on 13 Aug 2026: the 200 most recent records, including all 50 failures,
> carried only generic messages ("Discord API is not responding (code 503)",
> "cURL error: Operation timed out"), with no internal addresses.

---

## Configuration and management

| Endpoint | Access | Description |
|---|---|---|
| `action=get_settings` / `save_settings` | admin | Global settings |
| `action=presets` / `save_preset` / `delete_preset` / `assign_preset` | public read, admin write | Metric profiles |
| `action=status_pages` / `save_status_page` / `delete_status_page` | logged in | Public status pages |
| `action=dashboard_layout` | public read, admin write | Tile order and visibility |
| `action=users` | logged in | User list |
| `action=export_config` | logged in | Configuration export without secrets |
| `action=generate_metrics_token` | admin | Token for the Prometheus exporter |
| `action=upload_logo` | admin | Status page logo |
| `action=send_digest&period=` | admin | Manual digest send |
| `action=trigger_remote_action` | admin | Action on a router (allowed ones only) |
| `action=discovered_services` / `import_discovered_service` | admin | Service Discovery |
| `action=get_subscriptions` / `save_subscriptions` | logged in | Alert subscriptions |
| `action=my_profile` / `update_profile` | logged in | Own profile: contacts, notification channels, e-mail language, password change (requires the current password) |
| `action=oauth_unlink` | logged in | Unlink the OAuth sign-in (requires the current password) |
| `action=totp_setup` / `totp_confirm` / `totp_disable` | logged in | Two-factor enrollment: the secret stays in the session until a code confirms it; disabling requires the password |
| `action=set_password` | public (one-time token) | Set a password from an invite or reset e-mail; the token is consumed on first success |

`export_config` deliberately omits passwords, tokens and agent keys - it exists
to back up settings, not to clone access.

The settings key list lives in exactly one place (`bk_settings_keys()` in
`db.php`) and `run_settings_parity_lint.php` checks that the UI never asks for a
key the server does not know. It used to exist three times and drifted, which
silently erased WhatsApp settings: they could be saved but were never read back,
so the form showed empty fields and the next save overwrote the real values.

---

## Device interfaces

### `POST agent_api.php`

Telemetry from agents (VPS, OpenWrt). Authorised by the `agent_key` field in the
body. Requires POST and valid JSON, otherwise 405 / 400.

The server accepts keys it does not know in advance - otherwise a new metric from
an agent would vanish silently. Limits apply though: a typed server-side value
always wins, credentials are never taken over, the name has to look like an
identifier, an array is capped at 8 KB and at most 64 new keys are added at once.

`action_result` arrives as a separate lightweight POST - the confirmation of a
performed Remote Action. It carries no telemetry fields, so it is handled before
their validation.

### `GET|POST node_api.php?action=get_monitors|post_results`

Interface for remote measurement nodes. Authorised by a shared `cron_key`
(`hash_equals`, so without a timing side channel).

A node downloads the list of monitors to check and posts results back, including
`checked_from`. That value is what fills `action=regions`.

> **Status (verified 15 Aug 2026):** no own node runs through `node_client.php`
> yet, but measurements have long stopped coming from a single place -
> `action=regions` reports eleven distinct locations. Besides the main server in
> Frankfurt, a Cloudflare Worker and GitHub Actions runners (Boydton, Phoenix,
> Chicago) measure too, so "the service is dead" can be told apart from "our
> server cannot see it". An own node would add another location, but this is not
> a gap in coverage.

---

## Other endpoints

| Endpoint | Format | Access | Description |
|---|---|---|---|
| `metrics.php?token=…` | Prometheus text 0.0.4 | token | Scraping by an external Prometheus; disabled when no token is set. The token may also be sent as an `Authorization: Bearer` header |
| `badge.php?id=&type=` | 302 | public | Deprecated alias - redirects to `action=badge` (old README embeds keep working) |
| `widget.php?id=` | HTML | public | Compact embed via iframe |
| `health.php` | JSON | admin or CLI | Database schema completeness check |
| `cron.php[?key=…]` | text | CLI or `cron_key` | Data collection; from the web only with the key |

---

## Versioning and stability

The API has no version in its URL. The application and the SPA are deployed
together, so the contract can change between commits.

What can be treated as stable is what deployed devices use, and therefore cannot
be changed without touching them:

- `agent_api.php` - runs on other people's machines
- `node_api.php` - the same
- `heartbeat.php` - the URL is hardcoded in cron jobs
- `metrics.php` - scraped by Prometheus

The rest serves our own frontend and changes with it.
