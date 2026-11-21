import {
  CircleCheck,
  CircleDashed,
  CirclePause,
  CircleX,
  ClockAlert,
  TriangleAlert,
  Wrench,
  type LucideIcon,
} from 'lucide-react';

/**
 * The one status vocabulary (C-11), twin of PHP `bk_status_label()`.
 *
 * "unknown" meant two opposite things: a monitor nobody has heard from yet
 * (nothing is wrong, it is new) and an agent that stopped talking (something
 * may be very wrong). One word and one colour for both either cried wolf on
 * every new monitor or hid a dead agent in grey. Owner decision 5.10 splits
 * it: waiting for the first data is neutral dashed grey, a silent agent keeps
 * the warning colour.
 *
 * Keys, tones and icons match the server map one for one, so a chip in the
 * SPA and a line in the e-mail name the same state the same way.
 */
export type StatusKey = 'up' | 'warning' | 'down' | 'maintenance' | 'paused' | 'unknown_new' | 'unknown_stale';

/** The server's tone for a key: what the state means, independent of how a badge paints it. */
export type StatusTone = 'up' | 'warning' | 'down' | 'info' | 'neutral';

export interface StatusMeta {
  tone: StatusTone;
  /** The Badge/StatusDot variant: paused keeps its own grey token, the rest follow the tone. */
  variant: 'up' | 'warning' | 'down' | 'info' | 'paused' | 'neutral';
  icon: LucideIcon;
  /** Drawn with a dashed outline: a state with nothing measured yet, not a verdict. */
  dashed: boolean;
}

const META: Record<StatusKey, StatusMeta> = {
  up: { tone: 'up', variant: 'up', icon: CircleCheck, dashed: false },
  warning: { tone: 'warning', variant: 'warning', icon: TriangleAlert, dashed: false },
  down: { tone: 'down', variant: 'down', icon: CircleX, dashed: false },
  maintenance: { tone: 'info', variant: 'info', icon: Wrench, dashed: false },
  paused: { tone: 'neutral', variant: 'paused', icon: CirclePause, dashed: false },
  unknown_new: { tone: 'neutral', variant: 'neutral', icon: CircleDashed, dashed: true },
  unknown_stale: { tone: 'warning', variant: 'warning', icon: ClockAlert, dashed: false },
};

const isKey = (value: unknown): value is StatusKey => typeof value === 'string' && value in META;

/**
 * The key for a raw monitor status.
 *
 * @param hasReported false = no check and no agent report ever arrived. Only
 *   an explicit false makes "unknown" the new, harmless kind: the same rule as
 *   PHP, where an unknown word or an unknown history reads as the silent agent,
 *   because assuming "new" would hide a real silence.
 */
export function statusKeyOf(status: string | null | undefined, hasReported?: boolean | null): StatusKey {
  const raw = String(status ?? '')
    .trim()
    .toLowerCase();
  if (isKey(raw) && !raw.startsWith('unknown_')) return raw;
  return hasReported === false ? 'unknown_new' : 'unknown_stale';
}

/**
 * The key for a monitor from `action=monitors`: the server's own `statusKey`
 * when it sent one, otherwise derived from the same two facts it uses (a
 * check ever ran, an agent ever reported).
 */
export function monitorStatusKey(m: {
  status: string;
  statusKey?: string | null;
  lastCheck?: string | null;
  agentLastSeen?: number | null;
}): StatusKey {
  if (isKey(m.statusKey)) return m.statusKey;
  return statusKeyOf(m.status, Boolean(m.lastCheck) || m.agentLastSeen != null);
}

export function statusMeta(key: StatusKey): StatusMeta {
  return META[key];
}

type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

/** Spelled out key by key, so the dictionary lint sees every one. */
export function statusLabel(key: StatusKey, t: TranslateFn): string {
  switch (key) {
    case 'up':
      return t('common.online', 'Online');
    case 'warning':
      return t('common.warning', 'Varování');
    case 'down':
      return t('status.key_down', 'Výpadek');
    case 'maintenance':
      return t('common.maintenance', 'Údržba');
    case 'paused':
      return t('common.paused', 'Pozastaveno');
    case 'unknown_new':
      return t('status.key_unknown_new', 'Čeká na první data');
    case 'unknown_stale':
      return t('status.key_unknown_stale', 'Agent mlčí');
  }
}

/** The `?status=` value of the device list for a key; both unknowns share the list's one filter. */
export function statusFilterParam(key: StatusKey): string {
  return key.startsWith('unknown_') ? 'unknown' : key;
}
