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
(`credentials: 'include'` ve `fetch`). Odhlášení: `POST action=logout`. Ukončí i relaci staré administrace a zapíše do auditu záznam `logout`.

Stav relace: `GET /status/api.php?action=session`.

### Kdo vidí který monitor

Monitor patří účtům, které jsou k němu přiřazené, a jeden monitor jich může mít
víc. Administrátor vidí a mění všechny monitory. Účet s rolí `user` vidí jen
přiřazené monitory, jen pro čtení, spolu s vlastním profilem a odběrem
upozornění pro tyto monitory. Monitor, který volající vidět nesmí, odpoví 404
stejně jako neexistující, takže id nejde osahávat. Řádky označené **přiřazený
monitor** se řídí tímto pravidlem a seznamy vracejí jen viditelné monitory.

`scope=public` si u `public_status`, `monitors`, `daily_uptime`, `uptime_windows`, `regions`,
`events` a `incidents` řekne o pohled veřejné status stránky. Zahrnuje všechny
monitory, je stejný pro každého a nenese cíle, hostname, procesy ani názvy
rozhraní. Nepřihlášený volající dostane vždy tento pohled.

### Co vidí nepřihlášený návštěvník

Veřejné odpovědi nechají ze struktury `details` jen povolené klíče: agregáty
jako `cpu`, `ram` a `hdd`, verzi agenta a počty hráčů či klientů. Všechno
ostatní - IP adresy, názvy rozhraní, procesy, porty, nalezené služby - zůstává
na serveru, i klíče přidané v budoucnu. Důvod selhání se zúží na pevnou sadu vět
- HTTP kód, vypršený čas, DNS, TLS, zavřený port - bez hostitele, portu, procesu
či hledaného textu a aktualizace incidentů přijdou o automatický důvod kontroly
i jméno operátora.

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

### Denní připomínka toho, co je pořád rozbité

Není to endpoint, ale chování cronu hned za blokem digestů. Výstraha odchází
jen při ZMĚNĚ stavu, takže monitor, který spadl ve středu, se po zbytek týdne
neozval - právě tak zůstal čtyřdenní výpadek neviditelný.

Nastavení (administrace → Notifikace):

| Klíč | Význam |
|---|---|
| `daily_reminder_enabled` | `1` zapíná; **výchozí zapnuto** |
| `daily_reminder_hour` | Od které hodiny smí odejít, 0-23, výchozí 8 |
| `last_daily_reminder_sent` | Stráž: datum posledního rozhodnutí, zapisuje cron |

Odchází nejvýš jednou denně, od nastavené hodiny, a **jen když je opravdu něco
rozbité**: monitory ve výpadku nebo varování (od nejdelšího, s uloženým
důvodem), zvlášť sekce tichého sběru dat (agenti, kteří přestali hlásit,
heartbeaty po lhůtě, `bk_get_collection_issues`), nepřevzaté otevřené incidenty
a jeden řádek s posledním dokončeným během sběru - aby se mrtvý sběrač nemohl
schovat za krátkou zprávu. Monitory v údržbě a archivované se nepočítají.

Když není nic rozbité, **neodejde nic**: denní „vše v pořádku" naučí čtenáře
filtrovat odesílatele a s ním i první opravdovou zprávu. Rozhodnutí se přesto
zapíše - do protokolu odchozích zpráv jde řádek s `kind=daily_reminder`,
`channel=none` a `status=skipped`, takže „žádný e-mail nepřišel" jde odlišit od
„připomínka je rozbitá".

Razítko dne se zapisuje bez ohledu na to, jak to dopadlo, tedy i u přeskočení.
Na routeru běží cron každou minutu: bez razítka by zdravý stav zapsal řádek
každou minutu a odmítnutý kanál by se zkoušel do půlnoci a pohřbil své vlastní
selhání pod stovkami řádků.

---

## Endpointy, které dřív chyběly

Následující akce se z UI volaly, ale v `api.php` nebyly. Protože neznámá akce
vracela výchozí přehled služeb s kódem 200, tvářilo se každé takové volání
jako úspěch. **Dnes neznámá akce vrací 400** a hlídá to lint
(`run_api_action_lint.php`).

