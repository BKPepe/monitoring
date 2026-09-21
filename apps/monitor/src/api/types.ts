/**
 * The shape of data the charts expect.
 *
 * The single place defining the interface between the UI and the data source.
 * There are two implementations: `http-source.ts` (today's PHP `api.php`) and
 * `mock-source.ts` (which now just delegates to http-source; it generates no
 * invented data - via `isMock` the UI only reports the API was unreachable).
 *
 * The types match what `apps/status/api.php` really returns —
 * viz `apps/server/API-CONTRACT.md`.
 */

/**
 * Metric keys from `bk_get_metric_registry()` in `functions.php`.
 * The registry is the source of truth; this enum must copy it exactly,
 * otherwise the API returns `{"error":"Unknown metric"}`.
 */
export type MetricKey =
  | 'response_time'
  | 'discord_presence'
  | 'mc_players'
  | 'lte_rsrp'
  | 'cpu'
  | 'ram'
  | 'hdd'
  | 'net'
  | 'net_lte'
  | 'load1'
  | 'load5'
  | 'load15'
  | 'cpu_steal'
  | 'swap'
  | 'disk_io_read'
  | 'disk_io_write'
  | 'net_errors'
  | 'iowait'
  | 'inode_usage'
  | 'ts_clients'
  | 'ts_process_cpu'
  | 'ts_process_ram'
  | 'net_ipv4'
  | 'net_ipv6'
  | 'temperature_c'
  // Agent 0.1.7, Wi-Fi per band (OpenWrt only). The band suffix is part of the
  // key because the registry has one column per band, not a band parameter.
  | 'wifi_noise_24g'
  | 'wifi_noise_5g'
  | 'wifi_noise_6g'
  | 'wifi_busy_24g'
  | 'wifi_busy_5g'
  | 'wifi_busy_6g'
  | 'wifi_busy_other_24g'
  | 'wifi_busy_other_5g'
  | 'wifi_busy_other_6g'
  | 'wifi_weak_clients'
  | 'wifi_wpa2_clients'
  | 'wifi_6e_unserved'
  | 'wifi_5g_capable_24g'
  // Agent 0.1.7, WAN path. wan_errors, wan_drops, wan_ring_drops,
  // conntrack_drops and wan_link_flaps are step metrics: the server sums them
  // per bucket ('step' => true), so a point is "new since then", not a mean.
  | 'cpu_core_max'
  | 'cpu_core_max_softirq'
  | 'wan_rx_mbps'
  | 'wan_tx_mbps'
  | 'wan_errors'
  | 'wan_drops'
  | 'wan_ring_drops'
  | 'conntrack_drops'
  | 'wan_link_flaps'
  | 'agent_run_ms'
  | 'clock_skew_s';

/** Colour tone of a series. Must match the `--chart-*` tokens. */
export type MetricTone = 'cpu' | 'memory' | 'network' | 'temperature' | 'disk' | 'latency';

export interface MetricPoint {
  /**
   * Unix timestamp in **milliseconds**.
   *
   * `api.php` returns seconds — `http-source.ts` converts to ms so every
   * component does not have to deal with it separately.
   */
  t: number;
  /** Value in the series' unit; `null` = a missing measurement, not zero. */
  v: number | null;
}

export interface MetricSeries {
  key: string;
  label: string;
  unit: string;
  tone: MetricTone;
  points: MetricPoint[];
  /** The series is a prediction, not a measurement — drawn dashed. */
  predicted?: boolean;
  /**
   * The same metric one period earlier, laid over this window. Drawn dotted
   * and dimmed: it is measured data, but not of the window on the axis, and
   * it must be told apart from the dashed forecast at a glance.
   */
  past?: boolean;
}

/** An event on the chart's timeline (outage, restart, config change). */
export interface ChartEvent {
  /**
   * How serious the event was. An outage or a crossed limit is drawn boldly;
   * everything else stays a quiet dotted line. Matching on the label text
   * would break the moment the interface is read in another language.
   */
  severity?: 'alert' | 'info';
  t: number;
  label: string;
}

