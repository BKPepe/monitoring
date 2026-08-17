# API Blood Kings Monitoring

> 🇬🇧 [English version](api.md) · 🇨🇿 Česká verze (tato stránka)


Referenční popis HTTP rozhraní aplikace `apps/status`. Sepsáno podle kódu, ne
podle záměru - u každého endpointu platí to, co dělá `api.php`, ne to, co by
dělat měl. Kde se obojí liší, je to označené jako **Pozor**.

- **Základ:** `https://bloodkings.eu/status/`
- **Formát:** JSON (`Content-Type: application/json; charset=utf-8`), výjimky
  jsou uvedené u konkrétních endpointů (SVG, Prometheus text, HTML).
- **Jazyk chybových hlášek:** čeština - jsou určené administrátorovi, ne
  koncovému uživateli.

---

## Pravidlo, které platí pro celé API

**Co se nezměřilo, je `null`. Nikdy nula, nikdy zástupný řetězec.**

Toto není stylistická poznámka, ale kontrakt. Odpověď `"cpu": null` znamená
„tuhle hodnotu nemáme", ne „procesor je nevytížený". Klient je musí rozlišit -
v našem rozhraní se `null` vykresluje jako pomlčka.

Stejně tak `"uptime": null` u čerstvě založeného monitoru **není** 100 %.
Průměr z nula měření neexistuje; kdo ho dopočítá na 100 %, vyrobí si údaj,
který nikdo neměřil.

Dodržování hlídají linty v CI (`run_honesty_lint.php` a spol.), takže regrese
tohohle typu neprojde revizí, ale spadne v bráně.

---

## Autentizace

Aplikace nemá API tokeny pro třetí strany. Rozlišují se čtyři režimy:

| Režim | Jak se prokazuje | Kdo ho používá |
|---|---|---|
| **Veřejné** | nijak | status stránka, hlídač |
| **Přihlášený** | session cookie (`action=login`) | React SPA |
| **Administrátor** | session cookie + role `admin` | správa konfigurace |
| **Klíč zařízení** | `agent_key` / `token` v těle nebo URL | agenti, sondy, heartbeat |

Přihlášení:

```http
POST /status/api.php?action=login
Content-Type: application/json

{"username": "admin", "password": "…"}
```

Odpověď nastaví session cookie. Všechna další volání ji musí posílat
(`credentials: 'include'` ve `fetch`). Odhlášení: `action=logout`.

Stav relace: `GET /status/api.php?action=session`.

### Co vidí nepřihlášený návštěvník

Veřejné odpovědi procházejí filtrem, který ze struktury `details` odstraňuje
všechno, co nese síťovou identitu - IP adresy, MAC, SSID, hostname, endpointy,
sériová čísla, tokeny a hesla. Filtr pracuje podle vzoru v názvu klíče, ne
podle výčtu, takže zachytí i metriku, která vznikne až v budoucnu. Agregáty
(`cpu`, `ram`, počty klientů) zůstávají.

---

## Chyby

| Kód | Význam |
|---|---|
| 400 | Chybí nebo je neplatný parametr |
| 401 | Nepřihlášen |
| 403 | Přihlášen, ale nemá roli `admin` |
| 404 | Objekt neexistuje (nebo se to nemá prozradit - viz heartbeat) |
| 405 | Špatná HTTP metoda |
| 500 | Chyba na straně serveru |
| 503 | Databáze nebo navazující služba nedostupná |

Tělo chyby: `{"error": "Popis česky"}`. Endpointy agentů vracejí místo toho
`{"success": false, "message": "…"}` - historický rozdíl, sjednocení by
rozbilo nasazené agenty.

---

## Stav sběru dat

### `GET api.php?action=collection_health`

**Veřejné.** Odpovídá na jedinou otázku: běží ještě cron?

Existuje proto, že když sběr dat přestane běžet, aplikace se nerozbije - dál
zobrazuje poslední známé stavy a vypadá zdravě. Ze všech způsobů, jak může
monitoring selhat, je tenhle nejhorší, protože o sobě nedá vědět.

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

