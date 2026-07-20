# Blood Kings - Status Monitoring System

Tento systém slouží pro kompletní monitorování stavu služeb (webů, portů, herních serverů jako Minecraft, TeamSpeak 3 a Discord, a VPS zatížení přes lokálního agenta). 

Umožňuje nasazení jak na jediném sdíleném webhostingu, tak distribuované měření z více různých lokací (uzlů) po světě najednou.

---

## Obsah složky
* `index.php` - Hlavní veřejný status dashboard.
* `admin.php` - Administrační rozhraní (přidávání/editace serverů, nastavení SMS/email notifikací).
* `cron.php` - Hlavní spouštěcí skript měření pro cPanel (spouští kontroly z hlavního serveru).
* `node_api.php` - Bezpečné API rozhraní pro příjem měření ze vzdálených uzlů.
* `node_client.php` - Samostatný klientský skript pro nahrání na jiné vzdálené hostingy.
* `agent_api.php` - API endpoint pro příjem zátěže (CPU, RAM, HDD) z monitorovaných VPS.
* `agent.py` - Python skript, který se instaluje na monitorovaná VPS k zasílání systémových metrik.
* `db.php` - Inicializace PDO připojení k databázi.
* `functions.php` - Knihovna měřících funkcí (SLP Minecraft, ServerQuery TeamSpeak, Discord API, mail atd.).
* `schema.sql` - Struktura MySQL databáze.

---

## 1. Lokální instalace (Hlavní Server)
1. **Nahrajte složku `status/`** do kořenového adresáře vašeho hlavního webu (např. `public_html/status/`).
2. **Vytvořte MySQL databázi** ve vašem cPanelu a naimportujte do ní strukturu ze souboru `schema.sql`.
3. **Zprovozněte konfiguraci `config.php`**:
   * Zkopírujte vzorový soubor `config.sample.php` a uložte jej jako **`config.php`**.
   * Doplňte údaje k vytvořené MySQL databázi.
   * *Poznámka:* Soubor `config.php` je automaticky ignorován v Gitu, takže jej můžete bezpečně používat lokálně bez rizika úniku hesel.

---

## 2. Automatické nasazení přes GitHub Actions
Pokud používáte přiložené automatické nasazení na cPanel, vaše hesla do databáze nesmí být v Gitu.
1. Na GitHubu v nastavení repozitáře přejděte do **Settings** -> **Secrets and variables** -> **Actions**.
2. Vytvořte nový secret s názvem **`STATUS_CONFIG_PHP`**.
3. Do hodnoty vložte kompletní obsah vašeho produkčního souboru `config.php` (s ostrými údaji k databázi).
4. GitHub Actions při každém pushnutí automaticky vytvoří bezpečný `config.php` na hostingu a vy nemusíte nic nahrávat ručně přes FTP.

---

## 3. Nastavení pravidelných kontrol (Cron)
Aby monitoring aktivně hlídal servery, musí se pravidelně (každých 1 až 5 minut) spouštět měřící cyklus.

### Spouštění z hlavního serveru:
V cPanelu přidejte novou plánovanou úlohu (Cron Job):
```bash
php -q /home/bloodkin/public_html/status/cron.php
```
*Tento příkaz spouští měření z lokace vašeho hlavního serveru. Zabezpečení skriptu automaticky rozpozná spuštění z příkazové řádky (CLI) a nevyžaduje žádný API klíč.*

---

## 4. Distribuované měření z více lokací (Monitoring Nodes)
Pokud máte více různých webhostingů v jiných lokacích (např. v Německu, v USA, v ČR) a chcete měřit dostupnost z různých míst najednou, využijte API uzly.

### Krok 1: Nastavení API klíče
1. Přihlaste se do administrace statusu (`admin.php`) -> přejděte do **Nastavení systému**.
2. Nastavte nebo vygenerujte **Bezpečnostní klíč pro Cron / Uzly (API Key)**. (Např. `MojeSuperTajneHeslo123`).