/** Input for one chart — one or more series sharing axes. */
export interface ChartData {
  id: string;
  title: string;
  /** Upper Y-axis bound. `null` = derive from the data. */
  yMax: number | null;
  /**
   * Lower bound of the y axis. 0 for anything measured from zero (a share, a
   * rate, a count); null lets the chart derive the range from the data, which
   * is the only way to draw a metric in negative dBm - and the only way a
   * temperature between 45 and 52 degrees is more than a flat line at the
   * bottom of a 0-120 axis.
   */
  yMin?: number | null;
  series: MetricSeries[];
  events?: ChartEvent[];
  /**
   * User-written notes ("deploy happened here"). Drawn as vertical lines like
   * events, but in their own colour - a note is a human's claim, an event is
   * a measured fact, and the chart must not blur the two.
   */
  annotations?: ChartEvent[];
  /**
   * Threshold bands (warning, critical) as horizontal areas.
   *
   * A single line at the critical limit does not tell you whether 78 % is
   * still fine or already on the edge. A band shows it without reading numbers.
   */
  bands?: { from: number; to: number; tone: 'warning' | 'critical'; label: string }[];
  /**
   * Time ranges shaded across the chart (ms) - the router running on its LTE
   * backup. Bytes drawn inside such a band went over the backup, not the
   * primary line.
   */
  periods?: { from: number; to: number; label: string }[];
  /**
   * Spread behind each point of the FIRST series, drawn as a band around the
   * line. Used for daily rollups (90d/1y), where a point is a day's average
   * and the day's real minimum and maximum are otherwise invisible.
   */
  range?: { t: number; min: number | null; max: number | null; samples?: number }[];
  /**
   * Days remaining until full (100 %).
   *
   * Computed by `api.php` with linear regression over 7 days, for `hdd`, `ram`
   * and `inode_usage`. Not computed here.
   */
  daysToFull?: number;
  /**
   * Whether this metric earns a full chart card on the overview. The curated
   * set gets cards; everything else the device reports is listed compactly
   * beneath them, because it is measured every minute and used to be
   * unreachable - the batch response has always carried it.
   */
  featured?: boolean;
  /**
   * Draw the series stacked on top of each other. For two links carrying the
   * same kind of traffic that is the honest picture: the height is the total
   * and each band is one link's share. Stacked areas are also the one case
   * where two fills do not overlap into mud.
   */
  stacked?: boolean;
}

/** The periods `api.php` accepts in the `period` parameter. */
export type TimeRange = '15m' | '1h' | '6h' | '24h' | '7d' | '30d';

/**
 * Periods longer than the raw-data retention (30 days). They are read from the
 * `metrics_daily` rollup, so a point is a daily average rather than a single
 * measurement - the server admits this with `resolution: 'daily'`.
 */
export type LongTimeRange = '90d' | '1y';
export type MetricRange = TimeRange | LongTimeRange;

export const timeRangeLabels: Record<TimeRange, string> = {
  '15m': 'Posledních 15 minut',
  '1h': 'Poslední hodina',
  '6h': 'Posledních 6 hodin',
  '24h': 'Posledních 24 hodin',
  '7d': 'Posledních 7 dní',
  '30d': 'Posledních 30 dní',
};

/** Response of `api.php?action=metric_series`. */
export interface MetricSeriesResponse {
  label: string;
  unit: string;
  /** [unix seconds, value] */
  points: [number, number][];
  /** `daily` = points are daily averages, not individual measurements. */
  resolution?: 'daily';
  /**
   * Per-day spread behind a daily average. The server has always sent it for
   * 90d/1y; drawing only the average hid that a day averaging 40 % peaked at
   * 100 %.
   */
  dailyRange?: { ts: number; min: number | null; max: number | null; samples: number }[];
  /** Days until the metric reaches 100 %; absent when there is no projection. */
  daysToFull?: number;
  error?: string;
}

