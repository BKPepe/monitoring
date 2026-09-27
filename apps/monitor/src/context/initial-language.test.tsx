// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { readInitialLanguage } from './initial-language';
import { LanguageProvider, useLanguage } from './language-context';

function memoryStorage(initial: Record<string, string> = {}) {
  const data = new Map(Object.entries(initial));
  return {
    data,
    getItem: (k: string) => data.get(k) ?? null,
    setItem: (k: string, v: string) => void data.set(k, v),
  };
}

describe('Jazyk při prvním načtení (site W1-8)', () => {
  it('?lang=en z odkazu webu vyhraje nad uloženou volbou a uloží se', () => {
    const storage = memoryStorage({ bk_lang: 'cs' });
    expect(readInitialLanguage({ search: '?lang=en', storage })).toBe('en');
    expect(storage.data.get('bk_lang')).toBe('en');
  });

  it('bez parametru platí uložená volba, jinak čeština', () => {
    expect(readInitialLanguage({ search: '', storage: memoryStorage({ bk_lang: 'en' }) })).toBe('en');
    expect(readInitialLanguage({ search: '?page=hry', storage: memoryStorage() })).toBe('cs');
  });

  it('neznámý jazyk v adrese se ignoruje a nic nepřepíše', () => {
    const storage = memoryStorage({ bk_lang: 'en' });
    expect(readInitialLanguage({ search: '?lang=de', storage })).toBe('en');
    expect(storage.data.get('bk_lang')).toBe('en');
  });

  it('zablokované úložiště nic neshodí', () => {
    const blocked = {
      getItem: () => {
        throw new Error('SecurityError');
      },
      setItem: () => {
        throw new Error('SecurityError');
      },
    };
    expect(readInitialLanguage({ search: '?lang=en', storage: blocked })).toBe('en');
    expect(readInitialLanguage({ search: '', storage: blocked })).toBe('cs');
    expect(readInitialLanguage({ search: '', storage: null })).toBe('cs');
  });
});

describe('LanguageProvider čte ?lang při startu', () => {
  afterEach(() => {
    cleanup();
    window.history.replaceState({}, '', '/');
    vi.unstubAllGlobals();
  });

  function Probe() {
    const { lang, t } = useLanguage();
    return <p data-lang={lang}>{t('nav.settings', 'Nastavení')}</p>;
  }

  it('otevře stránku rovnou anglicky, bez českého probliknutí', () => {
    // jsdom here has no localStorage of its own.
    const storage = memoryStorage();
    vi.stubGlobal('localStorage', storage);
    window.history.replaceState({}, '', '/public?lang=en');
    render(
      <LanguageProvider>
        <Probe />
      </LanguageProvider>
    );
    expect(screen.getByText('Settings').getAttribute('data-lang')).toBe('en');
    expect(document.documentElement.lang).toBe('en');
    expect(storage.data.get('bk_lang')).toBe('en');
  });
});
