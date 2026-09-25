// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { Globe } from 'lucide-react';
import { SectionTitle } from './section-title';

afterEach(cleanup);

describe('SectionTitle', () => {
  it('nadpis je h2, ikona je pro čtečku skrytá', () => {
    const { container } = render(<SectionTitle icon={Globe} title="Weby" />);
    expect(screen.getByRole('heading', { level: 2, name: 'Weby' })).toBeTruthy();
    expect(container.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
  });

  it('počet jen když je znám - 0 se ukáže, vynechaný počet ne', () => {
    render(<SectionTitle icon={Globe} title="A" count={0} />);
    expect(screen.getByRole('heading').textContent).toBe('A0');
    cleanup();
    render(<SectionTitle icon={Globe} title="B" />);
    expect(screen.getByRole('heading').textContent).toBe('B');
  });

  it('popisek a akce vpravo', () => {
    render(
      <SectionTitle icon={Globe} title="C" hint="posledních 30 dní" action={<button type="button">Vše</button>} />
    );
    expect(screen.getByText('posledních 30 dní')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Vše' })).toBeTruthy();
  });
});