| Endpoint | Přístup | Popis |
|---|---|---|
| `action=export_csv&monitor_id=&days=` | přiřazený monitor | Historie kontrol monitoru jako CSV včetně chybové hlášky každé kontroly |
| `action=save_annotation` | admin | Poznámka ke grafu (`monitor_id`, `metric_key`, `timestamp`, `note`) |
| `action=annotations&monitor_id=&metric=&hours=` | přiřazený monitor | Poznámky pro vykreslení. Anonym i monitor, ke kterému účet není přiřazený, dostanou prázdný seznam, ne 403 - graf bez poznámek není chyba |
| `action=delete_annotation` | admin, POST | Smaže poznámku podle `id`. Poznámka je tvrzení a chybné tvrzení u grafu musí jít vzít zpět |
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

**Přiřazené monitory, nebo veřejný pohled.** Seznam monitorů s posledním stavem,
odezvou a metrikami agenta, bez archivovaných, pokud si `archived=1` neřekne
právě o ně. Účet `user` dostane přiřazené monitory, administrátor
všechny. Nepřihlášený volající nebo kdokoli se `scope=public` dostane všechny
monitory s `target`, `port`, `hostname` a `agentLastSeen` nastavenými na `null`
a jen s povolenými klíči `details`.

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

### `POST api.php?action=archive_monitor` / `unarchive_monitor`

**Administrátor.** Tělo `{"id": N}`. Archivace je pro monitor, který skončil
nadobro, třeba nahrazený router. Monitor i jeho historie zůstanou, ale vypadne ze
všeho živého: seznamy a souhrny ho vynechají, cron ho nekontroluje, žádné
upozornění neodejde, otevřené incidenty se uzavřou, čekající vzdálené akce
selžou, hlášení jeho agenta se odmítnou s 403 a `heartbeat.php` odpoví 410. Detail
podle id zůstává čitelný a `action=monitors&archived=1` vypíše archiv. Každý zápis
do archivovaného monitoru odpoví 409. Obnovení nastaví stav na `unknown` do příští
kontroly nebo hlášení. Obojí se zapíše do auditu.

### `GET api.php?action=agent_install_info&monitor_id=`

**Administrátor.** Co potřebuje instalace agenta jednoho monitoru: `agentKey`,
`apiUrl` tohoto serveru a adresy ke stažení ve `files`. Klíč je přihlašovací
údaj, proto se každé čtení zapíše do auditu. Archivovaný monitor odpoví 409.

---

---

## Metriky a historie