/** Context for the metric detail page (`api.php?action=metric_detail`). */
export interface MetricDetail {
  monitor: {
    id: number;
    name: string;
    type: string;
    /** What is being measured (host, URL, address). null = the monitor has none. */
    target: string | null;
    port: number | null;
    /** Where the last check was run from; null for values an agent reports about itself. */
    checkedFrom: string | null;
    assetId: number | null;
  };
  /**
   * `step` (X8): the stored value is already the increment since the previous
   * report, so a bucket is SUMMED, never averaged - true for the five step
   * metrics `wan_errors`, `wan_drops`, `wan_ring_drops`, `wan_link_flaps`,
   * `conntrack_drops`. Absent on an older server.
   */
  metric: { key: string; label: string; unit: string; counter: boolean; step?: boolean };
  /** `null` = no threshold is set; no band is drawn in the chart. */
  thresholds: { warning: number | null; critical: number | null };
  /** Which of the thresholds is derived rather than configured by an admin. */
  thresholdsDerived?: { warning: boolean; critical: boolean };
  /** Only metrics this monitor actually reported in its latest measurement. */
  related: { key: string; label: string; unit: string; latest: number }[];
  events: { t: number; type: string; label: string }[];
}

/**
 * Response of `api.php?action=metric_heatmap` - hour-by-day grid over the
 * raw-sample window (30 days at most; raw data is pruned after that).
 */
export interface MetricHeatmapResponse {
  label: string;
  unit: string;
  days: {
    /** `YYYY-MM-DD` in the server's timezone. */
    day: string;
    /** 24 cells; `null` = no sample that hour, never zero. */
    hours: (number | null)[];
    /** How many samples each cell's value stands on. */
    samples: number[];
  }[];
  error?: string;
}

/**
 * Response of `api.php?action=metric_correlations` - how the device's other
 * metrics moved together with this one over the selected period.
 */
export interface MetricCorrelationsResponse {
  label: string;
  /** Measurement rows the calculation had available. */
  samples: number;
  /** Minimum usable pairs below which no coefficient is reported. */
  minPairs: number;
  /** How many metrics were compared in total (the list itself is the top few). */
  total: number;
  correlations: {
    key: string;
    label: string;
    unit: string;
    /**
     * Pearson coefficient in [-1, 1], or `null` when it is undefined -
     * a series that never changed, or too few overlapping samples. Never 0
     * as a stand-in: that would assert the two are unrelated.
     */
    r: number | null;
    pairs: number;
    /** Why `r` is null: `constant` | `few_samples`. */
    reason: string | null;
  }[];
  error?: string;
}

/**
 * Response of `api.php?action=link_traffic` - a router's traffic split by
 * link role (primary line vs. LTE backup) and the periods spent on the backup.
 */
export interface LinkTrafficResponse {
  /** `null` = the agent does not report the device for this role (before 0.1.3). */
  primary: LinkTrafficSide | null;
  backup: LinkTrafficSide | null;
  days: number;
  /**
   * Primary-link outages (wan_lost -> wan_restored). Whether traffic really
   * went over the backup during them is what `backup`'s byte counts say - a
   * router with no LTE has outages too. `from`/`to` are unix seconds;
   * `from: null` = began before the window, `to: null` = still down.
   */
  wan_down_periods: { from: number | null; to: number | null; seconds: number }[];
  wan_down_seconds: number;
  wan_down_now: boolean;
  /** Every interface the router reported traffic for, roles or not. */
  interfaces: string[];
  error?: string;
}

export interface LinkTrafficSide {
  iface: string;
  /** `null` = no traffic rows for this device yet. */
  today: { rx_bytes: number; tx_bytes: number } | null;
  '7d': { rx_bytes: number; tx_bytes: number } | null;
  '30d': { rx_bytes: number; tx_bytes: number } | null;
  total: { rx_bytes: number; tx_bytes: number } | null;
}

/** 'public' = the status page's whole fleet; 'app' = only the monitors the signed-in viewer may see. */
export type PublicStatusScope = 'app' | 'public';

