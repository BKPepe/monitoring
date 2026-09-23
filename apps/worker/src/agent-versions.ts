/**
 * Agent versions for the download page.
 *
 * The agents moved out of apps/status into the BKPepe/monitoring-agent
 * repository (the `agents` submodule). The worker kept reading the old
 * apps/status paths, which no longer exist on GitHub, so every version came
 * back "unknown" - and the catch branch handed out an invented 1.7.0/1.3.0.
 * The versions are now read from the published scripts themselves, so what
 * the page shows is what `curl` downloads.
 */
export const AGENT_SOURCE_REPO = 'https://github.com/BKPepe/monitoring-agent';
export const AGENT_SOURCE_BASE = 'https://raw.githubusercontent.com/BKPepe/monitoring-agent/main/vps-agent/';

export const AGENT_FILES = {
  bash: 'agent.sh',
  python: 'agent.py',
  powershell: 'agent.ps1',
  openwrt: 'agent_openwrt.sh',
} as const;

export type AgentKey = keyof typeof AGENT_FILES;
/** null = nobody read it; the site renders a dash, never a guess. */
export type AgentVersions = Record<AgentKey, string | null>;

const AGENT_KEYS = Object.keys(AGENT_FILES) as AgentKey[];

/** Reads `AGENT_VERSION="0.1.3"` (sh, py) or `$AGENT_VERSION = "0.1.0"` (ps1); null when absent. */
export function parseAgentVersion(source: string): string | null {
  const match = source.match(/\$?AGENT_VERSION\s*=\s*["']([0-9][0-9A-Za-z.-]*)["']/);
  return match ? match[1] : null;
}

type Fetcher = (input: string, init?: RequestInit) => Promise<Response>;

async function readOne(fetcher: Fetcher, file: string, token?: string): Promise<string | null> {
  try {
    const res = await fetcher(`${AGENT_SOURCE_BASE}${file}`, {
      headers: {
        'User-Agent': 'BloodKings-Monitoring-Website-Worker',
        ...(token ? { Authorization: `token ${token}` } : {}),
      },
    });
    if (!res.ok) return null;
    return parseAgentVersion(await res.text());
  } catch {
    return null;
  }
}

/**
 * Reads all four scripts in parallel. `missing` lists the ones that could
 * not be read or carry no version line, so the caller can refuse to cache an
 * incomplete answer and say so with its status code.
 */
export async function readAgentVersions(
  fetcher: Fetcher,
  token?: string
): Promise<{ versions: AgentVersions; missing: AgentKey[] }> {
  const read = await Promise.all(AGENT_KEYS.map((key) => readOne(fetcher, AGENT_FILES[key], token)));
  const versions = Object.fromEntries(AGENT_KEYS.map((key, i) => [key, read[i]])) as AgentVersions;
  const missing = AGENT_KEYS.filter((key) => versions[key] === null);
  return { versions, missing };
}

/** Carries what was read, so a partial failure still reports the versions it knows. */
export class AgentVersionsIncomplete extends Error {
  constructor(
    readonly versions: AgentVersions,
    readonly missing: AgentKey[]
  ) {
    super(`Agent version not readable: ${missing.join(', ')}`);
    this.name = 'AgentVersionsIncomplete';
  }
}

export const NO_AGENT_VERSIONS: AgentVersions = { bash: null, python: null, powershell: null, openwrt: null };
