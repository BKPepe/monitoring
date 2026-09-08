/**
 * Metric explainers: what is measured, how and from where.
 *
 * Born from a concrete question: latency had its meaning written down, the
 * others did not. A number without context invites wrong conclusions - "disk
 * usage 40 %" means one thing for a whole disk and another when it covers only
 * the router's writable overlay.
 *
 * The catalogue is deliberately in one place: if every component wrote its own
 * caption, they would drift and nobody could tell which one holds. The strings
 * go through t() like everything else the user reads - they used to be Czech
 * literals, so an English session got a Czech explainer.
 *
 * `source` answers "where is this measured" - where the value comes from and
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

type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

/**
 * The explainer for a metric, or null when there is none.
 *
 * The catalogue is built per call so the strings follow the current language;
 * it is a handful of entries, rendered behind a tooltip.
 */
export function metricHelp(key: string, t: TranslateFn): MetricHelp | null {
  const agent = t('help.source_agent', 'Agent na zařízení, při každém hlášení (obvykle jednou za minutu).');
  const server = t('help.source_server', 'Kontrola ze serveru monitoringu, při každém běhu cronu.');

  const catalogue: Record<string, MetricHelp> = {
    response_time: {
      what: t('help.response_time_what', 'Doba, za kterou služba odpověděla na kontrolu.'),
      how: t('help.response_time_how', 'Změří se celý požadavek - navázání spojení, TLS i odpověď.'),
      source: server,
      caveat: t(
        'help.response_time_caveat',
        'Měří se z jednoho místa, takže vysoká hodnota může znamenat i problém na cestě, ne u služby.'
      ),
    },
    cpu: {
      what: t('help.cpu_what', 'Podíl času, kdy procesor nebyl nečinný.'),
      how: t('help.cpu_how', 'Rozdíl hodnot v /proc/stat mezi dvěma hlášeními.'),
      source: agent,
      caveat: t('help.cpu_caveat', 'Krátká špička mezi dvěma hlášeními se do průměru nemusí promítnout.'),
    },
    ram: {
      what: t('help.ram_what', 'Obsazená paměť v procentech.'),
      how: t('help.ram_how', 'Z /proc/meminfo: (MemTotal - MemAvailable) / MemTotal.'),
      source: agent,
      caveat: t('help.ram_caveat', 'Počítá se z MemAvailable, takže cache se nezapočítává jako obsazená.'),
    },
    swap: {
      what: t('help.swap_what', 'Kolik odkládacího prostoru je použito.'),
      how: t('help.swap_how', 'Z /proc/meminfo: (SwapTotal - SwapFree) / SwapTotal.'),
      source: agent,
      caveat: t('help.swap_caveat', 'Router bez swapu hlásí prázdnou hodnotu, ne nulu - to jsou dvě různé věci.'),
    },
    hdd: {
      what: t('help.hdd_what', 'Zaplnění hlavního úložiště.'),
      how: t('help.hdd_how', 'df na /overlay (běžný OpenWrt) nebo na / (Turris a systémy s zapisovatelným rootem).'),
      source: agent,
      caveat: t('help.hdd_caveat', 'Je to jen jeden oddíl. Připojené disky najdete v přehledu úložiště níže.'),
    },
    disk_io_read: {
      what: t('help.disk_io_read_what', 'Rychlost čtení z disků.'),
      how: t(
        'help.disk_io_read_how',
        'Přírůstek přečtených sektorů v /proc/diskstats mezi hlášeními, přepočtený na kB/s.'
      ),
      source: agent,
    },
    disk_io_write: {
      what: t('help.disk_io_write_what', 'Rychlost zápisu na disky.'),
      how: t(
        'help.disk_io_write_how',
        'Přírůstek zapsaných sektorů v /proc/diskstats mezi hlášeními, přepočtený na kB/s.'
      ),
      source: agent,
      caveat: t(
        'help.disk_io_write_caveat',
        'U routerů s flash pamětí je trvale vysoký zápis důvod ke kontrole - flash má omezený počet přepisů.'
      ),
    },
    wan_latency_ms: {
      what: t('help.wan_latency_ms_what', 'Odezva směrem do internetu měřená z routeru.'),
      how: t('help.wan_latency_ms_how', 'Ping na bránu poskytovatele.'),
      source: agent,
      caveat: t(
        'help.wan_latency_ms_caveat',
        'Měří se z routeru, takže vylučuje vaši domácí síť - je to jiná hodnota než odezva služby z internetu.'
      ),
    },
    dns_latency_ms: {
      what: t('help.dns_latency_ms_what', 'Jak dlouho trvá přeložit doménové jméno.'),
      how: t('help.dns_latency_ms_how', 'Skutečný dotaz na lokální resolver a změření času.'),
      source: agent,
      caveat: t('help.dns_latency_ms_caveat', 'Vyžaduje nslookup i time; kde chybí, zůstává hodnota prázdná.'),
    },
    tcp_retrans: {
      what: t('help.tcp_retrans_what', 'Kolik TCP segmentů se muselo poslat znovu.'),
      how: t('help.tcp_retrans_how', 'Sloupec RetransSegs v /proc/net/snmp, rozdíl mezi hlášeními.'),
      source: agent,
      caveat: t(
        'help.tcp_retrans_caveat',
        'Roste dřív, než si někdo stěžuje na pomalé připojení - je to dobrý včasný signál.'
      ),
    },
    conntrack: {
      what: t('help.conntrack_what', 'Jak zaplněná je tabulka sledovaných spojení.'),
      how: t('help.conntrack_how', 'Podíl nf_conntrack_count a nf_conntrack_max.'),
      source: agent,
      caveat: t('help.conntrack_caveat', 'Při 100 % router odmítá nová spojení, i když má volný procesor i pamět.'),
    },
    temperature_c: {
      what: t('help.temperature_c_what', 'Teplota procesoru nebo desky.'),
      how: t('help.temperature_c_how', 'Z thermal zón jádra (/sys/class/thermal).'),
      source: agent,
      caveat: t(
        'help.temperature_c_caveat',
        'Zařízení, které teplotní čidlo nevystavuje, hodnotu neposílá - proto je prázdná, ne nulová.'
      ),
    },
    entropy: {
      what: t('help.entropy_what', 'Kolik náhodnosti má jádro k dispozici.'),
      how: t('help.entropy_how', 'Z /proc/sys/kernel/random/entropy_avail.'),
      source: agent,
      caveat: t('help.entropy_caveat', 'Trvale nízká hodnota umí zdržovat navazování šifrovaných spojení.'),
    },
    ups_battery_pct: {
      what: t('help.ups_battery_pct_what', 'Nabití baterie záložního zdroje.'),
      how: t('help.ups_battery_pct_how', 'Dotaz na démona UPS (NUT).'),
      source: agent,
    },
    fw_dropped: {
      what: t('help.fw_dropped_what', 'Kolik paketů firewall zahodil.'),
      how: t('help.fw_dropped_how', 'Součet počítadel u pravidel s verdiktem drop (nftables, jinak iptables).'),
      source: agent,
      caveat: t(
        'help.fw_dropped_caveat',
        'Pakety zahozené politikou řetězce nemají počítadlo, takže se do součtu nepromítnou.'
      ),
    },
    lte_rsrp: {
      what: t('help.lte_rsrp_what', 'Síla signálu z vysílače v místě routeru.'),
      how: t('help.lte_rsrp_how', 'Z modemu přes ModemManager nebo uqmi, u HiLink modemů z /api/device/signal.'),
      source: agent,
      caveat: t(
        'help.lte_rsrp_caveat',
        'Stupnice: nad -80 dBm výborný, do -90 dobrý, do -100 slabší, níž špatný. Sama o sobě neřekne všechno: se špatným RSRQ nebo SINR jde o rušení, ne o vzdálenost, a posun antény k oknu nepomůže.'
      ),
    },
    lte_rsrq: {
      what: t('help.lte_rsrq_what', 'Kolik z přijatého signálu je užitečné a kolik rušení.'),
      how: t('help.lte_rsrq_how', 'Z modemu přes ModemManager nebo uqmi, u HiLink modemů z /api/device/signal.'),
      source: agent,
      caveat: t(
        'help.lte_rsrq_caveat',
        'Stupnice: nad -10 dB výborný, do -15 dobrý, do -20 slabší, níž špatný. Nízká hodnota při dobrém RSRP znamená přetíženou nebo zarušenou buňku.'
      ),
    },
    lte_sinr: {
      what: t('help.lte_sinr_what', 'Poměr signálu k šumu, tedy kolik rychlosti linka utáhne.'),
      how: t('help.lte_sinr_how', 'Z modemu přes ModemManager nebo uqmi, u HiLink modemů z /api/device/signal.'),
      source: agent,
      caveat: t(
        'help.lte_sinr_caveat',
        'Stupnice: nad 20 dB výborný, do 13 dobrý, do 0 slabší, záporný špatný. Pomáhá směrová anténa, která odfiltruje okolní rušení.'
      ),
    },
  };

  return catalogue[key] ?? null;
}
