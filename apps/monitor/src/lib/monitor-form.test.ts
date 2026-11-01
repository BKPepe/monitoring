import { describe, expect, it } from 'vitest';
import type { ApiMonitor } from '@/api/app-api';
import {
  emptyMonitorForm,
  formFromMonitor,
  formToPayload,
  fromLocalInput,
  toLocalInput,
  withMonitorType,
  type MonitorForm,
} from './monitor-form';

/** A monitor as action=monitors sends it to an administrator. */
function monitor(extra: Partial<ApiMonitor>): ApiMonitor {
  return {
    id: 6,
    name: 'Monitor',
    type: 'web',
    target: '',
    status: 'up',
    category: null,
    assetId: 42,
    assetName: 'Monitor',
    lastCheck: null,
    lastStatusChange: null,
    responseMs: null,
    cpu: null,
    ram: null,
    hdd: null,
    uptimeSeconds: null,
    agentLastSeen: null,
    hostname: null,
    os: null,
    ...extra,
  };
}

/** Every setting changed from its default, so a field that is lost shows. */
const CONFIGURED: Partial<ApiMonitor> = {
  timeout: 12,
  emailNotifications: false,
  smsNotifications: true,
  notes: 'pozn',
  maintenance: true,
  maintenanceDescription: 'kernel',
  maintenanceStart: '2026-08-12 02:00:00',
  maintenanceEnd: '2026-08-12 04:30:00',
  monitoredProcesses: 'nginx,mysql',
  presetId: 3,
  latencyThresholdMs: 250,
  latencyThresholdMins: 10,
  heartbeatIntervalSecs: 86400,
  heartbeatGraceSecs: 600,
  cpuThreshold: 70,
  ramThreshold: 80,
  hddThreshold: 85,
  bodyKeyword: 'Kings',
  cpanelStatsUrl: 'https://example.test/stats',
  sqUsername: 'qadmin',
  sqPasswordSet: true,
  ts3FiletransferPort: 30044,
  rconPort: 25576,
  rconPasswordSet: true,
  enabledMetrics: ['ssl_card', 'ports'],
  discordWebhookUrl: 'https://discord.example.test/w',
  slackWebhookUrl: null,
  telegramBotToken: 'tok',
  telegramChatId: null,
  remoteActionsEnabled: true,
  logLinesEnabled: false,
  isPublic: true,
  allowedActions: ['reboot_router'],
};

/** What saving CONFIGURED back sends, up to the switches the server may omit. */
const CONFIGURED_HEAD = {
  id: 6,
  name: 'Monitor',
  type: 'web',
  target: '',
  port: null,
  category: 'Weby',
  asset_id: 42,
  timeout: 12,
  email_notifications: 0,
  sms_notifications: 1,
  notes: 'pozn',
  maintenance: 1,
  maintenance_description: 'kernel',
  maintenance_start: '2026-08-12 02:00:00',
  maintenance_end: '2026-08-12 04:30:00',
  cpanel_stats_url: 'https://example.test/stats',
  body_keyword: 'Kings',
  heartbeat_interval: null,
  heartbeat_grace: null,
  sq_username: 'qadmin',
  sq_password: null,
  ts3_filetransfer_port: 30044,
  rcon_port: 25576,
  rcon_password: null,
  monitored_processes: 'nginx,mysql',
  preset_id: 3,
  latency_threshold_ms: 250,
  latency_threshold_mins: '10',
  cpu_threshold: 70,
  ram_threshold: 80,
  hdd_threshold: 85,
  remote_actions_enabled: 1,
};

/** And the rest, after log_lines_enabled and is_public when those go out. */
const CONFIGURED_TAIL = {
  allowed_actions: ['reboot_router'],
  enabled_metrics: ['ssl_card', 'ports'],
  discord_webhook_url: 'https://discord.example.test/w',
  slack_webhook_url: '',
  telegram_bot_token: 'tok',
  telegram_chat_id: '',
};

/**
 * The body saving CONFIGURED back sends, in the order formToPayload builds
 * it: `fields` replace values where they stand, `switches` go between
 * remote_actions_enabled and allowed_actions.
 */