/** Response of `api.php?action=public_status`. */
export interface PublicStatus {
  status: 'healthy' | 'degraded';
  /** null = no check in the last 30 days yet (fresh install / dead cron), not 100 %. */
  uptimePercent: number | null;
  totalMonitors: number;
  downMonitors: number;
  agentsOnline: number;
  agentsTotal: number;
  /** null = no latency measurement in the last hour yet. */
  avgLatencyMs: number | null;
  lastUpdated: string | null;
  nodes: { name: string; status: 'online' | 'warning' | 'offline'; latencyMs: number | null }[];
}

export interface MetricsSource {
  /** Source name for the UI — the user should see whether the data is real. */
  readonly name: 'api.php' | 'mock';
  getAssetCharts(monitorId: number, range: TimeRange): Promise<ChartData[]>;
  getPublicStatus(scope?: PublicStatusScope): Promise<PublicStatus>;
  getMetricDetail(monitorId: number, metric: string): Promise<MetricDetail>;
  /** @param previous The window immediately before this one, for comparison. */
  getMetricSeries(
    monitorId: number,
    metric: string,
    range: MetricRange,
    previous?: boolean
  ): Promise<MetricSeriesResponse>;
  getMetricHeatmap(monitorId: number, metric: string, days: number): Promise<MetricHeatmapResponse>;
  /** @param all Every comparison, not only the strongest few. */
  getMetricCorrelations(
    monitorId: number,
    metric: string,
    range: MetricRange,
    all?: boolean
  ): Promise<MetricCorrelationsResponse>;
  getLinkTraffic(monitorId: number, days?: number): Promise<LinkTrafficResponse>;
}

/*
 * Router health (agent 0.1.7). The objects below arrive inside the monitor
 * detail (`last_details`), sanitized by the server and otherwise unchanged,
 * hence snake_case. Everything is nullable: null = not measured, never zero.
 * A field may also be absent altogether when the router runs an older agent.
 */

export type WifiBand = '2.4GHz' | '5GHz' | '6GHz';

export type WifiEncryption = 'open' | 'owe' | 'wep' | 'wpa' | 'wpa_wpa2' | 'wpa2' | 'wpa2_wpa3' | 'wpa3';

export type WifiBusyState = 'measured' | 'warming_up' | 'unsupported' | 'not_installed';

/** 0 = older than Wi-Fi 4 (802.11a/b/g). */
export type WifiGeneration = 0 | 4 | 5 | 6 | 7;

/** Connected stations by Wi-Fi generation; the highest flag a station shows wins. */
export interface WifiClientGenerations {
  /** Over `ubus` hostapd cannot tell Wi-Fi 7 apart: `wifi7` is null and `wifi6` means "6 or newer". */
  source: 'hostapd_cli' | 'ubus';
  legacy: number | null;
  wifi4: number | null;
  wifi5: number | null;
  wifi6: number | null;
  wifi7: number | null;
}