| Endpoint | Přístup | Popis |
|---|---|---|
| `action=metric_series&monitor_id=&metric=&period=` | přiřazený monitor | Jedna metrika v čase. Metrika označená `step` (`wan_errors`, `wan_drops`, `wan_ring_drops`, `wan_link_flaps`, `conntrack_drops`) už nese přírůstek mezi dvěma hlášeními: syrový bod je krok té minuty a devadesátidenní pohled je SOUČET dne (`avg_val * samples`), nikdy jeho průměr |
| `action=metric_series_batch&monitor_id=&period=` | přiřazený monitor | Všechny grafy zařízení v jednom volání. Série pro `hdd` a `ram` navíc nese `daysToFull` (počet dní do zaplnění), a to jen tam, kde je růst opravdu naměřený - chybějící klíč znamená bez predikce, nikdy nulu |
| `action=metric_detail&monitor_id=&metric=` | přiřazený monitor | Kontext stránky detailu metriky |
| `action=metric_correlations&monitor_id=&metric=&period=` (volitelně `&all=1` pro všechny porovnávané metriky, ne jen nejsilnějších 8) | přiřazený monitor | Jak se ostatní metriky zařízení hýbaly spolu s touto (Pearson). Počítá se jen z metrik ve `vps_metrics`: sdílejí jeden řádek měření, takže se vzorky párují přesně místo průměrování do společných oken, které by obě řady vyhladilo a koeficient nadhodnotilo. `r` je `null`, nikdy `0`, když je nedefinovaný - neměnná řada (`reason: constant`) nebo málo překryvů (`few_samples`) |
| `action=metric_heatmap&monitor_id=&metric=&days=` | přiřazený monitor | Mřížka hodina × den (jedno pole = průměr hodiny, u počítadel přírůstek za hodinu). Strop je 30 dní - syrová měření se po nich mažou, takže delší okno by tiše odpovědělo kratším. Hodina bez měření je `null`, nikdy `0` |
| `action=link_traffic&monitor_id=&days=` | přiřazený monitor | Provoz routeru podle role linky: primární (`wan_l3_device`) vs. LTE záloha (`lte_device`) za dnes / 7 / 30 dní z denních součtů per rozhraní, plus období výpadku primární linky (`wan_down_periods`, `wan_down_seconds`, `wan_down_now`) spárovaná z událostí `wan_lost`/`wan_restored` - jestli v té době provoz opravdu šel po záloze, říkají bajty na záložním zařízení, ne tato období (otevřené období běží do teď; výpadek, který začal před oknem a dosud neskončil, se dohledá zvlášť a započítá od začátku okna, jinak by router běžící na záloze celé týdny hlásil „nikdy"). Role bere jen z toho, co agent hlásí - bez `wan_l3_device` (agent < 0.1.3) je primární strana `null`, ne odhad podle jména |
| `action=process_history&monitor_id=&kind=&at=&radius=` | přiřazený monitor | Které procesy běžely kolem daného okamžiku |
| `action=router_recommendations&monitor_id=` | přiřazený monitor | Co na tomhle routeru právě teď najde týdenní stroj doporučení, jen pro čtení (GET nikdy nezapíše řádek stavu). Viz „Zdraví routeru" níž |
| `action=storage_history&monitor_id=&days=` | přiřazený monitor | Denní historie jednotlivých disků (teplota, čítače chyb, zápisy hostitele, opotřebení). `days` se ořízne na 1-400; den, který nikdo nezměřil, je `null`, nikdy `0`. Viz „Zdraví routeru" níž |
| `action=wan_bottleneck&monitor_id=` | přiřazený monitor | Co omezuje internetovou linku routeru, zvlášť pro každý směr, z jeho posledních měření rychlosti. Viz „Zdraví routeru" níž |
| `action=metrics_history&monitor_id=&period=` | přiřazený monitor | Historie metrik agenta |
| `action=daily_uptime&days=` | veřejný stav / přiřazené | Denní dostupnost z `uptime_daily` |
| `action=uptime_windows` | veřejný stav / přiřazené | Dostupnost monitorů za 24 h / 7 d / 30 d / 90 d jedním průchodem; nezměřené okno je `null`, nikdy 100 |
| `action=check_stages&monitor_id=` | přiřazený monitor | Rozpad kontroly (DNS/TCP/TLS/HTTP, ServerQuery) |
| `action=regions&days=` | veřejný stav / přiřazené | Dostupnost podle místa měření (`checked_from`) |
| `action=public_status` | veřejný stav / přiřazené | Souhrn pro veřejnou stránku (počty, průměrná dostupnost). V aplikaci dostane účet `user` součty jen za přiřazené monitory |
| `action=badge[&monitor_id=][&type=uptime][&lang=en]` | veřejné | Vložitelný SVG odznak (cache 60 s): živý stav, s `type=uptime` 30denní dostupnost; bez `monitor_id` shrnuje celou flotilu, neznámý monitor je 404 |
| `action=websites_overview` | přiřazený monitor | Weby s certifikáty a dostupností v okně |
| `action=monitor_insights&monitor_id=` | přiřazený monitor | Odvozená pozorování k jednomu monitoru |
| `action=dashboard_insights&limit=` | přiřazený monitor | Totéž napříč monitory, pro přehled |
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
  "monitor": {
    "id": 6,
    "name": "Turris",
    "type": "openwrt",
    "target": "10.0.0.1",
    "port": null,
    "checkedFrom": "Praha, CZ",
    "assetId": 6
  },
  "metric": { "key": "cpu", "label": "Využití CPU", "unit": "%", "counter": false, "step": false },
  "thresholds": { "warning": 75, "critical": 90 },
  "thresholdsDerived": { "warning": true, "critical": false },
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

### Zdraví routeru: úložiště, profil Wi-Fi a týdenní doporučení

Tři endpointy jen pro čtení sdílejí jeden stroj. Počítá přes **sedm celých dní**
před dneškem a týdenní pravidlo vyhodnotí, až když jsou k dispozici aspoň
**čtyři dny s daty** (360 vzorků na den); router s kratší historií dostane
`applicable: false` a řekne proč, místo aby hlásil „nic jsme nenašli".
Nezměřená hodnota je všude níž `null`, nikdy nula.

`action=router_recommendations&monitor_id=` odpovídá

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
    "title": "Disk běží teplý",
    "measured": "za poslední týden průměrně 67 °C (nejvýše 68 °C)",
    "action": "Zkontrolujte proudění vzduchu …",
    "subject": { "kind": "disk", "label": "sda" },
    "openSince": "2026-09-01 04:12:00",
    "muted": false
  }],
  "muted": []
}
```

- `applicable: false` nese `reason`: `not_router` (není to monitor OpenWrtu),
  `agent_old` (pravidla čtou pole, která posílá teprve agent 0.1.7, a starý
  agent není zdravý router, který nemá co hlásit) nebo `silent` (víc než sedm
  dní bez hlášení).
- `severity` je `critical` (jednat hned), `warning` (jednat tento měsíc) nebo
  `info` (dobré vědět). Pořadí `items` je závažnost, pak oblast, pak vlastní
  pořadí pravidla – nikdy abeceda klíčů.
- Každé týdenní pravidlo má hranici pro **spuštění** a hranici pro **držení**.
  Nález, který už je otevřený, zůstane otevřený až k hranici držení, takže
  metrika na hraně neblikne každé pondělí v e-mailu.
- Pravidlo, které nešlo vyhodnotit, se nehlásí jako „v pořádku": v `items`
  není a jeho uložený řádek se nezmění.
- **Ztlumení** je rozhodnutí administrátora a platí pro všechny, kdo na router
  vidí. Ztlumený nález se přesune do `muted` s `mutedReason`, `mutedBy` a
  `mutedAt` a do týdenního e-mailu nejde. Vrátí se – označený `wasMuted` –
  jakmile je stejný nález **závažnější**, než byl v okamžiku ztlumení.
  Ztlumení pravidla, které už neplatí, se pořád vypisuje, s prázdnými texty a
  `active: false`, aby šlo vzít zpět.
- V žádném textu se neobjeví SSID, MAC adresa, BSSID, sériové číslo disku ani
  WWN; disky se pojmenovávají svým `/dev` jménem a rozlišují serverovým otiskem.

`action=storage_history&monitor_id=&days=` odpovídá `{monitorId, days, disks: []}`,
jedna položka na disk s jeho identitou (`key`, `label`, `model`, `size`) a se
seznamem `days: []` po `{day, tempMin, tempMean, tempMax, samples, reallocated,
pending, offline, runtimeBadBlocks, unsafeShutdowns, powerCycles, hostWritten,
hostWrittenPartial, wearPct}`. `samples` počítá čerstvá čtení SMART toho dne;
`hostWrittenPartial: true` říká, že denní počet bajtů je neúplný (router se
restartoval nebo čítač přetekl) a hodnota je dolní odhad.

`action=wan_bottleneck&monitor_id=` odpovídá verdiktem pro každý směr
(`verdict.dl`, `verdict.ul`), testy, o které se opírá, cestou WAN a tarifem:

```json
{ "class": "line_limited", "reason": "below_plan", "confidence": "high",
  "basis": [41, 38, 35],
  "numbers": { "s_mbps": 700.0, "s_max_mbps": 710.0, "agree": 3,
               "span_days": 3, "servers": 2, "tests": 3 } }
