import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { monitorStatusKey, statusFilterParam, statusKeyOf, statusLabel, statusMeta, type StatusKey } from './status';

/** Every key of the vocabulary - the wire test holds the PHP map to exactly these. */
const STATUS_KEYS: readonly StatusKey[] = [
  'down',
  'unknown_stale',
  'warning',
  'maintenance',
  'paused',
  'unknown_new',
  'up',
];

const echo = (_key: string, fallback?: unknown) => String(fallback);

describe('statusKeyOf (C-11, dvojče bk_status_label)', () => {
  it('známé stavy nechá beze změny', () => {
    for (const s of ['up', 'warning', 'down', 'maintenance', 'paused'] as const) {
      expect(statusKeyOf(s)).toBe(s);
    }
  });

  it('„unknown“ bez jediného hlášení je nový monitor, ne mlčící agent', () => {
    expect(statusKeyOf('unknown', false)).toBe('unknown_new');
  });

  it('„unknown“ s historií nebo bez údaje je mlčící agent - neznámá historie se neskrývá', () => {
    expect(statusKeyOf('unknown', true)).toBe('unknown_stale');
    expect(statusKeyOf('unknown')).toBe('unknown_stale');
    expect(statusKeyOf('nesmysl', true)).toBe('unknown_stale');
  });

  it('velikost písmen a mezery nevadí, stejně jako v PHP', () => {
    expect(statusKeyOf(' DOWN ')).toBe('down');
  });
});

describe('monitorStatusKey', () => {
  it('přednost má klíč od serveru', () => {
    expect(monitorStatusKey({ status: 'unknown', statusKey: 'unknown_new', lastCheck: '2026-09-01' })).toBe(
      'unknown_new'
    );
  });

  it('bez klíče ho odvodí z poslední kontroly a hlášení agenta', () => {
    expect(monitorStatusKey({ status: 'unknown', lastCheck: null, agentLastSeen: null })).toBe('unknown_new');
    expect(monitorStatusKey({ status: 'unknown', lastCheck: null, agentLastSeen: 1_700_000_000 })).toBe(
      'unknown_stale'
    );
    expect(monitorStatusKey({ status: 'unknown', lastCheck: '2026-09-01T10:00:00Z' })).toBe('unknown_stale');
  });

  it('neznámý klíč od serveru ignoruje', () => {
    expect(monitorStatusKey({ status: 'up', statusKey: 'offline' })).toBe('up');
  });
});

describe('statusMeta (rozhodnutí 5.10)', () => {
  it('čekání na první data je neutrální a čárkované, mlčící agent varování', () => {
    expect(statusMeta('unknown_new')).toMatchObject({ tone: 'neutral', variant: 'neutral', dashed: true });
    expect(statusMeta('unknown_stale')).toMatchObject({ tone: 'warning', variant: 'warning', dashed: false });
  });

  it('tóny odpovídají serverové mapě', () => {
    expect(statusMeta('maintenance').tone).toBe('info');
    expect(statusMeta('paused').tone).toBe('neutral');
    expect(statusMeta('paused').variant).toBe('paused');
  });
});

describe('statusLabel a filtr', () => {
  it('pojmenuje oba neznámé stavy různě', () => {
    const t = (key: string, fallback?: unknown) => echo(key, fallback);
    expect(statusLabel('unknown_new', t)).toBe('Čeká na první data');
    expect(statusLabel('unknown_stale', t)).toBe('Agent mlčí');
    expect(statusLabel('down', t)).toBe('Výpadek');
  });

  it('oba neznámé stavy vedou na jeden filtr seznamu zařízení', () => {
    expect(statusFilterParam('unknown_new')).toBe('unknown');
    expect(statusFilterParam('unknown_stale')).toBe('unknown');
    expect(statusFilterParam('down')).toBe('down');
  });
});

/**
 * The wire contract of C-11, both ends at once: PHP's bk_status_label() map in
 * functions.php and this module must name, colour and draw every state the
 * same way - the server's `statusKey` is only as good as this agreement.
 */
describe('stavový slovník: PHP bk_status_label() = lib/status.ts (C-11)', () => {
  const php = readFileSync(join(__dirname, '../../../status/functions.php'), 'utf8');
  const body = php.slice(php.indexOf('function bk_status_label('), php.indexOf('function bk_event_fallback_text('));
  const rows = [...body.matchAll(/'(\w+)' => \['([^']*)', '([^']*)', '(\w+)', '([\w-]+)'\]/g)].map(
    ([, key, cs, en, tone, icon]) => ({ key, cs, en, tone, icon })
  );
  const dict = readFileSync(join(__dirname, '../context/language-context.tsx'), 'utf8');
  const head = 'const translations: Record<string, { cs: string; en: string }> = ';
  const start = dict.indexOf(head) + head.length;
  const translations = new Function(`return (${dict.slice(start, dict.indexOf('\n};\n', start) + 2)});`)() as Record<
    string,
    { cs: string; en: string }
  >;
  const tIn = (lang: 'cs' | 'en') => (key: string, fallback?: unknown) => translations[key]?.[lang] ?? String(fallback);
  const pascal = (kebab: string) => kebab.replace(/(^|-)(\w)/g, (_, _dash: string, c: string) => c.toUpperCase());

  it('obě strany znají stejných sedm stavů', () => {
    expect(rows.map((r) => r.key).sort()).toEqual([...STATUS_KEYS].sort());
  });

  it('každý stav má na obou stranách stejný popisek (cs i en), tón a ikonu', () => {
    for (const row of rows) {
      const key = row.key as (typeof STATUS_KEYS)[number];
      const meta = statusMeta(key);
      expect({ key, cs: statusLabel(key, tIn('cs')), en: statusLabel(key, tIn('en')) }).toEqual({
        key,
        cs: row.cs,
        en: row.en,
      });
      expect({ key, tone: meta.tone }).toEqual({ key, tone: row.tone });
      expect({ key, icon: (meta.icon as { displayName?: string }).displayName }).toEqual({
        key,
        icon: pascal(row.icon),
      });
    }
  });
});
