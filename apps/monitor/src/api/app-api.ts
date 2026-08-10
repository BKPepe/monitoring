import { STATUS_API } from './http-source';

/**
 * Klient autentizovaného API (`apps/status/app_api.php`).
 *
 * Oddělené od `http-source.ts` schválně: tohle jsou data za přihlášením,
 * zatímco `api.php` je veřejné. Míchat je dohromady by svádělo k tomu
 * vystavit chráněný obsah přes veřejný endpoint.
 */

export interface SessionInfo {
  authenticated: boolean;
  user: { id: number; username: string; email: string; role: string } | null;
  csrfToken: string | null;
  loginUrl: string;
}

export interface ApiMonitor {
  id: number;
  name: string;
  type: string;
  target: string;
  port?: number | null;
  status: 'up' | 'down' | 'warning' | 'maintenance' | 'paused';
  category: string | null;
  assetId: number | null;
  assetName: string | null;
  lastCheck: string | null;
  lastStatusChange: string | null;
  responseMs: number | null;
  cpu: number | null;
  ram: number | null;
  hdd: number | null;
  uptimeSeconds: number | null;
  agentLastSeen: number | null;
  hostname: string | null;
  os: string | null;
  details?: Record<string, any>;
  /** Výpadky SBĚRU dat (ne služby samotné) - viz bk_get_collection_issues()
   *  v PHP. Pravidlo projektu: frontend je MUSÍ viditelně zobrazit; tiché
   *  zahazování/zamlčení chybějících dat je zakázané. Server je posílá JEN
   *  administrátorovi - je to provozní diagnostika, ne veřejný stav služeb. */
  collectionIssues?: { type: string; message: string; hint?: string | null; since: string | null }[];
  // Konfigurační pole - přítomná jen v odpovědi pro přihlášeného administrátora
  // (viz api.php action=monitors, $is_admin blok). Hesla se nikdy neposílají zpátky,
  // jen příznak, že jsou nastavená.
  timeout?: number;
  emailNotifications?: boolean;
  smsNotifications?: boolean;
  notes?: string | null;
  maintenance?: boolean;
  maintenanceDescription?: string | null;
  maintenanceStart?: string | null;
  maintenanceEnd?: string | null;
  monitoredProcesses?: string | null;
  presetId?: number | null;
  latencyThresholdMs?: number | null;
  latencyThresholdMins?: number;
  cpuThreshold?: number;
  ramThreshold?: number;
  hddThreshold?: number;
  bodyKeyword?: string | null;
  cpanelStatsUrl?: string | null;
  sqUsername?: string | null;
  sqPasswordSet?: boolean;
  ts3FiletransferPort?: number | null;
  rconPort?: number | null;
  rconPasswordSet?: boolean;
  enabledMetrics?: string[];
  /** Novější verze agenta dostupná na serveru (admin-only; chybí = aktuální/neznámé). */
  agentUpdateAvailable?: string;
  /** Verze skriptu agenta nasazená na serveru (admin-only). */
  agentLatestVersion?: string;
  /** Cíl monitoru není z hostingu dosažitelný (privátní síť) - admin-only. */
  unreachableTarget?: boolean;
  remoteActionsEnabled?: boolean;
  allowedActions?: string[];
}

export interface ApiAsset {
  id: number;
  monitorId: number;
  name: string;
  kind: string;
  icon: string | null;
  status: ApiMonitor['status'];
  monitorCount: number;
  hostname: string | null;
  hasAgent: boolean;
}

export interface ApiUser {
  id: number;
  username: string;
  email: string;
  phone: string | null;
  role: string;
  totpEnabled: boolean;
  oauthProvider: string | null;
  createdAt: string | null;
  isSelf: boolean;
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number
  ) {
    super(message);
  }
}

/** CSRF token se drží v paměti — do localStorage nepatří. */
let csrfToken: string | null = null;

async function request<T>(action: string, init?: RequestInit): Promise<T> {
  let url = `${STATUS_API}/api.php?action=${action}`;
  if (action === 'session') {
    url = `/status/api.php?action=session`;
  }

  const res = await fetch(url, {
    credentials: 'include',
    ...init,
  });

  const data = await res.json().catch(() => ({}));

  if (!res.ok) {
    // Fallback: zkusi starý PHP URL if Go API selhal
    if (url.startsWith('/api/v1/')) {
      const fallbackUrl = `${STATUS_API}/app_api.php?action=${action}`;
      const fallbackRes = await fetch(fallbackUrl, { credentials: 'include', ...init });
      if (fallbackRes.ok) return (await fallbackRes.json()) as T;
    }
    throw new ApiError(
      (data as { error?: string; message?: string }).message ??
        (data as { error?: string }).error ??
        `HTTP ${res.status}`,
      res.status
    );
  }

  return data as T;
}

function mutate<T>(action: string, body: unknown): Promise<T> {
  if (!csrfToken) {
    // Bez tokenu by server vrátil 403 — lepší je selhat srozumitelně tady.
    throw new ApiError('Chybí CSRF token — načtěte stránku znovu.', 403);
  }

  return request<T>(action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify(body),
  });
}

export const appApi = {
  async getSession(): Promise<SessionInfo> {
    const session = await request<SessionInfo>('session');
    csrfToken = session.csrfToken;
    return session;
  },

  getMonitors: () => request<{ monitors: ApiMonitor[] }>('monitors').then((r) => r.monitors),

  getUsers: () => request<{ users: ApiUser[] }>('users').then((r) => r.users),

  saveUser: (user: { id?: number; username: string; email: string; phone?: string; role: string; password?: string }) =>
    mutate<{ success: true; id: number; invited?: boolean }>('save_user', user),

  deleteUser: (id: number) => mutate<{ success: true }>('delete_user', { id }),
};
