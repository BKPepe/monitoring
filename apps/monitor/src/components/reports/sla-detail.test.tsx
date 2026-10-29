// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { SlaDetail, type MonitorSla, type OutageDetail } from './sla-detail';

afterEach(cleanup);

/**
 * R143: the last outage runs from its first failed check, and a monitor that
 * left 'down' without an 'up' since has no measured end - neither "dosud"
 * (it is not down any more) nor the 0 s it used to get.
 */
const item = (lastOutage: OutageDetail, mttrSec: number | null): MonitorSla => ({
  id: 7,
  name: 'Shop',
  target: 'https://shop.example.com',
  type: 'WEB',
  currentStatus: lastOutage.resolved ? 'warning' : 'down',
  uptimePercent: 99.5,
  outageMinutes: 60,
  totalChecks: 900,
  lastOutage,
  mttrSec,
  lastStatusChange: null,
});

const renderCs = (sla: MonitorSla) =>
  render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter>
          <SlaDetail item={sla} days={30} />
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );

describe('SlaDetail – poslední výpadek (R143)', () => {
  it('vyřešený výpadek bez další kontroly up nemá konec ani délku, ne „dosud“', () => {
    renderCs(
      item({ start: '29.10.2026 10:00:00', end: null, durationSec: null, reason: 'HTTP 503', resolved: true }, null)
    );
    const line = screen.getByText(/29\.10\.2026 10:00:00/);
    expect(line.textContent).toContain('konec nezměřen');
    expect(line.textContent).toContain('(—)');
    expect(line.textContent).not.toContain('dosud');
    expect(line.textContent).not.toContain('0 s');
    // The MTTR cell above it does not claim there was no outage.
    const mttr = screen.getByText('MTTR (doba obnovení)').nextElementSibling;
    expect(mttr?.textContent).toBe('Nezměřeno');
  });

  it('trvající výpadek běží „dosud“ s délkou od začátku', () => {
    renderCs(
      item({ start: '29.10.2026 08:00:00', end: null, durationSec: 10_800, reason: 'HTTP 503', resolved: false }, null)
    );
    const line = screen.getByText(/29\.10\.2026 08:00:00/);
    expect(line.textContent).toContain('dosud');
    expect(line.textContent).toContain('(3 h)');
  });

  it('vyřešený výpadek ukáže konec a celou délku', () => {
    renderCs(
      item(
        {
          start: '29.10.2026 10:00:00',
          end: '29.10.2026 12:00:00',
          durationSec: 7200,
          reason: 'HTTP 503',
          resolved: true,
        },
        7200
      )
    );
    const line = screen.getByText(/29\.10\.2026 10:00:00/);
    expect(line.textContent).toContain('→ 29.10.2026 12:00:00 (2 h)');
    expect(screen.getByText('MTTR (doba obnovení)').nextElementSibling?.textContent).toBe('2 h');
  });
});