/** One wireless netdev of `wifi_radios[]`. Aggregates only - no MAC, no BSSID. */
export interface WifiRadio {
  radio?: string | null;
  /** The router's own SSID; null on a disabled radio. */
  ssid?: string | null;
  mode?: 'ap' | 'client' | 'mesh' | 'other' | null;
  /** Derived from the frequency only; a disabled radio has no band. */
  band?: WifiBand | null;
  frequency_mhz?: number | null;
  channel?: number | null;
  /** `NOHT` or `(HT|VHT|HE|EHT)<width>[+80]`, e.g. `HE80`. */
  htmode?: string | null;
  /**
   * Derived by the server from `htmode` (0 = NOHT, 4 HT, 5 VHT, 6 HE, 7 EHT);
   * the app only formats it, so the two can never read an htmode differently.
   */
  generation?: WifiGeneration | null;
  width_mhz?: number | null;
  /** Best of `htmodes_supported`, clamped by the radio's own band; null without a band. */
  supported_generation?: WifiGeneration | null;
  supported_width_mhz?: number | null;
  /** What the card can do, cached daily on the router; null until the cache exists. */
  htmodes_supported?: string[] | null;
  phy_has_6ghz?: boolean | null;
  phy?: string | null;
  encryption?: WifiEncryption | null;
  encryption_enterprise?: boolean | null;
  tx_power?: number | null;
  noise?: number | null;
  clients?: number | null;
  signal_median?: number | null;
  signal_min?: number | null;
  snr_min?: number | null;
  /** Stations at -75 dBm or weaker; a measured 0 when nobody is connected. */
  clients_weak?: number | null;
  weakest_gen?: WifiGeneration | null;
  /** Mean rate of the last frames towards the clients - not a capability. */
  bitrate_tx_avg_mbps?: number | null;
  clients_gen?: WifiClientGenerations | null;
  clients_caps_known?: number | null;
  clients_6ghz_capable?: number | null;
  clients_opclass_known?: number | null;
  /** Only on 2.4 GHz radios. */
  clients_5ghz_capable?: number | null;
  clients_akm_known?: number | null;
  clients_wpa2?: number | null;
  clients_wpa3?: number | null;
  clients_8021x?: number | null;
  busy_pct?: number | null;
  busy_other_pct?: number | null;
  /** Why `busy_pct` is null when it is. */
  busy_state?: WifiBusyState | null;
}

export type DiskTransport = 'sata' | 'usb' | 'nvme' | 'emmc' | 'sd' | 'virtio' | 'other';

/**
 * How the last SMART refresh ended. With `standby`, `error` and `stuck` the
 * values are those of the PREVIOUS reading (`checked_at` says when); with every
 * other state except `ok` and `failing` all values are null.
 */
export type SmartState =
  | 'ok'
  | 'failing'
  | 'standby'
  | 'idle_skipped'
  | 'pending'
  | 'not_installed'
  | 'unsupported'
  | 'error'
  | 'stuck'
  | 'not_applicable';

export interface StorageDiskSmart {
  state: SmartState;
  /** Unix seconds of the reading the VALUES come from, not of the last attempt. */
  checked_at?: number | null;
  exit_bits?: number | null;
  passed?: boolean | null;
  /** false = the drive database does not know this model, so vendor attributes are unreadable. */
  in_drivedb?: boolean | null;
  protocol?: 'ATA' | 'NVMe' | 'SCSI' | null;
  /** Full model name; the sysfs `model` is cut to 16 characters. */
  model?: string | null;
  /** 0 = SSD. */
  rotation_rpm?: number | null;
  temperature_c?: number | null;
  power_on_hours?: number | null;
  power_cycles?: number | null;
  unsafe_shutdowns?: number | null;
  reallocated_sectors?: number | null;
  pending_sectors?: number | null;
  offline_uncorrectable?: number | null;
  reported_uncorrect?: number | null;
  crc_errors?: number | null;
  runtime_bad_blocks?: number | null;
  media_errors?: number | null;
  critical_warning?: number | null;
  available_spare_pct?: number | null;
  /** Percent of the rated life USED (0 = new). */
  wear_pct?: number | null;
  wear_source?: 'devstat' | 'attr231' | 'attr169' | 'attr202' | 'attr233' | 'attr177' | 'nvme' | null;
  /** Lifetime bytes written, as the disk reports them. */
  written_bytes?: number | null;
  written_source?: 'devstat' | 'nvme' | 'attr241' | null;
  error_log_count?: number | null;
  /** 0 = the disk has never run a self-test. */
  selftest_count?: number | null;
}

export interface StorageDiskPartition {
  name: string;
  size_bytes?: number | null;
  /** null = not mounted; then `fstype` and `used_pct` are null too. */
  mount?: string | null;
  fstype?: string | null;
  used_pct?: number | null;
}

/** JEDEC wear codes of an eMMC: `life_*` 1-11 (tenths of the rated life), `pre_eol` 1-3. */
export interface StorageDiskEmmc {
  life_a?: number | null;
  life_b?: number | null;
  pre_eol?: number | null;
}