```

- `class` je `none`, `link_limited`, `cpu_limited`, `line_limited` nebo
  `inconclusive`; `reason` říká, které pravidlo rozhodlo (`plan_reached`,
  `sqm_shaper`, `wan_port`, `packet_path`, `test_client`, `below_plan`, …).
- `line_limited` – jediný verdikt, který obviní cizí techniku – potřebuje tři
  shodné testy, rozpětí aspoň dvou dnů, **dva různé servery** do 15 % od sebe,
  důkaz, že aspoň jeden z nich někdy tarif opravdu dodal, a výsledky mimo
  náhorní plošiny gigabitového a 2,5gigabitového portu. Co nedokáže, vrací jako
  `inconclusive` s důvodem (`not_enough_tests`, `single_server`,
  `server_limited`, `server_capacity_unproven`, `port_plateau`,
  `tests_disagree`).
- Bez uloženého tarifu se nic nikdy nenazve „pod tarifem": odpovědí je
  `inconclusive / no_plan_known` a karta si o tarif řekne. Test, který router
  nespustil sám, umí jen potvrdit dosažený tarif, nikdy prohlásit linku za
  pomalou.
- `basis` vypisuje id řádků `speedtest_results`, o které se verdikt opírá, aby
  karta mohla ukázat přesně to, co se měřilo.

---

## Incidenty a reporty

| Endpoint | Přístup | Popis |
|---|---|---|
| `action=incidents` | veřejný stav / přiřazené | Seznam incidentů. Začátek výpadku je okamžik, kdy monitor spadl (`last_status_change`), ne poslední potvrzení výpadku. Veřejný pohled vynechá cíle, jména operátorů a důvody kontrol, i v `updates` |
| `action=create_incident` | přihlášený | Ruční založení. Volitelné `monitorId` naváže incident na monitor: 404 neznámý, 409 archivovaný, 409 když už monitor otevřený incident má |
| `action=incident_action` | přihlášený | `op`: acknowledge / resolve / postmortem. `resolve` vrátí `monitorStillDown: true`, když je monitor i po uzavření incidentu dál nedostupný |
| `action=events&monitor_id=&limit=` | veřejný stav / přiřazené | Události monitoru Navíc vrací `statusChange`: kontrolu, která zaznamenala poslední změnu stavu (přišpendlenou na `monitors.last_status_change`, se stavem, ze kterého se přešlo), nebo `null` - v samotném seznamu ten řádek často není, protože okno drží nejnovější kontroly plus nejnovější výpadky |
| `action=sla_report&days=` | přiřazený monitor | SLA přehled |
| `action=audit_logs&limit=` | admin | Poslední kontroly napříč monitory |

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
| `action=test_notification` | admin | POST `{channel}` (email/discord/telegram/slack): pošle jednu skutečnou testovací zprávu s uloženým nastavením, vrací `{ok, message}` |
| `action=notification_log&monitor_id=&kind=&channel=&ok=&from=&to=&q=&before_id=&limit=&summary=1` | admin | Co se odeslalo, komu, kterým kanálem a jestli to prošlo. Řádek vzniká i u neúspěchu - to je ta zajímavější půlka. Od chvíle, kdy protokoluje přímo `send_email()`, jsou tu všechny druhy zpráv, ne jen výstrahy: `kind` je filtruje (`alert`, `daily_reminder`, `digest`, `invitation`, …), `channel` vybírá cestu, `ok=0` jen neúspěchy, `q` je část adresy příjemce. `from`/`to` berou datum i datum s časem; holé datum v `to` znamená celý ten den a hodnota, která se nedá přečíst, je 400, ne tiše širší odpověď. Stránkuje se kurzorem - vrácený `nextCursor` se pošle jako `before_id` - protože během čtení přibývají řádky a stránkování přes offset by jeden řádek zopakovalo a jiný přeskočilo. `kinds` a `channels` v odpovědi vypisují hodnoty z CELÉHO protokolu, takže filtr nikdy nevezme možnost, která by ho zrušila. `summary=1` přidá `last24h` a `last7d` (`total`, `failed` a totéž po kanálech), rovněž přes celý protokol a nikdy zúžené filtry: živí pruh „něco neodešlo" a pruh, kterému filtr dokáže výpadek rozmluvit, je horší než žádný. Obsah zprávy se neukládá nikdy, řádky se mažou po 180 dnech |
| `action=interface_traffic_daily&monitor_id=&days=` | přiřazený monitor | Provoz po dnech a rozhraních, seřazený od nejvytíženějšího. Chybějící den = ten den se nehlásilo, ne nulový provoz |
| `action=process_top&monitor_id=&kind=&minutes=` | přiřazený monitor | Které procesy braly výkon za celé období (průměr, špička, počet vzorků), seskupené podle jména - restartovaná služba se nerozdrobí na řádek na pid. `enabled: false` = historie procesů je v nastavení vypnutá |
| `action=toggle_maintenance` | admin | POST `{monitor_ids[], maintenance, description?, maintenance_end?}`: zapne nebo vypne údržbu pro jeden i více monitorů. Vypnutí maže i okno, aby další údržba nevypršela hned po zapnutí |
| `action=clear_monitor_history` | admin | POST `{monitor_id, confirm_name}`: smaže měření, logy i denní agregace monitoru a vrátí ho do stavu „neznámý“. Nevratné, proto chce zpátky přesný název monitoru |
| `action=redetect_location` | admin | Vynutí nový dotaz na geolokaci serveru a uloží ji do `ip_loc_local` |
| `action=router_recommendation_mute` | admin | POST `{monitor_id, key, muted, reason?}`: ztlumí jedno doporučení routeru (nebo ztlumení vezme zpět). Závažnost se vyhodnotí na serveru, nikdy se nebere z těla požadavku – ztlumení tak umlčí nález v dnešní podobě, ne jeho horší verzi. Neznámé id pravidla je 400, archivovaný monitor se odmítne a každá změna zapíše řádek do `audit_log` |
| `action=wan_settings_save` | admin | POST `{monitor_id, plan_down_mbit?, plan_up_mbit?, plan_ok_pct?}`: tarif routeru. `null` nebo prázdný řetězec hodnotu smaže; číslo mimo 1-100000 (30-100 u procent) je 400 a nikdy se neořízne, protože oříznutý tarif je tarif, který majitel nezadal. Chybějící klíč `probe_enabled` nechá uložený souhlas být |
| `action=presets` / `save_preset` / `delete_preset` / `assign_preset` | veřejné čtení, admin zápis | Profily metrik |
| `action=status_pages` / `save_status_page` / `delete_status_page` | seznam veřejný, skryté stránky a zápis admin | Veřejné status stránky |
| `action=dashboard_layout` | přihlášený | Pořadí a viditelnost dlaždic |
| `action=users` | admin | Seznam uživatelů, každý účet se svými `monitorIds` |
| `action=export_config` | admin | Export konfigurace bez tajemství |
| `action=generate_metrics_token` | admin | Token pro Prometheus exporter |
| `action=upload_logo` | admin | Logo status stránky |
| `action=send_digest&period=` | admin | Ruční odeslání souhrnu |
| `action=trigger_remote_action` | admin | Akce na routeru (jen povolené) |
| `action=discovered_services` / `import_discovered_service` | admin | Service Discovery |
| `action=get_subscriptions` / `save_subscriptions` | přihlášený | Odběr upozornění, jen pro monitory, které účet vidí |
| `action=public_subscribe` / `public_subscribe_confirm` / `public_unsubscribe` | veřejné | E-mailový odběr pro návštěvníky bez účtu: double opt-in (nic se neposílá, dokud majitel nepotvrdí), IP rate limit na přihlášení, neutrální odpovědi (žádná enumerace), odhlášení jedním klikem v každém e-mailu |
| `action=public_subscribers` / `delete_public_subscriber` | admin | Přehled odběratelů a ruční odstranění (GDPR žádosti) |
| `action=save_user` / `delete_user` | admin | Správa uživatelů pro React (vytvoření posílá pozvánkový e-mail, smazání odmítne vlastní účet; `monitorIds` určuje, které monitory účet `user` vidí - vynechané pole přiřazení zachová, `[]` ho zruší, neexistující id se zahodí); do 2026-08 existovaly jen jako formulářové handlery v admin.php a volání z Reactu končila na „neznámé akci" |
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
Vyžaduje POST a platný JSON, jinak 405 / 400. Povinný je jen `agent_key`:
`cpu`, `ram` a `hdd` smí být `null`, což agenti posílají při prvním běhu a po
restartu. Hlášení archivovaného monitoru se odmítne s 403 a `archived: true`.

Server přijímá i klíče, o kterých předem neví - jinak by nová metrika z agenta
tiše zmizela. Platí ale omezení: typovaná hodnota serveru vždy vyhrává,
přihlašovací údaje se nepřebírají, název musí vypadat jako identifikátor,
pole má strop 8 KB a najednou přibude nejvýš 64 nových klíčů.

Odděleným lehkým POSTem chodí `action_result` - potvrzení provedené Remote
Action. Nemá telemetrická pole, proto se zpracovává dřív než jejich validace.

**Agent 0.1.7 (OpenWrt) přidává** `wifi_radios[]` (jeden objekt na bezdrátové
síťové zařízení, nejvýš 16: pásmo odvozené z frekvence, generace a šířka, počty
klientů podle schopností a šifrování, šum a vytížení kanálu), `storage_disks[]`
(nejvýš 8 fyzických disků po 16 oddílech, stav SMART, teplota, čítače chyb,
opotřebení, zapsané bajty), `agent_tools` (sedm přísných booleanů, které říkají,
které volitelné programy router opravdu má), `wan_path` (packet steering, flow
offloading, SQM, zahozené rámce v kruhu) a čítače WAN. Pravidla, která na ně
server uplatňuje:

- **Hodnota mimo rozsah je `null`, nikdy okraj rozsahu.** Vytížení 101 % nebo
  teplota 0 °C se neořízne na 100 ani na minimum: zahodí se, protože oříznutá
  hodnota vypadá jako měření.
- **Chybějící `storage_disks` nechá poslední seznam být.** Agent 0.1.6 klíč
  neposílá a hlášení 0.1.7, které se muselo zmenšit, o něj může přijít; číst to
  jako „žádné disky" by smazalo funkční seznam. Klíč, který poslaný JE, seznam
  nahradí.
- Pět čítačů WAN se ukládá jako **krok** mezi dvěma hlášeními a krok je `null`
  přes restart nebo při změně zařízení WAN – nikdy hodnota od startu.
- **Soukromí:** router neopustí žádná MAC adresa, BSSID, SSID sousedů, sériové
  číslo disku ani WWN; identita disku je serverový otisk z přenosu, portu,
  modelu a velikosti.

Upozornění z těchto polí používají tři stavy – `storage_failing` (pageuje),
`storage_warning` a `storage_recovered` (nepageují ani jeden) – a všechna
podléhají přepínači `agent_notifications_enabled`; do časové osy se událost
zapíše tak jako tak. Teplota disku potřebuje dvě čerstvá čtení nad limitem po
sobě a končí 5 °C pod ním, rostoucí čítač chyb je při prvním pohledu tichý
a opakuje se nejvýš jednou za den a upozornění na zaplněný souborový systém
potřebuje dvě hlášení nad limitem a končí o pět bodů níž.

**Agent 0.1.8 (OpenWrt) přidává** `lan_ports` – drátěný přepínač port po portu,
posílaný s každým hlášením, protože kabel se mění každou minutou. Na port:
`name`, `link`, `speed_mbit`, `duplex`, `max_mbit` (co port umí),
`partner_max_mbit` (co nabízí protistrana) a `clients`; vedle toho `bridge`,
`conduits[]` (vedení DSA k procesoru, které sdílejí všichni drátoví klienti)
a `clients_total`. Server to ukládá do `last_details`, a tím to přichází do
aplikace v objektu `details` u `action=monitors` – vlastní endpoint to nemá.
Pravidla:

- **Port bez linku nemá rychlost.** `speed_mbit`, `duplex` i `partner_max_mbit`
  jsou `null`, kdykoli je `link` `false`, a nečitelná hodnota je `null` také –
  nikdy 0 a nikdy věrohodný dohad. Port s `clients: 0` je něco jiného: to je
  měření, „za touhle zásuvkou nikdo nepromluvil".
- **`lan_ports: null` znamená, že se router nemohl podívat** (není ubus, není
  `bridge`, přepínač není DSA). Hlášení, které sekci nenese, uloženou sekci
  smaže, místo aby si ji nechalo: na rozdíl od seznamu disků by obrázek
  zapojených kabelů ze staršího hlášení byl do minuty lež.
- **Soukromí:** router opouštějí jen počty. Žádná MAC adresa, žádné jméno
  stanice ani zápůjčka se neposílá ani neukládá a sekce nikdy není součástí
  veřejného pohledu.
- **Není** to časová řada a nemá sloupec v metrikách. Počet pochází z
  přeposílací tabulky mostu, která na zařízení po pár tichých minutách zapomene,
  takže graf by ukazoval, jak se zařízení sama odpojují.
- Jediné doporučení, které z toho čerpá, je `lan_wired_ceiling`. To se pořád
  spouští podle **schopnosti** portů; přepínač k němu jen přidává vlastní čísla
  domácnosti – kolik drátových zařízení sdílí které vedení – a jen když se
  obojí opravdu změřilo.

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
