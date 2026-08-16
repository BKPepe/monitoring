# PHP nebo Go pro backend

Podklad k rozhodnutí. Argumenty jsou vážené k **tomuhle projektu**, ne obecné
srovnání jazyků — u obecného srovnání by odpověď byla „na tom nezáleží".

Ověřeno v kódu, ne odhadem. Instalované Go: 1.26.5.

---

## Rozhodnutí visí na třech otázkách

1. **Chceš živá data přes WebSocket?** V roadmapě to je.
2. **Chceš kontrolovat častěji než jednou za minutu?** Dnes to nejde.
3. **Musí self-hoster umět nasadit na levný sdílený hosting?** Dnešní README
   to prodává jako hlavní přednost.

Ano na 1 a 2 → Go. Ano na 3 jako priorita → PHP. Nedá se mít oboje bez
dvojí údržby.

---

## Co mluví pro Go

### Souběžné sondy (nejsilnější argument)

`cron.php:47` prochází monitory **sekvenčně**:

```php
foreach ($monitors as $monitor) { ... }
```

Žádné `curl_multi`, žádný fork. Doba běhu je součet všech kontrol. Když je
pět hostů nedostupných a timeout je 5 s, spotřebuje se 25 sekund čekáním
na nic. Při 24 monitorech se běh reálně blíží minutovému intervalu cronu —
a při stovce monitorů ho překročí, běhy se začnou překrývat.

V Go je souběžnost sto monitorů triviální (goroutina na sondu, `WaitGroup`,
sdílený timeout). Tohle je ten rozdíl, kvůli kterému se to dělá.

### Kontroly častěji než jednou za minutu

Cron má granularitu 1 minuta. Chceš-li kontroly po 10 s, PHP cesta je
běžící daemon — a tím se PHP výhoda „stačí nahrát soubory" stejně ztrácí.
Go plánuje v procesu.

### WebSocket

PHP drží spojení za cenu procesu/workeru na klienta. Deset otevřených
dashboardů = deset zablokovaných workerů. Go zvládne tisíce spojení
v jednom procesu. LiteSpeed WS proxy máš, takže překážka je jen na straně
aplikace.

### Nasazení a provoz

Jedna binárka bez závislostí, `GOOS`/`GOARCH` cross-compile, Docker image
o desítkách MB. Pro uživatele s VPS je to jednodušší než PHP + rozšíření
+ správná verze.

### Refaktoring

`functions.php` má 6 000 řádků, `index.php` 2 700, `admin.php` 3 400.
Kompilátor a typy v takové kódové bázi ušetří třídu chyb, kterou dnes
odhalí až produkce.

---

## Co mluví pro PHP

### 19 000 řádků, které fungují

To není dluh, to je funkcionalita — devět notifikačních kanálů, sedm typů
sond včetně vlastních implementací Minecraft SLP/RCON a TeamSpeak, predikce
zaplnění disku, SLA reporty, Prometheus exportér, 2FA, OAuth. Viz
[API-CONTRACT.md](API-CONTRACT.md). Přepsat to znamená měsíce, během nichž
nepřibude uživateli nic nového.

### Levný hosting je součást produktu

Landing page prodává self-hosting a README instruuje „nahraj do
`public_html`". Na sdíleném hostingu s cPanelem je PHP nativní: nahraješ
soubory a běží. Go tam potřebuje trvale běžící proces — což na sdíleném
tarifu bývá zakázané nebo ho hosting zabíjí, a po deployi ho musí něco
restartovat. Na tvém serveru to půjde; u cizího self-hostera je to loterie.

### Deploy pipeline už existuje

FTPS → cPanel běží. Go znamená build per architekturu, nahrání binárky
a restart procesu — jiný a křehčí postup.

### Agenti se nemění tak jako tak

Agenti jsou shell/Python/PowerShell a mají zůstat. Go na straně serveru
jim nepomůže ani neuškodí.

---

## K PowerPC

Ověřeno přes `go tool dist list`:

- Go **umí** `linux/mips`, `linux/mipsle`, `linux/mips64`, `linux/mips64le`,
  `linux/ppc64`, `linux/ppc64le`
- Go **neumí** 32bitový `linux/ppc` — a to je většina PowerPC OpenWrt routerů

Na rozhodnutí o serveru to ale nemá vliv: **na routeru běží agent, ne
backend**. A agent je `agent_openwrt.sh` — POSIX shell. Právě proto běží na
ppc, mips i armv5. Přepsat agenta do Go by byla chyba bez ohledu na to, jak
dopadne volba serveru.

---

## Doporučení

**Go — ale postupně, ne přepisem.**

Rozhoduje bod se souběžnými sondami a WebSockety: obojí je v roadmapě a
obojí je v PHP buď drahé, nebo znamená stejně provozovat daemon. Pak už je
Go přímočařejší.

Podmínky, bez kterých to nedoporučuju:

1. **Souběžný provoz nad jednou databází.** PHP zůstane a přepíná se po
   endpointech. Big-bang přepnutí u monitoringu vytvoří slepé místo přesně
   ve chvíli, kdy něco spadne.
2. **Ingest agentů první.** Dokud Go neumí přijmout stávající payload
   (85+ polí) a podepsat Remote Actions stejným HMAC, nemá se co přepínat.
3. **Integrační endpointy zůstávají na stejných URL** — `metrics.php`,
   `badge.php`, `widget.php` mají lidé vložené v cizích README a Grafaně.
4. **PHP verze se nemaže**, dokud Go neprojde celým parity checklistem.

### Kdy bych doporučil opak

Kdyby cílem bylo „mít moderní dashboard co nejdřív", je odpověď PHP:
`apps/monitor` se napojí na dnešní `api.php` během jednoho sprintu a
funkčně nic neztratíš. Go se vyplatí, jen když ty realtime a škálovací
požadavky myslíš vážně — ne kvůli tomu, že je to modernější jazyk.