| Pole | Význam |
|---|---|
| `lastRunAt` | Konec posledního **dokončeného** běhu; `null` = cron s tímto zápisem ještě neběžel |
| `ageSecs` | Stáří v sekundách; `null`, když `lastRunAt` je `null` |
| `maxAgeSecs` | Limit z nastavení `collection_max_age_secs` (výchozí 900) |
| `stale` | `true`, když je stáří přes limit **nebo** cron nikdy neběžel |
| `lastDurationMs` | Doba běhu; `null` = neměřeno |

Endpoint je veřejný záměrně - hlídač běží jinde a nemá se čím přihlašovat.
Nic citlivého se odsud nedozví.

Hlídá to Cloudflare Worker (`apps/worker`) cronem každých 5 minut. Ten běží
mimo cPanel, takže funguje i ve chvíli, kdy je celý server mrtvý. Nastavení
kanálu pro alerty:

```sh
cd apps/worker && npx wrangler secret put WATCHDOG_DISCORD_WEBHOOK
```

Bez toho hlídač kontroluje dál, ale jen loguje - a přizná to na
`GET /api/watchdog`, kde je `alertChannelConfigured: false`.

### Funguje ten kanál doopravdy?

„Nastavený" a „funkční" nejsou totéž. Smazaný kanál, přegenerovaný token nebo
překlep v URL vypadají zvenčí úplně stejně jako správně nastavený webhook,
takže hlídač může měsíce hlásit `alertChannelConfigured: true` a přitom nemít
kam alert poslat. `GET /api/watchdog` proto vrací dvě další pole:

| Pole | Význam |
|---|---|
| `alertChannelValid` | `true` = Discord webhook potvrzuje, `false` = neplatí, `null` = nedalo se ověřit |
| `alertChannelDetail` | Důvod, když je `false` nebo `null`; jinak `null` |

Ověřuje se dotazem GET na adresu webhooku (Discord na ni vrací objekt
webhooku), takže do kanálu nic nechodí. Výsledek se hodinu cachuje - jinak by
stačilo tlouct na `/api/watchdog` a worker by tím tloukl na Discord.

### `POST /api/watchdog/test`

Ověření přes GET pozná smazaný webhook, ale ne to, jestli zpráva opravdu
dorazí **do kanálu** - chybějící oprávnění se pozná až při odeslání. Tenhle
endpoint projde celou cestu skutečného alertu:

```sh
cd apps/worker && npx wrangler secret put WATCHDOG_TEST_TOKEN   # jednou
curl -X POST -H "Authorization: Bearer $TOKEN" https://api.bloodkings.eu/api/watchdog/test
```

Odpověď je `{"delivered": true, "detail": null}` (HTTP 200), nebo HTTP 502
s důvodem v `detail`. Do kanálu přijde zpráva označená jako zkouška.

Bez nastaveného `WATCHDOG_TEST_TOKEN` endpoint odpovídá 404, jako by
neexistoval - jinak by kdokoli mohl kanál zaplavit. Token se porovnává
v konstantním čase, aby ho nešlo uhodnout znak po znaku.

---

## Heartbeat: úloha se hlásí sama

Obrácený směr než zbytek monitoringu. Aktivní kontrola umí jen to, na co
dosáhne ze sítě - záloha, která se spustí ve tři ráno a tiše selže, je pro ni
neviditelná. Proto se hlásí úloha sama.

### `GET|POST heartbeat.php?token=…`

**Autentizace tokenem.** Token je 48 hexadecimálních znaků z CSPRNG a je
jediné, co endpoint autorizuje.

| Parametr | Povinný | Význam |
|---|---|---|
| `token` | ano | Lze předat i v cestě: `heartbeat.php/TOKEN` |
| `status` | ne | `fail` = úloha ohlašuje vlastní selhání. Cokoli jiného (i překlep) je úspěch |
| `msg` | ne | Popis, max. 255 znaků |

```sh
# na konec zálohovacího skriptu
curl -fsS -m 10 "https://bloodkings.eu/status/heartbeat.php?token=TOKEN"

# když úloha selže
curl -fsS -m 10 "https://bloodkings.eu/status/heartbeat.php?token=TOKEN&status=fail&msg=tar%20skoncil%20kodem%202"
```

Odpověď: `{"ok": true, "monitor": "Noční záloha", "result": "ok", "receivedAt": "…"}`

Neplatný token vrací **404**, stejně jako token špatného tvaru - platnost
tokenu se odsud nedá zjistit zkoušením.

