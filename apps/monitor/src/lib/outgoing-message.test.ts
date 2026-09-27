import { describe, expect, it } from 'vitest';
import { deliveryLabel, deliveryOf, kindLabel } from './outgoing-message';

/** Answers with the key, so a test sees which label was chosen, whatever the language. */
const key = (k: string) => k;
/** Answers with the Czech fallback, the text a Czech reader gets. */
const cs = (k: string, fallback?: string) => fallback ?? k;

describe('kindLabel', () => {
  it('pojmenuje výstrahu podle tónu, ne jen podle druhu', () => {
    expect(kindLabel('alert', key, 'bad', 'down')).toBe('outgoing.kind_alert_bad');
    expect(kindLabel('alert', key, 'warn', 'storage_warning')).toBe('outgoing.kind_alert_warn');
    expect(kindLabel('alert', key, 'good', 'storage_recovered')).toBe('outgoing.kind_alert_good');
  });

  it('obnovení se nikdy nejmenuje výstraha výpadku', () => {
    // The owner's report: "storage_recovered" and "lte_backup_restored" were
    // listed as "Outage alert", because every status change is kind 'alert'.
    for (const status of ['storage_recovered', 'lte_backup_restored', 'up']) {
      const label = kindLabel('alert', cs, 'good', status);
      expect(label).toBe('Obnovení');
      expect(label).not.toBe('Výstraha výpadku');
    }
  });

  it('údržba není varování, i když má tón varování', () => {
    expect(kindLabel('alert', key, 'warn', 'maintenance')).toBe('outgoing.kind_alert_maintenance');
  });

  it('bez tónu řekne jen to, co je jisté', () => {
    // The filter's option and a row from a server that does not send the tone:
    // guessing "outage" there would repeat the very mistake this fixes.
    expect(kindLabel('alert', key)).toBe('outgoing.kind_alert');
    expect(kindLabel('alert', cs, null, 'storage_recovered')).toBe('Změna stavu');
  });

  it('tón nemění jiné druhy zpráv', () => {
    expect(kindLabel('digest', key, 'good', 'digest')).toBe('outgoing.kind_digest');
    expect(kindLabel('neznamy_druh', key, 'bad')).toBe('neznamy_druh');
  });
});

describe('deliveryOf', () => {
  it('bere výsledek ze serveru, když ho server poslal', () => {
    expect(deliveryOf({ status: 'down', ok: true, delivery: 'sent' })).toBe('sent');
    expect(deliveryOf({ status: 'down', ok: true, delivery: 'unknown' })).toBe('unknown');
    expect(deliveryOf({ status: 'down', ok: false, delivery: 'failed' })).toBe('failed');
  });

  it('bez výsledku ze serveru nikdy neřekne odesláno', () => {
    // An older server knows only ok, and ok = 1 covered a CallMeBot refusal
    // answered with a 2xx and a mail() hand-off nobody confirmed.
    expect(deliveryOf({ status: 'down', ok: true })).toBe('unknown');
    expect(deliveryOf({ status: 'down', ok: false })).toBe('failed');
  });

  it('tichý den připomínky je přeskočený, ať server řekne cokoli', () => {
    expect(deliveryOf({ status: 'skipped', ok: true })).toBe('skipped');
    expect(deliveryOf({ status: 'skipped', ok: true, delivery: 'sent' })).toBe('skipped');
  });

  it('každý výsledek má vlastní slova', () => {
    const labels = (['sent', 'unknown', 'failed', 'skipped'] as const).map((d) => deliveryLabel(d, key));
    expect(labels).toEqual([
      'outgoing.result_sent',
      'outgoing.result_unknown',
      'outgoing.result_failed',
      'outgoing.result_skipped',
    ]);
  });
});
