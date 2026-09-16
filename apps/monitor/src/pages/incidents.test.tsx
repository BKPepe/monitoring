// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { IncidentsPage } from './incidents';

const json = (body: unknown) => ({ ok: true, status: 200, json: () => Promise.resolve(body) }) as Response;

/** One router down, and the incident the outage lifecycle opened for it. */
const api = (url: string): Response => {
  if (url.includes('action=session')) {
    return json({ authenticated: true, user: { id: 1, username: 'admin', role: 'admin' }, csrfToken: 't' });
  }
  if (url.includes('action=incidents')) {
    return json({
      incidents: [
        {
          id: 900,
          incidentId: 5,
          monitor_id: 7,
          monitor_name: 'Router - Praha',
          target: 'Turris - domov',
          type: 'OPENWRT',
          status: 'open',
          severity: 'down',
          reason: 'Agent routeru neodpovídá',
          started_at: '15.09.2026 08:41:06',
          duration_text: '2 hodin',
          acknowledgedBy: 'admin',
        },
      ],
      manualIncidents: [
        {
          id: 5,
          title: 'Výpadek: Router - Praha',
          status: 'investigating',
          monitorId: 7,
          acknowledgedBy: 'admin',
          createdAt: '15.09.2026 08:41:06',
          durationText: '2 hodin',
          updates: [{ status: 'investigating', message: 'Incident převzal: admin', at: '15.09.2026 08:45:00' }],
        },
      ],
    });
  }
  if (url.includes('action=monitors')) {
    return json({ monitors: [{ id: 7, name: 'Router - Praha', type: 'openwrt', status: 'down' }] });
  }
  if (url.includes('action=public_status')) {
    return json({ totalMonitors: 1, uptimePercent: null, avgLatencyMs: null, nodes: [] });
  }
  return json({});
};

describe('IncidentsPage', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('shows an outage and its incident as one card, with the notes and actions on it', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((url: string) => Promise.resolve(api(String(url))))
    );
    render(
      <LanguageProvider>
        <MemoryRouter>
          <IncidentsPage />
        </MemoryRouter>
      </LanguageProvider>
    );

    const notesButton = await screen.findByRole('button', { name: /poznámky a akce|notes & actions/i });
    // Counted once: the outage row and the incident it opened are one outage.
    expect(screen.getByText(/1 aktivní výpadek|1 active outage/i)).toBeTruthy();
    // No second card titled after the incident.
    expect(screen.queryByText('Výpadek: Router - Praha')).toBeNull();

    fireEvent.click(notesButton);
    const card = notesButton.closest('.rounded-lg') as HTMLElement;
    expect(within(card).getAllByText(/Incident převzal: admin/).length).toBeGreaterThan(0);
    expect(within(card).getByPlaceholderText(/poznámka do timeline|note/i)).toBeTruthy();
    expect(within(card).getByRole('button', { name: /uzavřít incident|resolve/i })).toBeTruthy();
  });
});
