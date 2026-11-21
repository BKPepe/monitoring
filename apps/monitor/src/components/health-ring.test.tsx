// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import type * as React from 'react';
import { LanguageProvider } from '@/context/language-context';
import { HealthBreakdown, HealthRing } from './health-ring';

afterEach(cleanup);

const renderCs = (ui: React.ReactElement) => render(<LanguageProvider>{ui}</LanguageProvider>);

describe('HealthRing: skóre ze serveru, nic domyšleného', () => {
  it('skóre 92: číslo, dobré pásmo, zelený oblouk a jedna věta pro čtečku', () => {
    const { container } = renderCs(<HealthRing score={92} grade="good" caption="Zdraví sítě" />);
    expect(screen.getByRole('img', { name: 'Zdraví sítě: 92 ze 100, Dobré' })).toBeTruthy();
    const ring = container.querySelector('[data-slot="health-ring"]') as HTMLElement;
    expect(ring.dataset.grade).toBe('good');
    const circles = container.querySelectorAll('circle');
    expect(circles).toHaveLength(2);
    expect(circles[1].getAttribute('class')).toContain('text-up');
    expect(screen.getByText('Dobré')).toBeTruthy();
  });

  it('bez známky ji dopočítá stejnými pásmy jako server (70 = ucházející)', () => {
    const { container } = renderCs(<HealthRing score={70} />);
    expect((container.querySelector('[data-slot="health-ring"]') as HTMLElement).dataset.grade).toBe('fair');
    expect(container.querySelectorAll('circle')[1].getAttribute('class')).toContain('text-warning');
  });

  it('nedostatek dat: pomlčka, jen stopa bez oblouku, nikdy zelená ani nula', () => {
    const { container } = renderCs(<HealthRing score={null} caption="Zdraví routeru" />);
    expect(screen.getByRole('img', { name: 'Zdraví routeru: nedostatek dat' })).toBeTruthy();
    expect(container.querySelectorAll('circle')).toHaveLength(1);
    expect(container.textContent).toContain('—');
    expect(container.textContent).not.toMatch(/\b0\b/);
    expect(container.querySelector('.text-up')).toBeNull();
  });

  it('délka oblouku odpovídá skóre', () => {
    const { container } = renderCs(<HealthRing score={25} grade="poor" size="lg" />);
    const arc = container.querySelectorAll('circle')[1];
    const length = Number(arc.getAttribute('stroke-dasharray'));
    const offset = Number(arc.getAttribute('stroke-dashoffset'));
    expect(offset / length).toBeCloseTo(0.75, 5);
    expect(arc.getAttribute('class')).toContain('text-down');
  });

  it('malý kroužek (hlavička, karta routeru) bez štítku známky', () => {
    renderCs(<HealthRing score={98} size="sm" />);
    expect(screen.queryByText('Dobré')).toBeNull();
    expect(screen.getByRole('img', { name: 'Skóre zdraví: 98 ze 100, Dobré' })).toBeTruthy();
  });
});

describe('HealthBreakdown: z čeho skóre je', () => {
  const components = [
    { key: 'availability', label: 'Dostupnost', weight: 30, points: 98, deduction: 0.6 },
    { key: 'latency', label: 'Odezva', weight: 10, points: 60, deduction: 4.4 },
    { key: 'temperature', label: 'Teploty', weight: 10, points: null, deduction: null },
    { key: 'disk', weight: 10, points: null },
  ];

  it('pruhy od nejhorší složky, u každé body a v titulku váha a srážka', () => {
    renderCs(<HealthBreakdown components={components} />);
    const items = screen.getAllByRole('listitem');
    expect(items[0].textContent).toContain('Odezva');
    expect(items[0].getAttribute('title')).toBe('Váha 10 ze 100 · ubírá 4,4 b.');
    expect(items[1].textContent).toContain('Dostupnost');
  });

  it('neměřené složky jsou vyjmenované jako vynechané, ne jako plný počet bodů', () => {
    renderCs(<HealthBreakdown components={components} />);
    expect(screen.getByText('Neměřeno, do skóre nepočítáno: Teploty, Disky')).toBeTruthy();
    expect(screen.getAllByRole('listitem')).toHaveLength(2);
  });

  it('kompaktní řádek pod kroužkem: jméno, tečka pásma, body', () => {
    renderCs(<HealthBreakdown components={components} variant="compact" />);
    const list = screen.getByRole('list', { name: 'Složky skóre' });
    expect(list.textContent).toContain('Odezva60');
    expect(list.textContent).toContain('Dostupnost98');
  });
});
