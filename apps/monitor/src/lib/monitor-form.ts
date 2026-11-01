import type { ApiMonitor } from '@/api/app-api';

/**
 * The monitor editor's form: one object, filled from a monitor or for a new
 * one, and turned into the body of api.php?action=save_monitor.
 *
 * Every field used to be a useState of its own on the Infrastructure page,
 * kept in step by hand in four places: the initial state, the edit handler,
 * the add handler and the save body. Two of them drifted apart: an edit kept
 * the previous dialog's heartbeat (R145), and Add kept the last edited
 * monitor's preset, latency limit, maintenance window, category and type
 * (R173). A field is now one member here and one line in each mapper.
 *
 * Values are kept the way the inputs hold them (strings), and converted only
 * on the way to the API.
 */
export interface MonitorForm {
  /**
   * The stored type, lower-cased. The picker offers eight; a monitor of
   * another type (dns, agent_service...) keeps and saves its own.
   */
  type: string;
  name: string;
  target: string;
  port: string;
  category: string;
  /** Seconds. */
  timeout: string;
  emailNotifications: boolean;
  smsNotifications: boolean;
  notes: string;
  maintenance: boolean;
  maintenanceDescription: string;
  /**
   * The maintenance window (from-to) as <input type="datetime-local"> values.
   * Empty = maintenance holds until switched off; filled = cron applies it
   * only inside the interval (is_in_maintenance).
   */
  maintenanceStart: string;
  maintenanceEnd: string;
  /**
   * Heartbeat - the job reports itself, we do not ask. Values are in minutes,
   * because nobody enters a backup interval in seconds; converted for the API.
   */
  heartbeatIntervalMins: string;
  heartbeatGraceMins: string;
  cpanelStatsUrl: string;
  bodyKeyword: string;
  sqUsername: string;
  /** A new password only; empty keeps the stored one. */
  sqPassword: string;
  /** A password is stored (it never travels back, only this flag). */
  sqPasswordSet: boolean;
  ts3FiletransferPort: string;
  rconPort: string;
  rconPassword: string;
  rconPasswordSet: boolean;
  monitoredProcesses: string;
  /**
   * Preset: a named set of metrics and thresholds. Empty = the monitor keeps
   * its own values (the pre-preset behaviour).
   */
  presetId: string;
  /** An empty threshold = slowdown alerting is off. */
  latencyThresholdMs: string;
  latencyThresholdMins: string;
  cpuThreshold: string;
  ramThreshold: string;
  hddThreshold: string;
  remoteActionsEnabled: boolean;
  /**
   * The router's masked log lines (W1-C3, owner decision 5.7), on by default.
   * null = the server did not say (a new monitor, or an older server): the key
   * is then left out of the save and the stored value stays as it is.
   */
  logLinesEnabled: boolean | null;
  /**
   * On the public status page (W1-G3, owner decision 5.3). `readPublic` is what
   * the server answered for this monitor (its own choice or its type's
   * default), `publicChoice` is set only by a click. Only a click is saved, so
   * a monitor nobody chose for keeps following its type's default.
   */
  readPublic: boolean | null;
  publicChoice: boolean | null;
  allowedActions: string[];
  /** Displayed dashboard sections (Service Profiles). */
  enabledMetrics: string[];
  /**
   * Notification channels for THIS monitor. The notifier has honoured them
   * for a long time ("the router shouts into the ops channel"), but nothing
   * ever wrote them, so they were dead settings. Empty = the global channel.
   */
  discordWebhookUrl: string;
  slackWebhookUrl: string;
  telegramBotToken: string;
  telegramChatId: string;
}