function configuredPayload(fields: Record<string, unknown>, switches: Record<string, unknown> = {}) {
  return { ...CONFIGURED_HEAD, ...fields, ...switches, ...CONFIGURED_TAIL };
}

const saveBack = (mon: ApiMonitor) => formToPayload(formFromMonitor(mon), mon.id, mon.assetId);

/** The string, not just the values: the body is the same byte for byte. */
function expectSameBody(actual: object, expected: object) {
  expect(JSON.stringify(actual)).toBe(JSON.stringify(expected));
}

describe('formFromMonitor -> formToPayload: an untouched edit saves back what is stored', () => {
  it('web: every field, in the order the API has always received them', () => {
    const mon = monitor({ ...CONFIGURED, type: 'web', target: 'https://example.test', port: 8443, category: 'Weby' });
    expectSameBody(saveBack(mon), configuredPayload({ target: 'https://example.test', port: 8443 }));
  });

  it.each([
    ['minecraft', 'mc.example.test:25565', 'mc.example.test', 25565],
    ['teamspeak', 'ts.example.test', 'ts.example.test', null],
    ['vps', 'vps.example.test', 'vps.example.test', null],
    ['discord', '123456789', '123456789', null],
    // Types the picker does not offer keep their own and save it back.
    ['port', 'host.example.test:2222', 'host.example.test', 2222],
    ['dns', 'ns.example.test', 'ns.example.test', null],
    ['agent_service', 'nginx', 'nginx', null],
  ])('%s', (type, target, savedTarget, savedPort) => {
    const mon = monitor({ ...CONFIGURED, type, target, category: 'Weby' });
    expectSameBody(saveBack(mon), configuredPayload({ type, target: savedTarget, port: savedPort }));
  });

  it('openwrt: the log-lines switch the server sent goes back', () => {
    const mon = monitor({ ...CONFIGURED, type: 'openwrt', target: '', category: 'Weby' });
    expectSameBody(saveBack(mon), configuredPayload({ type: 'openwrt' }, { log_lines_enabled: 0 }));
  });

  it('heartbeat: interval and grace in seconds, as stored', () => {
    const mon = monitor({ ...CONFIGURED, type: 'heartbeat', target: '', category: 'Weby' });
    expectSameBody(
      saveBack(mon),
      configuredPayload({ type: 'heartbeat', heartbeat_interval: 86400, heartbeat_grace: 600 })
    );
  });

  it('a monitor without the admin settings saves the defaults, not blanks', () => {
    const mon = monitor({ type: 'teamspeak', target: 'ts.example.test' });
    const payload = saveBack(mon);
    expect(payload).toMatchObject({
      category: 'Webové Portály & API',
      timeout: 5,
      email_notifications: 1,
      sms_notifications: 0,
      sq_username: 'serveradmin',
      ts3_filetransfer_port: 30033,
      rcon_port: 25575,
      latency_threshold_ms: null,
      latency_threshold_mins: '5',
      cpu_threshold: 90,
      ram_threshold: 95,
      hdd_threshold: 90,
      allowed_actions: [],
      enabled_metrics: [],
    });
  });
});

describe('formFromMonitor: target and port', () => {
  it('splits host:port and keeps a stored port over the one in the target', () => {
    expect(formFromMonitor(monitor({ type: 'minecraft', target: 'mc.example.test:25565' }))).toMatchObject({
      target: 'mc.example.test',
      port: '25565',
    });
    expect(formFromMonitor(monitor({ type: 'minecraft', target: 'mc.example.test:25565', port: 25570 }))).toMatchObject(
      { target: 'mc.example.test', port: '25570' }
    );
  });

  it('keeps the colon of a URL', () => {
    expect(formFromMonitor(monitor({ type: 'web', target: 'https://example.test:8443/x' })).target).toBe(
      'https://example.test:8443/x'
    );
    expect(formFromMonitor(monitor({ type: 'port', target: 'http://example.test:81' })).target).toBe(
      'http://example.test:81'
    );
  });

  it('lower-cases the type and reads a missing one as web', () => {
    expect(formFromMonitor(monitor({ type: 'WEB' })).type).toBe('web');
    expect(formFromMonitor(monitor({ type: '' })).type).toBe('web');
  });
});

