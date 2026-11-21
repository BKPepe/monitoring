import { describe, expect, it } from 'vitest';
import { splitLocationLabel } from './public-location';

describe('splitLocationLabel: štítek místa měření pro veřejnou stránku', () => {
  it('emoji vlajka zmizí (vlajku kreslí stránka), síť v závorce jde na druhý řádek, ", DE" zůstává', () => {
    expect(splitLocationLabel('🇩🇪 Frankfurt, DE (AS13335 Cloudflare)')).toEqual({
      name: 'Frankfurt, DE',
      network: 'AS13335 Cloudflare',
    });
  });

  it('glóbus bez země i štítek bez značky a bez závorky', () => {
    expect(splitLocationLabel('🌐 Cloudflare Edge (AS13335 Cloudflare)')).toEqual({
      name: 'Cloudflare Edge',
      network: 'AS13335 Cloudflare',
    });
    expect(splitLocationLabel('prague')).toEqual({ name: 'prague', network: null });
  });

  it('místo neuvedené nebo prázdné je null, ne vymyšlený název; samotná síť se stane názvem', () => {
    expect(splitLocationLabel(null)).toEqual({ name: null, network: null });
    expect(splitLocationLabel('   ')).toEqual({ name: null, network: null });
    expect(splitLocationLabel('🇺🇸 (AS13335 Cloudflare)')).toEqual({ name: 'AS13335 Cloudflare', network: null });
  });
});
