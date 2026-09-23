import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
  isProbeMonitor,
  isPublicByDefault,
  isTeamSpeakMonitor,
  monitorTypeLabel,
  monitorTypeProfile,
  normalizeMonitorType,
} from './monitor-type';

const t = (key: string, params?: Record<string, string | number> | string, fallback?: string) =>
  typeof params === 'string' ? params : (fallback ?? key);

describe('normalizeMonitorType', () => {
  it('accepts the stored value in any casing', () => {
    expect(normalizeMonitorType('AGENT_SERVICE')).toBe('agent_service');
    expect(normalizeMonitorType('  OpenWrt ')).toBe('openwrt');
    expect(normalizeMonitorType(null)).toBe('');
  });
});

describe('monitorTypeProfile', () => {
  it('gives an agent_service no latency, no disk and no sensor', () => {
    // bk_apply_agent_service_result() logs response_time NULL on every row,
    // and a watched process has neither a filesystem nor a thermometer.
    const p = monitorTypeProfile('agent_service');
    expect(p.latency).toBe(false);
    expect(p.disk).toBe(false);
    expect(p.temperature).toBe(false);
  });

  it('gives an agent_service CPU and memory of the process, in megabytes', () => {
    const p = monitorTypeProfile('agent_service');
    expect(p.cpu).toBe(true);
    expect(p.ram).toBe('mb');
  });

  it('points an agent_service at its parent for processes and has no history', () => {
    expect(monitorTypeProfile('AGENT_SERVICE').processes).toBe('parent');
    expect(monitorTypeProfile('agent_service').timeSeries).toBe(false);
  });

  it('gives a web monitor a latency and nothing of a machine', () => {
    const p = monitorTypeProfile('web');
    expect(p.latency).toBe(true);
    expect([p.cpu, p.ram, p.disk, p.temperature]).toEqual([false, false, false, false]);
    expect(p.processes).toBe('none');
    expect(p.timeSeries).toBe(true);
  });

  it('treats port, teamspeak, minecraft and discord as the same network probe', () => {
    for (const type of ['port', 'teamspeak', 'minecraft', 'discord', 'dns']) {
      expect(monitorTypeProfile(type), type).toEqual(monitorTypeProfile('web'));
    }
  });

  it('gives a vps and an openwrt router the full machine', () => {
    for (const type of ['vps', 'openwrt']) {
      const p = monitorTypeProfile(type);
      // Both read their latency from the agent's own wan_latency_ms.
      expect([p.latency, p.cpu, p.ram, p.disk, p.temperature], type).toEqual([true, true, 'percent', true, true]);
      expect(p.processes, type).toBe('own');
    }
  });

  it('leaves a cPanel account without a sensor and without a process list', () => {
    const p = monitorTypeProfile('cpanel');
    expect(p.cpu).toBe(true);
    expect(p.disk).toBe(true);
    expect(p.temperature).toBe(false);
    expect(p.processes).toBe('none');
  });

  it('leaves a heartbeat with no measurement at all', () => {
    const p = monitorTypeProfile('heartbeat');
    expect([p.latency, p.cpu, p.ram, p.disk, p.temperature]).toEqual([false, false, false, false, false]);
    expect(p.timeSeries).toBe(false);
  });

  it('keeps every tile for a type this table does not know', () => {
    // The legacy bk_get_type_card_profile() fell back to the vps profile and
    // would hide, for example, the latency of a future probe type.
    const p = monitorTypeProfile('something_new');
    expect([p.latency, p.cpu, p.ram, p.disk, p.temperature]).toEqual([true, true, 'percent', true, true]);
    expect(p.timeSeries).toBe(true);
  });
});