Endpoint jen zapíše signál. Stav vyhodnotí cron při nejbližším běhu, takže
mezi ohlášením selhání a notifikací je zpoždění do jednoho cyklu (1-5 minut).

### Jak se vyhodnocuje stav

| Stav | Kdy |
|---|---|
| `up` | Signál přišel do `interval + tolerance` a úloha hlásí úspěch |
| `down` | Úloha se neozvala včas, **nebo** ohlásila selhání |
| `unknown` | Ještě se neozvala ani jednou, nebo nemá nastavený interval |

Rozdíl mezi `down` a `unknown` je zásadní: monitor, který nikdy nedostal
signál, **není dole** - nevíme o něm nic. Alert na výpadek, který se nestal,
je stejná lež jako vymyšlená nula v grafu.

Ohlášené selhání má přednost před stářím signálu. Kdyby ne, tiše selhávající
záloha by vypadala zdravě jen proto, že se cron spouští.

### `GET api.php?action=heartbeat_info&monitor_id=…`

**Admin.** Vrátí adresu k nastavení úlohy, aktuální stav a čas posledního
signálu. Parametr `regenerate=1` vyrobí nový token - stará adresa tím okamžitě
přestane platit.

Token se vrací **jen tudy**. V běžném seznamu monitorů není: kdyby unikl, cizí
člověk může heartbeat posílat za vás a monitor bude svítit zeleně, i když
záloha dávno neběží.

---

## Odběr a eskalace

### `GET rss.php[?page=slug]`

**Veřejné.** RSS 2.0 kanál s výpadky a jejich vyřešením. Bez parametru pokrývá
všechny monitory, s `page` jen ty, které jsou na dané status stránce.

Skrytá stránka vrací **404** stejně jako neexistující slug - přes RSS nelze
obejít viditelnost, kterou má stránka na webu.

Vznik a vyřešení incidentu jsou **dvě samostatné položky** s různým `guid`
(`incident-12-opened`, `incident-12-resolved`). Kdyby se vyřešení jen připsalo
k původní položce, čtečka by ho odběrateli nikdy neukázala - jednou zobrazené
`guid` už znovu nevypisuje.

Položka o vyřešení vzniká jen tehdy, když je `resolved_at` opravdu vyplněné.
U probíhajícího incidentu se nedopočítává z „teď".

Kanál je odkazovaný z hlavičky status stránky (`<link rel="alternate">`), takže
ho čtečky najdou samy.

### Eskalace nepřevzatých výpadků

Není to endpoint, ale chování cronu. Upozornění na výpadek dosud odešlo jednou
a tím to skončilo; když ho nikdo neviděl, výpadek běžel dál.

Nastavení (administrace → Notifikace):

| Klíč | Význam |
|---|---|
| `escalation_enabled` | `1` zapíná; výchozí vypnuto |
| `escalation_after_mins` | Lhůta na převzetí, výchozí 15 |
| `escalation_webhook_url` | Kanál pro eskalaci - záměrně jiný než běžná upozornění |

Eskaluje se incident, který **není vyřešený, nikdo ho nepřevzal** (`acknowledged_at`
je prázdné) a od vzniku uplynula lhůta. Každý incident nejvýš jednou - razítko
`escalated_at` brání opakování při každém běhu cronu.

Bez vyplněného kanálu se razítko **nedává**. Kdyby se dalo, incident by se
tvářil jako eskalovaný a po doplnění kanálu by se už neozval - tiché selhání
přesně tam, kde má pojistka fungovat.

---

## Endpointy, které dřív chyběly

Následující akce se z UI volaly, ale v `api.php` nebyly. Protože neznámá akce
vracela výchozí přehled služeb s kódem 200, tvářilo se každé takové volání
jako úspěch. **Dnes neznámá akce vrací 400** a hlídá to lint
(`run_api_action_lint.php`).

