/**
 * Metric explainers: what is measured, how and from where.
 *
 * Born from a concrete question: latency had its meaning written down, the others
 * did not. A number without context invites wrong conclusions - "disk usage
 * 40 %" is one thing for the whole disk and another when it is only
 * o zapisovatelnou vrstvu routeru.
 *
 * The catalogue is deliberately in one place: if every component wrote its
 * own caption, they would drift and nobody could tell which one holds.
 *
 * `source` answers "where is this measured" - i.e. where the value comes from and
 * how often. That matters more in monitoring than in an ordinary app: a value
 * from the router's agent and a value measured from the hosting can both be
 * right and still contradict each other.
 */
export interface MetricHelp {
  /** What the value means. */
  what: string;
  /** How it is obtained - the concrete command or file, not a generic "from the system". */
  how: string;
  /** From where and how often. */
  source: string;
  /** What to watch out for when reading the value. Optional. */
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

/** The explainer for a metric, or null when there is none. */
export function metricHelp(key: string): MetricHelp | null {
  return METRIC_HELP[key] ?? null;
}
