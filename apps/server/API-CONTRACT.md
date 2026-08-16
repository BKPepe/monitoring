# Kontrakt pro přepis `apps/status` → Go + `apps/monitor`

Inventura toho, co dnešní PHP aplikace **skutečně umí**, pořízená čtením kódu
(ne z paměti a ne z mockupu). Slouží jako definice hotového: dokud tenhle
seznam není splněný, je přepis funkční downgrade, ne rework.

Zdroje: `apps/status/{api,agent_api,node_api,metrics,badge,widget,report,health}.php`,
`functions.php`, `cron.php`, `monitor.php`, `schema.sql`.

---

## 0. Nejdřív oprava, kterou dlužím

V předchozích sprintech jsem tvrdil, že „predikce zaplnění disku je Sprint 6,
zatím neexistuje". **To byla chyba — už je hotová.**

`api.php` (akce `metric_series`, řádky ~200–235) počítá lineární regresi nad
posledními 7 dny, promítá ji po hodinových krocích až ke 100 % a vrací
`prediction[]` a `days_to_full`. Aktivní pro metriky `hdd`, `ram`,
`inode_usage`, jen když křivka roste (`b > 0`) a aktuální hodnota je nad 20 %.

Karta „Predictive Alert" v mém dashboardu tedy nemá čekat na Sprint 6 —
má se jen napojit na existující výpočet. Totéž nejspíš platí pro další
„insighty"; při napojování je potřeba každý ověřit proti kódu, ne proti mému
dřívějšímu odhadu.

---

## 1. HTTP rozhraní

### 1.1 `api.php` — veřejné čtecí API

| Akce | Parametry | Vrací |
| --- | --- | --- |
| `metrics_history` | `monitor_id`, `period` = `24h`\|`7d`\|`30d` | `labels[]`, `cpu[]`, `ram[]`, `hdd[]`, `net[]` + `*_avg`, `*_max` |
| `metric_series` | `monitor_id`, `metric`, `period` = `15m`\|`1h`\|`6h`\|`24h`\|`7d`\|`30d`, volitelně `compare`, `baseline` | `points[]`, `events[]`, `unit`, `label`, volitelně `prediction[]`, `days_to_full` |
| `response_history` | `monitor_id` | historie odezvy |
| `status_history` | `monitor_id` | historie stavů |
| `public_status` | — | viz níže |
| `save_annotation` | POST, jen admin | `{success:true}` |

**`public_status`** (konzumuje ho landing page přes worker):

```json
{
  "status": "healthy | degraded",
  "uptimePercent": 99.78,
  "totalMonitors": 24, "downMonitors": 0,
  "agentsOnline": 12, "agentsTotal": 12,
  "avgLatencyMs": 23,
  "lastUpdated": "2026-07-28T10:00:00+02:00",
  "nodes": [{ "name": "Frankfurt, DE", "status": "online|warning|offline", "latencyMs": 18 }]
}
```

Pozor na detaily, které se snadno ztratí:

- **Downsampling už existuje** a je odstupňovaný: bucket `0 s` pro 15m/1h,
  `300 s` pro 6h/24h, `1800 s` pro 7d, `7200 s` pro 30d. Go verze to musí
  držet, jinak 30denní graf potáhne stovky tisíc bodů.
- **Uptime se počítá s vyloučením údržby** — `WHERE status != 'maintenance'`.
  Naivní `up/total` dá jiné číslo a rozbije SLA reporty.
- **„Agent existuje" ≠ „má klíč".** `agent_key` se generuje všem monitorům;
  za agenta se počítá jen ten, který se někdy ozval (`agent_last_seen`).
- **Hub se vylučuje z distribuovaných lokací** (`checked_from != 'Main Server'`
  a != `cron_location`), jinak se hlavní server tváří jako regionální sonda.

### 1.2 `agent_api.php` — ingest z agentů ⚠️ **nejrizikovější část**

`POST` s JSON tělem. Autentizace: `agent_key` v těle (plain text).

Registrace: `{token, name, type}` → `{success, agent_key}`, kde `token` se
porovnává přes `hash_equals` proti `agent_registration_token`.

**Payload má přes 85 polí.** Základ je povinný (`agent_key`, `cpu`, `ram`,
`hdd`), zbytek volitelný podle typu agenta:

| Skupina | Pole |
| --- | --- |
| Základ | `cpu`, `ram`, `hdd`, `net`, `swap`, `swap_pct`, `uptime`, `load1/5/15` |
| CPU/IO | `cpu_steal`, `iowait`, `disk_io_read`, `disk_io_write`, `fork_rate`, `zombie_count`, `entropy` |
| Disk | `inode_usage`, `smart`, `btrfs_errors` |
| Síť | `net_errors`, `net_ipv4_kbps`, `net_ipv6_kbps`, `interfaces`, `ports` |
| Systém | `hostname`, `os`, `kernel`, `model`, `board_name`, `timezone`, `virtualization`, `cloud_provider`, `version`, `agent_type`, `reboot_required` |
| Balíčky | `installed_packages`, `upgradable_packages`, `service_restarts` |
| Procesy | `processes`, `top_cpu_processes`, `top_ram_processes` |
| Logy | `log_errors_24h`, `log_warnings_24h` |
| Teplota | `temperature` |
| **OpenWrt** | `wan_up`, `wan_proto`, `wan_ipv4`, `wan_ipv6`, `wan_gateway`, `wan_dns`, `wan_uptime`, `wan_reconnect_count`, `wan_last_reconnect`, `lan_subnet`, `conntrack_pct`, `wifi_clients_count`, `wifi_radios`, `wireguard_peers`, `dhcp_leases_count`, `dhcp_reservations_count`, `mwan3_policies`, `mwan3_active_gw`, `sqm_enabled`, `sqm_download_kbps`, `sqm_upload_kbps`, `sqm_dropped`, `sqm_ecn`, `fw_accepted`, `fw_dropped`, `fw_rejected`, `dns_queries`, `dns_cache_hits`, `dns_cache_misses` |
| **LTE** | `lte_rsrp`, `lte_rsrq`, `lte_sinr`, `lte_band`, `lte_carrier` |
| TeamSpeak | `teamspeak_servers`, `ts3_process` |
| Discovery | `discovered_services`, `heavy_op_interval_hours` |
| Remote Actions | `action_result` (výsledek zpět), odpověď nese `action`, `action_id`, `signature` |

**Odpověď na ingest není jen potvrzení** — je to kanál pro Remote Actions:
server vrací příkaz (`restart_wan`, `restart_wireguard`, `reboot_router`,
`renew_dhcp`) podepsaný `hash_hmac('sha256', payload, agent_key)`. Agent podpis
ověřuje. Go implementace musí použít **identický payload i pořadí polí**, jinak
podpisy nesednou a Remote Actions přestanou fungovat.

Nasazení dnes běží pěti implementacemi: `agent.sh`, `agent.py`, `agent.ps1`,
`agent_openwrt.sh`, `node_client.php`. Přepínat je nelze najednou → nový
backend musí umět starý protokol i po přepnutí.

### 1.3 `node_api.php` — distribuované sondy

Autentizace klíčem v `?key=` nebo hlavičce `X-Node-Key` (`cron_key`).

- `action=get_monitors` → `{monitors: [...]}` — seznam k proměření
- `action=post_results` → tělo `{results: [...]}` s `details` jako JSON

### 1.4 Ostatní

| Endpoint | Parametry | Poznámka |
| --- | --- | --- |
| `metrics.php` | `?token=` nebo `Authorization: Bearer` | Prometheus text format, `hash_equals` |
| `badge.php` | `id`, `type` = `status`\|`uptime` | SVG odznak do README |
| `widget.php` | `id` | iframe karta pro cizí weby |
| `report.php` | `monitor_id`, `month`, `year`, `format` | SLA report HTML/CSV |
| `health.php` | `format` | healthcheck |

Tyhle čtyři jsou **veřejné integrační body** — někdo je má vložené v cizím
README nebo v Grafaně. Musí zůstat na stejných URL se stejnými parametry,
jinak se rozbijí cizí stránky, o kterých se nedozvíme.

---

## 2. Typy monitorů a sondy

`monitors.type`: `web`, `port`, `minecraft`, `teamspeak`, `discord`, `vps`
(+ cPanel přes `cpanel_stats.php`).

Měřicí funkce v `monitor.php`: `check_http`, `check_socket`, `check_minecraft`
(se třemi cestami — SLP, RCON, API fallback), `check_teamspeak`,
`check_ts3_ports`, `check_discord`, `check_cpanel`.

Minecraft a TeamSpeak nejsou okrajové: mají vlastní protokolové implementace
a v `vps_metrics` vlastní sloupce (`ts_clients_online`, `ts_process_cpu`,
`ts_process_ram`).

---

## 3. Metriky

`bk_get_metric_registry()` — **19 metrik**, ne šest, jak předpokládá dnešní
`apps/monitor/src/api/mock-source.ts`:

```
cpu, ram, hdd, net, load1, load5, load15, cpu_steal, swap,
disk_io_read, disk_io_write, net_errors, iowait, inode_usage,
ts_clients, ts_process_cpu, ts_process_ram, net_ipv4, net_ipv6
```

Registr mapuje klíč → sloupec v `vps_metrics` + jednotku + překladový klíč.
Go verze by měla mít stejný registr jako jediný zdroj pravdy a **nikdy**
neskládat název sloupce z parametru requestu (dnešní PHP to explicitně hlídá).

---

