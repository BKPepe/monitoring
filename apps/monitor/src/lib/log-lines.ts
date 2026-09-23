/**
 * The router's log errors: what was counted, over which window, and the lines
 * behind the count (agent 0.1.8, W1-C3).
 *
 * The agent counts error lines in the last 500 lines of logread. The label
 * used to say "24 h", which logread never promised: on a chatty router 500
 * lines are twenty minutes, on a quiet one a week. From 0.1.8 the agent sends
 * how far back those lines reach (`log_window_secs`) and the newest distinct
 * error lines, already masked on the router (`<ipv4>`, `<mac>`, `<host>`...).
 */

export interface LogErrorLine {
  /** Epoch seconds (UTC) of the newest repeat; null when logread gave no usable time. */
  ts: number | null;
  prog: string | null;
  msg: string;
  /** How many times this (masked) line repeats in the counted buffer. */
  count: number;
}

/** Why no lines are shown although the agent is new enough to send them. */
export type LogLinesState = 'on' | 'off_monitor' | 'off_router';

export type LogLinesView =
  /** An agent (or server) older than 0.1.8: there is nothing to say about lines. */
  | { kind: 'absent' }
  /** Sending is switched off - for this monitor on the server, or on the router itself. */
  | { kind: 'off'; where: 'monitor' | 'router' }
  /** Sending is on and the log was unreadable: unmeasured, not "no errors". */
  | { kind: 'unmeasured' }
  /** The log was read and none of its lines is an error. */
  | { kind: 'none' }
  | { kind: 'lines'; lines: LogErrorLine[] };

/** At most this many lines; the server and the agent cut at 5 too. */
const MAX_LINES = 5;

function readLine(raw: unknown): LogErrorLine | null {
  if (!raw || typeof raw !== 'object') return null;
  const o = raw as Record<string, unknown>;
  if (typeof o.msg !== 'string' || o.msg === '') return null;
  const ts = typeof o.ts === 'number' && Number.isFinite(o.ts) && o.ts > 0 ? o.ts : null;
  const prog = typeof o.prog === 'string' && o.prog !== '' ? o.prog : null;
  const count = typeof o.count === 'number' && Number.isFinite(o.count) && o.count >= 1 ? Math.floor(o.count) : 1;
  return { ts, prog, msg: o.msg, count };
}

/**
 * Reads `log_errors_recent` + `log_lines_state` from a router's last_details.
 *
 * A key that is missing altogether is an older agent (or a server that does
 * not store the lines yet) and stays silent; null with a switch off says
 * which switch; null with sending on means the log could not be read.
 */
export function readLogLines(details: Record<string, unknown> | null | undefined): LogLinesView {
  if (!details || !('log_errors_recent' in details)) return { kind: 'absent' };
  const raw = details.log_errors_recent;
  const state = details.log_lines_state;
  if (Array.isArray(raw)) {
    const lines = raw
      .map(readLine)
      .filter((l): l is LogErrorLine => l !== null)
      .slice(0, MAX_LINES);
    if (raw.length === 0) return { kind: 'none' };
    // Items that were there but unreadable are not "no errors".
    return lines.length > 0 ? { kind: 'lines', lines } : { kind: 'unmeasured' };
  }
  if (state === 'off_monitor') return { kind: 'off', where: 'monitor' };
  if (state === 'off_router') return { kind: 'off', where: 'router' };
  return { kind: 'unmeasured' };
}

/**
 * How far back the counted log reaches, in whole hours (at least 1), or in
 * minutes below an hour. null = the agent did not say (older than 0.1.8).
 */
export function logWindow(secs: unknown): { value: number; unit: 'h' | 'min' } | null {
  if (typeof secs !== 'number' || !Number.isFinite(secs) || secs < 0) return null;
  if (secs < 3600) return { value: Math.max(1, Math.round(secs / 60)), unit: 'min' };
  return { value: Math.round(secs / 3600), unit: 'h' };
}
