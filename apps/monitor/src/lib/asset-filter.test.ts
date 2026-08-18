import { describe, expect, it } from 'vitest';
import { filterAssets, parseStatusFilter, type AssetStatus } from './asset-filter';

const asset = (name: string, status: AssetStatus, hostname?: string) => ({ name, status, hostname });

const inventory = [
  asset('Router - Praha', 'up', 'turris'),
  asset('BloodKings.eu', 'up', 'https://bloodkings.eu'),
  asset('Minecraft', 'down', 'mc.bloodkings.eu'),
  asset('Donald', 'warning', 'donald.bloodkings.eu'),
];

describe('parseStatusFilter', () => {
  it('accepts every status the API reports', () => {
    for (const s of ['up', 'down', 'warning', 'paused', 'maintenance'] as const) {
      expect(parseStatusFilter(s)).toBe(s);
    }
  });

  // A typo in the URL must not be taken for a real status: it would match no
  // device and show an empty inventory as if that were the truth.
  it('treats an unknown or missing value as no filter', () => {
    expect(parseStatusFilter('offline')).toBeNull();
    expect(parseStatusFilter('')).toBeNull();
    expect(parseStatusFilter(null)).toBeNull();
    expect(parseStatusFilter(undefined)).toBeNull();
  });
});

describe('filterAssets', () => {
  it('returns null when nothing is filtered, so the caller shows the full tree', () => {
    expect(filterAssets(inventory, {})).toBeNull();
    expect(filterAssets(inventory, { query: '   ', status: null })).toBeNull();
  });

  it('filters by status alone', () => {
    expect(filterAssets(inventory, { status: 'up' })?.map((a) => a.name)).toEqual(['Router - Praha', 'BloodKings.eu']);
    expect(filterAssets(inventory, { status: 'down' })?.map((a) => a.name)).toEqual(['Minecraft']);
  });

  it('filters by text over name and hostname', () => {
    expect(filterAssets(inventory, { query: 'praha' })?.map((a) => a.name)).toEqual(['Router - Praha']);
    expect(filterAssets(inventory, { query: 'mc.bloodkings' })?.map((a) => a.name)).toEqual(['Minecraft']);
  });

  it('combines both instead of letting one override the other', () => {
    expect(filterAssets(inventory, { query: 'bloodkings', status: 'up' })?.map((a) => a.name)).toEqual([
      'BloodKings.eu',
    ]);
    // The text matches a device, but not one in that state - an empty result
    // here is correct, unlike the empty result a bogus status would produce.
    expect(filterAssets(inventory, { query: 'praha', status: 'down' })).toEqual([]);
  });

  it('survives an asset without a hostname', () => {
    const noHost = [{ name: 'Bez hostname', status: 'up' as AssetStatus, hostname: null }];
    expect(filterAssets(noHost, { query: 'bez' })?.length).toBe(1);
    expect(filterAssets(noHost, { query: 'jine' })?.length).toBe(0);
  });
});