### Krok 2: Nasazení uzlu na jiný hosting
1. Vezměte soubor **`node_client.php`** z vašeho projektu.
2. Otevřete ho v textovém editoru a upravte konfiguraci na začátku souboru:
   ```php
   $central_api_url = 'https://bloodkings.eu/status/node_api.php';
   $node_key        = 'MojeSuperTajneHeslo123'; // Musí odpovídat klíči z adminu
   $node_location   = 'Frankfurt, DE';         // Název lokace tohoto uzlu
   ```
3. Nahrajte tento jeden soubor `node_client.php` kamkoliv na váš druhý webhosting (např. do `public_html/node_client.php`).

### Krok 3: Spuštění cronu na druhém hostingu
Na druhém hostingu nastavte plánovanou úlohu (Cron), která bude spouštět tento soubor:
```bash
php -q /cesta-k-souboru/node_client.php
```
nebo pokud hosting nepodporuje PHP CLI cron, můžete ho volat přes HTTP:
```bash
curl -s https://druhy-web.cz/node_client.php
```

Tento uzel se při každém spuštění bezpečně spojí s hlavním status serverem, získá seznam vašich serverů k testování, provede testy ze své lokace (např. z Frankfurtu) a pošle výsledky zpět. V detailech měření pak uvidíte značku s lokací, odkud test proběhl.

---

## 5. Vzdálené přímé připojení (Alternativní možnost)
Pokud váš druhý hosting podporuje vzdálené připojení k MySQL:
1. Povolte IP adresu druhého hostingu v cPanelu hlavního serveru pod **Remote MySQL**.
2. Nahrajte na druhý hosting celou složku `status/` a v `config.php` vyplňte jako `DB_HOST` IP adresu vašeho hlavního serveru.
3. Spusťte cron standardně na `cron.php`. Data se zapíšou rovnou do centrální databáze.

---

## 6. Sledování cPanel hostingu (cpanel_stats.php)
Pro detailní sledování zátěže (CPU, RAM, Disk, aktivní procesy, zatížení MySQL/PostgreSQL a přenosy dat/bandwidth) sdíleného hostingu:
1. Vyhledejte soubor **`cpanel_stats.php`** v instalačním balíčku.
2. Nahrajte ho do kořenového adresáře sledovaného webu (např. do `public_html/cpanel_stats.php`).
3. V administraci statusu (`admin.php`) upravte nebo přidejte příslušný monitor:
   * Nastavte typ monitoringu na **cPanel Hosting**.
   * Do pole **cPanel Stats URL** zadejte plnou cestu s vaším unikátním bezpečnostním klíčem jako parametr `key` (např. `https://bloodkings.eu/cpanel_stats.php?key=MojeSuperTajneHeslo123`).
   * Z bezpečnostních důvodů je toto pole v administraci maskováno jako heslo a klíč není přímo viditelný bez stisknutí ikony oka pro odmaskování.

---

## 7. Monitorování VPS zatížení (agent.py)
Pro sběr systémových metrik (zátěž CPU, využití RAM, obsazení disků a běžící systémové služby/procesy) na virtuálních serverech (VPS):
1. V administraci klikněte u VPS monitoru na ikonu **i (Instrukce pro nastavení)**.
2. Stáhněte si klientský python skript **`agent.py`**.
3. Nahrajte ho na sledovaný VPS (např. do `/opt/monitoring/agent.py`).
4. Spusťte agenta nebo jej nastavte jako systemd službu pro trvalý běh na pozadí:
   ```bash
   python3 agent.py --interval 60 --url https://bloodkings.eu/status/agent_api.php --key VAS_VPS_AGENT_KLIC
   ```

V **Nastavení → záložka Notifikace → VPS Agent Nastavení** lze časový limit pro označení agenta za offline nastavit na `0` - detekce neaktivity agenta se tím úplně vypne (monitor zůstává v posledním nahlášeném stavu, žádná upozornění na neaktivního agenta). Samostatný přepínač pak zůstává jen pro upozornění na překročení limitů CPU/RAM/HDD, které se řídí prahy nastavenými u každého VPS monitoru zvlášť.

---

