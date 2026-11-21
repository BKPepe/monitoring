// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { StatBlock, breachTone } from './stat-block';
import { OverflowMenu } from './ui/overflow-menu';

afterEach(cleanup);

const renderCs = (ui: React.ReactElement) => render(<LanguageProvider>{ui}</LanguageProvider>);

describe('StatBlock (C-1)', () => {
  it('neměřenou hodnotu ukáže jako pomlčku a jednotku vynechá', () => {
    const { container } = renderCs(<StatBlock label="CPU" value={null} secondary="%" />);
    expect(container.querySelector('.text-2xl')?.textContent).toBe('—');
  });

  it('hodnotu píše barvou popředí strojovým písmem s číslicemi stejné šířky, pokud mez není překročena', () => {
    const { container } = renderCs(<StatBlock label="CPU" value="12,4" secondary="%" />);
    const value = container.querySelector('.text-2xl') as HTMLElement;
    expect(value.className).not.toMatch(/text-(up|down|warning|chart)/);
    // apps/site DESIGN.md: figures are mono and tabular, like main's MetricTile.
    expect(value.className).toContain('font-mono');
    expect(value.className).toContain('tabular-nums');
  });

  it('překročenou mez obarví stavovou barvou', () => {
    const { container } = renderCs(<StatBlock label="CPU" value="95" tone="down" />);
    expect(container.querySelector('[data-tone="down"]')?.className).toContain('text-down');
  });

  it('„běží“ není překročení - breachTone nechá hodnotu neutrální', () => {
    expect(breachTone('up')).toBeNull();
    expect(breachTone('warning')).toBe('warning');
    expect(breachTone(null)).toBeNull();
  });

  it('trend bez překročené meze je tlumený, s překročením červený', () => {
    const { container, rerender } = renderCs(
      <StatBlock label="CPU" value="14" delta={{ value: 2, unit: 'pp', direction: 'up', tone: 'neutral' }} />
    );
    const chip = () => container.querySelector('[data-tone="neutral"], [data-tone="bad"]') as HTMLElement;
    expect(chip().textContent).toBe('↑ 2 p. b.');
    expect(chip().className).toContain('text-muted-foreground');
    rerender(
      <LanguageProvider>
        <StatBlock label="CPU" value="85" delta={{ value: 15, unit: 'pp', direction: 'up', tone: 'bad' }} />
      </LanguageProvider>
    );
    expect(chip().className).toContain('text-down');
  });

  it('během načítání ukáže zástupný pruh, ne nulu ani popisek', () => {
    renderCs(<StatBlock label="Výpadky" value={0} hint="Bez výpadku" loading />);
    expect(screen.getByTestId('stat-block-skeleton')).toBeTruthy();
    expect(screen.queryByText('0')).toBeNull();
    expect(screen.queryByText('Bez výpadku')).toBeNull();
  });

  it('vlastní řadu nakreslí jako průběh v čase', () => {
    const points = [0, 1, 2, 3].map((i) => ({ t: i * 60_000, v: 10 + i }));
    const { container } = renderCs(
      <StatBlock
        label="CPU"
        value="13"
        sparkline={{ points, tone: 'cpu', unit: '%', window: { from: 0, to: 3 * 60_000 } }}
      />
    );
    // main's sparkline strokes in currentColor under the metric's text class.
    expect(container.querySelector('svg.text-chart-cpu polyline')).toBeTruthy();
  });
});

describe('OverflowMenu', () => {
  it('otevře nabídku, Escape ji zavře a vrátí fokus na tlačítko', () => {
    renderCs(<OverflowMenu label="Akce grafu" items={[{ label: 'Uložit PNG', onSelect: () => {} }]} />);
    const button = screen.getByRole('button', { name: 'Akce grafu' });
    fireEvent.click(button);
    expect(button.getAttribute('aria-expanded')).toBe('true');
    const item = screen.getByRole('menuitem', { name: 'Uložit PNG' });
    expect(document.activeElement).toBe(item);
    fireEvent.keyDown(item, { key: 'Escape' });
    expect(screen.queryByRole('menu')).toBeNull();
    expect(document.activeElement).toBe(button);
  });

  it('položka spustí akci a nabídku zavře', () => {
    const onSelect = vi.fn();
    renderCs(<OverflowMenu label="Akce grafu" items={[{ label: 'Export CSV', onSelect }]} />);
    fireEvent.click(screen.getByRole('button', { name: 'Akce grafu' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Export CSV' }));
    expect(onSelect).toHaveBeenCalledOnce();
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('šipky procházejí položky dokola', () => {
    renderCs(
      <OverflowMenu
        label="Akce"
        items={[
          { label: 'A', onSelect: () => {} },
          { label: 'B', onSelect: () => {} },
        ]}
      />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Akce' }));
    const a = screen.getByRole('menuitem', { name: 'A' });
    fireEvent.keyDown(a, { key: 'ArrowDown' });
    expect(document.activeElement).toBe(screen.getByRole('menuitem', { name: 'B' }));
    fireEvent.keyDown(document.activeElement as HTMLElement, { key: 'ArrowDown' });
    expect(document.activeElement).toBe(a);
  });
});
