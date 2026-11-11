// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { ProbeLocations } from './probe-locations';

/** R136: the panel counted every place and listed the first nine, silently. */
const places = (n: number) =>
  Array.from({ length: n }, (_, i) => ({ location: `Místo ${i + 1}`, country: null, successRate: 100 }));

afterEach(cleanup);

describe('Místa měření (R136)', () => {
  it('nad devět míst je odkaz na všechna a ten je ukáže', () => {
    render(
      <LanguageProvider>
        <ProbeLocations regions={places(12)} />
      </LanguageProvider>
    );
    expect(screen.getAllByRole('listitem')).toHaveLength(9);
    fireEvent.click(screen.getByRole('button', { name: 'Zobrazit všechna místa (12)' }));
    expect(screen.getAllByRole('listitem')).toHaveLength(12);
    expect(screen.queryByRole('button', { name: /Zobrazit všechna/ })).toBeNull();
  });

  it('do devíti míst žádný odkaz', () => {
    render(
      <LanguageProvider>
        <ProbeLocations regions={places(9)} />
      </LanguageProvider>
    );
    expect(screen.getAllByRole('listitem')).toHaveLength(9);
    expect(screen.queryByRole('button')).toBeNull();
  });
});