## 8. Automatizovaný deploy agent.py na VPS přes GitHub Actions
Pokud chcete automaticky nasazovat novou verzi `agent.py` na VPS servery při pushnutí do repozitáře:
1. Vytvořte v projektu soubor `.github/workflows/deploy-agent.yml` (vzor níže).
2. Nastavte v GitHub Secrets vašeho repozitáře potřebné SSH klíče a IP adresy VPS serverů:
   * `VPS_HOST_1` - IP adresa / hostname VPS.
   * `VPS_USERNAME_1` - Uživatelské jméno pro SSH (např. `root`).
   * `VPS_SSH_KEY_1` - Privátní SSH klíč.
   * `VPS_TARGET_DIR_1` - Cesta pro uložení agenta (např. `/opt/monitoring/`).

### Vzorový workflow `.github/workflows/deploy-agent.yml`:
```yaml
name: Deploy VPS Monitoring Agent

on:
  push:
    paths:
      - 'status/agent.py'
    branches:
      - main

jobs:
  deploy:
    name: Deploy to VPS
    runs-on: ubuntu-latest
    steps:
      - name: Checkout Code
        uses: actions/checkout@v4

      - name: Upload agent.py to VPS via SCP
        uses: appleboy/scp-action@v0.1.7
        if: "${{ secrets.VPS_HOST_1 != '' }}"
        with:
          host: ${{ secrets.VPS_HOST_1 }}
          username: ${{ secrets.VPS_USERNAME_1 }}
          key: ${{ secrets.VPS_SSH_KEY_1 }}
          port: 22
          source: "status/agent.py"
          target: "${{ secrets.VPS_TARGET_DIR_1 || '/opt/monitoring/' }}"
          strip_components: 1

      - name: Restart Agent via SSH
        uses: appleboy/ssh-action@v1.2.0
        if: "${{ secrets.VPS_HOST_1 != '' }}"
        with:
          host: ${{ secrets.VPS_HOST_1 }}
          username: ${{ secrets.VPS_USERNAME_1 }}
          key: ${{ secrets.VPS_SSH_KEY_1 }}
          port: 22
          script: |
            sudo systemctl restart bloodkings-agent || pm2 restart bloodkings-agent || true
```

---

## 9. Windows agent (agent.ps1)
Pro Windows servery je k dispozici nativní PowerShell agent `agent.ps1` (bez externích závislostí, PowerShell 5.1+). Hlásí CPU, RAM, zaplnění disku, uptime, SMART stav, naslouchající porty a běžící procesy.

1. Zkopírujte `agent.ps1` na server (např. `C:\bloodkings\agent.ps1`).
2. Nastavte `API_URL` a `AGENT_KEY` přímo ve skriptu, přes `agent.cfg` ve stejné složce, nebo proměnnými prostředí `STATUS_API_URL` / `STATUS_AGENT_KEY`.
3. V Naplánovaných úlohách (Task Scheduler) vytvořte úlohu spouštěnou každých 5 minut:
   ```
   powershell.exe -ExecutionPolicy Bypass -File C:\bloodkings\agent.ps1
   ```

---

## 10. Docker agent (docker-compose.agent.yml)
Python agenta lze provozovat jako Docker kontejner, který měří metriky **hostitele** (ne kontejneru) - kontejner běží s `pid: host`, `network_mode: host` a kořenový FS hostitele je připojen read-only na `/host`.

1. Zkopírujte `docker-compose.agent.yml` a `agent.py` na server do jedné složky.
2. Vyplňte `STATUS_API_URL` a `STATUS_AGENT_KEY` v compose souboru.
3. Spusťte: `docker compose -f docker-compose.agent.yml up -d`

---

## 11. Automatické aktualizace agentů
Všichni agenti (`agent.sh`, `agent.py`, `agent.ps1`) se umí sami aktualizovat na verzi zveřejněnou na serveru. Funkce je **vypnutá ve výchozím stavu** - zapíná se nastavením `AUTO_UPDATE=1` (ve skriptu, v `agent.cfg`, nebo proměnnou prostředí `STATUS_AUTO_UPDATE=1`).

