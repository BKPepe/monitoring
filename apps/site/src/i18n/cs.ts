/**
 * Czech copy of the layout and the home page. The `Dict` type from en.ts
 * makes a missing or extra key a type error.
 *
 * `czTypography` puts a non-breaking space after one-letter prepositions and
 * conjunctions (v, k, s, z, o, u, a, i), so a line never ends with one - the
 * Czech typesetting rule. It skips links and code, where a changed space
 * would change the meaning.
 */
import { GITHUB_REPO_URL } from '../config';
import type { Dict } from './en';

const AGENT_BASE = 'https://bloodkings.eu/status';

const SKIP_KEYS = new Set(['href', 'code', 'lang', 'locale', 'ogLocale', 'icon', 'status']);
const ONE_LETTER = /(^|[\s(„])([vkszouaiVKSZOUAI]) /g;

function nbsp(text: string): string {
  // Twice, because the matches overlap in "a v síti".
  return text.replace(ONE_LETTER, '$1$2 ').replace(ONE_LETTER, '$1$2 ');
}

export function czTypography<T>(value: T, key = ''): T {
  if (typeof value === 'string') return (SKIP_KEYS.has(key) ? value : nbsp(value)) as T;
  if (Array.isArray(value)) return value.map((v) => czTypography(v, key)) as T;
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, czTypography(v, k)])) as T;
  }
  return value;
}

