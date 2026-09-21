/**
 * What a monitor TYPE can ever report, and how a human calls that type.
 *
 * The asset detail used to build the same page for everything: five hero
 * tiles, a chart frame and a process card, whatever the monitor was. On an
 * `agent_service` four of the five tiles said "—", the chart frame said the
 * database is empty and the process card said no agent is connected - while
 * the service runs UNDER an agent. Two thirds of the page claimed "nothing
 * here" about values that type never measures in the first place.
 *
 * The rule the page follows: a tile or card is rendered when the type CAN
 * report the value (then an honest "—" until it arrives) or when a value is
 * actually there; a value the type can never produce gets no tile at all.
 * Never a zero - the honest-data rule holds either way.
 *
 * Prior art: `bk_get_type_card_profile()` (apps/status/functions.php) gave the
 * legacy PHP page a per-type card list. It falls back to the `vps` profile for
 * an unknown type, which would HIDE tiles of a type this table does not know
 * yet; here an unknown type is permissive instead - it keeps everything.
 *
 * Evidence for the "never" answers, from the server:
 * - `agent_service`: `bk_apply_agent_service_result()` writes `response_time`
 *   NULL on every log row, and no `vps_metrics` row is ever keyed to it.
 * - `heartbeat`: cron.php writes NULL on purpose ("Heartbeats measure no
 *   response time - at best one could measure signal delay").
 * - `vps` / `openwrt`: agent_api.php stores the agent's own `wan_latency_ms`,
 *   so both CAN report a latency even though nothing pings them.
 */
type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

/** Where this monitor's process ranking is collected. */
export type ProcessSource = 'own' | 'parent' | 'none';

/** How the RAM number of this type is expressed. */
export type RamUnit = 'percent' | 'mb';

export interface MonitorTypeProfile {
  /** The type measures a response time of its own. */
  latency: boolean;
  /** Share of a machine's CPU (or of a host's CPU, for one process). */
  cpu: boolean;
  /** false = the type has no memory reading at all. */
  ram: false | RamUnit;
  /** Filesystem usage of the machine. */
  disk: boolean;
  /** A board or SoC temperature sensor. */
  temperature: boolean;
  /** Whose process ranking answers "what is busy here". */
  processes: ProcessSource;
  /** The type stores a metric history, so an empty chart frame means "no data yet". */
  timeSeries: boolean;
}

/** Everything on: what an unknown type may report is not this table's to decide. */
const UNKNOWN: MonitorTypeProfile = {
  latency: true,
  cpu: true,
  ram: 'percent',
  disk: true,
  temperature: true,
  processes: 'own',
  timeSeries: true,
};

/** A check made over the network from the hosting: latency and nothing else. */
const PROBE: MonitorTypeProfile = {
  latency: true,
  cpu: false,
  ram: false,
  disk: false,
  temperature: false,
  processes: 'none',
  timeSeries: true,
};

/** A whole machine an agent reports about. */
const MACHINE: MonitorTypeProfile = {
  latency: true,
  cpu: true,
  ram: 'percent',
  disk: true,
  temperature: true,
  processes: 'own',
  timeSeries: true,
};

const PROFILES: Record<string, MonitorTypeProfile> = {
  web: PROBE,
  http: PROBE,
  https: PROBE,
  port: PROBE,
  dns: PROBE,
  teamspeak: PROBE,
  minecraft: PROBE,
  discord: PROBE,
  vps: MACHINE,
  openwrt: MACHINE,
  // A shared hosting account: the panel reports load, memory and quota, but
  // nothing pings the box and no process list ever arrives.
  cpanel: { ...MACHINE, temperature: false, processes: 'none' },
  // One process watched by the agent of its server. Its CPU and memory are the
  // process's own (megabytes, not a share of the machine) and come from the
  // parent's rankings; it has no disk, no sensor and no latency of its own.
  agent_service: {
    latency: false,
    cpu: true,
    ram: 'mb',
    disk: false,
    temperature: false,
    processes: 'parent',
    timeSeries: false,
  },
  // A job that reports itself. There is a signal and a deadline, no numbers.
  heartbeat: {
    latency: false,
    cpu: false,
    ram: false,
    disk: false,
    temperature: false,
    processes: 'none',
    timeSeries: false,
  },
};

/** The stored type, lower-cased and trimmed; '' for a monitor without one. */
export function normalizeMonitorType(type: string | null | undefined): string {
  return (type ?? '').trim().toLowerCase();
}

/** What this type can ever report. An unknown type keeps every tile. */
export function monitorTypeProfile(type: string | null | undefined): MonitorTypeProfile {
  return PROFILES[normalizeMonitorType(type)] ?? UNKNOWN;
}

/**
 * The name a human uses for a monitor type.
 *
 * The badge on the asset detail printed the raw enum - "Typ: AGENT_SERVICE" -
 * and the parameter list repeated it. The labels follow the admin's own type
 * picker (apps/status/admin.php "Typ monitoringu") so both pages call the same
 * thing by the same name. An unknown type keeps its stored value: inventing a
 * nicer word for something this build does not know would be a claim.
 */
export function monitorTypeLabel(type: string | null | undefined, t: TranslateFn): string {
  switch (normalizeMonitorType(type)) {
    case 'web':
    case 'http':
    case 'https':
      return t('montype.web', 'Web (HTTP/S)');
    case 'port':
      return t('montype.port', 'TCP port');
    case 'dns':
      return t('montype.dns', 'DNS dotaz');
    case 'vps':
      return t('montype.vps', 'Server s agentem');
    case 'openwrt':
      return t('montype.openwrt', 'OpenWrt router');
    case 'cpanel':
      return t('montype.cpanel', 'cPanel hosting');
    case 'agent_service':
      return t('montype.agent_service', 'Služba pod agentem');
    case 'teamspeak':
      return t('montype.teamspeak', 'TeamSpeak server');
    case 'minecraft':
      return t('montype.minecraft', 'Minecraft server');
    case 'discord':
      return t('montype.discord', 'Discord server');
    case 'heartbeat':
      return t('montype.heartbeat', 'Heartbeat (úloha se hlásí)');
    default:
      return (type ?? '').trim() || t('common.unknown', 'Neznámo');
  }
}
