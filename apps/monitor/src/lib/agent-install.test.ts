import { describe, expect, it } from 'vitest';
import {
  AGENT_KEY_PLACEHOLDER,
  agentInstallSteps,
  genericInstallTarget,
  type AgentInstallTarget,
} from './agent-install';

const t = (_key: string, params?: Record<string, string | number> | string, fallback?: string) =>
  typeof params === 'string' ? params : (fallback ?? _key);

const target: AgentInstallTarget = {
  apiUrl: 'https://example.test/status/agent_api.php',
  agentKey: 'abc123',
  files: {
    openwrt: 'https://example.test/status/agent_openwrt.sh',
    shell: 'https://example.test/status/agent.sh',
    python: 'https://example.test/status/agent.py',
    windows: 'https://example.test/status/agent.ps1',
    docker: 'https://example.test/status/docker-compose.agent.yml',
  },
};

const all = (platform: Parameters<typeof agentInstallSteps>[0]) =>
  agentInstallSteps(platform, target, t)
    .map((s) => s.command)
    .join('\n');

describe('agentInstallSteps', () => {
  it('puts the OpenWrt config next to the script, with the key and the API address', () => {
    const steps = agentInstallSteps('openwrt', target, t);
    const download = steps.find((s) => s.id === 'download')!.command;
    const config = steps.find((s) => s.id === 'config')!.command;
    expect(download).toContain('-O /usr/bin/agent_openwrt.sh');
    expect(config).toContain('/usr/bin/agent_openwrt.cfg');
    expect(config).toContain('AGENT_KEY="abc123"');
    expect(config).toContain('API_URL="https://example.test/status/agent_api.php"');
    expect(steps.map((s) => s.id)).toEqual(['download', 'config', 'test', 'schedule', 'schedule_check', 'wifi6e']);
  });

  it('keeps agent.cfg in the same folder as agent.sh and agent.py', () => {
    for (const platform of ['shell', 'python'] as const) {
      const text = all(platform);
      expect(text).toContain('/opt/bk-agent/agent.cfg');
      expect(text).toContain(`/opt/bk-agent/${platform === 'shell' ? 'agent.sh' : 'agent.py'}`);
      expect(text).toContain('--verbose');
    }
  });

  it('schedules through crontab, because Turris runs cronie and ignores /etc/crontabs', () => {
    const schedule = agentInstallSteps('openwrt', target, t).find((s) => s.id === 'schedule')!.command;
    expect(schedule).toContain('| crontab -');
    // cronie backs the old crontab up into $HOME/.cache/crontab and creates that
    // directory with one non-recursive mkdir; on a Turris /root/.cache is absent.
    expect(schedule).toContain('mkdir -p "${HOME:-/root}/.cache/crontab"');
    expect(schedule).toContain('crontab -l');
    // Repeating the step must not schedule the agent twice.
    expect(schedule).toContain('grep -v agent_openwrt.sh');
    expect(schedule).not.toContain('/etc/crontabs');
  });

  it('naplánování nezanechá prázdný crontab, když žádný ještě neexistuje (set -e)', () => {
    // Under `set -e` a failing `crontab -l` (no crontab yet) stopped the subshell
    // before the echo, and `| crontab -` then installed an EMPTY crontab.
    for (const platform of ['openwrt', 'shell', 'python'] as const) {
      const schedule = agentInstallSteps(platform, target, t).find((s) => s.id === 'schedule')!.command;
      expect(schedule).toMatch(/crontab -l 2>\/dev\/null( \| grep -v agent_openwrt\.sh)? \|\| true; echo /);
    }
  });

  it('uses no flag or image the agents do not have', () => {
    for (const platform of ['openwrt', 'shell', 'python', 'windows', 'docker'] as const) {
      const text = all(platform);
      expect(text).not.toMatch(/--server|--key=|bloodkings\/agent|\| iex|\| bash/);
    }
  });

  it('quotes the Windows config lines so a quote in a value cannot break them', () => {
    const steps = agentInstallSteps('windows', { ...target, agentKey: "o'key" }, t);
    expect(steps.find((s) => s.id === 'config')!.command).toContain("'AGENT_KEY=\"o''key\"'");
  });

  it('fills both values into the Docker compose file', () => {
    const text = all('docker');
    expect(text).toContain('STATUS_API_URL: "https://example.test/status/agent_api.php"');
    expect(text).toContain('STATUS_AGENT_KEY: "abc123"');
  });
});

describe('genericInstallTarget', () => {
  it('builds the addresses under /status and marks the key as missing', () => {
    const generic = genericInstallTarget('https://bloodkings.eu/');
    expect(generic.apiUrl).toBe('https://bloodkings.eu/status/agent_api.php');
    expect(generic.files.openwrt).toBe('https://bloodkings.eu/status/agent_openwrt.sh');
    expect(generic.agentKey).toBe(AGENT_KEY_PLACEHOLDER);
  });
});