Průběh: server v odpovědi `agent_api.php` oznámí novější verzi včetně SHA-256 checksumu; agent stáhne nový skript do dočasného souboru, ověří checksum i syntaxi a teprve poté se atomicky nahradí (se zálohou `.bak`). Nová verze se použije při dalším spuštění z cronu/systemd/Task Scheduleru. Doporučujeme provozovat API přes HTTPS. V Docker režimu je aktualizace vypnutá (skript je připojen read-only).

---

## 12. Prometheus exporter (metrics.php)
Endpoint `/status/metrics.php` vystavuje stav monitorů a metriky agentů ve formátu Prometheus. Je aktivní až po nastavení tajného tokenu **Nastavení → Prometheus Exporter** v administraci (vygenerujte např. `openssl rand -hex 24`).

Ukázka konfigurace scraperu:
```yaml
scrape_configs:
  - job_name: bloodkings
    metrics_path: /status/metrics.php
    params:
      token: ['VAS_TAJNY_TOKEN']
    static_configs:
      - targets: ['status.example.com']
```

Exportované metriky: `bloodkings_monitor_up`, `bloodkings_monitor_maintenance`, `bloodkings_monitor_response_time_ms`, `bloodkings_vps_cpu_percent`, `bloodkings_vps_ram_percent`, `bloodkings_vps_hdd_percent`, `bloodkings_vps_uptime_seconds`, `bloodkings_vps_agent_last_seen_timestamp`, `bloodkings_monitors_total`.

---

## 13. Přihlášení přes GitHub (SSO)
Administrátoři se mohou přihlašovat GitHub účtem místo hesla.

1. Na GitHubu přejděte do **Settings → Developer settings → OAuth Apps → New OAuth App**.
2. Jako **Authorization callback URL** zadejte adresu administrace, např. `https://vase-domena.cz/status/admin.php`.
3. Vygenerované **Client ID** a **Client Secret** vyplňte v administraci v **Nastavení → záložka Integrace → Přihlášení přes GitHub** a uložte.

**Důležité:** Tlačítko „Přihlásit se přes GitHub" se na přihlašovací stránce zobrazí **až po vyplnění a uložení Client ID** — bez něj nelze sestavit autorizační adresu, proto se do té doby nabízí jen klasické přihlášení heslem.

Průběh přihlášení: tlačítko přesměruje na GitHub autorizaci (scope `user:email`, s CSRF ochranou přes `state` parametr), po potvrzení se ověří přístupový token a **ověřený** e-mail GitHub účtu se napáruje na existující účet administrátora. Uživatelé, jejichž e-mail v systému není registrován jako administrátor, se přes GitHub nepřihlásí — SSO tedy nikdy nevytváří nové účty.

---

## 14. Pravidelné e-mailové reporty (digest)
Systém automaticky posílá souhrnný přehled dostupnosti (celková uptime %, počet výpadků, 5 nejméně spolehlivých monitorů za dané období) na e-maily všech administrátorů:

- **Týdenní** report - každé pondělí mezi 8:00 a 12:00 (podle běhu cronu).
- **Měsíční** report - první den v měsíci mezi 8:00 a 12:00.

Odeslání se eviduje v nastavení (`last_weekly_digest_sent` / `last_monthly_digest_sent`), takže při více bězích cronu ve stejném okně se report neodešle duplicitně. Report lze kdykoliv spustit i ručně tlačítkem v **Profilu administrátora** ("Odeslat týdenní/měsíční digest").

---

## 15. SMS a WhatsApp notifikace jsou nezávislé
Placená SMS brána (Twilio / SMSbrana.cz) a bezplatná WhatsApp brána (CallMeBot) jsou dva samostatné kanály, které lze zapnout současně - výběr SMS brány v nastavení WhatsApp nijak neovlivňuje. Ke globálnímu CallMeBot klíči (záložnímu, pro uživatele bez vlastního) se dostanete v **Nastavení → záložka Notifikace → WhatsApp Notifikace**; vlastní osobní klíč a telefon si každý uživatel nastavuje ve svém profilu.
