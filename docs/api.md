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
(`credentials: 'include'` in `fetch`). Logout: `POST action=logout`. It ends the session the legacy admin page shares and writes a `logout` entry to the audit log.

Session state: `GET /status/api.php?action=session`.

### Who sees which monitor

A monitor belongs to the accounts assigned to it, and one monitor can have
several. An administrator sees and changes every monitor. A `user` account sees
only its assigned monitors, read-only, together with its own profile and alert
subscriptions for those monitors. A monitor the caller may not see answers 404,
the same as one that does not exist, so ids cannot be probed. Rows marked
**assigned monitor** follow this rule, and list actions return only the visible
monitors.

`scope=public` asks `public_status`, `monitors`, `daily_uptime`, `uptime_windows`, `regions`,
`events` and `incidents` for the public status view. It covers every monitor, is
the same for everyone and carries no targets, hostnames, processes or interface
names. An anonymous caller always gets this view.

### What an anonymous visitor sees

Public responses keep only an allowlist of `details` keys: aggregates such as
`cpu`, `ram` and `hdd`, the agent version, and player or client counts. Anything
else - IP addresses, interface names, processes, ports, discovered services -
stays on the server, including keys added in the future. Failure reasons shrink to a fixed set
of sentences - HTTP code, timeout, DNS, TLS, closed port - with no host, port,
process or searched text, and incident updates lose the automatic check reason
and the operator's name.

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

**A failed read is never an empty success.** When the query behind a list or
a summary fails, the answer is `500` with `{"error": "<code>", "message":
"<Czech sentence>"}` - never `200` with `monitors: []`, `incidents: []` or
`series: {}`, which every client read as "all online" or "no outages" exactly
when nothing was known. `error` is a stable code to branch on
(`monitors_unavailable`, `incidents_unavailable`, `events_unavailable`,
`daily_uptime_unavailable`, `uptime_windows_unavailable`,
`metric_series_unavailable`, `audit_logs_unavailable`, `overview_unavailable`,
`dashboard_insights_unavailable`, … - always `<action>_unavailable` for a read,
`<action>_failed` for a write),
`message` is the sentence to show. The exception text goes to the server log
only; it can name tables and the database host.

**Database unreachable:** every endpoint answers `503` with `Retry-After: 60`.
`api.php`, `agent_api.php`, `node_api.php`, `heartbeat.php`, `health.php`,
`cron.php` and `metrics.php` (and any request whose `Accept` asks for JSON but
not HTML) get exactly `{"error": "database_unavailable"}`, agent endpoints
included; browser pages get the branded error page with code 503. Neither says
why - the database message used to be printed into the page, host, account and
file names included; it now goes to the server's error log only.

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

### Daily reminder of what is still broken

Not an endpoint but cron behaviour, next to the digest block. An alert goes out
on a CHANGE of state, so a monitor that went down on a Wednesday said nothing
for the rest of the week - which is how a four-day outage stayed invisible.

Settings (admin → Notifications):

| Key | Meaning |
|---|---|
| `daily_reminder_enabled` | `1` enables it; **on by default** |
| `daily_reminder_hour` | The hour it may go out from, 0-23, default 8 |
| `last_daily_reminder_sent` | The guard: the date of the last decision, written by cron |

It goes out at most once a day, from the configured hour, and **only when
something really is wrong**: monitors down or in warning (longest first, with
the stored reason), a separate section for silent data (agents that stopped
reporting, heartbeats past their grace, `bk_get_collection_issues`), open
incidents nobody has acknowledged, and one line naming the last completed
collection run - so a dead collector cannot hide behind a short report.
Monitors in maintenance and archived ones are left out.

When nothing is broken, **nothing is sent**: a daily "all good" teaches the
reader to filter the sender, and the first real message is filtered with it.
The decision is still written down - the outgoing message log gets a row with
`kind=daily_reminder`, `channel=none` and `status=skipped`, so "no e-mail came"
can be told apart from "the reminder is broken".

