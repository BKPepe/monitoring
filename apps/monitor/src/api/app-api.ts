import { STATUS_API } from './http-source';

/**
 * Client of the authenticated API (`apps/status/app_api.php`).
 *
 * Deliberately separate from `http-source.ts`: this is data behind a login,
 * while `api.php` is public. Mixing them would invite exposing protected
 * content through a public endpoint.
 */

export interface SessionInfo {
  authenticated: boolean;
  user: { id: number; username: string; email: string; role: string; totpEnabled?: boolean | null } | null;
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
  /** Outages of data COLLECTION (not the service itself) - see bk_get_collection_issues()
   *  in PHP. Project rule: the frontend MUST show them visibly; silently
   *  dropping/hiding missing data is forbidden. The server sends them ONLY
   *  to the administrator - operational diagnostics, not public service status. */
  collectionIssues?: { type: string; message: string; hint?: string | null; since: string | null }[];
  // Configuration fields - present only in the logged-in administrator's response
  // (see api.php action=monitors, the $is_admin block). Passwords never travel back,
  // only a flag that they are set.
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
  /** A newer agent version available on the server (admin-only; absent = current/unknown). */
  agentUpdateAvailable?: string;
  /** The agent script version deployed on the server (admin-only). */
  agentLatestVersion?: string;
  /** The monitor's target is unreachable from the hosting (private network) - admin-only. */
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

/** The CSRF token lives in memory — it does not belong in localStorage. */
let csrfToken: string | null = null;

/** For the fetch wrapper in main.tsx — since 08/2026 the server enforces CSRF on writes. */
export function getCsrfToken(): string | null {
  return csrfToken;
}

export function setCsrfToken(token: string | null): void {
  csrfToken = token;
}

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
    // Fallback: try the old PHP URL if the Go API failed
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
    // Without a token the server would return 403 — better to fail intelligibly here.
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

  /** Chart notes. An anonymous caller gets an empty list from the server, not an error. */
  getAnnotations: (monitorId: number, metric: string, hours: number) =>
    request<{ annotations: ChartAnnotation[] }>(
      'annotations' + `&monitor_id=${monitorId}&metric=${encodeURIComponent(metric)}&hours=${hours}`
    ).then((r) => r.annotations),

  saveAnnotation: (note: { monitor_id: number; metric_key: string; timestamp: string; note: string }) =>
    mutate<{ success: true; id: number }>('save_annotation', note),

  deleteAnnotation: (id: number) => mutate<{ success: true }>('delete_annotation', { id }),
};

/** One chart note as `action=annotations` returns it. */
export interface ChartAnnotation {
  id: number;
  /** Unix seconds. */
  ts: number;
  note: string;
  /** `null` when the author's account has since been deleted. */
  author: string | null;
}