/** The body of api.php?action=save_monitor, in the order it is sent. */
export interface MonitorPayload {
  id: number;
  name: string;
  type: string;
  target: string;
  port: number | null;
  category: string;
  asset_id: number | null;
  timeout: number;
  email_notifications: 0 | 1;
  sms_notifications: 0 | 1;
  notes: string | null;
  maintenance: 0 | 1;
  maintenance_description: string | null;
  maintenance_start: string | null;
  maintenance_end: string | null;
  cpanel_stats_url: string | null;
  body_keyword: string | null;
  heartbeat_interval: number | null;
  heartbeat_grace: number | null;
  sq_username: string | null;
  sq_password: string | null;
  ts3_filetransfer_port: number;
  rcon_port: number;
  rcon_password: string | null;
  monitored_processes: string | null;
  preset_id: number | null;
  latency_threshold_ms: number | null;
  latency_threshold_mins: string;
  cpu_threshold: number;
  ram_threshold: number;
  hdd_threshold: number;
  remote_actions_enabled: 0 | 1;
  log_lines_enabled?: 0 | 1;
  is_public?: 0 | 1;
  allowed_actions: string[];
  enabled_metrics: string[];
  discord_webhook_url: string;
  slack_webhook_url: string;
  telegram_bot_token: string;
  telegram_chat_id: string;
}

const DEFAULT_CATEGORY = 'Webové Portály & API';

/** The Remote Actions a new router allows, once they are switched on. */
const DEFAULT_ALLOWED_ACTIONS = [
  'restart_wan',
  'restart_wireguard',
  'reboot_router',
  'renew_dhcp',
  'restart_service',
  'reconnect_pppoe',
];

/** The dashboard sections a new monitor shows. */
const DEFAULT_SECTIONS = [
  'check_pipeline',
  'response_breakdown',
  'ssl_card',
  'headers',
  'health_score',
  'process',
  'service',
  'clients_chart',
  'quality',
  'ports',
  'license_version',
];

/** The form for a new monitor. Add starts here every time, whatever was edited before. */
export function emptyMonitorForm(): MonitorForm {
  return {
    type: 'web',
    name: '',
    target: '',
    port: '',
    category: DEFAULT_CATEGORY,
    timeout: '5',
    emailNotifications: true,
    smsNotifications: false,
    notes: '',
    maintenance: false,
    maintenanceDescription: '',
    maintenanceStart: '',
    maintenanceEnd: '',
    heartbeatIntervalMins: '60',
    heartbeatGraceMins: '5',
    cpanelStatsUrl: '',
    bodyKeyword: '',
    sqUsername: 'serveradmin',
    sqPassword: '',
    sqPasswordSet: false,
    ts3FiletransferPort: '30033',
    rconPort: '25575',
    rconPassword: '',
    rconPasswordSet: false,
    monitoredProcesses: '',
    presetId: '',
    latencyThresholdMs: '',
    latencyThresholdMins: '5',
    cpuThreshold: '90',
    ramThreshold: '95',
    hddThreshold: '90',
    remoteActionsEnabled: false,
    logLinesEnabled: null,
    readPublic: null,
    publicChoice: null,
    allowedActions: [...DEFAULT_ALLOWED_ACTIONS],
    enabledMetrics: [...DEFAULT_SECTIONS],
    discordWebhookUrl: '',
    slackWebhookUrl: '',
    telegramBotToken: '',
    telegramChatId: '',
  };
}

/** Whole minutes for a stored number of seconds, rounded up; not set = empty. */
function minutesUp(secs: number | null | undefined): string {
  return secs != null ? String(Math.ceil(secs / 60)) : '';
}

/**
 * The form for an existing monitor.
 *
 * The settings beyond name and target are only sent to a logged-in
 * administrator (see api.php action=monitors) - fields are left at the
 * monitor's saved value, not a fixed default, otherwise saving the form would
 * overwrite the real settings (monitored processes, limits, Remote
 * Actions...) with that default.
 */