## 4. Notifikace

Kanály: **SMTP e-mail** (PHPMailer), **SMS** (SMSbrana i Twilio),
**WhatsApp**, **Discord**, **Telegram**, **Slack**, **Pushover**,
**PagerDuty**, **generický webhook**.

Související funkcionalita:
- šablony e-mailů (`render_email_wrapper`, `bk_email_*`) s vlastním jazykem
  (`email_lang`) nezávislým na jazyku UI
- týdenní a měsíční digest (`last_weekly_digest_sent`, `last_monthly_digest_sent`)
- upozornění na expiraci SSL (`ssl_alert_days`)
- alerty na offline agenta (`agent_notifications_enabled`,
  `agent_offline_timeout`, `agent_notify_admin_only`)
- prahové hodnoty na monitor (`cpu_threshold`, `ram_threshold`, `hdd_threshold`)

---

## 5. Účty a bezpečnost

- bcrypt hesla, **TOTP 2FA**, **OAuth** přihlášení
- rate limiting přihlášení: 5 pokusů / 15 min → lockout
- `audit_log` (retence 90 dní), `users` s rolemi
- CSRF tokeny v adminu
- session cookie s `HttpOnly`, `SameSite=Lax`, `Secure` podle HTTPS

Co bych při přepisu **změnil, ne zkopíroval**: ingest se dnes autentizuje
sdíleným `agent_key` v těle requestu. Vzor pro lepší řešení už v projektu je —
Remote Actions se podepisují HMAC. Ingest by měl jít stejnou cestou
(podpis + timestamp proti replay). Přechodné období musí umět obojí.

---

## 6. Databáze

Tabulky: `users`, `audit_log`, `assets`, `monitors`, `monitor_events`,
`monitor_logs`, `vps_metrics` (28 sloupců), `user_subscriptions`, `settings`,
`metric_annotations`, `incidents`, `incident_updates`, `agent_actions`,
`monitor_interface_traffic`.

Retence (`cron.php`): `monitor_logs` a `vps_metrics` 30 dní, `audit_log` 90 dní.

⚠️ **Reports (Sprint 7) chtějí delší historii než 30 dní.** Řešením není
prodloužit retenci syrových dat, ale zavést denní rollup — jinak tabulka
poroste lineárně a 30denní graf začne být pomalý.

---

## 7. Parity checklist

Stav vůči dnešnímu `apps/monitor` (Sprint 1–4).

| Oblast | PHP | Monitor UI | Go backend |
| --- | --- | --- | --- |
| Přehled stavu | ✅ | ✅ mock | ❌ |
| Detail zařízení | ✅ | ✅ mock | ❌ |
| Grafy metrik | ✅ 19 metrik | ⚠️ 6 metrik, mock | ❌ |
| Downsampling | ✅ | ❌ | ❌ |
| Predikce + `days_to_full` | ✅ | ❌ | ❌ |
| Anotace grafů | ✅ | ❌ | ❌ |
| Ingest agentů (85 polí) | ✅ | — | ❌ |
| Remote Actions (HMAC) | ✅ | ❌ | ❌ |
| Distribuované sondy | ✅ | ❌ | ❌ |
| Service discovery | ✅ | ❌ | ❌ |
| Incidenty + updaty | ✅ | ❌ zástupná stránka | ❌ |
| Odběry stavu | ✅ | ❌ | ❌ |
| Notifikace (9 kanálů) | ✅ | ❌ | ❌ |
| Digesty, SSL alerty | ✅ | ❌ | ❌ |
| SLA reporty | ✅ | ❌ zástupná stránka | ❌ |
| Prometheus exportér | ✅ | — | ❌ |
| Badge / widget | ✅ | — | ❌ |
| 2FA, OAuth, audit log | ✅ | ❌ | ❌ |
| Veřejná status stránka | ✅ | ❌ | ❌ |
| i18n (CS/EN) | ✅ | ❌ jen CS | — |

**Realita: UI ze sprintů 1–4 pokrývá zhruba pětinu funkčního povrchu.**
Vypadá hotověji, než je, protože mockup ukazoval jen přehledové obrazovky.

---

## 8. Doporučené pořadí

1. **Go backend + ingest kompatibilní s agenty** — dokud tohle neběží, nemá
   se co napojovat a nasazení agenti jsou rukojmí.
2. **Čtecí API** pro `apps/monitor` (metric registry, downsampling, predikce).
3. **Souběžný provoz.** PHP a Go nad stejnou databází, přepínání po
   endpointech. Big-bang přepnutí u monitoringu znamená slepé místo právě ve
   chvíli, kdy se něco rozbije.
4. Notifikace, incidenty, reporty.
5. Integrační endpointy (`metrics.php`, `badge.php`, `widget.php`) na
   **stejných URL** — kvůli cizím vloženým odkazům.
