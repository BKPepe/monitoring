// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import type { TimelineEvent } from '@/data/model';
import { CollapsedTimeline } from './timeline';

afterEach(cleanup);

const renderCs = (ui: React.ReactElement) => render(<LanguageProvider>{ui}</LanguageProvider>);

const ok = (id: number): TimelineEvent => ({
  id,
  title: 'Kontrola proběhla v pořádku',
  detail: 'Kontrola proběhla v pořádku.',
  at: `22.9. 19:${String(id).padStart(2, '0')}`,
  atIso: `2026-09-22T19:${String(id).padStart(2, '0')}:00`,
  severity: 'info',
  resolution: 'Info',
  kind: 'check',
});

describe('CollapsedTimeline (C-10)', () => {
  it('sto úspěšných kontrol je jeden řádek, ne sto', () => {
    const events = Array.from({ length: 30 }, (_, i) => ok(i + 10));
    const { container } = renderCs(<CollapsedTimeline events={events} />);
    expect(container.querySelectorAll('ol > li[data-run]')).toHaveLength(1);
    expect(screen.getByText(/30× za sebou/)).toBeTruthy();
  });

  it('běh výpadků řekne slovem, že jde o výpadek, i tečkou pro čtečku (V-01)', () => {
    const down = (id: number): TimelineEvent => ({
      id,
      title: 'E-shop',
      detail: 'HTTP 503',
      at: '',
      atIso: `2026-09-22T19:0${id}:00`,
      severity: 'down',
    });
    const { container } = renderCs(<CollapsedTimeline events={[down(2), down(1)]} />);
    expect(container.querySelector('li[data-run] summary')?.textContent).toMatch(/E-shop · Výpadek · 2× za sebou/);
    expect(screen.getByRole('img', { name: 'Výpadek' })).toBeTruthy();
  });

  it('výpadek, který prokazatelně trvá, řekne „Probíhá“ jednou, i když je složený do běhu', () => {
    const down = (id: number, ongoing = false): TimelineEvent => ({
      id,
      title: 'E-shop',
      detail: 'HTTP 503',
      at: '',
      atIso: `2026-09-22T19:0${id}:00`,
      severity: 'down',
      resolution: 'Open',
      episode: '7:open',
      ongoing,
    });
    const { container } = renderCs(<CollapsedTimeline events={[down(3, true), down(2), down(1)]} />);
    expect(screen.getAllByText('Probíhá')).toHaveLength(1);
    expect(container.querySelector('li[data-run] summary')?.textContent).toContain('Probíhá');
  });

  it('tělo shodné s titulkem a odznak, který jen opakuje tečku, se nekreslí', () => {
    renderCs(
      <CollapsedTimeline
        events={[
          { ...ok(1), kind: undefined },
          { id: 2, title: 'Výpadek služby', detail: 'HTTP 503', at: '', severity: 'down', resolution: 'Open' },
        ]}
      />
    );
    expect(screen.queryByText('Kontrola proběhla v pořádku.')).toBeNull();
    expect(screen.queryByText('Open')).toBeNull();
    expect(screen.queryByText('Probíhá')).toBeNull();
    expect(screen.getByText('HTTP 503')).toBeTruthy();
  });

  it('odznak, který něco dodává, je přeložený', () => {
    renderCs(
      <CollapsedTimeline
        events={[{ id: 1, title: 'Výpadek služby', detail: 'x', at: '', severity: 'down', resolution: 'Resolved' }]}
      />
    );
    expect(screen.getByText('Vyřešeno')).toBeTruthy();
    expect(screen.queryByText('Resolved')).toBeNull();
  });

  it('s filtry otevře na změnách a rutinu ukáže až pod „Vše“', () => {
    const down: TimelineEvent = {
      id: 99,
      title: 'Výpadek služby',
      detail: 'HTTP 503',
      at: '',
      atIso: '2026-09-22T20:00:00',
      severity: 'down',
      kind: 'check',
    };
    renderCs(<CollapsedTimeline filters events={[down, ok(1), ok(2), ok(3)]} />);
    expect(screen.getByRole('button', { name: /Změny/ }).getAttribute('aria-pressed')).toBe('true');
    expect(screen.queryByText(/3× za sebou/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /Vše/ }));
    expect(screen.getByText(/3× za sebou/)).toBeTruthy();
  });
});
