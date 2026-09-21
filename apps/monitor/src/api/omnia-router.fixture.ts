import type {
  AgentTools,
  RouterRecommendationsResponse,
  SpeedtestMeasurement,
  StorageDisk,
  StorageHistoryResponse,
  WanBottleneckResponse,
  WanPath,
  WifiRadio,
} from './types';

/**
 * Test data: the user's Turris Omnia as agent 0.1.7 reports it and the server
 * stores it in `last_details` (the full example of the router health
 * contract, section 2.5, plus what the sanitizer derives: the radio profile
 * and the disk key). Imported by tests only.
 *
 * Typed against the API shapes on purpose: when the contract's example stops
 * fitting `WifiRadio` or `StorageDisk`, tsc says so before any test runs.
 *
 * No address, MAC, BSSID, serial number or WWN - the real payload has none
 * either, and a fixture must not be the place where one first appears.
 */
const radio5g: WifiRadio = {
  radio: 'phy0-ap0',
  ssid: 'Domov 5 GHz',
  mode: 'ap',
  band: '5GHz',
  frequency_mhz: 5180,
  channel: 36,
  htmode: 'HE80',
  generation: 6,
  width_mhz: 80,
  htmodes_supported: ['HT20', 'HT40', 'VHT20', 'VHT40', 'VHT80', 'VHT160', 'HE20', 'HE40', 'HE80', 'HE160'],
  supported_generation: 6,
  supported_width_mhz: 160,
  phy_has_6ghz: false,
  phy: 'phy0',
  encryption: 'wpa2_wpa3',
  encryption_enterprise: false,
  tx_power: 23,
  noise: -92,
  clients: 4,
  signal_median: -54,
  signal_min: -75,
  snr_min: 17,
  clients_weak: 1,
  weakest_gen: 4,
  bitrate_tx_avg_mbps: 736.8,
  clients_gen: { source: 'hostapd_cli', legacy: 0, wifi4: 1, wifi5: 1, wifi6: 2, wifi7: 0 },
  clients_caps_known: 4,
  clients_6ghz_capable: 2,
  clients_opclass_known: 2,
  clients_5ghz_capable: null,
  clients_akm_known: 4,
  clients_wpa2: 0,
  clients_wpa3: 4,
  clients_8021x: 0,
  busy_pct: 3.5,
  busy_other_pct: 1.2,
  busy_state: 'measured',
};

/** The same radio on the first run after boot: no survey delta yet. */
const radio5gFirstRun: WifiRadio = { ...radio5g, busy_pct: null, busy_other_pct: null, busy_state: 'warming_up' };

const diskSda: StorageDisk = {
  // sha1('sata|ata1|KINGSTON SUV500M|120034123776'), first 16 characters - the server's bk_disk_key().
  key: 'b8791c9c63a644ef',
  name: 'sda',
  transport: 'sata',
  port: 'ata1',
  model: 'KINGSTON SUV500M',
  size_bytes: 120034123776,
  rotational: false,
  removable: false,
  partitions: [{ name: 'sda1', size_bytes: 120033075200, mount: '/', fstype: 'btrfs', used_pct: 19 }],
  emmc: null,
  smart: {
    state: 'ok',
    checked_at: 1789553203,
    exit_bits: 0,
    passed: true,
    in_drivedb: true,
    protocol: 'ATA',
    model: 'KINGSTON SUV500MS120G',
    rotation_rpm: 0,
    temperature_c: 67,
    power_on_hours: 24750,
    power_cycles: 230,
    unsafe_shutdowns: 227,
    reallocated_sectors: 0,
    pending_sectors: 0,
    offline_uncorrectable: null,
    reported_uncorrect: 0,
    crc_errors: 0,
    runtime_bad_blocks: 3,
    media_errors: null,
    critical_warning: null,
    available_spare_pct: null,
    wear_pct: 0,
    wear_source: 'attr231',
    written_bytes: 450971566080,
    written_source: 'attr241',
    error_log_count: 0,
    selftest_count: 0,
  },
};

/** The first SMART refresh has not finished yet: a state, and every value null. */
const diskSdaPending: StorageDisk = {
  ...diskSda,
  smart: { state: 'pending', checked_at: null, passed: null, temperature_c: null, wear_pct: null, written_bytes: null },
};

/**
 * What this router really has: `librespeed-cli` is installed and its autostart
 * is on, while `ethtool` and `tc` are not installed at all - which is why the
 * ring counter and the SQM drops stay null here (INDEX section 6, R7).
 */
const agentTools: AgentTools = {
  smartctl: true,
  smart_drivedb: true,
  hostapd_cli: true,
  iw: true,
  librespeed_cli: true,
  ethtool: false,
  tc: false,
  pkg_manager: 'opkg',
  smart_probe_age_s: 97,
  smart_probe_running_s: null,
};

