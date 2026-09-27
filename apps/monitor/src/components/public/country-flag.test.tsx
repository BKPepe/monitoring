// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { CountryFlag } from './country-flag';

/**
 * The flags of the measurement places are drawn by the page from the
 * server's ISO code - never the emoji, which Windows prints as two letters.
 */
function renderFlag(code: string | null, lang: 'cs' | 'en' = 'cs') {
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => (key === 'bk_lang' ? lang : null),
    setItem: () => {},
    removeItem: () => {},
  });
  return render(
    <LanguageProvider>
      <CountryFlag code={code} />
    </LanguageProvider>
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Vlajky míst měření (veřejná stránka)', () => {
  it('známá země je obrázek pojmenovaný v jazyce stránky, i z malých písmen', () => {
    renderFlag('de');
    const flag = screen.getByRole('img', { name: 'Německo' });
    expect(flag.tagName.toLowerCase()).toBe('svg');
    expect(flag.getAttribute('data-country')).toBe('DE');
    cleanup();
    renderFlag('DE', 'en');
    expect(screen.getByRole('img', { name: 'Germany' })).toBeTruthy();
  });

  it('země mimo tabulku dostane kód v rámečku, ne cizí ani vymyšlenou vlajku', () => {
    renderFlag('KE');
    const box = screen.getByRole('img', { name: 'Keňa' });
    expect(box.tagName.toLowerCase()).toBe('span');
    expect(box.textContent).toBe('KE');
  });

  it('bez země nic: null, prázdno i nesmysl', () => {
    const { container } = renderFlag(null);
    expect(container.innerHTML).toBe('');
    cleanup();
    expect(renderFlag('Frankfurt').container.innerHTML).toBe('');
  });

  it('každá kolokace Workeru má svou kreslenou vlajku, ne rámeček s kódem', () => {
    // The countries of apps/status/cloudflare_colos.php (the Worker's COLO map).
    const colos =
      'US DE IN CA BR RO MX JP GB FR ES AU ZA TW TR TH SG SE SA RS PT PL PK PH PE NO NL KR IT IL IE HK CZ CL CH BG BE AT AR';
    const boxed = colos.split(' ').filter((cc) => {
      const { container } = renderFlag(cc);
      const drawn = container.querySelector(`svg[data-country="${cc}"]`) !== null;
      cleanup();
      return !drawn;
    });
    expect(boxed).toEqual([]);
  });
});