export function formFromMonitor(mon: ApiMonitor): MonitorForm {
  const typeLower = (mon.type || '').toLowerCase();
  const rawT = mon.target || '';
  let target = rawT;
  let port = mon.port ? String(mon.port) : '';
  // A host:port target is split for the two inputs; a URL keeps its colon.
  if (typeLower !== 'web' && !rawT.startsWith('http') && rawT.includes(':')) {
    const lastColon = rawT.lastIndexOf(':');
    target = rawT.slice(0, lastColon);
    port = mon.port ? String(mon.port) : rawT.slice(lastColon + 1);
  }

  return {
    type: (mon.type || 'web').toLowerCase(),
    name: mon.name,
    target,
    port,
    category: mon.category || DEFAULT_CATEGORY,
    timeout: mon.timeout != null ? String(mon.timeout) : '5',
    emailNotifications: mon.emailNotifications ?? true,
    smsNotifications: mon.smsNotifications ?? false,
    notes: mon.notes ?? '',
    maintenance: mon.maintenance ?? false,
    maintenanceDescription: mon.maintenanceDescription ?? '',
    maintenanceStart: toLocalInput(mon.maintenanceStart),
    maintenanceEnd: toLocalInput(mon.maintenanceEnd),
    // The stored heartbeat, in the form's minutes. The form kept its 60/5 (or
    // the last dialog's values) and saving wrote them over a daily job's 1440.
    // Not set = empty: an interval nobody stored is typed in, not assumed.
    // Seconds that are no whole minute round up: rounding 89 s down to 1 minute
    // saved back a deadline the job missed on every run; up only adds slack.
    heartbeatIntervalMins: minutesUp(mon.heartbeatIntervalSecs),
    heartbeatGraceMins: minutesUp(mon.heartbeatGraceSecs),
    cpanelStatsUrl: mon.cpanelStatsUrl ?? '',
    bodyKeyword: mon.bodyKeyword ?? '',
    sqUsername: mon.sqUsername ?? 'serveradmin',
    sqPassword: '',
    sqPasswordSet: mon.sqPasswordSet ?? false,
    ts3FiletransferPort: mon.ts3FiletransferPort != null ? String(mon.ts3FiletransferPort) : '30033',
    rconPort: mon.rconPort != null ? String(mon.rconPort) : '25575',
    rconPassword: '',
    rconPasswordSet: mon.rconPasswordSet ?? false,
    monitoredProcesses: mon.monitoredProcesses ?? '',
    presetId: mon.presetId != null ? String(mon.presetId) : '',
    latencyThresholdMs: mon.latencyThresholdMs != null ? String(mon.latencyThresholdMs) : '',
    latencyThresholdMins: mon.latencyThresholdMins != null ? String(mon.latencyThresholdMins) : '5',
    cpuThreshold: mon.cpuThreshold != null ? String(mon.cpuThreshold) : '90',
    ramThreshold: mon.ramThreshold != null ? String(mon.ramThreshold) : '95',
    hddThreshold: mon.hddThreshold != null ? String(mon.hddThreshold) : '90',
    remoteActionsEnabled: mon.remoteActionsEnabled ?? false,
    logLinesEnabled: typeof mon.logLinesEnabled === 'boolean' ? mon.logLinesEnabled : null,
    readPublic: typeof mon.isPublic === 'boolean' ? mon.isPublic : null,
    publicChoice: null,
    allowedActions: mon.allowedActions ?? [],
    enabledMetrics: mon.enabledMetrics ?? [],
    discordWebhookUrl: mon.discordWebhookUrl ?? '',
    slackWebhookUrl: mon.slackWebhookUrl ?? '',
    telegramBotToken: mon.telegramBotToken ?? '',
    telegramChatId: mon.telegramChatId ?? '',
  };
}

/**
 * The form after a click on a type in the picker.
 *
 * An edited monitor of another type has no heartbeat, so its fields are
 * empty, and an empty grace saved no tolerance under a placeholder reading 5.
 * Switching to Heartbeat starts where Add does; a click on the type already
 * chosen keeps what is stored.
 */
export function withMonitorType(form: MonitorForm, type: string): MonitorForm {
  if (type === 'heartbeat' && form.type !== 'heartbeat') {
    return {
      ...form,
      type,
      heartbeatIntervalMins: form.heartbeatIntervalMins === '' ? '60' : form.heartbeatIntervalMins,
      heartbeatGraceMins: form.heartbeatGraceMins === '' ? '5' : form.heartbeatGraceMins,
    };
  }
  return { ...form, type };
}