describe('formToPayload: numbers', () => {
  it('a zero limit or timeout falls back to the default, a zero preset or latency does not', () => {
    const form: MonitorForm = {
      ...emptyMonitorForm(),
      timeout: '0',
      cpuThreshold: '0',
      presetId: '0',
      latencyThresholdMs: '0',
    };
    expect(formToPayload(form, null, null)).toMatchObject({
      timeout: 5,
      cpu_threshold: 90,
      preset_id: 0,
      latency_threshold_ms: 0,
    });
  });

  it('a new password is sent, an empty one is not', () => {
    const form = { ...emptyMonitorForm(), sqPassword: 'tajne', rconPassword: '' };
    expect(formToPayload(form, null, null)).toMatchObject({ sq_password: 'tajne', rcon_password: null });
  });
});

describe('heartbeat interval and grace (R145)', () => {
  it('seconds that are no whole minute round up, so the deadline never shrinks', () => {
    const form = formFromMonitor(monitor({ type: 'heartbeat', heartbeatIntervalSecs: 89, heartbeatGraceSecs: 89 }));
    expect(form.heartbeatIntervalMins).toBe('2');
    expect(form.heartbeatGraceMins).toBe('2');
    expect(formToPayload(form, 6, 42)).toMatchObject({ heartbeat_interval: 120, heartbeat_grace: 120 });
  });

  it('a grace nobody set stays empty and is saved as null, not 0', () => {
    const form = formFromMonitor(monitor({ type: 'heartbeat', heartbeatIntervalSecs: 3600, heartbeatGraceSecs: null }));
    expect(form.heartbeatGraceMins).toBe('');
    expect(formToPayload(form, 6, 42).heartbeat_grace).toBeNull();
  });

  it('a monitor of another type has empty fields and sends no interval', () => {
    const form = formFromMonitor(monitor({ type: 'web', target: 'https://example.test' }));
    expect(form.heartbeatIntervalMins).toBe('');
    expect(form.heartbeatGraceMins).toBe('');
    expect(formToPayload(form, 6, 42)).toMatchObject({ heartbeat_interval: null, heartbeat_grace: null });
  });

  it('a new monitor starts at 60/5 minutes', () => {
    const form = withMonitorType(emptyMonitorForm(), 'heartbeat');
    expect(formToPayload(form, null, null)).toMatchObject({ heartbeat_interval: 3600, heartbeat_grace: 300 });
  });

  it('switching an edited monitor to Heartbeat fills the empty fields with 60/5', () => {
    const form = withMonitorType(
      formFromMonitor(monitor({ type: 'web', target: 'https://example.test' })),
      'heartbeat'
    );
    expect(form.heartbeatIntervalMins).toBe('60');
    expect(form.heartbeatGraceMins).toBe('5');
  });

  it('a click on Heartbeat that is already chosen invents no grace', () => {
    const form = formFromMonitor(monitor({ type: 'heartbeat', heartbeatIntervalSecs: 3600, heartbeatGraceSecs: null }));
    expect(withMonitorType(form, 'heartbeat').heartbeatGraceMins).toBe('');
  });

  it('switching to another type keeps what was typed', () => {
    const form = { ...emptyMonitorForm(), heartbeatIntervalMins: '1440' };
    expect(withMonitorType(form, 'web')).toStrictEqual({ ...form, type: 'web' });
  });
});