/** One physical disk of `storage_disks[]`. No serial number or WWN ever leaves the router. */
export interface StorageDisk {
  /** Added by the server: stable id from port + model + size, the same key `storage_history` uses. */
  key?: string | null;
  name: string;
  transport?: DiskTransport | null;
  port?: string | null;
  model?: string | null;
  size_bytes?: number | null;
  rotational?: boolean | null;
  removable?: boolean | null;
  partitions?: StorageDiskPartition[] | null;
  /** null for everything that is not an eMMC. */
  emmc?: StorageDiskEmmc | null;
  smart?: StorageDiskSmart | null;
}

/** `agent_tools`: what the router has installed, so a missing reading can name its package. */
export interface AgentTools {
  smartctl?: boolean | null;
  smart_drivedb?: boolean | null;
  hostapd_cli?: boolean | null;
  iw?: boolean | null;
  librespeed_cli?: boolean | null;
  ethtool?: boolean | null;
  tc?: boolean | null;
  pkg_manager?: 'opkg' | 'apk' | null;
  smart_probe_age_s?: number | null;
  smart_probe_running_s?: number | null;
}

/** One enabled SQM queue on a device of the WAN chain. A rate of null = that direction is not shaped. */
export interface WanSqmQueue {
  iface?: string | null;
  download_kbps?: number | null;
  upload_kbps?: number | null;
  /** null without the `tc` package. */
  egress_dropped?: number | null;
  ingress_dropped?: number | null;
}

/** `wan_path`: how packets travel through the router, re-read hourly on the router. */
export interface WanPath {
  checked_at?: number | null;
  /** What the firewall config asks for ... */
  flow_offloading?: boolean | null;
  flow_offloading_hw?: boolean | null;
  /** ... and whether the loaded ruleset really has a flowtable. */
  flowtable_active?: boolean | null;
  /** The raw uci value; its meaning depends on the release, so nothing judges it. */
  packet_steering?: string | null;
  packet_steering_active?: boolean | null;
  wan_rps_mask?: string | null;
  wan_threaded_napi?: boolean | null;
  /** Cumulative; null without `ethtool`. */
  wan_rx_ring_drops?: number | null;
  /** `[]` = checked, no queue on the WAN device; null = could not check. */
  sqm?: WanSqmQueue[] | null;
  /** The fastest LAN port linked right now - a state, not a capability. */
  lan_port_max_mbit?: number | null;
  /** What the LAN ports can do at most; null until the router can tell. */
  lan_port_cap_mbit?: number | null;
  lan_conduits?: { dev?: string | null; mbit?: number | null }[] | null;
}

/*
 * Router recommendations (`api.php?action=router_recommendations`).
 * The texts are rendered by the server in the request language; the app has
 * no copy of the rule texts.
 */

export type RecommendationSeverity = 'critical' | 'warning' | 'info';

export type RecommendationArea = 'storage' | 'wifi' | 'wan' | 'security' | 'system' | 'packages';

export type RecommendationSubject =
  | { kind: 'router' }
  | { kind: 'wan'; direction: 'dl' | 'ul' }
  | { kind: 'band'; band: WifiBand }
  | { kind: 'radio'; radio: string; band: WifiBand | null; channel: number | null }
  | { kind: 'disk'; diskKey: string; name: string }
  | { kind: 'mount'; mount: string };

export interface RecommendationMute {
  at: string;
  by: string;
  reason: string | null;
  /** Severity at the time of muting; a later rise brings the item back. */
  severity: RecommendationSeverity;
}

export interface RouterRecommendation {
  /** `<rule id>` or `<rule id>:<subject>`; what the mute is stored under. */
  key: string;
  id: string;
  area: RecommendationArea;
  severity: RecommendationSeverity;
  /** The three texts are null on a muted item that no longer fires. */
  title: string | null;
  measured: string | null;
  action: string | null;
  subject: RecommendationSubject;
  params: Record<string, string | number | boolean | null>;
  /** An install or smartctl command named by the action, shown in a copyable block. */
  command: string | null;
  openSince: string | null;
  /** Shown on the live page only, never in the Monday e-mail. */
  pageOnly?: boolean;
  /** It was muted and came back because its severity rose. */
  wasMuted?: boolean;
  /** Only in `muted`: false = the rule does not fire now, the mute is kept. */
  active?: boolean;
  mute?: RecommendationMute | null;
}

