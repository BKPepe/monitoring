import { afterEach, describe, expect, it, vi } from 'vitest';
import worker from './index';
import { AGENT_SOURCE_BASE, parseAgentVersion, readAgentVersions } from './agent-versions';

// The version lines exactly as the scripts in agents/vps-agent spell them.
// Fixtures instead of the submodule: the frontend CI job checks out without it.
const SCRIPTS: Record<string, string> = {
  'agent.sh': '#!/bin/bash\nfi\n\nAGENT_VERSION="0.1.3"\nLOG_FILE="$ScriptPath/agent.log"\n',
  'agent.py': '    except Exception:\n        pass\n\nAGENT_VERSION = "0.1.2"\n',
  'agent.ps1': ')\n$AGENT_VERSION = "0.1.0"\nif ($Help) {\n',
  'agent_openwrt.sh': '#!/bin/sh\nAGENT_VERSION="0.1.8"\n',
};

/** Serves SCRIPTS from the monitoring-agent raw URL; anything else, or a listed file, fails. */
function fakeGitHub(failing: string[] = []) {
  return vi.fn(async (input: string | URL | Request) => {
    const url = String(input instanceof Request ? input.url : input);
    const file = url.startsWith(AGENT_SOURCE_BASE) ? url.slice(AGENT_SOURCE_BASE.length) : null;
    if (file === null || !(file in SCRIPTS) || failing.includes(file)) {
      return new Response('404: Not Found', { status: 404 });
    }
    return new Response(SCRIPTS[file], { status: 200 });
  });
}

async function getAgents(): Promise<Response> {
  return worker.fetch(new Request('https://api.bloodkings.eu/api/agents'), {}, undefined);
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('verze agentů pro stránku ke stažení', () => {
  it('čte verzi z hlavičky sh, py i ps1 skriptu', () => {
    expect(parseAgentVersion(SCRIPTS['agent.sh'])).toBe('0.1.3');
    expect(parseAgentVersion(SCRIPTS['agent.py'])).toBe('0.1.2');
    expect(parseAgentVersion(SCRIPTS['agent.ps1'])).toBe('0.1.0');
  });

  it('bez řádku AGENT_VERSION vrací null, ne odhad', () => {
    expect(parseAgentVersion('#!/bin/sh\necho hello\n')).toBeNull();
    expect(parseAgentVersion('<html>404</html>')).toBeNull();
  });

  it('čte skripty z repozitáře monitoring-agent, ne ze staré cesty apps/status', async () => {
    const fetcher = fakeGitHub();
    const { versions, missing } = await readAgentVersions(fetcher);

    expect(versions).toEqual({ bash: '0.1.3', python: '0.1.2', powershell: '0.1.0', openwrt: '0.1.8' });
    expect(missing).toEqual([]);
    const urls = fetcher.mock.calls.map(([input]) => String(input));
    expect(urls).toHaveLength(4);
    for (const url of urls) {
      expect(url).toMatch(/^https:\/\/raw\.githubusercontent\.com\/BKPepe\/monitoring-agent\/main\/vps-agent\//);
      expect(url).not.toContain('apps/status');
    }
  });

  it('nečitelný skript je null a je uvedený mezi chybějícími', async () => {
    const { versions, missing } = await readAgentVersions(fakeGitHub(['agent.ps1']));
    expect(versions.powershell).toBeNull();
    expect(versions.bash).toBe('0.1.3');
    expect(missing).toEqual(['powershell']);
  });

  it('/api/agents vrací skutečné verze 0.1.x a zdroj', async () => {
    vi.stubGlobal('fetch', fakeGitHub());
    const res = await getAgents();
    const body = (await res.json()) as Record<string, unknown>;

    expect(res.status).toBe(200);
    expect(body).toMatchObject({ bash: '0.1.3', python: '0.1.2', powershell: '0.1.0', openwrt: '0.1.8' });
    expect(body.source).toBe('https://github.com/BKPepe/monitoring-agent');
    expect(typeof body.updatedAt).toBe('string');
  });

  it('/api/agents při výpadku GitHubu vrací 503 a null, žádné vymyšlené 1.7.0', async () => {
    vi.stubGlobal('fetch', fakeGitHub(Object.keys(SCRIPTS)));
    const res = await getAgents();
    const body = (await res.json()) as Record<string, unknown>;

    expect(res.status).toBe(503);
    expect(body).toMatchObject({ bash: null, python: null, powershell: null, openwrt: null, updatedAt: null });
    expect(JSON.stringify(body)).not.toMatch(/1\.7\.0|1\.3\.0|unknown/);
  });

  it('/api/agents při částečném výpadku vrací 503 se známými verzemi a null u chybějící', async () => {
    vi.stubGlobal('fetch', fakeGitHub(['agent_openwrt.sh']));
    const res = await getAgents();
    const body = (await res.json()) as Record<string, unknown>;

    expect(res.status).toBe(503);
    expect(body).toMatchObject({ bash: '0.1.3', openwrt: null });
    expect(String(body.error)).toContain('openwrt');
  });
});