| Endpoint | Přístup | Popis |
|---|---|---|
| `action=export_csv&monitor_id=&days=` | veřejné | Historie kontrol monitoru jako CSV. Sloupec s chybovou hláškou dostane jen přihlášený - stránka monitoru je veřejná a hlášky nesou interní jména |
| `action=save_annotation` | admin | Poznámka ke grafu (`monitor_id`, `metric_key`, `timestamp`, `note`) |
| `action=annotations&monitor_id=&metric=&hours=` | přihlášený | Poznámky pro vykreslení. Anonym dostane prázdný seznam, ne 403 - graf bez poznámek není chyba |
| `action=forgot_password` | veřejné, POST | Odešle odkaz na obnovu hesla. Odpověď je stejná pro existující i neexistující e-mail |
| `action=setup` | veřejné, POST | Založí prvního administrátora. **Jen do prázdné tabulky uživatelů**, jinak 409 |
| `action=user_audit_log&limit=` | admin | Skutečný auditní protokol (kdo se přihlásil, kdo co změnil) |

> **Pozor na názvy:** `audit_logs` (s „s") vrací **výsledky kontrol z cronu**,
> ne uživatelské akce. Uživatelský protokol je `user_audit_log`. React dřív
> zobrazoval ten první pod nadpisem slibujícím přihlášení, takže filtry na
> bezpečnost a konfiguraci nemohly nikdy nic najít.

`action=session` nově vrací `installed` (existuje aspoň jeden uživatel) a
skutečný e-mail přihlášeného - dřív se vracelo natvrdo `admin@bloodkings.eu`
bez ohledu na to, kdo je přihlášený.

---

## Monitory

### `GET api.php?action=monitors`

**Přihlášený.** Seznam monitorů s posledním stavem, odezvou a metrikami agenta.

Odezva pochází z `monitor_logs`, hodnoty CPU/RAM/HDD z `vps_metrics` - nejsou
to sloupce tabulky `monitors`. Chybějící hodnota je `null`.

### `POST api.php?action=save_monitor`

**Admin.** Vytvoří (`id: 0`) nebo upraví monitor. Tělo je JSON.

Typy: `web`, `port`, `vps`, `openwrt`, `minecraft`, `teamspeak`, `discord`,
`heartbeat`, `agent_service`.

Vybrané parametry:

| Parametr | Platí pro | Poznámka |
|---|---|---|
| `target` | vše kromě `vps`, `openwrt`, `heartbeat` | Povinný tam, kde platí |
| `body_keyword` | `web` | Tělo odpovědi musí obsahovat tento řetězec |
| `heartbeat_interval` | `heartbeat` | **Sekundy.** Povinný, minimum 60 |
| `heartbeat_grace` | `heartbeat` | Sekundy; `null` = hlídá se přesně na interval |
| `latency_threshold_ms` | vše | `null` = upozornění na zpomalení vypnuté |
| `preset_id` | vše | `null` = monitor si drží vlastní nastavení metrik |
| `enabled_metrics` | vše | Pole klíčů; prázdné = doporučené výchozí |
| `allowed_actions` | `openwrt` | Jen s `remote_actions_enabled` |

Hesla (`sq_password`, `rcon_password`) se přepíší jen při zadání nové hodnoty -
prázdné pole uložené heslo nesmaže. Heartbeat token se při editaci
**nepřegeneruje**: úloha ho má zadrátovaný ve svém curl příkazu.

### `POST api.php?action=delete_monitor`

**Admin.** Tělo `{"id": 12}`.

---

## Metriky a historie

| Endpoint | Přístup | Popis |
|---|---|---|
| `action=metric_series&monitor_id=&metric=&period=` | veřejné | Jedna metrika v čase |
| `action=metric_series_batch` | veřejné | Víc metrik jedním dotazem |
| `action=metric_detail&monitor_id=&metric=` | veřejné | Kontext stránky detailu metriky |
| `action=process_history&monitor_id=&kind=&at=&radius=` | veřejné | Které procesy běžely kolem daného okamžiku |
| `action=metrics_history&monitor_id=&period=` | veřejné | Historie metrik agenta |
| `action=daily_uptime&days=` | veřejné | Denní dostupnost z `uptime_daily` |
| `action=uptime_windows` | veřejné | Dostupnost monitorů za 24 h / 7 d / 30 d / 90 d jedním průchodem; nezměřené okno je `null`, nikdy 100 |
| `action=check_stages&monitor_id=` | veřejné | Rozpad kontroly (DNS/TCP/TLS/HTTP, ServerQuery) |
| `action=regions&days=` | veřejné | Dostupnost podle místa měření (`checked_from`) |
| `action=public_status` | veřejné | Souhrn pro veřejnou stránku (počty, průměrná dostupnost) |
| `action=badge[&monitor_id=][&type=uptime][&lang=en]` | veřejné | Vložitelný SVG odznak (cache 60 s): živý stav, s `type=uptime` 30denní dostupnost; bez `monitor_id` shrnuje celou flotilu, neznámý monitor je 404 |
| `action=websites_overview` | veřejné | Weby s certifikáty a dostupností v okně |
| `action=monitor_insights&monitor_id=` | veřejné | Odvozená pozorování k jednomu monitoru |
| `action=dashboard_insights&limit=` | veřejné | Totéž napříč monitory, pro přehled |
| `action=ui_config` | veřejné | Nastavení vzhledu pro frontend (logo, názvy) |
| `action=alerts_read_state` | přihlášený | Meze přečtených upozornění (`readUpToId`) |
| `action=convert_to_agent_check` | admin | Převede proces hlídaný agentem na samostatný monitor |

**Poznámka k dlouhodobým datům:** syrové logy se po 30 dnech mažou. Roční SLA
se proto počítá z denní agregace `uptime_daily`, ne z logů. Odpovědi vždy
uvádějí, za jaké období hodnota skutečně je - nikdy nevydávají třicetidenní
okno za rok.

### Období v `period`

| Hodnota | Okno | Zdroj |
|---|---|---|
| `15m`, `1h`, `6h`, `12h`, `24h` | 15 min až den | `vps_metrics` / `monitor_logs` |
| `7d`, `30d` | týden, měsíc | totéž |
| `90d`, `180d`, `1y` | čtvrtletí až rok | `metrics_daily` (denní průměr) |

Neznámá hodnota spadne na den. U dlouhých období odpověď nese
`resolution: "daily"` - bod je průměr dne, ne jednotlivé měření, a klient to
musí přiznat, jinak by uživatel z grafu četl přesnost, kterou data nemají.

> Do 12. 8. 2026 se okno počítalo v hodinách a dvě období vycházela špatně:
> `15m` vracelo hodinu a `6h` vracelo 24 hodin. Popisek v UI tedy tvrdil něco
> jiného, než graf ukazoval. Hlídá to teď `run_tests.php` (jednotkově) i
> `run_api_tests.php` (nad skutečnou databází).

### `action=metric_detail`

Kontext pro stránku detailu metriky - co metrika znamená, jaké má monitor
prahy, které příbuzné metriky vůbec hlásí a co se v okolí dělo:

```json
{
  "monitor": { "id": 6, "name": "Turris", "type": "openwrt", "assetId": 6 },
  "metric": { "key": "cpu", "label": "Využití CPU", "unit": "%", "counter": false },
  "thresholds": { "warning": 75, "critical": 90 },
  "related": [{ "key": "ram", "label": "Využití paměti", "unit": "%", "latest": 41.2 }],
  "events": [{ "t": 1755000000000, "type": "status_change", "label": "Obnoveno" }]
}
```

Statistiky (aktuální, průměr, špička) se **záměrně neposílají** - klient je
počítá z týchž bodů, které kreslí, takže po přepnutí období nemůžou popisovat
jiné okno než graf. `thresholds.critical: null` znamená, že práh nastavený
není a pásmo se v grafu nekreslí; `related` obsahuje jen metriky, které
monitor v posledním měření skutečně hlásil, aby proklik nevedl do prázdna.

### `action=process_history`

Odpovídá na otázku, kterou graf nezodpoví: v 19:40 vyskočilo CPU na 90 %, ale
čím?

| Parametr | Význam |
|---|---|
| `monitor_id` | Povinný |
| `kind` | `cpu` (výchozí) nebo `ram` - který žebříček číst |
| `at` | Střed okna v unixových sekundách. Povinný |
| `radius` | Poloměr v minutách, výchozí 10, maximum 180 |

```json
{
  "samples": [{ "at": "2026-08-14 19:40:02", "name": "hostapd", "pid": 1234, "cpuPct": 87.5, "ramMb": 12.5 }],
  "from": "2026-08-14 19:30:02",
  "to": "2026-08-14 19:50:02",
  "enabled": true,
  "pruned": false
}
```

Prázdné pole `samples` má tři různé příčiny a klient je musí rozlišit:
`enabled: false` znamená vypnutý sběr, `pruned: true` znamená prořezané okno,
do kterého žádná špička nespadla, a jinak pro tu chvíli prostě vzorky nejsou.
Slít to do „žádná data" by způsobilo, že vypnutá funkce vypadá jako klidný
stroj.

Retence je nastavitelná (`process_history_days`, volitelně
`process_history_peak_after_days` s `process_history_peak_pct`), protože tahle
tabulka roste ze všech nejrychleji: deset řádků na monitor a minutu. Změřeno:
1 728 000 řádků zabírá 253 MB a tenhle dotaz trvá 0,089 ms, protože ho krycí
index zúží na 60 řádků. Žádná stránka do té tabulky při načtení nesahá.

---

## Incidenty a reporty

| Endpoint | Přístup | Popis |
|---|---|---|
| `action=incidents` | veřejné | Seznam incidentů |
| `action=create_incident` | přihlášený | Ruční založení |
| `action=incident_action` | přihlášený | `op`: acknowledge / resolve / postmortem |
| `action=events&monitor_id=&limit=` | veřejné | Události monitoru |
| `action=sla_report&days=` | veřejné | SLA přehled |
| `action=audit_logs&limit=` | veřejné | Poslední kontroly napříč monitory |

> **Pozor:** `audit_logs` a `sla_report` jsou dnes bez přihlášení a vracejí
> názvy monitorů a texty chybových hlášek. Ty můžou obsahovat interní hostname
> nebo detail infrastruktury. Není to záměr návrhu, je to stav kódu - stojí za
> rozhodnutí, jestli je schovat za přihlášení.
>
> Ověřeno 13. 8. 2026: posledních 200 záznamů včetně všech 50 výpadkových
> neslo jen obecné hlášky („Discord API neodpovídá (kód 503)", „cURL chyba:
> Operation timed out"), žádné interní adresy.

---

## Konfigurace a správa

| Endpoint | Přístup | Popis |
|---|---|---|
| `action=get_settings` / `save_settings` | admin | Globální nastavení |
| `action=presets` / `save_preset` / `delete_preset` / `assign_preset` | veřejné čtení, admin zápis | Profily metrik |
| `action=status_pages` / `save_status_page` / `delete_status_page` | přihlášený | Veřejné status stránky |
| `action=dashboard_layout` | veřejné čtení, admin zápis | Pořadí a viditelnost dlaždic |
| `action=users` | přihlášený | Seznam uživatelů |
| `action=export_config` | přihlášený | Export konfigurace bez tajemství |
| `action=generate_metrics_token` | admin | Token pro Prometheus exporter |
| `action=upload_logo` | admin | Logo status stránky |
| `action=send_digest&period=` | admin | Ruční odeslání souhrnu |
| `action=trigger_remote_action` | admin | Akce na routeru (jen povolené) |
| `action=discovered_services` / `import_discovered_service` | admin | Service Discovery |
| `action=get_subscriptions` / `save_subscriptions` | přihlášený | Odběr upozornění |
| `action=save_user` / `delete_user` | admin | Správa uživatelů pro React (vytvoření posílá pozvánkový e-mail, smazání odmítne vlastní účet); do 2026-08 existovaly jen jako formulářové handlery v admin.php a volání z Reactu končila na „neznámé akci" |
| `action=my_profile` / `update_profile` | přihlášený | Vlastní profil: kontakty, kanály notifikací, jazyk e-mailů, změna hesla (vyžaduje stávající heslo) |
| `action=oauth_unlink` | přihlášený | Odpojení OAuth přihlašování (vyžaduje stávající heslo) |
| `action=totp_setup` / `totp_confirm` / `totp_disable` / `totp_recovery_regenerate` | přihlášený | Zapnutí dvoufázového ověření: tajemství žije v session, dokud ho kód nepotvrdí; potvrzení vrací deset jednorázových záložních kódů (ukládají se jen hashe, zobrazí se právě jednou); záložní kód funguje při přihlášení místo TOTP kódu a spotřebuje se; nová sada i vypnutí vyžadují heslo |
| `action=set_password` | veřejné (jednorázový token) | Nastavení hesla z pozvánky nebo resetu; token se spotřebuje prvním úspěchem |

`export_config` záměrně nevrací hesla, tokeny ani agent klíče - existuje na
zálohu nastavení, ne na klonování přístupů.

Seznam klíčů nastavení žije na jediném místě (`bk_settings_keys()` v `db.php`)
a `run_settings_parity_lint.php` hlídá, že se UI neptá na klíč, o kterém server
neví. Existoval třikrát a rozešel se, což tiše mazalo nastavení WhatsAppu: šlo
uložit, ale nečetlo se zpátky, takže formulář zobrazil prázdná pole a další
uložení skutečné hodnoty přepsalo.

---

## Rozhraní pro zařízení

### `POST agent_api.php`

Telemetrie z agentů (VPS, OpenWrt). Autorizace polem `agent_key` v těle.
Vyžaduje POST a platný JSON, jinak 405 / 400.

Server přijímá i klíče, o kterých předem neví - jinak by nová metrika z agenta
tiše zmizela. Platí ale omezení: typovaná hodnota serveru vždy vyhrává,
přihlašovací údaje se nepřebírají, název musí vypadat jako identifikátor,
pole má strop 8 KB a najednou přibude nejvýš 64 nových klíčů.

Odděleným lehkým POSTem chodí `action_result` - potvrzení provedené Remote
Action. Nemá telemetrická pole, proto se zpracovává dřív než jejich validace.

### `GET|POST node_api.php?action=get_monitors|post_results`

Rozhraní pro vzdálené měřicí uzly. Autorizace sdíleným `cron_key`
(`hash_equals`, tedy bez časového postranního kanálu).

Uzel si stáhne seznam monitorů ke kontrole a pošle zpátky výsledky včetně
`checked_from`. Právě tahle hodnota plní `action=regions`.

> **Stav (ověřeno 15. 8. 2026):** vlastní uzel přes `node_client.php` zatím
> neběží, ale měření z jednoho místa už dávno nepocházejí - `action=regions`
> hlásí jedenáct různých lokalit. Kromě hlavního serveru ve Frankfurtu měří
> Cloudflare Worker a běhy GitHub Actions (Boydton, Phoenix, Chicago), takže
> „služba je mrtvá" od „náš server na ni nevidí" odlišit lze. Vlastní uzel by
> přidal další místo, není to ale díra v pokrytí.

---

## Ostatní endpointy

| Endpoint | Formát | Přístup | Popis |
|---|---|---|---|
| `metrics.php?token=…` | Prometheus text 0.0.4 | token | Scrapování externím Prometheem; bez nastaveného tokenu je vypnutý. Token lze poslat i hlavičkou `Authorization: Bearer` |
| `badge.php?id=&type=` | 302 | veřejné | Zastaralý alias - přesměruje na `action=badge` (staré README embedy fungují dál) |
| `widget.php?id=` | HTML | veřejné | Kompaktní vložení přes iframe |
| `health.php` | JSON | admin nebo CLI | Kontrola úplnosti schématu databáze |
| `cron.php[?key=…]` | text | CLI nebo `cron_key` | Sběr dat; z webu jen s klíčem |

---

## Zápisy: POST + CSRF

Od 2026-08 každá stav měnící akce přijímá **jen POST** (GET dostane 405)
a každý session-autentizovaný zápis musí nést CSRF token session v hlavičce
`X-CSRF-Token` (multipart formuláře mohou poslat pole `csrf_token`). Token
vrací `action=login` a `action=session`. Tokenem autentizované toky
(`set_password`) a akce session teprve zakládající (`login`, `setup`,
`forgot_password`, `logout`) jsou vyjmuté. CORS odráží jen vlastní origin -
cizí origin `Access-Control-Allow-Origin` nedostane vůbec.

## Verzování a stabilita

API nemá verzování v URL. Aplikace i SPA se nasazují společně, takže se
kontrakt může měnit mezi commity.

Za stabilní se dá považovat to, co používají nasazená zařízení a co se tedy
nedá změnit bez zásahu na nich:

- `agent_api.php` - běží na cizích strojích
- `node_api.php` - totéž
- `heartbeat.php` - URL je zadrátovaná v cronech
- `metrics.php` - scrapuje Prometheus

Zbytek slouží vlastnímu frontendu a mění se s ním.