/**
 * The save_monitor body. `id` null is a new monitor (sent as 0); `assetId`
 * is the edited monitor's device (null gives a new one a device of its own).
 */
export function formToPayload(form: MonitorForm, id: number | null, assetId: number | null): MonitorPayload {
  const heartbeat = form.type === 'heartbeat';
  return {
    id: id ?? 0,
    name: form.name,
    type: form.type,
    target: form.target,
    port: form.port ? parseInt(form.port, 10) : null,
    category: form.category,
    asset_id: assetId,
    timeout: parseInt(form.timeout, 10) || 5,
    email_notifications: form.emailNotifications ? 1 : 0,
    sms_notifications: form.smsNotifications ? 1 : 0,
    notes: form.notes || null,
    maintenance: form.maintenance ? 1 : 0,
    maintenance_description: form.maintenanceDescription || null,
    maintenance_start: fromLocalInput(form.maintenanceStart),
    maintenance_end: fromLocalInput(form.maintenanceEnd),
    cpanel_stats_url: form.cpanelStatsUrl || null,
    body_keyword: form.bodyKeyword || null,
    // The form works in minutes, the API in seconds. Other types send
    // null, so saving does not smuggle an interval where it does not belong.
    // An empty grace stays null (checked exactly on the interval).
    heartbeat_interval: heartbeat ? (parseInt(form.heartbeatIntervalMins, 10) || 0) * 60 : null,
    heartbeat_grace:
      heartbeat && form.heartbeatGraceMins !== '' ? (parseInt(form.heartbeatGraceMins, 10) || 0) * 60 : null,
    sq_username: form.sqUsername || null,
    sq_password: form.sqPassword || null,
    ts3_filetransfer_port: form.ts3FiletransferPort ? parseInt(form.ts3FiletransferPort, 10) : 30033,
    rcon_port: form.rconPort ? parseInt(form.rconPort, 10) : 25575,
    rcon_password: form.rconPassword || null,
    monitored_processes: form.monitoredProcesses || null,
    preset_id: form.presetId === '' ? null : parseInt(form.presetId, 10),
    latency_threshold_ms: form.latencyThresholdMs === '' ? null : parseInt(form.latencyThresholdMs, 10),
    latency_threshold_mins: form.latencyThresholdMins,
    cpu_threshold: parseInt(form.cpuThreshold, 10) || 90,
    ram_threshold: parseInt(form.ramThreshold, 10) || 95,
    hdd_threshold: parseInt(form.hddThreshold, 10) || 90,
    remote_actions_enabled: form.remoteActionsEnabled ? 1 : 0,
    // Only a value somebody saw is sent back: an unknown one must not
    // switch the lines on again by being saved as the default.
    ...(form.type === 'openwrt' && form.logLinesEnabled !== null
      ? { log_lines_enabled: form.logLinesEnabled ? (1 as const) : (0 as const) }
      : {}),
    ...(form.publicChoice !== null ? { is_public: form.publicChoice ? (1 as const) : (0 as const) } : {}),
    allowed_actions: form.allowedActions,
    enabled_metrics: form.enabledMetrics,
    // Per-monitor channel overrides. Empty clears the override and the
    // monitor falls back to the global channel.
    discord_webhook_url: form.discordWebhookUrl,
    slack_webhook_url: form.slackWebhookUrl,
    telegram_bot_token: form.telegramBotToken,
    telegram_chat_id: form.telegramChatId,
  };
}

/**
 * MySQL datetime ("2026-08-12 02:00:00") -> the value for <input type="datetime-local">.
 *
 * Deliberately not parsed via Date(): it would read a zoneless string as local
 * time in one browser and as UTC in another, shifting maintenance by hours.
 * The format is fixed, so a trim and a space swap suffice.
 */
export function toLocalInput(value?: string | null): string {
  if (!value) return '';
  const match = String(value).match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
  return match ? `${match[1]}T${match[2]}` : '';
}

/** The other direction; an empty field means "window not set" (null). */
export function fromLocalInput(value: string): string | null {
  if (!value) return null;
  const match = value.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
  return match ? `${match[1]} ${match[2]}:00` : null;
}
