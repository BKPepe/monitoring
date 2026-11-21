// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import omnia from '@/api/omnia-router.fixture';

// ECharts needs a canvas 2D context, jsdom has none: this file is about the
// layout of the router's Network tab, not the drawing.
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: () => <div data-testid="chart" />,
}));

import { AssetDetailPage } from './asset-detail';

/**
 * The router's Network tab after W2-3: the two speed cards merged, "SQM & LTE" split into WAN, LTE and VPN, and per-link traffic as
 * one bar per period. Only documentation values - no address, SSID or key of
 * a real device.
 */
const details = {
  version: '0.1.8',
  agent_type: 'openwrt',
  wan_proto: 'pppoe',
  wan_up: true,
  wan_internet: true,
  wan_uptime: 13 * 86400 + 22 * 3600,
  wan_link_mbit: 2500,
  sqm_enabled: true,
  sqm_download_kbps: 900000,
  sqm_upload_kbps: 450000,
  lte_up: true,
  lte_device: 'wwan0',
  lte_sim_state: 'ready',
  lte_connected: true,
  lte_rsrp: -85,
  lte_rsrq: -9,
  lte_sinr: 15,
  wifi_radios: [
    { radio: 'radio0', band: '5GHz', channel: 36, width_mhz: 80, clients: 4, noise: -92, busy_pct: 3.5 },
    { radio: 'radio1', band: '2.4GHz', channel: 6, width_mhz: 20, clients: 6, noise: -84, busy_pct: 30 },
  ],
  tailscale_up: true,
  openvpn_tunnels: 1,
  wireguard_peers: [{ interface: 'wg0', public_key: 'peer-a', latest_handshake: null }],
};

const monitor = {
  id: 6,
  name: 'Router',
  type: 'openwrt',
  target: '192.0.2.1',
  status: 'up',
  category: 'Routery',
  assetId: 6,
  assetName: 'Router',
  lastCheck: '2026-09-21T00:00:00Z',
  lastStatusChange: null,
  responseMs: 3,
  cpu: 20,
  ram: 30,
  hdd: 10,
  uptimeSeconds: 89000,
  agentLastSeen: null,
  hostname: null,
  os: 'OpenWrt',
  details,
};

const linkTraffic = {
  primary: {
    iface: 'pppoe-wan',
    today: { rx_bytes: 3 * 1073741824, tx_bytes: 1073741824 },
    '7d': { rx_bytes: 30 * 1073741824, tx_bytes: 5 * 1073741824 },
    '30d': null,
    total: null,
  },
  backup: { iface: 'wwan0', today: null, '7d': { rx_bytes: 1048576, tx_bytes: 1048576 }, '30d': null, total: null },
  days: 30,
  wan_down_periods: [],
  wan_down_seconds: 0,
  wan_down_now: false,
  interfaces: ['pppoe-wan', 'wwan0'],
};

const json = (body: unknown) =>
  ({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

const api = (url: string): Response => {
  if (url.includes('action=monitors')) return json({ monitors: [monitor] });
  if (url.includes('action=router_recommendations'))
    return json({ monitorId: 6, applicable: true, reason: null, items: [], muted: [] });
  if (url.includes('action=storage_history')) return json({ monitorId: 6, days: 90, disks: [] });
  if (url.includes('action=wan_bottleneck')) return json(omnia.wanBottleneck);
  if (url.includes('action=link_traffic')) return json(linkTraffic);
  return json({});
};

function renderDetail() {
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter initialEntries={['/infrastructure/6']}>
          <Routes>
            <Route path="/infrastructure/:id" element={<AssetDetailPage />} />
          </Routes>
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

async function openTab(name: string) {
  // Radix activates a tab on mouseDown, not on click.
  fireEvent.mouseDown(await screen.findByRole('tab', { name: new RegExp(name) }, { timeout: 5000 }));
}

describe('Router: záložka Síť (W2-3)', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => Promise.resolve(api(String(input))))
    );
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe() {}
        unobserve() {}
        disconnect() {}
      }
    );
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('sekce WAN, LTE záloha, Wi-Fi a Rychlost linky mají kotvy, na které lze skočit', async () => {
    renderDetail();
    await openTab('Síť');
    await waitFor(() => expect(document.getElementById('net-wan')).not.toBeNull(), { timeout: 5000 });
    for (const id of ['net-wan', 'net-lte', 'net-wifi', 'net-speed']) {
      const target = await waitFor(() => document.getElementById(id) as HTMLElement);
      expect(target, id).not.toBeNull();
      // Focusable for a jump, but not a stop in the tab order.
      expect(target.getAttribute('tabindex'), id).toBe('-1');
    }
  });

  it('SQM patří k WAN, LTE má vlastní kartu a tunely jednu kartu VPN', async () => {
    renderDetail();
    await openTab('Síť');
    await waitFor(() => expect(document.getElementById('net-wan')).not.toBeNull(), { timeout: 5000 });
    const wan = document.getElementById('net-wan') as HTMLElement;
    expect(within(wan).getByText('SQM')).toBeTruthy();
    expect(within(wan).getByText('2500 Mbit/s')).toBeTruthy();
    const lte = document.getElementById('net-lte') as HTMLElement;
    expect(within(lte).getByText('SIM')).toBeTruthy();
    expect(within(lte).getByText('připravená')).toBeTruthy();
    expect(within(lte).queryByText('SQM')).toBeNull();
    // The heading's card (a labelled region since the NetPulse panels):
    // WireGuard, Tailscale and OpenVPN side by side.
    const vpn = screen.getByRole('region', { name: 'VPN' });
    expect(within(vpn).getByText('WireGuard (1)')).toBeTruthy();
    expect(within(vpn).getByText('Tailscale')).toBeTruthy();
    expect(within(vpn).getByText('OpenVPN')).toBeTruthy();
    expect(screen.queryByText('SQM & LTE')).toBeNull();
  });

  it('provoz podle linky je jeden pruh WAN proti LTE za každé období, bez emoji', async () => {
    renderDetail();
    await openTab('Síť');
    const today = await screen.findByRole('img', { name: /^Provoz podle linky · Dnes/ }, { timeout: 5000 });
    // The backup has no rows today: named with a dash, not drawn as zero.
    expect(today.getAttribute('aria-label')).toContain('Záloha (LTE) wwan0 —');
    expect(screen.getByRole('img', { name: /^Provoz podle linky · 7 dní/ })).toBeTruthy();
    expect(screen.queryByText(/🔀/)).toBeNull();
  });

  it('rychlost linky je jedna karta a úložiště routeru už nevypisuje služby', async () => {
    renderDetail();
    await openTab('Síť');
    expect(await screen.findByRole('heading', { name: /Rychlost linky/ }, { timeout: 5000 })).toBeTruthy();
    expect(screen.queryByText('Kde končí rychlost linky')).toBeNull();
    await openTab('Úložiště');
    expect(screen.queryByText('Síťové služby routeru')).toBeNull();
  });

  it('Události: protokol kontrol je odkaz na společný protokol, ne druhá kopie (W2-6)', async () => {
    renderDetail();
    await openTab('Události');
    const link = await screen.findByRole('link', { name: 'Otevřít protokol kontrol →' }, { timeout: 5000 });
    expect(link.getAttribute('href')).toBe('/incidents/checks?monitor=6');
  });
});
