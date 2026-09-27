// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { RangeMeter, RatioBar } from './meter';
import { MetricGroup } from './metric-group';
import { rateRsrp } from '@/lib/signal-quality';

afterEach(cleanup);

describe('RangeMeter (C-9)', () => {
  it('neměřená hodnota nekreslí výplň na nule a čtečce řekne pomlčku', () => {
    const { container } = render(<RangeMeter min={0} max={100} value={null} label="Disk" />);
    expect(container.querySelector('[data-slot="meter-fill"]')).toBeNull();
    // No meter without a value (aria-valuenow is required): a picture named with a dash (PA-4).
    expect(screen.queryByRole('meter')).toBeNull();
    expect(screen.getByRole('img', { name: 'Disk: —' })).toBeTruthy();
  });

  it('výplň je neutrální, dokud mez není překročena', () => {
    const { container, rerender } = render(<RangeMeter min={0} max={100} value={40} label="Disk" />);
    const fill = () => container.querySelector('[data-slot="meter-fill"]') as HTMLElement;
    expect(fill().className).toContain('bg-muted-foreground');
    expect(fill().className).not.toContain('bg-primary');
    expect(fill().style.width).toBe('40%');
    rerender(<RangeMeter min={0} max={100} value={95} tone="down" label="Disk" />);
    expect(fill().className).toContain('bg-down');
  });

  it('značka stojí na stupnici v jednotkách měření, i záporných (dBm)', () => {
    const rating = rateRsrp(-95);
    expect(rating?.meter.zones.map((z) => z.level)).toEqual(['good', 'fair', 'poor']);
    const { container } = render(
      <RangeMeter mode="marker" min={-120} max={-70} value={-95} zones={rating?.meter.zones} label="RSRP" />
    );
    expect((container.querySelector('[data-slot="meter-marker"]') as HTMLElement).style.left).toBe('50%');
  });
});

describe('RatioBar (C-9)', () => {
  it('díly jsou podílem celku, neměřený díl je v legendě pomlčkou a v pruhu chybí', () => {
    const { container } = render(
      <RatioBar
        label="Jádro 0"
        max={100}
        format={(v) => `${v} %`}
        parts={[
          { key: 'user', label: 'Klient', value: 20 },
          { key: 'packets', label: 'Pakety', value: 50 },
          { key: 'system', label: 'Systém', value: null },
        ]}
      />
    );
    const parts = [...container.querySelectorAll('[data-part]')] as HTMLElement[];
    expect(parts.map((p) => p.style.width)).toEqual(['20%', '50%']);
    expect(parts.some((p) => /bg-(up|down|warning|info|paused|primary)\b/.test(p.className))).toBe(false);
    expect(container.textContent).toContain('Systém —');
  });
});

describe('MetricGroup (C-13)', () => {
  it('nehybné metriky schová za jednu větu se správným tvarem čísla', () => {
    render(
      <LanguageProvider>
        <MetricGroup title="Systém" count={5} notable="Load 1,2" unchangedCount={3} unchanged={<p>skryté</p>}>
          <p>viditelné</p>
        </MetricGroup>
      </LanguageProvider>
    );
    expect(screen.getByText('3 metriky se nezměnily')).toBeTruthy();
    expect(screen.getByText('(5)')).toBeTruthy();
    expect(screen.getByText('Load 1,2')).toBeTruthy();
  });
});
