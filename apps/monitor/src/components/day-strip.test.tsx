// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import type { DayUptime } from '@/data/model';
import { DayStrip, DayStripLegend, dayCellStatus } from './day-strip';

afterEach(cleanup);

const renderCs = (ui: React.ReactElement) =>
  render(
    <LanguageProvider>
      <MemoryRouter>{ui}</MemoryRouter>
    </LanguageProvider>
  );

const day = (status: string, extra: Partial<DayUptime> = {}): DayUptime => ({
  date: '22.9.',
  status: status as DayUptime['status'],
  uptimePct: null,
  ...extra,
});

describe('DayStrip (C-7)', () => {
  it('neznámý stav včetně starého „paused“ kreslí jako neměřený den, nikdy jako zelený', () => {
    expect(dayCellStatus('paused')).toBe('nodata');
    expect(dayCellStatus(undefined)).toBe('nodata');
    expect(dayCellStatus('partial')).toBe('partial');
  });

  it('den měřený jen zčásti je šrafovaný, den bez měření čárkovaný', () => {
    const { container } = renderCs(<DayStrip days={[day('partial'), day('nodata')]} label="E-shop" />);
    const cells = container.querySelectorAll('[data-day-status]');
    expect(cells[0].className).toContain('hatch');
    expect(cells[1].className).toContain('border-dashed');
  });

  it('den měřený jen zčásti řekne pokrytí i ve jméně buňky, ne jen „100 %“', () => {
    const { container } = renderCs(
      <DayStrip
        days={[day('partial', { uptimePct: 100, coveragePct: 58.3, measuredSecs: 50_400, expectedSecs: 86_400 })]}
        label="E-shop"
      />
    );
    expect(container.querySelector('[data-day-status="partial"]')?.getAttribute('aria-label')).toMatch(
      /Měřeno jen zčásti · 100 % · měřeno 14 h z 24 h$/
    );
  });

  it('odznak v detailu má barvu stavu dne, ne procenta (charts-26)', () => {
    const { container } = renderCs(
      <DayStrip
        days={[day('warning', { uptimePct: 100, degradedMin: 30, detail: 'Zhoršená odezva ve 3 kontrolách.' })]}
        label="E-shop"
      />
    );
    fireEvent.mouseEnter(container.querySelector('[data-day-status]') as HTMLElement);
    const tip = screen.getByRole('tooltip');
    expect(tip.querySelector('.text-warning')?.textContent).toBe('Zhoršená odezva');
    expect(tip.textContent).toContain('Dostupnost 100 %');
    expect(tip.textContent).toContain('zhoršeno 30 min');
    expect(tip.textContent).toContain('Zhoršená odezva ve 3 kontrolách.');
  });

  it('výpadkový den s dírou v měření řekne, kolik z něj se měřilo', () => {
    const { container } = renderCs(
      <DayStrip
        days={[day('down', { uptimePct: 90, coveragePct: 50, measuredSecs: 12 * 3600, expectedSecs: 24 * 3600 })]}
        label="E-shop"
      />
    );
    fireEvent.mouseEnter(container.querySelector('[data-day-status]') as HTMLElement);
    expect(screen.getByRole('tooltip').textContent).toContain('měřeno 12 h z 24 h');
  });

  it('v režimu popisku píše detail pod pás a klepnutí nepropadne do tlačítka karty', () => {
    const { container } = renderCs(
      <button type="button" onClick={() => (document.body.dataset.opened = '1')}>
        <DayStrip days={[day('up', { uptimePct: 100 })]} label="E-shop" detail="caption" size="sm" nested />
      </button>
    );
    fireEvent.click(container.querySelector('[data-day-status]') as HTMLElement);
    expect(document.body.dataset.opened).toBeUndefined();
    expect(container.textContent).toContain('22.9. · Bez výpadku · 100 %');
  });

  it('pás bez odkazů se dá projít klávesnicí: fokus ukáže nejnovější den, šipky se posouvají (PA-7)', () => {
    const days = [
      day('up', { date: '20.9.', uptimePct: 100 }),
      day('down', { date: '21.9.', uptimePct: 97.5, downMin: 36 }),
      day('warning', { date: '22.9.', uptimePct: 100 }),
    ];
    renderCs(<DayStrip days={days} detail="caption" size="sm" label="Web" />);
    const strip = screen.getByRole('group');
    expect(strip.getAttribute('tabindex')).toBe('0');
    fireEvent.focus(strip);
    const caption = strip.parentElement?.querySelector('[aria-live]') as HTMLElement;
    expect(caption.textContent).toContain('22.9.');
    fireEvent.keyDown(strip, { key: 'ArrowLeft' });
    expect(caption.textContent).toContain('21.9.');
    fireEvent.keyDown(strip, { key: 'Home' });
    expect(caption.textContent).toContain('20.9.');
    fireEvent.blur(strip);
    expect(caption.textContent?.trim()).toBe('');
  });

  it('pás uvnitř jiného ovládacího prvku fokus nebere, pás s odkazy ho nechá buňkám', () => {
    const { unmount } = renderCs(<DayStrip days={[day('up')]} detail="caption" label="Web" nested />);
    expect(screen.getByRole('group').hasAttribute('tabindex')).toBe(false);
    unmount();
    renderCs(<DayStrip days={[day('up')]} href="/infrastructure/1" label="Web" />);
    expect(screen.getByRole('group').hasAttribute('tabindex')).toBe(false);
    expect(screen.getByRole('link')).toBeTruthy();
  });

  it('legenda jmenuje všech šest druhů dne', () => {
    const { container } = renderCs(<DayStripLegend />);
    expect(container.querySelectorAll('li')).toHaveLength(6);
    expect(container.textContent).toContain('Měřeno jen zčásti');
  });
});