const raw: Dict = {
  lang: 'cs',
  locale: 'cs-CZ',
  ogLocale: 'cs_CZ',

  layout: {
    defaultTitle: 'Blood Kings Monitoring | Self-hosted monitoring webů, serverů a routerů',
    defaultDescription:
      'Open-source (MIT) self-hosted monitoring na PHP a MySQL. Agenti pro Linux, Windows, Docker a OpenWrt/Turris posílají data přes HTTPS; stavové stránky, incidenty a upozornění v ceně.',
    ogImageAlt: 'Blood Kings Monitoring - self-hosted monitoring s otevřeným kódem',
    features: 'Funkce',
    routers: 'Routery',
    download: 'Ke stažení',
    docs: 'Dokumentace',
    changelog: 'Změny',
    roadmap: 'Roadmapa',
    about: 'O projektu',
    install: 'Instalace',
    liveStatus: 'Živá stavová stránka',
    product: 'Produkt',
    resources: 'Zdroje',
    project: 'Projekt',
    mainNav: 'Hlavní navigace',
    footerNav: 'Patička',
    skipToContent: 'Přeskočit na obsah',
    themeLabel: 'Barevný motiv',
    themeDark: 'Tmavý motiv (kliknutím světlý)',
    themeLight: 'Světlý motiv (kliknutím podle systému)',
    themeSystem: 'Motiv podle systému (kliknutím tmavý)',
    menu: 'Menu',
    language: 'Jazyk',
    footerDesc: 'Self-hosted monitoring webů, serverů a routerů s OpenWrt. Co nikdo nezměřil, zůstane pomlčkou.',
    licence: 'Licence MIT',
    noThirdParty: 'Žádné požadavky na třetí strany',
    build: 'sestavení',
  },

  home: {
    title: 'Blood Kings Monitoring | Self-hosted monitoring webů, serverů a routerů',
    description:
      'Self-hosted monitoring pod licencí MIT na PHP a MySQL. Agenti pro Linux, Windows, Docker a OpenWrt/Turris posílají data ven přes HTTPS, takže není třeba otevírat žádný port. Stavové stránky, incidenty a upozornění v ceně.',

    hero: {
      eyebrow: 'self-hosted · MIT · weby, servery a routery s OpenWrt',
      titleA: 'Uvidíte, co vaše síť opravdu dělá.',
      titleB: 'Měříme, nehádáme.',
      sub: 'Weby, servery a routery s OpenWrt v jednom self-hosted přehledu. Agenti posílají data ven přes HTTPS, takže na hlídaném stroji není potřeba otevírat žádný port, a co nikdo nezměřil, zůstane pomlčkou, nikdy vymyšlenou nulou.',
      ctaInstall: 'Nainstalovat',
      ctaLive: 'Otevřít živou stavovou stránku',
      ctaGithub: 'Zdrojový kód na GitHubu',
    },

    panel: {
      title: 'Živě z bloodkings.eu',
      subtitle: 'Vlastní instance projektu, přes náš Cloudflare Worker',
      loading: 'Načítám',
      live: 'Živě',
      stale: 'Stará data',
      unavailable: 'Nedostupné',
      monitors: 'Monitorů',
      agents: 'Agentů online',
      latency: 'Prům. odezva · 1\u00a0h',
      uptime: 'Dostupnost · 30\u00a0dní',
      updated: 'Poslední měření',
      release: 'Poslední vydání serveru',
      healthy: 'Všechny systémy v pořádku',
      degraded: 'Některé systémy mají potíže',
      down: 'Některé systémy mají výpadek',
      maintenance: 'Probíhá plánovaná údržba',
      unknown: 'Chybí čerstvé měření, proto žádný verdikt',
      staleLine: 'Nejnovější měření je starší než 20 minut.',
      loadError: 'Živá data se teď nepodařilo načíst. Každá hodnota zůstane pomlčkou, dokud nepřijde skutečná odpověď.',
      waiting: 'Čekám na první odpověď…',
      source: 'Dotaz každou minutu; worker odpovědi drží v mezipaměti až 5 minut.',
    },

    facts: [
      { label: 'Na hlídaném stroji žádný příchozí port', href: '/cs/docs/#architecture' },
      { label: 'Agent v POSIX sh pro OpenWrt a Turris', href: `${AGENT_BASE}/agent_openwrt.sh` },
      { label: 'Server na PHP + MySQL', href: '/cs/docs/#requirements' },
      { label: 'Licence MIT', href: `${GITHUB_REPO_URL}/blob/main/LICENSE` },
      { label: 'Česky i anglicky', href: '/' },
      {
        label: 'Tento web nic nenačítá od třetích stran',
        href: `${GITHUB_REPO_URL}/blob/main/apps/site/astro.config.mjs`,
      },
    ],
    factsLabel: 'Fakta, každé s odkazem na důkaz',

    routers: {
      eyebrow: 'OpenWrt · Turris OS',
      title: 'Váš router, změřený zevnitř.',
      lead: 'Jeden skript v POSIX sh, který každou minutu spustí cron. Žádný démon v paměti, žádný bash ani Python. Čte to, co router už ví, přes ubus, /proc a /sys, a posílá to ven přes HTTPS.',
      items: [
        {
          icon: 'ports',
          title: 'Switch v LAN, port po portu',
          body: 'Které zásuvky mají linku, jakou vyjednanou rychlostí, co zvládne port i zařízení na druhém konci a kolik zařízení se bridge za kterým portem naučil.',
        },
        {
          icon: 'gauge',
          title: 'WAN proti tarifu, který platíte',
          body: 'Zadejte rychlost ze smlouvy a přehled ji porovná s tím, co unese linka WAN a vlastní porty routeru. Pomalá linka a úzké hrdlo v routeru pak vypadají jinak.',
        },
        {
          icon: 'wifi',
          title: 'Wi-Fi rádia bez skenování',
          body: 'Pásmo, šířka kanálu, klienti a signál na každém rádiu a vytížení kanálu z čítačů, které si ovladač vede sám. Žádné skenování okolí se nespouští.',
        },
        {
          icon: 'signal',
          title: 'Záložní LTE, které jde ověřit',
          body: 'S modemem Huawei HiLink jako záložní linkou agent čte jeho signál (RSRP, SINR, RSSI) a registraci v síti, takže víte, že záloha funguje, dřív než ji budete potřebovat.',
        },
        {
          icon: 'disk',
          title: 'Disky se SMART',
          body: 'Disky v routeru čte smartctl nejvýš jednou za hodinu, takže spící disk se nebudí každou minutu.',
        },
        {
          icon: 'key',
          title: 'Vzdálené akce: jen na přání a podepsané',
          body: 'Šest akcí (restart WAN, WireGuardu nebo služby, nové připojení PPPoE, obnova DHCP, restart routeru) proběhne jen tehdy, když je povolíte v konfiguraci routeru, jen s platným podpisem HMAC a nikdy dvakrát pro jeden požadavek.',
        },
      ],
      privacyTitle: 'MAC adresy router nikdy neopustí',
      privacyBody:
        'Posílají se počty zařízení na port, ne identifikátory. Názvy a IP adresy zařízení zůstávají na routeru také, pokud si výslovně nezapnete seznam pojmenovaných zařízení.',
      costTitle: 'Kolik stojí jeden běh',
      costForks: '182',
      costForksLabel: 'forků procesů na teplý běh',
      costNote:
        'Agent 0.1.9 v e2e testech projektu s busyboxem, ne na fyzickém routeru. Na vašem routeru agent hlásí čas CPU svého předchozího běhu, takže přehled ukáže skutečnou cenu.',
      costLink: 'Jak se to měří',
      diagram: {
        title: 'Tok dat agenta pro OpenWrt (ilustrace, ne živá data)',
        router: 'Váš router',
        cron: 'cron · každou minutu',
        wan: 'WAN',
        lan: 'LAN',
        radios: 'Wi-Fi rádia',
        lte: 'záložní LTE',
        disk: 'disk',
        stays: 'Zůstává na routeru',
        staysList: 'MAC · názvy · IP zařízení',
        leaves: 'HTTPS POST, jen směrem ven',
        server: 'Váš server',
        serverSub: 'PHP + MySQL',
      },
    },

    watches: {
      eyebrow: 'Co hlídá',
      title: 'Jeden přehled pro to, co se doma i v práci rozbíjí.',
      lead: 'Každá karta říká, jak daleko je hotová. „Částečně“ a „V plánu“ znamená přesně to.',
      available: 'Hotovo',
      partial: 'Částečně',
      planned: 'V plánu',
      more: 'Všechny funkce',
      cards: [
        {
          icon: 'globe',
          status: 'available',
          title: 'Weby a certifikáty',
          body: 'Kontroly HTTP(S) s volitelným klíčovým slovem na stránce, expirace certifikátů TLS a kontroly TCP portů. Také servery Minecraft, TeamSpeak a Discord.',
        },
        {
          icon: 'server',
          status: 'available',
          title: 'Servery',
          body: 'Agenti pro Linux (Bash nebo Python), Windows (PowerShell) a Docker: CPU, paměť, disky a síť, na Linuxu i SMART.',
        },
        {
          icon: 'router',
          status: 'available',
          title: 'Routery s OpenWrt a Turris',
          body: 'Porty switche, WAN, Wi-Fi rádia, záložní LTE, disky se SMART a podepsané vzdálené akce na přání, z jednoho shellového skriptu.',
        },
        {
          icon: 'heart',
          status: 'available',
          title: 'Heartbeaty a watchdog',
          body: 'Cron úlohy a zálohy se hlásí samy. Cloudflare Worker mimo váš server pozná, když se zastaví samotný sběr dat.',
        },
        {
          icon: 'status',
          status: 'available',
          title: 'Stavové stránky a incidenty',
          body: 'Veřejná stavová stránka s incidenty, plánovanou údržbou, odběrateli e-mailů, SVG odznaky a RSS kanálem.',
        },
        {
          icon: 'bell',
          status: 'available',
          title: 'Upozornění a integrace',
          body: 'E-mail, SMS (Twilio), WhatsApp (CallMeBot), Discord, Slack, Telegram, Pushover a PagerDuty a k tomu exportér pro Prometheus.',
        },
        {
          icon: 'lock',
          status: 'available',
          title: 'Účty',
          body: 'Dvoufázové přihlášení (TOTP), OAuth přes GitHub, Google, Discord nebo GitLab a dvě role: admin a uživatel.',
        },
        {
          icon: 'nodes',
          status: 'partial',
          title: 'Kontroly z více míst',
          body: 'Kontroly běží z vašeho serveru. Pro ověření odjinud přidejte vlastní měřicí uzly (PHP skript na jiném hostingu nebo Cloudflare Worker pro weby).',
        },
        {
          icon: 'phone',
          status: 'planned',
          title: 'Mobilní aplikace',
          body: 'Nativní aplikace zatím není. Přehled na /app funguje v prohlížeči telefonu.',
        },
      ],
    },

    live: {
      eyebrow: 'Živý důkaz',
      title: 'Tuhle stránku hlídá produkt, o kterém mluví.',
      lead: 'Oba panely níže se právě teď ptají našeho Cloudflare Workeru. Když dotaz selže, uvidíte pomlčku nebo chybu, nikdy náhradní číslo.',
    },

    how: {
      eyebrow: 'Jak to funguje',
      title: 'Od PHP hostingu k prvnímu upozornění.',
      steps: [
        {
          title: 'Server na PHP hosting',
          body: 'PHP 8.2+ (CI projektu běží na 8.4) s MySQL nebo MariaDB a cron, který každou minutu spustí cron.php. Stačí sdílený hosting, server nepotřebuje žádný kontejner. Node.js je potřeba jen jednou, k sestavení přehledu /app.',
          code: 'git clone https://github.com/BKPepe/monitoring.git && cd monitoring\ncp apps/status/config.sample.php apps/status/config.php   # DB_HOST, DB_NAME, DB_USER, DB_PASS\nmysql -u USER -p DB_NAME < apps/status/schema.sql\nnpm ci && npm run build:monitor   # přehled /app\n# nahrajte apps/status/ do /status/ a apps/monitor/dist/ do /app/\n# crontab:\n* * * * * php -q /path/to/status/cron.php',
          after: 'Pak na své doméně otevřete /app/setup a založte prvního administrátora.',
        },
        {
          title: 'Zaregistrujte agenta',
          body: 'V přehledu vytvořte registrační token a spusťte instalaci pro svou platformu níže. Agent posílá hlášení ven přes HTTPS; na stroji nic nenaslouchá.',
          code: '',
          after: '',
        },
        {
          title: 'Upozornění a stavová stránka',
          body: 'Připojte kanály, které používáte (Discord, Slack a Telegram jdou nastavit i pro jednotlivý monitor), a sdílejte veřejnou stavovou stránku s incidenty a údržbou.',
          code: '',
          after: '',
        },
      ],
      docsLink: 'Celý návod k instalaci',
    },

    install: {
      eyebrow: 'Instalace agenta',
      title: 'Vyberte platformu, přečtěte si skript, spusťte příkazy.',
      lead: 'Adresy míří na server bloodkings.eu, na vlastní instanci použijte její adresu. Registrační token a klíče agentů najdete v přehledu.',
    },

    limits: {
      eyebrow: 'Poctivý rozsah',
      title: 'Co (zatím) neumí',
      items: [
        'Žádný ICMP ping ani kontroly UDP. Kontrola „portu“ je TCP spojení.',
        'Žádná sdílená síť měřicích bodů. Kontroly běží z vašeho serveru, pokud nepřidáte vlastní měřicí uzly.',
        'Žádné SNMP. Zařízení bez agenta se hlídá jen zvenku (HTTP, TCP).',
        'Nativní mobilní aplikace zatím není, je v roadmapě.',
        'Jen dvě role: admin a uživatel.',
        'Starší PHP administrace (admin.php) je převážně česky; přehled na /app mluví česky i anglicky.',
        'Agent pro routery potřebuje cron a ubus, takže běží na OpenWrt a Turris OS, ne na firmwaru výrobce.',
      ],
      roadmap: 'Podívat se na roadmapu',
    },

    faq: {
      eyebrow: 'Časté dotazy',
      title: 'Na co se lidé ptají nejdřív',
      items: [
        {
          q: 'Je to zdarma?',
          a: 'Ano. Licence MIT, žádná placená verze a žádný účet u nás. Provozujete to na vlastním hostingu.',
        },
        {
          q: 'Co potřebuje server?',
          a: 'Webhosting s PHP 8.2 nebo novějším, MySQL nebo MariaDB a cron, který každou minutu spustí cron.php. Sdílený hosting stačí, server nepotřebuje Docker. Node.js je potřeba jen jednou, k sestavení přehledu /app.',
        },
        {
          q: 'Musím na routeru otevřít port?',
          a: 'Ne. Agenti jen odesílají požadavky HTTPS ven na váš server. Vzdálené akce, pokud je zapnete, přijdou v odpovědi na agentův vlastní požadavek.',
        },
        {
          q: 'Jak moc zatěžuje agent router?',
          a: 'Cron ho spustí jednou za minutu a agent skončí, nic nezůstává v paměti. V testech projektu s busyboxem udělá agent 0.1.9 asi 182 forků na teplý běh. Na vašem routeru hlásí čas CPU předchozího běhu, takže uvidíte skutečnou cenu.',
        },
        {
          q: 'Kam putují moje data?',
          a: 'Jen na server, na který agenty nasměrujete. MAC adresy, názvy a IP adresy zařízení zůstávají na routeru, posílají se jen počty na port, pokud si nezapnete seznam pojmenovaných zařízení. Tento web nic nenačítá od třetích stran.',
        },
        {
          q: 'Proč jsou některé hodnoty pomlčkou?',
          a: 'Pomlčka znamená, že to nikdo nezměřil: chybí senzor nebo balíček, první vzorek se teprve zahřívá, nebo jsou data stará. Nula by byla měření, a tak ji aplikace nikdy nevypíše, když ji nenaměřila.',
        },
        {
          q: 'Funguje na Turris OS?',
          a: 'Ano. Agent pro routery se vyvíjí na Turrisu Omnia a instalace přidává řádek do cronu přes crontab, který Turris OS (cronie) respektuje.',
        },
        {
          q: 'Dá se použít i bez routerů?',
          a: 'Ano. Kontroly webů, TCP portů a heartbeaty žádného agenta nepotřebují a agenti jsou i pro Linux, Windows a Docker.',
        },
      ],
    },

    story: {
      eyebrow: 'Proč vznikl',
      title: 'Nejdřív pro jednu domácí síť.',
      body: [
        'Začalo to doma s routerem Turris Omnia: WAN na modulu SFP, LTE modem jako záložní linka a disk mSATA. Obyčejná kontrola dostupnosti řekla, jestli je dům online, ale ne proč je pomalý ani jestli záloha zabere.',
        'Agent se proto naučil číst router zevnitř a ujalo se jedno pravidlo: co nikdo nezměřil, je pomlčka. Stejné pravidlo platí v přehledu, na stavové stránce i na tomto webu.',
      ],
      link: 'Více o projektu',
      markLabel: 'nezměřeno',
    },
  },
};

export const cs: Dict = czTypography(raw);