/**
 * PPPoE over a VLAN on the SFP cage, resolved down to the physical port.
 * `lan_port_cap_mbit` is 1000 since capture C5: lan0-lan4 sit behind the one
 * conduit eth1, which negotiates 1000F. The null case stays real - a router
 * with no DSA, or one whose `ubus` says nothing, sends all three as null - and
 * the card's test drives it from here.
 * `wan_rx_ring_drops` is null, not 0: this router has no `ethtool`, so the
 * ring counter is unknown forever (INDEX section 6, R7).
 */
const wanPath: WanPath = {
  checked_at: 1789890000,
  flow_offloading: true,
  flow_offloading_hw: false,
  flowtable_active: true,
  packet_steering: 'unset',
  packet_steering_active: true,
  wan_rps_mask: '1',
  wan_threaded_napi: false,
  wan_rx_ring_drops: null,
  sqm: [],
  lan_port_max_mbit: 1000,
  lan_port_cap_mbit: 1000,
  lan_conduits: [{ dev: 'eth1', mbit: 1000 }],
};

/** `last_details` of the router, the keys of agent 0.1.7 only. */
const details = {
  agent_type: 'openwrt',
  version: '0.1.7',
  wifi_clients_count: 4,
  wifi_radios: [radio5g],
  storage_disks: [diskSda],
  agent_tools: agentTools,
  cpu_cores: 2,
  cpu_core_max_pct: 98.2,
  cpu_core_max_index: 0,
  cpu_core_max_softirq_pct: 95.5,
  wan_link_dev: 'eth2',
  wan_link_mbit: 2500,
  wan_carrier_down_count: 3,
  wan_rx_mbps: 412.7,
  wan_tx_mbps: 18.3,
  wan_path: wanPath,
  dns_resolver_ok: true,
  agent_run_ms: 4200,
  runs_skipped_lock: 0,
  runs_skipped_post: 0,
};

/** `router_recommendations` for this router: the warm disk, a page-only WAN note and one muted item. */
const recommendations: RouterRecommendationsResponse = {
  monitorId: 6,
  applicable: true,
  reason: null,
  generatedAt: '2026-09-21T09:42:00+02:00',
  window: {
    from: '2026-09-14',
    to: '2026-09-20',
    previousFrom: '2026-09-07',
    previousTo: '2026-09-13',
    daysWithData: 7,
  },
  canMute: true,
  // Derived by the server from `agent_tools` (bk_rec_missing_packages): this
  // router has neither `ethtool` nor `tc`.
  missingPackages: ['ethtool', 'tc-tiny'],
  items: [
    {
      key: 'disk_temp_warm:d:b8791c9c63a644ef',
      id: 'disk_temp_warm',
      area: 'storage',
      severity: 'warning',
      title: 'Disk sda je trvale teplý',
      measured: 'Disk KINGSTON SUV500MS120G (sda) měl za posledních 7 dní průměrně 67 °C (limit 70 °C).',
      action: 'Zlepšete chlazení routeru nebo disk přesuňte dál od zdroje tepla.',
      subject: { kind: 'disk', diskKey: 'b8791c9c63a644ef', name: 'sda' },
      params: { avg_c: 67, max_c: 68, limit_c: 70 },
      command: null,
      openSince: '2026-09-21',
      wasMuted: false,
    },
    {
      key: 'wan_cpu_packet_path:dl',
      id: 'wan_cpu_packet_path',
      area: 'wan',
      severity: 'info',
      title: 'Měření z routeru brzdí jeho procesor',
      measured: null,
      action: null,
      subject: { kind: 'wan', direction: 'dl' },
      params: {},
      command: null,
      openSince: null,
      pageOnly: true,
    },
  ],
  muted: [
    {
      key: 'wifi_6ghz_unserved',
      id: 'wifi_6ghz_unserved',
      area: 'wifi',
      severity: 'info',
      title: 'Klienti s podporou 6 GHz nemají 6GHz síť',
      measured: null,
      action: null,
      subject: { kind: 'router' },
      params: {},
      command: null,
      openSince: '2026-09-21',
      active: true,
      mute: {
        at: '2026-09-22T18:03:00+02:00',
        by: 'pepe',
        reason: '6 GHz obsluhuje jiný přístupový bod',
        severity: 'info',
      },
    },
  ],
};