The date stamp is written whichever way it ended, including that skip. This
cron runs every minute on a router: without it the healthy case would write a
row every minute, and a refused channel would be retried until midnight and
bury its own failure under hundreds of rows.

---

## Endpoints that used to be missing

The actions below were called from the UI but did not exist in `api.php`.
Because an unknown action returned the default service overview with code 200,
every such call looked like a success. **Today an unknown action returns 400**
and a lint guards it (`run_api_action_lint.php`).

| Endpoint | Access | Description |
|---|---|---|
| `action=export_csv&monitor_id=&days=` | assigned monitor | Check history of a monitor as CSV, including the error message of each check |
| `action=save_annotation` | admin | A note on a chart (`monitor_id`, `metric_key`, `timestamp`, `note`) |
| `action=annotations&monitor_id=&metric=&hours=` | assigned monitor | Notes for rendering. An anonymous caller and a monitor the account is not assigned to get an empty list, not a 403 - a chart without notes is not an error |
| `action=delete_annotation` | admin, POST | Deletes a note by `id`. A note is a claim, and a wrong claim next to a chart has to be retractable |
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

**Assigned monitors, or the public view.** List of monitors with their last
state, response time and agent metrics, without archived monitors unless `archived=1`
asks for only those. A `user` account gets its assigned
monitors and an administrator all of them. An anonymous caller, or any caller
with `scope=public`, gets every monitor with `target`, `port`, `hostname` and
`agentLastSeen` set to `null` and only the allowlisted `details` keys.

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

### `POST api.php?action=archive_monitor` / `unarchive_monitor`

**Administrator.** Body `{"id": N}`. Archiving is for a monitor that is gone for
good, such as a replaced router. It keeps the monitor and its history but takes
it out of everything live: lists and summaries leave it out, cron does not check
it, no alert is sent, open incidents are closed, waiting remote actions fail, its
agent's reports are refused with 403 and `heartbeat.php` answers 410. A detail by
id stays readable and `action=monitors&archived=1` lists the archive. Any write
to an archived monitor answers 409. Restoring sets the state to `unknown` until
the next check or report. Both are written to the audit log.

### `GET api.php?action=agent_install_info&monitor_id=`

**Administrator.** What installing one monitor's agent needs: `agentKey`, the
`apiUrl` of this server and the download addresses in `files`. The key is a
credential, so every read is written to the audit log. An archived monitor
answers 409.

---

---

## Metrics and history