describe('monitorTypeLabel', () => {
  it('replaces the raw enum of the badge', () => {
    expect(monitorTypeLabel('AGENT_SERVICE', t)).toBe('Služba pod agentem');
  });

  it('has a human label for every type the app draws an icon for', () => {
    // The icon map of the dashboard is the list of types the app claims to know.
    for (const type of [
      'web',
      'http',
      'https',
      'teamspeak',
      'minecraft',
      'discord',
      'openwrt',
      'vps',
      'cpanel',
      'port',
      'dns',
      'agent_service',
      'heartbeat',
    ]) {
      const label = monitorTypeLabel(type, t);
      expect(label, type).not.toBe(type);
      expect(label.toUpperCase(), type).not.toBe(label);
    }
  });

  it('keeps an unknown type as it is stored instead of inventing a word', () => {
    expect(monitorTypeLabel('sftp', t)).toBe('sftp');
    expect(monitorTypeLabel('', t)).toBe('Neznámo');
  });

  it('has both dictionary sides for every label key it can ask for', () => {
    // The labels are passed to t() as literals, but a missing key would only
    // show up as a Czech word inside an English page - exactly what F2 was.
    const dict = readFileSync(join(__dirname, '..', 'context', 'language-context.tsx'), 'utf8');
    for (const key of [
      'montype.web',
      'montype.port',
      'montype.dns',
      'montype.vps',
      'montype.openwrt',
      'montype.cpanel',
      'montype.agent_service',
      'montype.teamspeak',
      'montype.minecraft',
      'montype.discord',
      'montype.heartbeat',
    ]) {
      expect(dict, key).toContain(`'${key}':`);
    }
  });
});

describe('isProbeMonitor (W1-D2)', () => {
  it('sondu pozná podle typu, ne podle jména', () => {
    expect(isProbeMonitor('node')).toBe(true);
    expect(isProbeMonitor('PROBE')).toBe(true);
    expect(isProbeMonitor('web')).toBe(false);
    expect(isProbeMonitor(null)).toBe(false);
  });
});

describe('isTeamSpeakMonitor (W1-D2)', () => {
  it('rozložení TeamSpeaku dostane typ teamspeak nebo agent, který TS server opravdu našel', () => {
    expect(isTeamSpeakMonitor({ type: 'teamspeak' })).toBe(true);
    expect(isTeamSpeakMonitor({ type: 'vps', details: { teamspeak_servers: [{ port: 9987 }] } })).toBe(true);
  });

  it('jméno monitoru ani prázdný seznam serverů o typu nerozhoduje', () => {
    expect(isTeamSpeakMonitor({ type: 'vps', details: { teamspeak_servers: [] } })).toBe(false);
    expect(isTeamSpeakMonitor({ type: 'web', details: null })).toBe(false);
    expect(isTeamSpeakMonitor({ type: 'vps' })).toBe(false);
  });
});

describe('isPublicByDefault (W1-G3)', () => {
  it('servery, router a služby pod agentem jsou bez volby vlastníka skryté, weby a herní služby veřejné', () => {
    for (const type of ['vps', 'openwrt', 'agent_service', 'OpenWrt ']) expect(isPublicByDefault(type)).toBe(false);
    for (const type of ['web', 'teamspeak', 'minecraft', 'discord', 'port', 'heartbeat', null]) {
      expect(isPublicByDefault(type)).toBe(true);
    }
  });

  it('seznam skrytých typů je stejný jako BK_PRIVATE_BY_DEFAULT_TYPES na serveru', () => {
    const php = readFileSync(join(__dirname, '../../../status/functions.php'), 'utf8');
    const m = php.match(/const BK_PRIVATE_BY_DEFAULT_TYPES = \[([^\]]*)\];/);
    expect(m).not.toBeNull();
    const server = [...m![1].matchAll(/'([a-z_]+)'/g)].map((x) => x[1]).sort();
    const hidden = ['vps', 'openwrt', 'agent_service', 'web', 'teamspeak', 'minecraft', 'port', 'heartbeat', 'discord']
      .filter((type) => !isPublicByDefault(type))
      .sort();
    expect(hidden).toEqual(server);
  });
});