describe('switches the server may not have sent', () => {
  it('log lines go out only for a router whose value is known', () => {
    const unknown = formFromMonitor(monitor({ type: 'openwrt' }));
    expect('log_lines_enabled' in formToPayload(unknown, 6, 42)).toBe(false);
    const known = formFromMonitor(monitor({ type: 'openwrt', logLinesEnabled: true }));
    expect(formToPayload(known, 6, 42).log_lines_enabled).toBe(1);
    const notRouter = formFromMonitor(monitor({ type: 'vps', logLinesEnabled: true }));
    expect('log_lines_enabled' in formToPayload(notRouter, 6, 42)).toBe(false);
  });

  it('the public switch goes out only after a click', () => {
    const form = formFromMonitor(monitor({ type: 'web', isPublic: true }));
    expect(form.readPublic).toBe(true);
    expect('is_public' in formToPayload(form, 6, 42)).toBe(false);
    expect(formToPayload({ ...form, publicChoice: false }, 6, 42).is_public).toBe(0);
  });

  it('both go out in their place: after remote actions, the public one last', () => {
    const mon = monitor({ ...CONFIGURED, type: 'openwrt', target: '', category: 'Weby' });
    const form = { ...formFromMonitor(mon), publicChoice: false };
    expectSameBody(
      formToPayload(form, mon.id, mon.assetId),
      configuredPayload({ type: 'openwrt' }, { log_lines_enabled: 0, is_public: 0 })
    );
  });
});

describe('emptyMonitorForm (R173)', () => {
  it('a new monitor sends the defaults it always has', () => {
    const form = { ...emptyMonitorForm(), name: 'Nový', target: 'https://example.test' };
    expect(JSON.stringify(formToPayload(form, null, null))).toBe(
      JSON.stringify({
        id: 0,
        name: 'Nový',
        type: 'web',
        target: 'https://example.test',
        port: null,
        category: 'Webové Portály & API',
        asset_id: null,
        timeout: 5,
        email_notifications: 1,
        sms_notifications: 0,
        notes: null,
        maintenance: 0,
        maintenance_description: null,
        maintenance_start: null,
        maintenance_end: null,
        cpanel_stats_url: null,
        body_keyword: null,
        heartbeat_interval: null,
        heartbeat_grace: null,
        sq_username: 'serveradmin',
        sq_password: null,
        ts3_filetransfer_port: 30033,
        rcon_port: 25575,
        rcon_password: null,
        monitored_processes: null,
        preset_id: null,
        latency_threshold_ms: null,
        latency_threshold_mins: '5',
        cpu_threshold: 90,
        ram_threshold: 95,
        hdd_threshold: 90,
        remote_actions_enabled: 0,
        allowed_actions: [
          'restart_wan',
          'restart_wireguard',
          'reboot_router',
          'renew_dhcp',
          'restart_service',
          'reconnect_pppoe',
        ],
        enabled_metrics: [
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
        ],
        discord_webhook_url: '',
        slack_webhook_url: '',
        telegram_bot_token: '',
        telegram_chat_id: '',
      })
    );
  });

  it('every call is a fresh form: a ticked box does not carry over to the next Add', () => {
    const first = emptyMonitorForm();
    first.allowedActions.pop();
    first.enabledMetrics.length = 0;
    expect(emptyMonitorForm().allowedActions).toHaveLength(6);
    expect(emptyMonitorForm().enabledMetrics).toHaveLength(11);
  });
});

describe('maintenance window: time conversion', () => {
  it('MySQL datetime -> the datetime-local value', () => {
    expect(toLocalInput('2026-08-12 02:00:00')).toBe('2026-08-12T02:00');
    expect(toLocalInput('2026-08-12T02:00:00')).toBe('2026-08-12T02:00');
  });

  it('an empty or invalid value makes no nonsense', () => {
    expect(toLocalInput(null)).toBe('');
    expect(toLocalInput('')).toBe('');
    expect(toLocalInput('nesmysl')).toBe('');
  });

  it('back to the MySQL format, empty = null', () => {
    expect(fromLocalInput('2026-08-12T02:00')).toBe('2026-08-12 02:00:00');
    expect(fromLocalInput('')).toBeNull();
  });

  it('the time does not move with the zone (no Date parsing)', () => {
    // With new Date() this string would be read as local time or UTC
    // depending on the browser, shifting maintenance by hours.
    expect(fromLocalInput(toLocalInput('2026-01-15 23:30:00'))).toBe('2026-01-15 23:30:00');
  });
});
