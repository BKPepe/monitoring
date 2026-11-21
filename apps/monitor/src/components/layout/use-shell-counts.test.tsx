// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, renderHook, waitFor } from '@testing-library/react';
import { useShellCounts } from './use-shell-counts';

const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

function serve(incidents: () => Response) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) =>
      Promise.resolve(String(input).includes('action=incidents') ? incidents() : json({}))
    )
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('odznak incidentů počítá jako stránka incidentů', () => {
  it('ohlášený incident bez výpadku odznak ukáže', async () => {
    serve(() => json({ incidents: [], manualIncidents: [{ id: 7, status: 'investigating' }] }));
    const { result } = renderHook(() => useShellCounts());
    await waitFor(() => expect(result.current.incidentsState).toBe('ok'));
    expect(result.current.openIncidents).toBe(1);
  });

  it('odpověď bez seznamu záznamů je selhání, ne nula', async () => {
    serve(() => json({ incidents: [] }));
    const { result } = renderHook(() => useShellCounts());
    await waitFor(() => expect(result.current.incidentsState).toBe('failed'));
  });
});
