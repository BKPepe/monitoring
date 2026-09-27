/**
 * The first-run installer's calls (site W1-5). They run before the app has a
 * session or a database, so they bypass api/app-api (whose CSRF token comes
 * from action=session, which answers needs_setup until the install is done)
 * and carry the token install_status hands out. Every error body has a
 * `message` in the request language; the code in `error` is what the steps
 * branch on.
 */
export type InstallStep = 'config' | 'config_unreachable' | 'schema' | 'account' | 'installed';

export interface InstallStatus {
  step: InstallStep;
  locked: boolean;
  config?: { exists: boolean; sample: boolean; writable: boolean };
  database?: {
    connects: boolean;
    schema: 'missing' | 'partial' | 'ready' | null;
    users: number | null;
    tables: number | null;
  };
  requirements?: { php: string; phpOk: boolean; extensions: Record<string, boolean> };
  csrfToken?: string;
}

export interface InstallCron {
  schedule: string;
  command: string;
  crontabLine: string;
  script: string;
  phpBinary: string;
  phpBinaryFound: boolean;
}

/** A failed installer call: the server's code and its sentence. */
export class InstallError extends Error {
  constructor(
    message: string,
    readonly code: string,
    readonly status: number,
    readonly body: Record<string, unknown> = {}
  ) {
    super(message);
  }
}

const STEPS: readonly InstallStep[] = ['config', 'config_unreachable', 'schema', 'account', 'installed'];

export function isInstallStep(value: unknown): value is InstallStep {
  return typeof value === 'string' && (STEPS as readonly string[]).includes(value);
}

/** Reads an installer answer; a non-2xx becomes an InstallError with the server's code and sentence. */
export async function readInstallAnswer<T>(res: Response): Promise<T> {
  const body = (await res.json().catch(() => null)) as Record<string, unknown> | null;
  if (!res.ok || !body) {
    const code = typeof body?.error === 'string' ? body.error : `http_${res.status}`;
    const message = typeof body?.message === 'string' ? body.message : `HTTP ${res.status}`;
    throw new InstallError(message, code, res.status, body ?? {});
  }
  return body as T;
}

/** A POST step: JSON body, the installer's CSRF token, the page language for the messages. */
export function postInstall(url: string, csrfToken: string, body: unknown = {}): Promise<Response> {
  return fetch(url, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify(body),
  });
}

/**
 * The config text for the clipboard. The server sends it with a placeholder
 * instead of the password, and the password goes in only here, escaped for
 * the single-quoted PHP string it lands in - it is never rendered.
 */
export function configWithPassword(text: string, placeholder: string, password: string): string {
  const escaped = password.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  return text.split(placeholder).join(escaped);
}
