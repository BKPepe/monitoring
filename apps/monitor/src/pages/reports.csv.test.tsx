// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { ReportsPage } from './reports';

/**
 * The export was a data: URL run through encodeURI, which leaves '#' alone. A
 * browser reads that as the start of the fragment, so the file ended at the
 * first monitor with a '#' in its name, with no sign that rows were missing.
 */
const json = (body: unknown) =>
  ({ ok: true, status: 200, json: () => Promise.resolve(body), text: () => Promise.resolve('') }) as Response;

const row = (id: number, name: string) => ({
  id,
  name,
  target: `https://example.test/${id}`,
  type: 'web',
  currentStatus: 'up',
  uptimePercent: 99.2,
  outageMinutes: 40,
  totalChecks: 900,
  lastOutage: null,
  mttrSec: null,
  lastStatusChange: null,
});

const readText = (blob: Blob) =>
  new Promise<string>((resolve) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.readAsText(blob);
  });

describe('Výkazy: export CSV', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('znak # v názvu soubor neusekne', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) =>
        Promise.resolve(
          String(input).includes('action=sla_report')
            ? json({
                monitors: [row(1, 'Server #1'), row(2, 'Poslední řádek')],
                slaGoal: 99.9,
                overallUptime: 99.2,
                since: '2026-08-12',
                windowStart: '2026-08-25',
              })
            : json({})
        )
      )
    );
    const blobs: Blob[] = [];
    URL.createObjectURL = vi.fn((b: Blob) => {
      blobs.push(b);
      return 'blob:csv';
    });
    URL.revokeObjectURL = vi.fn();
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    render(
      <LanguageProvider>
        <TooltipProvider>
          <MemoryRouter>
            <ReportsPage />
          </MemoryRouter>
        </TooltipProvider>
      </LanguageProvider>
    );
    await screen.findAllByText('Poslední řádek');
    fireEvent.click(screen.getByRole('button', { name: /Exportovat CSV/ }));
    await waitFor(() => expect(click).toHaveBeenCalled());
    const text = await readText(blobs[0]);
    expect(text).toContain('Server #1');
    expect(text).toContain('Poslední řádek');
    expect((click.mock.instances[0] as unknown as HTMLAnchorElement).getAttribute('href')).toBe('blob:csv');
  });
});
