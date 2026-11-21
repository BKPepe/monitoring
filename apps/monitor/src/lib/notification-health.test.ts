import { describe, expect, it } from 'vitest';
import type { NotificationProblem } from '@/api/types';
import { groupNotificationProblems } from './notification-health';

const problem = (over: Partial<NotificationProblem> = {}): NotificationProblem => ({
  channel: 'email',
  recipient: 'a…@example.com',
  username: null,
  state: 'failed',
  sinceIso: '2026-09-27T06:00:00Z',
  lastAtIso: '2026-09-27T09:00:00Z',
  count: 1,
  lastReason: 'first',
  lastSentAtIso: null,
  legacy: false,
  ...over,
});

describe('groupNotificationProblems', () => {
  it('složí příjemce jednoho kanálu a stavu do jednoho řádku', () => {
    const [line, ...rest] = groupNotificationProblems([
      problem({ username: 'admin' }),
      problem({
        recipient: 'b…@example.com',
        sinceIso: '2026-09-26T06:00:00Z',
        lastAtIso: '2026-09-27T10:00:00Z',
        count: 3,
        lastReason: 'newest',
        lastSentAtIso: '2026-09-25T08:00:00Z',
      }),
    ]);
    expect(rest).toHaveLength(0);
    expect(line.who).toEqual(['admin (a…@example.com)', 'b…@example.com']);
    expect(line.count).toBe(4);
    expect(line.sinceIso).toBe('2026-09-26T06:00:00Z');
    // The reason of the most recent attempt, and the most recent confirmation.
    expect(line.lastReason).toBe('newest');
    expect(line.lastSentAtIso).toBe('2026-09-25T08:00:00Z');
  });

  it('neodeslané před nepotvrzeným a stavy se nemíchají', () => {
    const lines = groupNotificationProblems([
      problem({ channel: 'whatsapp', state: 'unknown' }),
      problem({ channel: 'whatsapp', state: 'failed' }),
      problem({ channel: 'discord', state: 'failed', recipient: null }),
    ]);
    expect(lines.map((l) => `${l.state}:${l.channel}`)).toEqual([
      'failed:discord',
      'failed:whatsapp',
      'unknown:whatsapp',
    ]);
    expect(lines[0].who).toEqual([]);
  });

  it('řádek je „starší“ jen když jsou starší všechny jeho záznamy', () => {
    expect(groupNotificationProblems([problem({ legacy: true }), problem({ legacy: false })])[0].legacy).toBe(false);
    expect(groupNotificationProblems([problem({ legacy: true })])[0].legacy).toBe(true);
  });
});
