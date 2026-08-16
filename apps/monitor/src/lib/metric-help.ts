/**
 * Vysvětlivky k metrikám: co se měří, čím a odkud.
 *
 * Vzniklo z konkrétní otázky: u odezvy bylo vidět, co znamená, u ostatních
 * hodnot ne. Číslo bez kontextu svádí ke špatným závěrům - "zaplnění disku
 * 40 %" je něco jiného, když jde o celý disk, a něco jiného, když jde jen
 * o zapisovatelnou vrstvu routeru.
 *
 * Katalog je schválně na jednom místě: kdyby si každá komponenta psala
 * vlastní popisek, rozejdou se a nikdo nepozná, který platí.
 *
 * `source` odpovídá na "kam se to měří" - tedy odkud hodnota pochází a jak
 * často. To je u monitoringu důležitější než u běžné aplikace: hodnota
 * z agenta na routeru a hodnota naměřená z hostingu můžou být obě správně
 * a přitom si odporovat.
 */
export interface MetricHelp {
  /** Co ta hodnota znamená. */
  what: string;
  /** Jak se získává - konkrétní příkaz nebo soubor, ne obecné "ze systému". */
  how: string;
  /** Odkud a jak často. */
  source: string;
  /** Na co si dát pozor při čtení hodnoty. Nepovinné. */
  caveat?: string;
}

const AGENT = 'Agent na zařízení, při každém hlášení (obvykle jednou za minutu).';
const SERVER = 'Kontrola ze serveru monitoringu, při každém běhu cronu.';

export const METRIC_HELP: Record<string, MetricHelp> = {
  response_time: {
    what: 'Doba, za kterou služba odpověděla na kontrolu.',
    how: 'Změří se celý požadavek - navázání spojení, TLS i odpověď.',
    source: SERVER,
    caveat: 'Měří se z jednoho místa, takže vysoká hodnota může znamenat i problém na cestě, ne u služby.',
  },
  cpu: {
    what: 'Podíl času, kdy procesor nebyl nečinný.',
    how: 'Rozdíl hodnot v /proc/stat mezi dvěma hlášeními.',
    source: AGENT,
    caveat: 'Krátká špička mezi dvěma hlášeními se do průměru nemusí promítnout.',
  },
  ram: {
    what: 'Obsazená paměť v procentech.',
    how: 'Z /proc/meminfo: (MemTotal - MemAvailable) / MemTotal.',
    source: AGENT,
    caveat: 'Počítá se z MemAvailable, takže cache se nezapočítává jako obsazená.',
  },
  swap: {
    what: 'Kolik odkládacího prostoru je použito.',
    how: 'Z /proc/meminfo: (SwapTotal - SwapFree) / SwapTotal.',
    source: AGENT,
    caveat: 'Router bez swapu hlásí prázdnou hodnotu, ne nulu - to jsou dvě různé věci.',
  },
  hdd: {
    what: 'Zaplnění hlavního úložiště.',
    how: 'df na /overlay (běžný OpenWrt) nebo na / (Turris a systémy s zapisovatelným rootem).',
    source: AGENT,
    caveat: 'Je to jen jeden oddíl. Připojené disky najdete v přehledu úložiště níže.',
  },
  disk_io_read: {
    what: 'Rychlost čtení z disků.',
    how: 'Přírůstek přečtených sektorů v /proc/diskstats mezi hlášeními, přepočtený na kB/s.',
    source: AGENT,
  },
  disk_io_write: {
    what: 'Rychlost zápisu na disky.',
    how: 'Přírůstek zapsaných sektorů v /proc/diskstats mezi hlášeními, přepočtený na kB/s.',
    source: AGENT,
    caveat: 'U routerů s flash pamětí je trvale vysoký zápis důvod ke kontrole - flash má omezený počet přepisů.',
  },
  wan_latency_ms: {
    what: 'Odezva směrem do internetu měřená z routeru.',
    how: 'Ping na bránu poskytovatele.',
    source: AGENT,
    caveat: 'Měří se z routeru, takže vylučuje vaši domácí síť - je to jiná hodnota než odezva služby z internetu.',
  },
  dns_latency_ms: {
    what: 'Jak dlouho trvá přeložit doménové jméno.',
    how: 'Skutečný dotaz na lokální resolver a změření času.',
    source: AGENT,
    caveat: 'Vyžaduje nslookup i time; kde chybí, zůstává hodnota prázdná.',
  },
  tcp_retrans: {
    what: 'Kolik TCP segmentů se muselo poslat znovu.',
    how: 'Sloupec RetransSegs v /proc/net/snmp, rozdíl mezi hlášeními.',
    source: AGENT,
    caveat: 'Roste dřív, než si někdo stěžuje na pomalé připojení - je to dobrý včasný signál.',
  },
  conntrack: {
    what: 'Jak zaplněná je tabulka sledovaných spojení.',
    how: 'Podíl nf_conntrack_count a nf_conntrack_max.',
    source: AGENT,
    caveat: 'Při 100 % router odmítá nová spojení, i když má volný procesor i pamět.',
  },
  temperature_c: {
    what: 'Teplota procesoru nebo desky.',
    how: 'Z thermal zón jádra (/sys/class/thermal).',
    source: AGENT,
    caveat: 'Zařízení, které teplotní čidlo nevystavuje, hodnotu neposílá - proto je prázdná, ne nulová.',
  },
  entropy: {
    what: 'Kolik náhodnosti má jádro k dispozici.',
    how: 'Z /proc/sys/kernel/random/entropy_avail.',
    source: AGENT,
    caveat: 'Trvale nízká hodnota umí zdržovat navazování šifrovaných spojení.',
  },
  ups_battery_pct: {
    what: 'Nabití baterie záložního zdroje.',
    how: 'Dotaz na démona UPS (NUT).',
    source: AGENT,
  },
  fw_dropped: {
    what: 'Kolik paketů firewall zahodil.',
    how: 'Součet počítadel u pravidel s verdiktem drop (nftables, jinak iptables).',
    source: AGENT,
    caveat: 'Pakety zahozené politikou řetězce nemají počítadlo, takže se do součtu nepromítnou.',
  },
};

/** Vysvětlivka k metrice, nebo null když pro ni žádná není. */
export function metricHelp(key: string): MetricHelp | null {
  return METRIC_HELP[key] ?? null;
}
