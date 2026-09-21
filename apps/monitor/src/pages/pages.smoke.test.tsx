// @vitest-environment jsdom
import * as React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { DashboardPage } from './dashboard';
import { WebsitesPage } from './websites';
import { IncidentsPage } from './incidents';
import { ServicesPage } from './services';
import { InfrastructurePage } from './infrastructure';
import { InsightsPage } from './insights';
import { ReportsPage } from './reports';
import { UsersPage } from './users';
import { ApiAgentsPage } from './api-agents';
import { StatusPagesPage } from './status-pages';
import { SettingsPage } from './settings';
import { OutgoingMessagesPage } from './outgoing-messages';
import { NotFoundPage } from './not-found';

/**
 * Page smoke tests: every page must render without crashing
 * (a) with empty API data and (b) on a network error.
 *
 * No pixel-perfect content checks - what is checked is that a refactor
 * does not break rendering outright (exactly what already happened in this
 * repo: a component defined inside a render function, a missing null-check on details…).
 */

const jsonResponse = (body: unknown) =>
  ({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

/** Minimal honest responses: emptiness, not invented numbers. */
const emptyApi = (url: string): Response => {
  if (url.includes('action=monitors')) return jsonResponse({ monitors: [] });
  if (url.includes('action=incidents')) return jsonResponse({ incidents: [], manualIncidents: [] });
  if (url.includes('action=public_status'))
    return jsonResponse({ totalMonitors: 0, uptimePercent: null, avgLatencyMs: null, nodes: [] });
  if (url.includes('action=dashboard_layout')) return jsonResponse({ catalog: [], tiles: [] });
  if (url.includes('action=websites_overview')) return jsonResponse({ slaGoal: 99.95, monitors: {} });
  if (url.includes('action=session')) return jsonResponse({ authenticated: false });
  if (url.includes('action=get_settings')) return jsonResponse({ settings: {} });
  if (url.includes('action=get_subscriptions')) return jsonResponse({ subscriptions: [] });
  if (url.includes('action=sla_report')) return jsonResponse({ slaGoal: 99.95, overallUptime: null, monitors: [] });
  if (url.includes('action=users')) return jsonResponse({ users: [] });
  if (url.includes('action=audit_logs')) return jsonResponse({ logs: [] });
  if (url.includes('action=notification_log'))
    return jsonResponse({ entries: [], nextCursor: null, kinds: [], channels: [], summary: null });
  if (url.includes('action=discovered_services')) return jsonResponse({ services: [] });
  if (url.includes('action=events')) return jsonResponse({ events: [] });
  if (url.includes('action=daily_uptime')) return jsonResponse({ rows: [] });
  if (url.includes('action=ui_config')) return jsonResponse({ title: 'Blood Kings', navLinks: [] });
  // The router endpoints answer honestly for a monitor that is not a router:
  // an empty list, not an invented recommendation or an invented disk.
  if (url.includes('action=router_recommendations'))
    return jsonResponse({ monitorId: 0, applicable: false, reason: 'not_router', items: [], muted: [] });
  if (url.includes('action=storage_history')) return jsonResponse({ monitorId: 0, days: 90, disks: [] });
  if (url.includes('action=wan_bottleneck'))
    return jsonResponse({
      monitorId: 0,
      generatedAt: '',
      canEdit: false,
      plan: { downMbit: null, upMbit: null, okPct: null },
      probe: { enabledServer: false, state: null, waitSince: null, budgetSpent: false },
      verdict: {
        dl: { class: 'inconclusive', reason: 'no_result' },
        ul: { class: 'inconclusive', reason: 'no_result' },
      },
      tests: [],
      wanPath: null,
      linkDev: null,
      linkMbit: null,
      tools: { librespeedCli: null, ethtool: null, tc: null },
    });
  return jsonResponse({});
};

function renderPage(page: React.ReactElement) {
  return render(
    <LanguageProvider>
      <MemoryRouter>{page}</MemoryRouter>
    </LanguageProvider>
  );
}

const pages: [string, () => React.ReactElement][] = [
  ['Dashboard', () => <DashboardPage />],
  ['Websites', () => <WebsitesPage />],
  ['Incidents', () => <IncidentsPage />],
  ['Services', () => <ServicesPage />],
  ['Infrastructure', () => <InfrastructurePage />],
  ['Insights', () => <InsightsPage />],
  ['Reports', () => <ReportsPage />],
  ['Users', () => <UsersPage />],
  ['ApiAgents', () => <ApiAgentsPage />],
  ['StatusPages', () => <StatusPagesPage />],
  ['Settings', () => <SettingsPage />],
  ['OutgoingMessages', () => <OutgoingMessagesPage />],
  ['NotFound', () => <NotFoundPage />],
];

describe('smoke: stránky se vykreslí', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => Promise.resolve(emptyApi(String(input))))
    );
    // jsdom lacks matchMedia; useChartTheme/reduced-motion need it.
    vi.stubGlobal(
      'matchMedia',
      vi.fn((query: string) => ({
        matches: false,
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
        addListener: () => {},
        removeListener: () => {},
        dispatchEvent: () => false,
        onchange: null,
      }))
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

  for (const [name, factory] of pages) {
    it(`${name}: prázdná data z API`, () => {
      expect(() => renderPage(factory())).not.toThrow();
    });

    it(`${name}: API nedostupné (síťová chyba)`, () => {
      vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.reject(new Error('network down')))
      );
      expect(() => renderPage(factory())).not.toThrow();
    });
  }

  it('Dashboard ukazuje nadpis a poctivé nuly, žádná vymyšlená čísla', async () => {
    renderPage(<DashboardPage />);
    // The Czech side of this title used to read 'Status Overview' (F2).
    expect(await screen.findByText('Přehled stavu')).toBeTruthy();
    // Uptime without data = a dash, never a fictional 100 %.
    expect(screen.queryByText('100.00')).toBeNull();
  });
});
