// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { UptimeStrip, type UptimeDay } from './uptime-strip';

/**
 * The public 30-day strip (C-7): every day status the server words has its
 * own paint, an unknown one is never green, and the day detail is reachable
 * from the keyboard, not only by mouse and touch (PA-7).
 */
const day = (d: number, status: string, detail: string): UptimeDay =>
  ({
    date: `${d}. 9.`,
    day: `2026-09-${String(d).padStart(2, '0')}`,
    status,
    uptimePct: status === 'nodata' ? null : 100,
    detail,
  }) as UptimeDay;

const DAYS = [
  day(20, 'up', 'Všech 1440 kontrol prošlo.'),
  day(21, 'warning', 'Zhoršená odezva 30 min.'),
  day(22, 'maintenance', 'Plánovaná údržba.'),
  day(23, 'partial', 'Bez výpadku, ale měřeno jen 14 h z 24 h.'),
  day(24, 'nodata', 'Tento den se neměřilo.'),
  // What an older server sent for an unmeasured day.
  day(25, 'paused', 'Pozastaveno'),
  day(26, 'down', '3 z 1440 kontrol selhaly.'),
];

afterEach(() => cleanup());

function renderStrip() {
  return render(
    <LanguageProvider>
      <UptimeStrip days={DAYS} />
    </LanguageProvider>
  );
}

describe('Veřejný pás dostupnosti (PUB-09)', () => {
  it('každý stav dne má svou barvu; neznámý stav je „bez měření“, nikdy zelený', () => {
    const { container } = renderStrip();
    const statuses = [...container.querySelectorAll('[data-day-status]')].map((el) =>
      el.getAttribute('data-day-status')
    );
    expect(statuses).toEqual(['up', 'warning', 'maintenance', 'partial', 'nodata', 'nodata', 'down']);
    const cell = (i: number) => container.querySelectorAll('[data-day-status]')[i] as HTMLElement;
    expect(cell(1).className).toContain('bg-warning');
    expect(cell(2).className).toContain('bg-info');
    expect(cell(3).className).toContain('hatch');
    expect(cell(5).className).toContain('border-dashed');
    expect(cell(5).className).not.toContain('bg-up');
  });

  it('klávesnicí: fokus ukáže nejnovější den, šipka vlevo předchozí', () => {
    renderStrip();
    const strip = screen.getByRole('group', { name: /Dostupnost po dnech/ });
    expect(strip.getAttribute('tabindex')).toBe('0');
    fireEvent.focus(strip);
    expect(screen.getByText('26. 9. 3 z 1440 kontrol selhaly.')).toBeTruthy();
    fireEvent.keyDown(strip, { key: 'ArrowLeft' });
    expect(screen.getByText('25. 9. Pozastaveno')).toBeTruthy();
    fireEvent.keyDown(strip, { key: 'Home' });
    expect(screen.getByText('20. 9. Všech 1440 kontrol prošlo.')).toBeTruthy();
  });
});
