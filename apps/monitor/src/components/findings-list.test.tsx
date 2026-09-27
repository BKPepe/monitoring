// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import type { Finding, FindingsResponse } from '@/api/types';
import { FindingsList } from './findings-list';

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const finding = (key: string, monitorId: number, source: Finding['source'], extra: Partial<Finding> = {}): Finding => ({
  key,
  source,
  kind: 'k',
  severity: 'warning',
  monitorId,
  monitorName: `Server ${monitorId}`,
  monitorType: 'vps',
  title: `Nález ${key}`,
  detail: null,
  action: null,
  since: null,
  ...extra,
});

const answer = (extra: Partial<FindingsResponse>): FindingsResponse => ({
  findings: [],
  total: 0,
  offset: 0,
  counts: { critical: 0, warning: 0, info: 0 },
  devices: [],
  monitorsChecked: 0,
  muted: [],
  canMute: false,
  sourceErrors: [],
  insightsCachedAt: null,
  generatedAt: '2026-09-23T10:00:00+02:00',
  ...extra,
});

const serve = (body: unknown, status = 200) =>
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => ({ ok: status < 400, status, json: async () => body }) as Response)
  );

const renderList = (ui: React.ReactElement) =>
  render(
    <LanguageProvider>
      <MemoryRouter>{ui}</MemoryRouter>
    </LanguageProvider>
  );

describe('FindingsList (C-12)', () => {
  it('„top“ ukáže jen vybrané zdroje, nejvýš limit, a odkaz na všechna zjištění', async () => {
    serve(
      answer({
        total: 4,
        findings: [
          finding('a', 1, 'status', { severity: 'critical' }),
          finding('b', 2, 'router'),
          finding('c', 3, 'insight'),
          finding('d', 4, 'insight'),
        ],
      })
    );
    renderList(<FindingsList density="top" sources={['insight', 'router']} limit={2} />);
    expect(await screen.findByText('Nález b')).toBeTruthy();
    expect(screen.getByText('Nález c')).toBeTruthy();
    expect(screen.queryByText('Nález a')).toBeNull();
    expect(screen.queryByText('Nález d')).toBeNull();
    expect(screen.getByRole('link', { name: 'Všechna upozornění (4) →' }).getAttribute('href')).toBe('/insights');
  });

  it('„top“ bez nálezu nezabírá místo', async () => {
    serve(answer({}));
    const { container } = renderList(<FindingsList density="top" />);
    await waitFor(() => expect(vi.mocked(fetch)).toHaveBeenCalled());
    expect(container.textContent).toBe('');
  });

  it('chyba serveru je hlasitá, nikdy „nic k řešení“', async () => {
    serve({ error: 'findings_unavailable' }, 500);
    renderList(<FindingsList density="all" />);
    expect(await screen.findByText(/Zjištění se nepodařilo načíst/)).toBeTruthy();
    expect(screen.queryByText(/Nic k řešení/)).toBeNull();
  });

  it('selhaný zdroj pojmenuje, i když seznam jinak přišel', async () => {
    serve(
      answer({ sourceErrors: [{ source: 'router', monitorId: null, error: 'router_recommendations_unavailable' }] })
    );
    renderList(<FindingsList density="all" />);
    expect(await screen.findByText(/Seznam není úplný: doporučení pro routery/)).toBeTruthy();
  });

  it('selhané tipy pojmenuje jako tipy, ne jako stav zařízení (CR-9b)', async () => {
    serve(answer({ sourceErrors: [{ source: 'tip', monitorId: 7, error: 'tips_unavailable' }] }));
    renderList(<FindingsList density="all" />);
    expect(await screen.findByText(/Seznam není úplný: tipy ze znalostní báze/)).toBeTruthy();
    expect(screen.queryByText(/stav zařízení/)).toBeNull();
  });

  it('selhaný zdroj a žádné zjištění: žádné „nic k řešení“ vedle „seznam není úplný“ (CR-12)', async () => {
    serve(
      answer({
        findings: [],
        total: 0,
        sourceErrors: [{ source: 'router', monitorId: null, error: 'router_recommendations_unavailable' }],
      })
    );
    renderList(<FindingsList density="device" monitorId={7} />);
    expect(await screen.findByText(/Seznam není úplný/)).toBeTruthy();
    expect(screen.queryByText(/Nic k řešení/)).toBeNull();
  });

  it('„all“ seskupí podle zařízení, ztlumit jde jen doporučení routeru a zbytek zařízení shrne jednou větou', async () => {
    const rec = { key: 'wifi_noise', command: 'opkg install iw' } as Finding['rec'];
    serve(
      answer({
        canMute: true,
        monitorsChecked: 9,
        findings: [finding('r', 7, 'router', { rec, action: 'Přesuňte kanál' }), finding('m', 8, 'metric')],
        devices: [
          {
            monitorId: 7,
            monitorName: 'Server 7',
            monitorType: 'openwrt',
            worst: 'warning',
            critical: 0,
            warning: 1,
            info: 0,
            total: 1,
          },
          {
            monitorId: 8,
            monitorName: 'Server 8',
            monitorType: 'vps',
            worst: 'warning',
            critical: 0,
            warning: 1,
            info: 0,
            total: 1,
          },
        ],
      })
    );
    renderList(<FindingsList density="all" />);
    expect(await screen.findByRole('region', { name: 'Server 7' })).toBeTruthy();
    expect(screen.getAllByRole('button', { name: /Ztlumit/ })).toHaveLength(1);
    expect(screen.getByText('opkg install iw')).toBeTruthy();
    expect(screen.getByText('Ostatní zařízení bez nálezů (7)')).toBeTruthy();
  });
});