| Endpoint | Access | Description |
|---|---|---|
| `action=metric_series&monitor_id=&metric=&period=` | assigned monitor | One metric over time. A metric flagged `step` (`wan_errors`, `wan_drops`, `wan_ring_drops`, `wan_link_flaps`, `conntrack_drops`) already holds the increment between two reports: a raw point is that minute's step and the 90-day view is the day's TOTAL (`avg_val * samples`), never the average of it |
| `action=metric_series_batch&monitor_id=&period=` | assigned monitor | Every chart of a device in one call. The `hdd` and `ram` series additionally carry `daysToFull` (days until full) wherever growth is actually measured - a missing key means no forecast, never a zero. Periods up to `30d` only: `90d`, `180d` and `1y` answer `400 {"error": "period_unsupported"}` - they used to return the last 24 hours under the long label |
| `action=metric_detail&monitor_id=&metric=` | assigned monitor | Context for the metric detail page |
| `action=metric_correlations&monitor_id=&metric=&period=` (optionally `&all=1` for every compared metric, not just the strongest 8) | assigned monitor | How the device's other metrics moved together with this one (Pearson). Only metrics stored in `vps_metrics` take part: they share one measurement row, so samples pair exactly instead of being averaged into common buckets, which would smooth both series and inflate the coefficient. `r` is `null`, never `0`, when undefined - a series that never changed (`reason: constant`) or too few overlapping pairs (`few_samples`) |
| `action=metric_heatmap&monitor_id=&metric=&days=` | assigned monitor | Hour-by-day grid (one cell = one hour's average, for counters the hourly increment). Capped at 30 days - raw samples are pruned after that, so a longer window would silently answer with a shorter one. An hour with no sample is `null`, never `0` |
| `action=link_traffic&monitor_id=&days=` | assigned monitor | A router's traffic by link role: primary (`wan_l3_device`) vs. LTE backup (`lte_device`) for today / 7 / 30 days from the daily per-interface totals, plus the primary-link outages (`wan_down_periods`, `wan_down_seconds`, `wan_down_now`) paired from `wan_lost`/`wan_restored` events - whether traffic really went over the backup during them is what the backup device's byte counts say, not these periods (an open period runs until now; an outage that began before the window and has not ended is looked up separately and counted from the start of the window, or a router that has been on the backup for weeks would report "never"). Roles come only from what the agent reports - without `wan_l3_device` (agent < 0.1.3) the primary side is `null`, never a guess from the name |
| `action=process_history&monitor_id=&kind=&at=&radius=` | assigned monitor | Which processes were running around a point in time |
| `action=router_recommendations&monitor_id=` | assigned monitor | What the weekly router engine finds on this router right now, read-only (the GET never writes a state row). See "Router health" below |
| `action=storage_history&monitor_id=&days=` | assigned monitor | Per-disk daily history (temperature, error counters, host writes, wear). `days` is clamped to 1-400; a day nobody measured is `null`, never `0`. See "Router health" below |
| `action=wan_bottleneck&monitor_id=` | assigned monitor | What limits the internet line of a router, per direction, from its last speed tests. See "Router health" below |
| `action=metrics_history&monitor_id=&period=` | assigned monitor | Agent metric history |
| `action=daily_uptime&days=` | public status / assigned | Daily availability in time (see "Availability is measured in time" below): today live, finished days from `uptime_daily`. A day an agent was silent is `down` and its `detail` says for how long |
| `action=uptime_windows` | public status / assigned | Per-monitor availability for 24 h / 7 d / 30 d / 90 d in time; `d1` is the last 24 hours, the others are calendar days with today included. An unmeasured window is `null`, never 100. Each row carries `since`, the first day with data in the 90-day window, and the answer carries `windowStart` (`d7`, `d30`, `d90`: each window's first day); both are server-local `Y-m-d`, so "90 days" over six weeks of history can say where its data starts |
| `action=check_stages&monitor_id=` | assigned monitor | Check breakdown (DNS/TCP/TLS/HTTP, ServerQuery) |
| `action=regions&days=` | public status / assigned | Availability by measurement location (`checked_from`) |
| `action=public_status` | public status / assigned | Summary for the public page (counts, average availability). Inside the app a `user` account gets totals over its assigned monitors |
| `action=badge[&monitor_id=][&type=uptime][&lang=en]` | public | Embeddable SVG badge (60 s cache): live state, or 30-day availability with `type=uptime`; without `monitor_id` it summarises the fleet, an unknown monitor is 404 |
| `action=websites_overview` | assigned monitor | Sites with certificates and availability in the window |
| `action=monitor_insights&monitor_id=` | assigned monitor | Derived observations for one monitor |
| `action=dashboard_insights&limit=&offset=&lang=` | assigned monitor | The same across monitors: forecasts, anomalies and network notes, worded in the request's language. Paged - `limit` 1-200 (default 4), `offset`, and `total` says how many there are; the list is no longer cut at eight. Cached for 5 minutes per language (`cachedAt` when the answer came from it) |
| `action=ui_config` | public | Appearance settings for the frontend (logo, names) |
| `action=alerts_read_state` | logged in | Read-alert watermark (`readUpToId`) |
| `action=convert_to_agent_check` | admin | Turns an agent-watched process into a monitor of its own |

**A note on long-range data:** raw logs are purged after 30 days. A yearly SLA is
therefore computed from the `uptime_daily` rollup, not from logs. Responses
always state which period a value really covers - they never pass a thirty-day
window off as a year: `uptime_windows` and `sla_report` say where the window
starts (`windowStart`) and where its data starts (`since`).

### One overall verdict

`public_status` (`status`), the fleet `badge`, the headline of the legacy
`/status/` page and, through the API, the public page and the marketing site
print one verdict over the same set of monitors (`bk_overall_verdict()`):

| Verdict | When |
|---|---|
| `down` | any monitor is down |
| `degraded` | any is `warning`, or in a state nobody knows although it was checked before |
| `unknown` | no monitor, only monitors never checked yet, or collection has not run within `collection_max_age_secs` (default 15 minutes) - the stored states are then nobody's current measurement |
| `maintenance` | any is in maintenance |
| `healthy` | everything else: all up, freshly checked |

It used to be "healthy unless something is down", so a degraded monitor, an
unknown one and a stopped collector all read as all-clear. A monitor that has
not had its first check yet ("waiting for first data") is counted in
`unmeasuredMonitors` but does not degrade the verdict.

### Availability is measured in time

Availability used to be "up rows / all rows". A silent agent gets one `down`
row from cron and then nothing, so a three-day blackout was one row among
thousands and still read ~99.99 %. Every availability number (`uptime_windows`,
`daily_uptime`, `websites_overview`, `sla_report`, `public_status`, the badge,
the widget, the monthly `report.php` and the e-mail digest) is now computed in
time:

- each check row stands for the time until the next row, at most 2.5 check
  intervals (the interval is read off the monitor's own rows, 60-1800 s);
- time no row covers is an **outage** for an agent (`vps`, `openwrt`) that has
  reported before - silence is the outage - and **unmeasured** for an active
  check, where it means cron did not run;
- `maintenance` and unmeasured time (also the `unknown` rows of an
  agent-side service whose agent went quiet) stay outside the fraction;
  `warning` is not up;
- a window without a single measured second is `null`, never 100.

Finished days come from `uptime_daily`, which cron rolls up in time every ten
minutes (`secs_up`, `secs_down`, `secs_warning`, `secs_silent`,
`secs_maintenance`, `secs_unmeasured`); today is computed live. A day from
before the time rollup (its logs already pruned) knows only its check counts
and is read as a whole measured day split by them.

### Values for `period`

| Value | Window | Source |
|---|---|---|
| `15m`, `1h`, `6h`, `12h`, `24h` | 15 minutes to a day | `vps_metrics` / `monitor_logs` |
| `7d`, `30d` | week, month | the same |
| `90d`, `180d`, `1y` | quarter to year | `metrics_daily` (daily average); `response_time` from `uptime_daily.avg_response_ms` |

An unknown value falls back to a day. `response_time` at `90d` / `1y` used to
fall back to that day too, so "1 year" drew the last 24 hours; it now reads
the day's average answer from `uptime_daily`, whose `dailyRange` carries no
minimum or maximum (`null` - the rollup does not keep them). A history shorter
than the window simply starts later: the first point is the first day with data. For long periods the response carries
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
  "monitor": {
    "id": 6,
    "name": "Turris",
    "type": "openwrt",
    "target": "10.0.0.1",
    "port": null,
    "checkedFrom": "Praha, CZ",
    "assetId": 6
  },
  "metric": { "key": "cpu", "label": "CPU usage", "unit": "%", "counter": false, "step": false },
  "thresholds": { "warning": 75, "critical": 90 },
  "thresholdsDerived": { "warning": true, "critical": false },
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

### Router health: storage, Wi-Fi profile and weekly recommendations

Three read-only endpoints share one engine. It runs over the **seven complete
days** before today and needs at least **four days with data** (360 samples a
day) before a weekly rule is evaluated at all; a router with less answers
`applicable: false` and says so instead of reporting "nothing found". A value
that was not measured is `null` everywhere below and never a zero.

`action=router_recommendations&monitor_id=` answers

```json
{
  "monitorId": 6,
  "applicable": true,
  "reason": null,
  "generatedAt": "2026-09-21T10:00:00+02:00",
  "window": { "from": "2026-09-14", "to": "2026-09-20",
              "previousFrom": "2026-09-07", "previousTo": "2026-09-13",
              "daysWithData": 7 },
  "canMute": true,
  "missingPackages": ["smartmontools-drivedb"],
  "items": [{
    "id": "disk_temp_warm",
    "key": "disk_temp_warm:d:1f0c…",
    "area": "storage",
    "severity": "warning",
    "title": "The disk runs warm",
    "measured": "on average 67 °C (at most 68 °C) over the last week",
    "action": "Check the airflow …",
    "subject": { "kind": "disk", "label": "sda" },
    "openSince": "2026-09-01 04:12:00",
    "muted": false
  }],
  "muted": []
}
```

- `applicable: false` carries a `reason`: `not_router` (not an OpenWrt monitor),
  `agent_old` (the rules read fields only agent 0.1.7 sends, and an old agent is
  not a healthy router with nothing to report) or `silent` (nothing reported for
  more than seven days).
- `severity` is `critical` (act now), `warning` (act this month) or `info`
  (worth knowing). The order of `items` is severity, then area, then the rule's
  own rank - never the alphabet of the keys.
- Every weekly rule has an **enter** and a **hold** threshold. A finding that is
  already open stays open down to the hold value, so a metric sitting on the
  line does not flap in and out of the Monday e-mail.
- A rule that could not be evaluated is not reported as "fine": it is left out
  of `items` and its stored row is not touched.
- **Muting** is an admin decision and applies to everyone who can see the
  router. A muted finding moves to `muted` with `mutedReason`, `mutedBy` and
  `mutedAt`, and it is left out of the weekly e-mail. It comes back - marked
  `wasMuted` - as soon as the same finding becomes **more severe** than it was
  when it was muted. A mute whose rule no longer fires is still listed, with
  null texts and `active: false`, so it can be taken back.
- No SSID, MAC address, BSSID, disk serial or WWN ever appears in a text; disks
  are named by their `/dev` name and identified by a server-side hash.

`action=storage_history&monitor_id=&days=` answers `{monitorId, days, disks: []}`,
one entry per disk with its identity (`key`, `label`, `model`, `size`) and a
`days: []` list of `{day, tempMin, tempMean, tempMax, samples, reallocated,
pending, offline, runtimeBadBlocks, unsafeShutdowns, powerCycles, hostWritten,
hostWrittenPartial, wearPct}`. `samples` counts the fresh SMART readings of that
day; `hostWrittenPartial: true` says the day's byte count is incomplete (the
router rebooted or the counter wrapped) and the figure is a lower bound.

`action=wan_bottleneck&monitor_id=` answers the verdict per direction
(`verdict.dl`, `verdict.ul`), the tests it is based on, the WAN path and the
plan:

```json
{ "class": "line_limited", "reason": "below_plan", "confidence": "high",
  "basis": [41, 38, 35],
  "numbers": { "s_mbps": 700.0, "s_max_mbps": 710.0, "agree": 3,
               "span_days": 3, "servers": 2, "tests": 3 } }
```

- `class` is `none`, `link_limited`, `cpu_limited`, `line_limited` or
  `inconclusive`; `reason` says which rule decided (`plan_reached`,
  `sqm_shaper`, `wan_port`, `packet_path`, `test_client`, `below_plan`, …).
- `line_limited` - the only verdict that blames somebody else's equipment -
  needs three tests that agree, a span of at least two days, **two different
  servers** within 15 % of each other, proof that one of those servers has ever
  delivered the plan, and results outside the 1 G / 2.5 G goodput plateaus.
  Whatever it cannot prove comes back as `inconclusive` with the reason
  (`not_enough_tests`, `single_server`, `server_limited`,
  `server_capacity_unproven`, `port_plateau`, `tests_disagree`).
- Without a stored plan nothing is ever called "below plan": the answer is
  `inconclusive / no_plan_known` and the card asks for the tariff. A test the
  router did not start itself can only ever confirm a reached plan, never
  declare the line slow.
- `basis` lists the ids of the `speedtest_results` rows the verdict rests on, so
  the card can show exactly what was measured.

---

## Incidents and reports

| Endpoint | Access | Description |
|---|---|---|
| `action=incidents` | public status / assigned | List of incidents. An outage starts when the monitor went down (`last_status_change`), not when the outage was last confirmed. The public view drops targets, operator names and check reasons, also from `updates` |
| `action=create_incident` | logged in | Manual creation. Optional `monitorId` ties the incident to a monitor: 404 unknown, 409 archived, 409 when that monitor already has an open incident |
| `action=incident_action` | logged in | `op`: acknowledge / resolve / postmortem. `resolve` answers `monitorStillDown: true` when the monitor is down even after the incident is closed |
| `action=events&monitor_id=&limit=` | public status / assigned | Monitor events Additionally returns `statusChange`: the check that recorded the last status change (pinned to `monitors.last_status_change`, with the status it came from), or `null` - that row is often absent from the list itself, whose window is the newest checks plus the newest failures |
| `action=sla_report&days=` | assigned monitor | SLA overview in time: `uptimePct` (`null` without a measured second), `outageMinutes` = real minutes of outage including an agent's silence, `silentMinutes` = the part of it an agent was silent, `unmeasuredMinutes` = time nobody measured (outside the percentage). `upChecks` / `downChecks` stay row counts; past 30 days (`days` 90 / 365) they come from `uptime_daily` plus today's logs, not from the month of logs that is left. The answer carries `days`, `windowStart` (the window's first day), `since` (the first day with data, also per row) and `percentileDays` (latency percentiles read raw logs, at most 30 days) |
| `action=audit_logs&limit=` | admin | Latest checks across monitors |

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
| `action=test_notification` | admin | POST `{channel}` (email/discord/telegram/slack): sends one real test message with the saved settings, returns `{ok, message}` |
| `action=notification_log&monitor_id=&kind=&channel=&ok=&from=&to=&q=&before_id=&limit=&summary=1` | admin | What was sent, to whom, on which channel and whether it went. A row is written for a failure too - that is the interesting half. Since `send_email()` logs centrally, this is every kind of message, not only alerts: `kind` filters them (`alert`, `daily_reminder`, `digest`, `invitation`, …), `channel` the route, `ok=0` the failures alone, `q` is a substring of the recipient. `from`/`to` take a date or a date and time; a bare date in `to` means that whole day, and a value that cannot be parsed is a 400 rather than a silently wider answer. Paging is by cursor - pass the returned `nextCursor` as `before_id` - because rows keep arriving while somebody reads, and an offset page would repeat one row and skip another. `kinds` and `channels` in the answer list the values present in the WHOLE log, so a filter can never remove the option that would undo it. `summary=1` adds `last24h` and `last7d` (`total`, `failed`, and the same pair per channel), likewise over the whole log and never narrowed by the filters: it feeds the "something did not go out" banner, and a banner a filter can talk out of a failure is worse than none. The body of a message is never stored; rows are pruned after 180 days |
| `action=interface_traffic_daily&monitor_id=&days=` | assigned monitor | Traffic per day and interface, busiest first. A missing day means nothing was reported that day, not zero traffic |
| `action=process_top&monitor_id=&kind=&minutes=` | assigned monitor | Which processes used the machine over the whole window (average, peak, sample count), grouped by name so a restarting service is not split per pid. `enabled: false` = process history is switched off in the settings |
| `action=toggle_maintenance` | admin | POST `{monitor_ids[], maintenance, description?, maintenance_end?}`: switches maintenance on or off for one or more monitors. Off also clears the window, so the next maintenance does not expire the moment it starts |
| `action=clear_monitor_history` | admin | POST `{monitor_id, confirm_name}`: erases the monitor's measurements, logs and daily aggregates and returns it to "unknown". Irreversible, so it asks for the exact monitor name back |
| `action=redetect_location` | admin | Forces a fresh geolocation lookup for the server and stores it in `ip_loc_local` |
| `action=router_recommendation_mute` | admin | POST `{monitor_id, key, muted, reason?}`: silences one router recommendation (or takes the mute back). The severity is re-evaluated on the server, never taken from the body, so a mute silences the finding as it is today and not a worse version of it. An unknown rule id is 400, an archived monitor is refused, and every change writes an `audit_log` row |
| `action=wan_settings_save` | admin | POST `{monitor_id, plan_down_mbit?, plan_up_mbit?, plan_ok_pct?}`: the router's tariff. `null` or an empty string clears a value; a number outside 1-100000 (30-100 for the percentage) is a 400 and is never clamped, because a clamped plan is a plan the owner did not enter. An absent `probe_enabled` key leaves the stored consent alone |
| `action=presets` / `save_preset` / `delete_preset` / `assign_preset` | public read, admin write | Metric profiles |
| `action=status_pages` / `save_status_page` / `delete_status_page` | list public, hidden pages and writes admin | Public status pages |
| `action=dashboard_layout` | logged in | Tile order and visibility |
| `action=users` | admin | User list, each account with its `monitorIds` |
| `action=export_config` | admin | Configuration export without secrets |
| `action=generate_metrics_token` | admin | Token for the Prometheus exporter |
| `action=upload_logo` | admin | Status page logo |
| `action=send_digest&period=` | admin | Manual digest send |
| `action=trigger_remote_action` | admin | Action on a router (allowed ones only) |
| `action=discovered_services` / `import_discovered_service` | admin | Service Discovery |
| `action=get_subscriptions` / `save_subscriptions` | logged in | Alert subscriptions, limited to the monitors the account can see |
| `action=public_subscribe` / `public_subscribe_confirm` / `public_unsubscribe` | public | E-mail subscription for visitors without accounts: double opt-in (nothing is sent until the owner confirms), IP rate limit on sign-up, neutral responses (no enumeration), one-click unsubscribe link in every mail |
| `action=public_subscribers` / `delete_public_subscriber` | admin | Subscriber overview and manual removal (GDPR requests) |
| `action=save_user` / `delete_user` | admin | User management for the React app (create sends the invite e-mail, delete refuses the own account; `monitorIds` sets which monitors a `user` account sees - omitted keeps the assignment, `[]` clears it, unknown ids are dropped); before 2026-08 these existed only as admin.php form handlers and the React page's calls hit "unknown action" |
| `action=my_profile` / `update_profile` | logged in | Own profile: contacts, notification channels, e-mail language, password change (requires the current password) |
| `action=oauth_unlink` | logged in | Unlink the OAuth sign-in (requires the current password) |
| `action=totp_setup` / `totp_confirm` / `totp_disable` / `totp_recovery_regenerate` | logged in | Two-factor enrollment: the secret stays in the session until a code confirms it; confirming returns ten one-time recovery codes (hashes only are stored, shown exactly once); a recovery code works in place of the TOTP code at login and is consumed; regenerating a set and disabling both require the password |
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
body. Requires POST and valid JSON, otherwise 405 / 400. Only `agent_key` is
required: `cpu`, `ram` and `hdd` may be `null`, which agents send on their first
run and after a reboot. The report of an archived monitor is refused with 403 and
`archived: true`.

The server accepts keys it does not know in advance - otherwise a new metric from
an agent would vanish silently. Limits apply though: a typed server-side value
always wins, credentials are never taken over, the name has to look like an
identifier, an array is capped at 8 KB and at most 64 new keys are added at once.

`action_result` arrives as a separate lightweight POST - the confirmation of a
performed Remote Action. It carries no telemetry fields, so it is handled before
their validation.

**Agent 0.1.7 (OpenWrt) adds** `wifi_radios[]` (one object per wireless netdev,
at most 16: band derived from the frequency, generation and width, client
counts by capability and encryption, noise and channel busy), `storage_disks[]`
(at most 8 physical disks with 16 partitions each, SMART state, temperature,
error counters, wear, host-written bytes), `agent_tools` (seven strict booleans
saying which optional programs the router really has), `wan_path` (packet
steering, flow offloading, SQM, ring drops) and the WAN counters. Rules the
server applies to all of them:

- **Out of range becomes `null`, never a bound.** A busy figure of 101 % or a
  temperature of 0 °C is not clamped to 100 or to a minimum: it is dropped,
  because a clamped value reads like a measurement.
- **An absent `storage_disks` keeps the last list.** Agent 0.1.6 does not send
  the key and a 0.1.7 report that had to shrink may drop it; reading that as
  "no disks" would erase a working disk list. A key that IS sent replaces it.
- Five WAN counters are stored as the **step** between two reports, and the step
  is `null` across a reboot or a change of WAN device - never the value since
  boot.
- **Privacy:** no MAC address, BSSID, neighbour SSID, disk serial or WWN leaves
  the router; disk identity is a server-side hash of transport, port, model and
  size.

Alerts raised from these fields use three statuses - `storage_failing` (pages),
`storage_warning` and `storage_recovered` (neither pages) - and all of them are
subject to the `agent_notifications_enabled` switch; the event is written to the
timeline either way. Disk temperature needs two consecutive fresh readings over
the limit and clears 5 °C below it, a growing error counter is silent on first
sight and repeats at most once a day, and a filesystem alert needs two reports
over the limit and clears five points lower.

**Agent 0.1.8 (OpenWrt) adds** `lan_ports` - the wired switch, port by port,
sent with every report because a cable changes by the minute. Per port: `name`,
`link`, `speed_mbit`, `duplex`, `max_mbit` (what the port supports),
`partner_max_mbit` (what the other end advertises) and `clients`; next to them
`bridge`, `conduits[]` (the DSA link to the CPU that every wired client shares)
and `clients_total`. The server stores it in `last_details`, which is how it
reaches the app in the `details` object of `action=monitors` - there is no
endpoint of its own. Its rules:

- **A port with no carrier has no rate.** `speed_mbit`, `duplex` and
  `partner_max_mbit` are `null` whenever `link` is `false`, and a value that
  cannot be read is `null` too - never 0 and never a plausible guess. A port
  with `clients: 0` is different: that is a measurement, "nothing has spoken
  behind this socket".
- **`lan_ports: null` means the router could not look** (no ubus, no `bridge`,
  a switch that is not DSA). A report that does not carry the section erases
  the stored one instead of keeping it: unlike the disk list, a cable picture
  from an earlier report would be a lie within a minute.
- **Privacy:** only counts leave the router. No MAC address, no hostname and no
  lease is sent or stored, and the section is never part of the public view.
- It is **not** a time series and has no metric column. The count comes from
  the bridge forwarding database, which forgets a device after a few quiet
  minutes, so a chart of it would show devices unplugging themselves.
- The only recommendation it feeds is `lan_wired_ceiling`, which still fires on
  the port **capability**; the switch merely adds the household's own numbers -
  how many wired devices share which conduit - and only when both were measured.

**The router's last error lines (agent 0.1.8, OpenWrt)** travel next to
`log_errors_24h`: `log_errors_recent` (at most 5 `{ts, prog, msg, count}`,
newest first; `[]` = the log was read and holds no error line, `null` = no
readable log or sending is switched off), `log_window_secs` (how many seconds
the counted log buffer covers, for "errors in the last N h") and
`log_lines_state` (`on`, `off_monitor`, `off_router`). Owner decision: the lines
may leave the router masked, at most five, kept in `last_details` only - no
history table - and switchable per monitor. The server masks them again
(e-mail, MAC, IPv6, IPv4, local host names, hex ids of 12+ digits; printable
ASCII, 200 characters), so an older agent cannot store a raw address. With the
monitor's `log_lines_enabled` off it keeps none (`log_errors_recent: null`,
`log_lines_state: "off_monitor"`), and every answer carries `"log_lines":true`
or `"log_lines":false` - unspaced, the agent matches it literally and stops
collecting from its next run.

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

## Writes: POST + CSRF

Since 2026-08 every state-changing action accepts **POST only** (GET gets 405)
and every session-authenticated write must carry the session's CSRF token in
the `X-CSRF-Token` header (multipart forms may send a `csrf_token` field
instead). The token comes back from `action=login` and `action=session`.
Token-authenticated flows (`set_password`) and session-establishing ones
(`login`, `setup`, `forgot_password`, `logout`) are exempt. CORS reflects
only the site's own origin - a foreign origin gets no
`Access-Control-Allow-Origin` at all.

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