export interface RouterRecommendationsResponse {
  monitorId: number;
  /** false = no recommendations can be computed; `reason` says why. */
  applicable: boolean;
  reason: 'agent_old' | 'silent' | 'not_router' | null;
  generatedAt: string;
  window: { from: string; to: string; previousFrom: string; previousTo: string; daysWithData: number } | null;
  canMute: boolean;
  missingPackages: string[];
  items: RouterRecommendation[];
  muted: RouterRecommendation[];
  error?: string;
}

/** Response of `POST api.php?action=router_recommendation_mute`. */
export interface RouterRecommendationMuteResponse {
  ok: boolean;
  key: string;
  muted: boolean;
  /** null after an unmute. */
  mute: RecommendationMute | null;
  error?: string;
}

/** One day of one disk in `storage_history`. A missing value is null, never 0. */
export interface StorageHistoryDay {
  day: string;
  samples: number;
  smartPassed: boolean | null;
  tempMin: number | null;
  tempAvg: number | null;
  tempMax: number | null;
  powerOnHours: number | null;
  powerCycles: number | null;
  unsafeShutdowns: number | null;
  reallocated: number | null;
  pending: number | null;
  offlineUncorrectable: number | null;
  reportedUncorrect: number | null;
  crcErrors: number | null;
  runtimeBadBlocks: number | null;
  mediaErrors: number | null;
  errorLogCount: number | null;
  wearPct: number | null;
  emmcLife: number | null;
  /** Lifetime counter of the disk itself. */
  writtenBytes: number | null;
  /** Bytes the router wrote that day, from the kernel's counters. */
  hostWrittenBytes: number | null;
  /** The day lost part of its counter to a router restart. */
  hostWrittenPartial: boolean;
}

export interface StorageHistoryDisk {
  /** Stable id derived from port + model + size - never from a serial number. */
  key: string;
  name: string;
  transport: DiskTransport | null;
  port: string | null;
  model: string | null;
  smartModel: string | null;
  sizeBytes: number | null;
  rotational: boolean | null;
  firstSeen: string | null;
  lastSeen: string | null;
  replacedAt: string | null;
  present: boolean;
  daily: StorageHistoryDay[];
}

/** Response of `api.php?action=storage_history`. */
export interface StorageHistoryResponse {
  monitorId: number;
  days: number;
  disks: StorageHistoryDisk[];
  error?: string;
}

/*
 * WAN bottleneck (`api.php?action=wan_bottleneck`). The classifier lives on
 * the server only; the app renders what it is told and never re-derives a
 * verdict, so the two cannot drift.
 */

export type WanVerdictClass = 'none' | 'link_limited' | 'cpu_limited' | 'line_limited' | 'inconclusive';

export type WanConfidence = 'high' | 'medium' | 'low';

/** One direction's verdict. An empty object = nothing to judge yet. */
export interface WanVerdict {
  class?: WanVerdictClass;
  /** `plan_reached`, `wan_port`, `packet_path`, `cpu_not_measured`, `no_plan_known`, ... */
  reason?: string;
  confidence?: WanConfidence | null;
  /** Ids of the tests the verdict stands on. */
  basis?: (string | number)[];
  /** The figures behind the sentence; a missing one was not measured. */
  numbers?: Record<string, number | string | boolean | null | string[]>;
}

export interface WanVerdictPair {
  dl: WanVerdict;
  ul: WanVerdict;
}

export interface WanBottleneckTest {
  measuredAt: string;
  /** Who started the test: Turris OS' own schedule or the agent. */
  startedBy: 'turris' | 'agent' | null;
  server: string | null;
  downloadMbps: number | null;
  uploadMbps: number | null;
  /** Port link rate at the time of the test. */
  linkMbit: number | null;
  verdict: WanVerdictPair;
  /** The agent's sanitized diagnostics, snake_case inside; `cpu_measured: false` for a Turris-started test. */
  diagnostics: Record<string, unknown> | null;
}

