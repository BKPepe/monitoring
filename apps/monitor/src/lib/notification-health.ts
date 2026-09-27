import type { NotificationProblem } from '@/api/types';

/** One line of the warning: a channel in one state, with everybody it is failing. */
export interface NotificationHealthLine {
  channel: string;
  state: 'failed' | 'unknown';
  /** "username (•••456)" or the masked address alone; empty for a webhook. */
  who: string[];
  /** The earliest start among the recipients. */
  sinceIso: string;
  /** Attempts since the last confirmed one, over all recipients. */
  count: number;
  /** The reason of the most recent attempt. */
  lastReason: string | null;
  /** The most recent confirmed send among them; `null` = none in the window. */
  lastSentAtIso: string | null;
  /** Every problem on the line comes from rows without a recorded provider reply. */
  legacy: boolean;
}

/**
 * Folds the per-recipient problems into one line per channel and state, the
 * failures first. A banner line per address would bury the one that matters
 * when a whole channel stops.
 */
export function groupNotificationProblems(problems: NotificationProblem[]): NotificationHealthLine[] {
  const lines = new Map<string, NotificationHealthLine & { lastAtIso: string }>();
  for (const p of problems) {
    const key = `${p.state}\n${p.channel}`;
    const who = p.recipient ? (p.username ? `${p.username} (${p.recipient})` : p.recipient) : null;
    const line = lines.get(key);
    if (!line) {
      lines.set(key, {
        channel: p.channel,
        state: p.state,
        who: who ? [who] : [],
        sinceIso: p.sinceIso,
        count: p.count,
        lastReason: p.lastReason,
        lastSentAtIso: p.lastSentAtIso,
        legacy: p.legacy,
        lastAtIso: p.lastAtIso,
      });
      continue;
    }
    if (who) line.who.push(who);
    line.count += p.count;
    if (Date.parse(p.sinceIso) < Date.parse(line.sinceIso)) line.sinceIso = p.sinceIso;
    if (Date.parse(p.lastAtIso) > Date.parse(line.lastAtIso)) {
      line.lastAtIso = p.lastAtIso;
      line.lastReason = p.lastReason;
    }
    if (p.lastSentAtIso && (!line.lastSentAtIso || Date.parse(p.lastSentAtIso) > Date.parse(line.lastSentAtIso))) {
      line.lastSentAtIso = p.lastSentAtIso;
    }
    line.legacy = line.legacy && p.legacy;
  }
  return [...lines.values()]
    .sort((a, b) => (a.state === b.state ? a.channel.localeCompare(b.channel) : a.state === 'failed' ? -1 : 1))
    .map((l) => ({
      channel: l.channel,
      state: l.state,
      who: l.who,
      sinceIso: l.sinceIso,
      count: l.count,
      lastReason: l.lastReason,
      lastSentAtIso: l.lastSentAtIso,
      legacy: l.legacy,
    }));
}