/** `storage_history`: one day of the disk, with the value the disk does not report left null. */
const storageHistory: StorageHistoryResponse = {
  monitorId: 6,
  days: 90,
  disks: [
    {
      key: 'b8791c9c63a644ef',
      name: 'sda',
      transport: 'sata',
      port: 'ata1',
      model: 'KINGSTON SUV500M',
      smartModel: 'KINGSTON SUV500MS120G',
      sizeBytes: 120034123776,
      rotational: false,
      firstSeen: '2026-09-21T10:02:00+02:00',
      lastSeen: '2026-09-21T11:02:00+02:00',
      replacedAt: null,
      present: true,
      daily: [
        {
          day: '2026-09-21',
          samples: 2,
          smartPassed: true,
          tempMin: 66,
          tempAvg: 66.5,
          tempMax: 67,
          powerOnHours: 24751,
          powerCycles: 230,
          unsafeShutdowns: 227,
          reallocated: 0,
          pending: 0,
          offlineUncorrectable: null,
          reportedUncorrect: 0,
          crcErrors: 0,
          runtimeBadBlocks: 3,
          mediaErrors: null,
          errorLogCount: 0,
          wearPct: 0,
          emmcLife: null,
          writtenBytes: 450971566080,
          hostWrittenBytes: 1073741824,
          hostWrittenPartial: false,
        },
      ],
    },
  ],
};
/**
 * `wan_bottleneck` as this release can answer it: a 2000/1000 plan, tests
 * started by Turris OS only. The upload reached the plan; the download did
 * not, and without CPU samples nobody can say whether the line or the router
 * held it back - so the verdict is "inconclusive", not "the line is slow".
 */
const wanBottleneck: WanBottleneckResponse = {
  monitorId: 6,
  generatedAt: '2026-09-21T09:42:00+02:00',
  canEdit: true,
  plan: { downMbit: 2000, upMbit: 1000, okPct: null },
  probe: { enabledServer: false, state: null, waitSince: null, budgetSpent: false },
  verdict: {
    dl: {
      class: 'inconclusive',
      reason: 'cpu_not_measured',
      confidence: null,
      basis: [],
      numbers: { speed_mbps: 1350.12, plan_mbit: 2000, link_mbit: 2500 },
    },
    ul: {
      class: 'none',
      reason: 'plan_reached',
      confidence: null,
      basis: [],
      numbers: { speed_mbps: 902.4, plan_mbit: 1000, link_mbit: 2500 },
    },
  },
  tests: [
    {
      measuredAt: '2026-09-20T05:23:41+02:00',
      startedBy: 'turris',
      server: 'Prague, Czech Republic (CESNET)',
      downloadMbps: 1350.12,
      uploadMbps: 902.4,
      linkMbit: 2500,
      verdict: {
        dl: { class: 'inconclusive', reason: 'cpu_not_measured', confidence: 'low', basis: [], numbers: {} },
        ul: { class: 'none', reason: 'plan_reached', confidence: 'low', basis: [], numbers: {} },
      },
      diagnostics: { v: 1, cpu_measured: false },
    },
  ],
  wanPath,
  linkDev: 'eth2',
  linkMbit: 2500,
  tools: { librespeedCli: true, ethtool: false, tc: false },
};

/** The same router before anybody entered the plan - the state of every router on release day. */
const wanBottleneckNoPlan: WanBottleneckResponse = {
  ...wanBottleneck,
  plan: { downMbit: null, upMbit: null, okPct: null },
  verdict: {
    dl: { class: 'inconclusive', reason: 'no_plan_known', confidence: null, basis: [], numbers: {} },
    ul: { class: 'inconclusive', reason: 'no_plan_known', confidence: null, basis: [], numbers: {} },
  },
};

/**
 * `speedtest_history`: a healthy row, and one that an agent before 0.1.7
 * damaged (results above 1000 Mbit/s were divided away) - its speeds are null,
 * not 0.01, and the fields the old agent never sent are null too.
 */
const speedtestHistory: SpeedtestMeasurement[] = [
  {
    measuredAt: '2026-09-20T05:23:41+02:00',
    downloadMbps: 1350.12,
    uploadMbps: 902.4,
    pingMs: 2.1,
    jitterMs: 0.3,
    server: 'Prague, Czech Republic (CESNET)',
    startedBy: 'turris',
    iface: null,
    tool: null,
    linkMbit: 2500,
  },
  {
    measuredAt: '2026-09-13T05:20:12+02:00',
    downloadMbps: null,
    uploadMbps: null,
    pingMs: 2.3,
    jitterMs: 0.4,
    server: null,
    startedBy: 'turris',
    iface: null,
    tool: null,
    linkMbit: null,
  },
];
export default {
  radio5g,
  radio5gFirstRun,
  diskSda,
  diskSdaPending,
  agentTools,
  wanPath,
  details,
  recommendations,
  storageHistory,
  wanBottleneck,
  wanBottleneckNoPlan,
  speedtestHistory,
};