export interface WanPlan {
  downMbit: number | null;
  upMbit: number | null;
  /** Share of the plan that counts as delivered; null = the default of 85 %. */
  okPct: number | null;
}

export interface WanBottleneckResponse {
  monitorId: number;
  generatedAt: string;
  canEdit: boolean;
  plan: WanPlan;
  /** The router's own probe; not offered by this release, the keys are kept for the wire format. */
  probe: {
    enabledServer: boolean;
    state: Record<string, unknown> | null;
    waitSince: string | null;
    budgetSpent: boolean;
  };
  verdict: WanVerdictPair;
  tests: WanBottleneckTest[];
  wanPath: WanPath | null;
  linkDev: string | null;
  linkMbit: number | null;
  /** null = the agent did not say (older than 0.1.7). */
  tools: { librespeedCli: boolean | null; ethtool: boolean | null; tc: boolean | null };
  error?: string;
}

/** Body of `POST api.php?action=wan_settings_save`. Rates 1-100000, `plan_ok_pct` 30-100, null = not set. */
export interface WanSettingsSaveRequest {
  monitor_id: number;
  plan_down_mbit: number | null;
  plan_up_mbit: number | null;
  plan_ok_pct: number | null;
  /**
   * Consent to the router's own weekly probe. Part of the wire format, but
   * this release has no probe and no toggle, so the app never sends the key
   * and the server keeps what it has stored.
   */
  probe_enabled?: boolean;
}

export interface WanSettingsSaveResponse {
  ok: boolean;
  plan?: WanPlan;
  probe?: { enabledServer: boolean };
  error?: string;
}

/** One row of `api.php?action=speedtest_history`. */
export interface SpeedtestMeasurement {
  measuredAt: string;
  /** null = the test failed, or the row was damaged by an agent bug before 0.1.7. */
  downloadMbps: number | null;
  uploadMbps: number | null;
  pingMs: number | null;
  jitterMs: number | null;
  server: string | null;
  startedBy: 'turris' | 'agent' | null;
  iface: string | null;
  tool: string | null;
  linkMbit: number | null;
}

/**
 * One row of the outgoing message log (`api.php?action=notification_log`).
 *
 * Written centrally in `send_email()` and in the channel senders, so the row
 * exists for every attempt - including the ones that failed, which are the
 * half worth reading. It never carries the message body; the recipient is
 * personal data and the endpoint is admin-only.
 */
export interface OutgoingMessage {
  id: number;
  /** `null` for messages that are not about one monitor (invitation, digest). */
  monitorId: number | null;
  monitorName: string | null;
  /** `alert`, `daily_reminder`, `digest`, `invitation`, ... - `other` when the sender named none. */
  kind: string;
  /** What the alert said about the monitor ('down', 'up'); empty for the other kinds. */
  status: string | null;
  channel: string;
  recipient: string | null;
  subject: string | null;
  /** E-mail only: `smtp` = a server acknowledged it, `fallback` = handed to the local mailer. */
  method: string | null;
  ok: boolean;
  error: string | null;
  atIso: string;
}

/** Counts over one time window, as the log's `summary` returns them. */
export interface OutgoingMessageWindow {
  total: number;
  failed: number;
  byChannel: { channel: string; total: number; failed: number }[];
}

/** A page of the log plus the values the filters offer. */
export interface OutgoingMessagePage {
  entries: OutgoingMessage[];
  /** Id to pass as `before_id` for the next page; `null` = this was the last one. */
  nextCursor: number | null;
  /** Kinds and channels that really occur in the log - the filters offer no empty option. */
  kinds: string[];
  channels: string[];
  /** Only when asked for with `summary=1`; `null` from a server that does not send it yet. */
  summary: { last24h: OutgoingMessageWindow; last7d: OutgoingMessageWindow } | null;
}
