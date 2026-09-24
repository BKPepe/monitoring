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

  // One explainer per measurement, shared by its per-band keys: the registry
  // has a column per band, but what is measured and how does not change with it.
  const wifiNoise: MetricHelp = {
    what: t('help.wifi_noise_band_what', 'Šum na kanálu rádií v tomto pásmu; při více rádiích ten nejhorší.'),
    how: t('help.wifi_noise_band_how', 'iwinfo <rádio> info (Noise), hodnota z ovladače karty.'),
    source: agent,
  };
  const wifiBusy: MetricHelp = {
    what: t('help.wifi_busy_band_what', 'Jak velkou část času byl kanál obsazený (kýmkoli, i sousedy).'),
    how: t('help.wifi_busy_band_how', 'iw dev <rádio> survey dump: přírůstek busy/active času mezi dvěma hlášeními.'),
    source: agent,
    caveat: t('help.wifi_busy_band_caveat', 'Některé ovladače čas nepočítají; pak zůstane prázdné.'),
  };
  const wifiBusyOther: MetricHelp = {
    what: t(
      'help.wifi_busy_other_what',
      'Část vytížení, která nepatří vaší síti: obsazený čas bez vlastního vysílání a příjmu.'
    ),
    how: t(
      'help.wifi_busy_other_how',
      'iw dev <rádio> survey dump: přírůstek času busy bez vlastního vysílání (transmit) a bez příjmu vlastní sítě (BSS receive), dělený přírůstkem času active mezi dvěma hlášeními.'
    ),
    source: agent,
  };
  // Shared by the step metrics: each point is the growth of a counter between
  // two reports, which has the same two blind spots whatever is being counted.
  const stepCaveat = t(
    'help.step_caveat',
    'Po restartu routeru nebo změně portu se přírůstek nepočítá, takže jde o dolní odhad. V delším období graf ukazuje součet, ne průměr.'
  );

  const wanRateHow = t(
    'help.wan_rate_how',
    'Přírůstek počítadel rx_bytes a tx_bytes rozhraní WAN mezi dvěma hlášeními, dělený časem podle uptime routeru.'
  );
  const wanRateCaveat = t(
    'help.wan_rate_caveat',
    'Minutový průměr, krátká špička se v něm rozpustí. Po startu routeru nebo změně rozhraní WAN zůstane prázdné.'
  );

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
    ram_free_mb: {
      what: t('help.ram_free_mb_what', 'Kolik paměti je skutečně volné.'),
      how: t('help.ram_free_mb_how', 'Z /proc/meminfo, hodnota MemAvailable.'),
      source: agent,
      caveat: t(
        'help.ram_free_mb_caveat',
        'Je to druhá strana téže mince jako využití paměti: tady je lepší vyšší číslo.'
      ),
    },
    load1: {
      what: t('help.load1_what', 'Průměrný počet procesů čekajících na procesor za poslední minutu.'),
      how: t('help.load1_how', 'První hodnota z /proc/loadavg.'),
      source: agent,
      caveat: t(
        'help.load1_caveat',
        'Porovnávejte s počtem jader: load 4 je na čtyřjádru plné vytížení, na jednojádru čtyřnásobné přetížení.'
      ),
    },
    load5: {
      what: t('help.load5_what', 'Zátěž procesoru průměrovaná přes pět minut.'),
      how: t('help.load5_how', 'Druhá hodnota z /proc/loadavg.'),
      source: agent,
    },
    load15: {
      what: t('help.load15_what', 'Zátěž procesoru průměrovaná přes patnáct minut.'),
      how: t('help.load15_how', 'Třetí hodnota z /proc/loadavg.'),
      source: agent,
      caveat: t('help.load15_caveat', 'Delší průměr ukazuje trend: když je vyšší než minutový, zátěž odeznívá.'),
    },
    iowait: {
      what: t('help.iowait_what', 'Podíl času, kdy procesor čekal na disk.'),
      how: t('help.iowait_how', 'Sloupec iowait v /proc/stat, rozdíl mezi hlášeními.'),
      source: agent,
      caveat: t(
        'help.iowait_caveat',
        'Vysoké čekání při nízkém využití CPU znamená, že úzké hrdlo je úložiště, ne procesor.'
      ),
    },
    cpu_steal: {
      what: t('help.cpu_steal_what', 'Čas, který hypervizor odebral tomuto virtuálnímu stroji.'),
      how: t('help.cpu_steal_how', 'Sloupec steal v /proc/stat.'),
      source: agent,
      caveat: t(
        'help.cpu_steal_caveat',
        'Trvale nenulová hodnota znamená přetížený hostitel u poskytovatele - na vaší straně se s tím nedá nic dělat.'
      ),
    },
    inode_usage: {
      what: t('help.inode_usage_what', 'Zaplnění tabulky inodů, tedy počtu souborů.'),
      how: t('help.inode_usage_how', 'df -i na stejném oddílu jako zaplnění disku.'),
      source: agent,
      caveat: t(
        'help.inode_usage_caveat',
        'Dojít mohou dřív než místo - typicky u milionů malých souborů, třeba cache nebo relací.'
      ),
    },
    net: {
      what: t('help.net_what', 'Provoz na WAN rozhraní.'),
      how: t('help.net_how', 'Přírůstek bajtů rozhraní mezi hlášeními, přepočtený na KB/s.'),
      source: agent,
      caveat: t('help.net_caveat', 'Jen WAN. Provoz na LAN a mezi rozhraními se sem nepočítá.'),
    },
    net_lte: {
      what: t('help.net_lte_what', 'Provoz na LTE záložním rozhraní.'),
      how: t('help.net_lte_how', 'Stejný výpočet jako u WAN, jen na LTE zařízení.'),
      source: agent,
      caveat: t(
        'help.net_lte_caveat',
        'Nenulová hodnota mimo výpadek znamená, že něco teče přes zálohu - obvykle placená data.'
      ),
    },
    net_ipv4: {
      what: t('help.net_ipv4_what', 'Provoz protokolem IPv4 přes všechna rozhraní.'),
      how: t('help.net_ipv4_how', 'Z /proc/net/netstat, rozdíl mezi hlášeními.'),
      source: agent,
      caveat: t('help.net_ipv4_caveat', 'Počítá i LAN, takže je to jiné číslo než provoz na WAN - nesčítejte je.'),
    },
    net_ipv6: {
      what: t('help.net_ipv6_what', 'Provoz protokolem IPv6 přes všechna rozhraní.'),
      how: t('help.net_ipv6_how', 'Z /proc/net/netstat, rozdíl mezi hlášeními.'),
      source: agent,
      caveat: t('help.net_ipv6_caveat', 'Nula znamená, že IPv6 neteče - buď není nasazené, nebo nefunguje.'),
    },
    net_errors: {
      what: t('help.net_errors_what', 'Chyby na síťových rozhraních.'),
      how: t('help.net_errors_how', 'Součet chybových počítadel rozhraní, rozdíl mezi hlášeními.'),
      source: agent,
      caveat: t('help.net_errors_caveat', 'Rostoucí počet ukazuje na vadný kabel, port nebo rušení, ne na zahlcení.'),
    },
    zombie_count: {
      what: t('help.zombie_count_what', 'Počet zombie procesů.'),
      how: t('help.zombie_count_how', 'Procesy ve stavu Z v /proc.'),
      source: agent,
      caveat: t(
        'help.zombie_count_caveat',
        'Zombie samy nic nespotřebují, ale jejich přibývání znamená, že rodičovský proces nesklízí potomky.'
      ),
    },
    fork_rate: {
      what: t('help.fork_rate_what', 'Kolik nových procesů systém spouští za sekundu.'),
      how: t('help.fork_rate_how', 'Hodnota processes v /proc/stat, rozdíl mezi hlášeními.'),
      source: agent,
      caveat: t('help.fork_rate_caveat', 'Náhlý skok často znamená smyčku ve skriptu nebo restartující se službu.'),
    },
    wifi_clients: {
      what: t('help.wifi_clients_what', 'Počet zařízení připojených k Wi-Fi.'),
      how: t('help.wifi_clients_how', 'Součet klientů všech rádií podle iwinfo.'),
      source: agent,
    },
    wifi_clients_24g: {
      what: t('help.wifi_band_clients_what', 'Počet zařízení připojených k rádiím v tomto pásmu.'),
      how: t(
        'help.wifi_band_clients_how',
        'Klienti rádií daného pásma podle iwinfo; server je sčítá z každého hlášení routeru.'
      ),
      source: agent,
    },
    wifi_clients_5g: {
      what: t('help.wifi_band_clients_what', 'Počet zařízení připojených k rádiím v tomto pásmu.'),
      how: t(
        'help.wifi_band_clients_how',
        'Klienti rádií daného pásma podle iwinfo; server je sčítá z každého hlášení routeru.'
      ),
      source: agent,
    },
    wifi_clients_6g: {
      what: t('help.wifi_band_clients_what', 'Počet zařízení připojených k rádiím v tomto pásmu.'),
      how: t(
        'help.wifi_band_clients_how',
        'Klienti rádií daného pásma podle iwinfo; server je sčítá z každého hlášení routeru.'
      ),
      source: agent,
    },
    wifi_6e_capable_24g: {
      what: t('help.wifi_6e_capable_what', 'Kolik klientů v tomto pásmu uvádí podporu 6 GHz, tedy Wi-Fi 6E.'),
      how: t(
        'help.wifi_6e_capable_how',
        'Seznam provozních tříd, který klient pošle při připojení (hostapd_cli all_sta); třídy 131 až 137 jsou 6 GHz.'
      ),
      source: agent,
      caveat: t(
        'help.wifi_6e_capable_caveat',
        'Ne každé zařízení Wi-Fi 6 seznam posílá. U kolika klientů je podpora známá, ukazuje graf se známou podporou pásem; zbytek je neznámý, ne bez podpory. Starší zařízení (Wi-Fi 4 a 5) se od agenta 0.1.7 počítají jako známá bez podpory.'
      ),
    },
    wifi_6e_capable_5g: {
      what: t('help.wifi_6e_capable_what', 'Kolik klientů v tomto pásmu uvádí podporu 6 GHz, tedy Wi-Fi 6E.'),
      how: t(
        'help.wifi_6e_capable_how',
        'Seznam provozních tříd, který klient pošle při připojení (hostapd_cli all_sta); třídy 131 až 137 jsou 6 GHz.'
      ),
      source: agent,
      caveat: t(
        'help.wifi_6e_capable_caveat',
        'Ne každé zařízení Wi-Fi 6 seznam posílá. U kolika klientů je podpora známá, ukazuje graf se známou podporou pásem; zbytek je neznámý, ne bez podpory. Starší zařízení (Wi-Fi 4 a 5) se od agenta 0.1.7 počítají jako známá bez podpory.'
      ),
    },
    wifi_6e_known_24g: {
      what: t('help.wifi_6e_known_what', 'U kolika klientů v tomto pásmu je podpora pásem známá.'),
      how: t(
        'help.wifi_6e_known_how',
        'Klienti, u kterých router ví, zda 6 GHz umí: zařízení Wi-Fi 6 a 7, která při připojení poslala seznam provozních tříd, a na síti Wi-Fi 6 také starší zařízení (Wi-Fi 4 a 5), která 6 GHz umět nemohou. Takto se počítá od agenta 0.1.7; starší agent počítal jen zařízení, která seznam poslala, takže řada může při aktualizaci agenta skokově vzrůst. Úplný údaj potřebuje balíček hostapd-utils.'
      ),
      source: agent,
    },
    wifi_6e_known_5g: {
      what: t('help.wifi_6e_known_what', 'U kolika klientů v tomto pásmu je podpora pásem známá.'),
      how: t(
        'help.wifi_6e_known_how',
        'Klienti, u kterých router ví, zda 6 GHz umí: zařízení Wi-Fi 6 a 7, která při připojení poslala seznam provozních tříd, a na síti Wi-Fi 6 také starší zařízení (Wi-Fi 4 a 5), která 6 GHz umět nemohou. Takto se počítá od agenta 0.1.7; starší agent počítal jen zařízení, která seznam poslala, takže řada může při aktualizaci agenta skokově vzrůst. Úplný údaj potřebuje balíček hostapd-utils.'
      ),
      source: agent,
    },
    conntrack_count: {
      what: t('help.conntrack_count_what', 'Počet sledovaných spojení.'),
      how: t('help.conntrack_count_how', 'Z nf_conntrack_count.'),
      source: agent,
      caveat: t(
        'help.conntrack_count_caveat',
        'Absolutní číslo; jak blízko je stropu, říká metrika Conntrack tabulka.'
      ),
    },
    dhcp_leases_count: {
      what: t('help.dhcp_leases_count_what', 'Kolik zařízení má právě zapůjčenou adresu.'),
      how: t('help.dhcp_leases_count_how', 'Počet záznamů v souboru zápůjček dnsmasq.'),
      source: agent,
    },
    lte_rssi: {
      what: t('help.lte_rssi_what', 'Celková síla přijímaného signálu včetně rušení.'),
      how: t('help.lte_rssi_how', 'Z modemu přes ModemManager nebo uqmi.'),
      source: agent,
      caveat: t(
        'help.lte_rssi_caveat',
        'Na LTE je vypovídající spíš RSRP: RSSI sčítá i cizí signály na stejné frekvenci.'
      ),
    },
    lte_uptime: {
      what: t('help.lte_uptime_what', 'Jak dlouho stojí současné LTE spojení.'),
      how: t('help.lte_uptime_how', 'Doba běhu rozhraní podle netifd.'),
      source: agent,
      caveat: t('help.lte_uptime_caveat', 'Krátká doba po nedávném výpadku znamená, že se spojení právě obnovilo.'),
    },
    ts_clients: {
      what: t('help.ts_clients_what', 'Počet uživatelů na TeamSpeak serveru.'),
      how: t('help.ts_clients_how', 'Dotaz ServerQuery na běžící server.'),
      source: server,
    },
    mc_players: {
      what: t('help.mc_players_what', 'Počet hráčů na Minecraft serveru.'),
      how: t('help.mc_players_how', 'Ze status odpovědi serveru.'),
      source: server,
    },
    discord_presence: {
      what: t('help.discord_presence_what', 'Kolik lidí je online na Discord serveru.'),
      how: t('help.discord_presence_how', 'Z widget API Discordu.'),
      source: server,
    },
    tailscale_peers: {
      what: t('help.tailscale_peers_what', 'Počet protějšků v síti Tailscale.'),
      how: t('help.tailscale_peers_how', 'Z tailscale status.'),
      source: agent,
    },
    wifi_noise_24g: wifiNoise,
    wifi_noise_5g: wifiNoise,
    wifi_noise_6g: wifiNoise,
    wifi_busy_24g: wifiBusy,
    wifi_busy_5g: wifiBusy,
    wifi_busy_6g: wifiBusy,
    wifi_busy_other_24g: wifiBusyOther,
    wifi_busy_other_5g: wifiBusyOther,
    wifi_busy_other_6g: wifiBusyOther,
    wifi_weak_clients: {
      what: t('help.wifi_weak_clients_what', 'Kolik klientů slyší router na −75 dBm a slaběji.'),
      how: t(
        'help.wifi_weak_clients_how',
        'iwinfo <rádio> assoclist: klienti se signálem −75 dBm a slabším, sečtení přes všechna rádia. Klient s neznámým signálem se nepočítá.'
      ),
      source: agent,
    },
    wifi_wpa2_clients: {
      what: t('help.wifi_wpa2_clients_what', 'Kolik klientů se přihlásilo přes WPA2 (PSK).'),
      how: t(
        'help.wifi_wpa2_clients_how',
        'hostapd_cli all_sta: klienti, jejichž AKMSuiteSelector je WPA2 (PSK). Potřebuje balíček hostapd-utils; bez něj zůstane prázdné.'
      ),
      source: agent,
    },
    // Derived by the server, but from the agent's report and at its cadence -
    // "a check from the monitoring server, every cron run" would be false.
    wifi_6e_unserved: {
      what: t(
        'help.wifi_6e_unserved_what',
        'Podíl času, kdy byli připojeni aspoň dva klienti s podporou 6 GHz a router na 6 GHz nevysílal.'
      ),
      how: t(
        'help.wifi_6e_unserved_how',
        'Server ji počítá z každého hlášení: žádné rádio nevysílá na 6 GHz a aspoň dva připojení klienti uvádějí provozní třídu 6 GHz (131–137). Když to nejde rozhodnout, protože část klientů podporu neuvedla, hlášení se nezapočítá.'
      ),
      source: agent,
    },
    wifi_5g_capable_24g: {
      what: t('help.wifi_5g_capable_what', 'Kolik klientů na 2.4 GHz uvádí podporu 5 GHz.'),
      how: t(
        'help.wifi_5g_capable_how',
        'hostapd_cli all_sta: klienti na 2.4 GHz, kteří v seznamu provozních tříd (supp_op_classes) uvádějí třídu pásma 5 GHz (115–130). Potřebuje balíček hostapd-utils.'
      ),
      source: agent,
    },
    // The all-core `cpu` of the same report hides a single saturated core: on
    // a two-core router forwarding can pin one core while the average says 50 %.
    cpu_core_max: {
      what: t('help.cpu_core_max_what', 'Vytížení nejvytíženějšího jádra procesoru za poslední minutu.'),
      how: t(
        'help.cpu_core_max_how',
        'Z /proc/stat, řádky cpu0, cpu1, …: rozdíl čítačů mezi dvěma hlášeními pro každé jádro zvlášť; ukazuje se to nejvytíženější.'
      ),
      source: agent,
      caveat: t(
        'help.cpu_core_max_caveat',
        'Průměr přes všechna jádra může být poloviční, protože přeposílání paketů často zatíží jediné jádro. Po startu routeru zůstane prázdné.'
      ),
    },
    cpu_core_max_softirq: {
      what: t(
        'help.cpu_core_max_softirq_what',
        'Jakou část času strávilo nejvytíženější jádro zpracováním paketů a přerušení (irq + softirq).'
      ),
      how: t(
        'help.cpu_core_max_softirq_how',
        'Z /proc/stat: přírůstek sloupců irq a softirq téhož jádra, dělený přírůstkem všech sloupců.'
      ),
      source: agent,
      caveat: t(
        'help.cpu_core_max_softirq_caveat',
        'Část síťové práce (vlákna NAPI, ovladač Wi-Fi) jádro účtuje jako system, ne softirq, takže skutečný podíl sítě může být vyšší.'
      ),
    },
    wan_rx_mbps: {
      what: t('help.wan_rx_mbps_what', 'Rychlost stahování na rozhraní WAN, průměr za minutu.'),
      how: wanRateHow,
      source: agent,
      caveat: wanRateCaveat,
    },
    wan_tx_mbps: {
      what: t('help.wan_tx_mbps_what', 'Rychlost odesílání na rozhraní WAN, průměr za minutu.'),
      how: wanRateHow,
      source: agent,
      caveat: wanRateCaveat,
    },
    wan_errors: {
      what: t('help.wan_errors_what', 'Nové chyby příjmu a odesílání na fyzickém portu WAN od předchozího hlášení.'),
      how: t(
        'help.wan_errors_how',
        'Počítadla rx_errors a tx_errors portu WAN v /sys/class/net; server ukládá přírůstek mezi dvěma hlášeními.'
      ),
      source: agent,
      caveat: `${t('help.wan_errors_caveat', 'Rostoucí počet ukazuje na kabel, modul SFP nebo port, ne na zahlcení.')} ${stepCaveat}`,
    },
    // rx_dropped also counts frames nobody handles (LLDP, foreign VLAN tags),
    // so the explainer must not let it read as "the router is overloaded".
    wan_drops: {
      what: t(
        'help.wan_drops_what',
        'Nově zahozené pakety na portu WAN od předchozího hlášení (včetně neznámých protokolů).'
      ),
      how: t(
        'help.wan_drops_how',
        'Počítadla rx_dropped a tx_dropped portu WAN v /sys/class/net; server ukládá přírůstek mezi dvěma hlášeními.'
      ),
      source: agent,
      caveat: `${t('help.wan_drops_caveat', 'Většinou jde o neškodné rámce, které router nezpracovává (LLDP, cizí VLAN, PPPoE discovery), ne o přetížení. Žádné doporučení z této hodnoty nevychází.')} ${stepCaveat}`,
    },
    wan_ring_drops: {
      what: t(
        'help.wan_ring_drops_what',
        'Pakety, které port WAN nestihl převzít z přijímací fronty (rx_discard + rx_overrun).'
      ),
      how: t(
        'help.wan_ring_drops_how',
        'ethtool -S <port WAN>, čte se jednou za hodinu; server ukládá přírůstek mezi dvěma čteními. Potřebuje balíček ethtool; bez něj zůstane prázdné.'
      ),
      source: agent,
      caveat: stepCaveat,
    },
    // Only `drop` with a full table is a refused connection; on its own the
    // column also grows on harmless races, so the text must not say "refused".
    conntrack_drops: {
      what: t('help.conntrack_drops_what', 'Pakety nově zahozené sledováním spojení od předchozího hlášení.'),
      how: t(
        'help.conntrack_drops_how',
        'Sloupec drop v /proc/net/stat/nf_conntrack, součet přes jádra; server ukládá přírůstek mezi dvěma hlášeními.'
      ),
      source: agent,
      caveat: `${t('help.conntrack_drops_caveat', 'O odmítnutá spojení jde jen tehdy, když je zároveň plná tabulka spojení (Conntrack tabulka na 90 % a výš).')} ${stepCaveat}`,
    },
    wan_link_flaps: {
      what: t('help.wan_link_flaps_what', 'Kolikrát od předchozího hlášení spadla linka na fyzickém portu WAN.'),
      how: t(
        'help.wan_link_flaps_how',
        'Počítadlo carrier_down_count portu WAN v /sys/class/net; server ukládá přírůstek mezi dvěma hlášeními.'
      ),
      source: agent,
      caveat: stepCaveat,
    },
    agent_run_ms: {
      what: t(
        'help.agent_run_ms_what',
        'Jak dlouho agentovi na routeru trvalo jedno měření, od startu po sestavení hlášení.'
      ),
      how: t('help.agent_run_ms_how', 'Rozdíl /proc/uptime na začátku běhu a při sestavení hlášení, v milisekundách.'),
      source: agent,
      caveat: t(
        'help.agent_run_ms_caveat',
        'Odeslání hlášení se do hodnoty nepočítá. Běh, který se blíží 60 sekundám, začne vynechávat minuty.'
      ),
    },
    // Agent 0.1.9. The wall time above includes waiting on the network; this
    // is what the agent actually takes from the router, the number the choice
    // of a cheaper agent on slow MIPS routers is read from. No caveat: the
    // dictionary is on the critical path, at its size budget (see there).
    agent_prev_cpu_ms: {
      what: t('help.agent_prev_cpu_ms_what', 'CPU čas předchozího běhu agenta i se vším, co spustil.'),
      how: t('help.agent_prev_cpu_ms_how', 'utime+stime+cutime+cstime z /proc/<agent>/stat.'),
      source: agent,
    },
    // Computed by the server, but from the agent's report and at its cadence,
    // like wifi_6e_unserved above.
    clock_skew_s: {
      what: t('help.clock_skew_s_what', 'O kolik sekund se hodiny routeru liší od hodin serveru, bez ohledu na směr.'),
      how: t(
        'help.clock_skew_s_how',
        'Server odečte čas, který agent uvedl v hlášení (date +%s), od času, kdy hlášení přijal, a uloží absolutní hodnotu.'
      ),
      source: agent,
      caveat: t(
        'help.clock_skew_s_caveat',
        'Zhruba 2 sekundy připadají na přenos hlášení. Při rozdílu nad 30 sekund router odmítá vzdálené akce.'
      ),
    },
  };

  return catalogue[key] ?? null;
}
