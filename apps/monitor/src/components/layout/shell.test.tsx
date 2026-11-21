// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import type * as React from 'react';
import { LanguageProvider } from '@/context/language-context';
import { ADMIN_NAV, PRIMARY_NAV, isActivePath, routeMeta } from './nav-config';
import { attentionCount, readFindingsSummary } from './use-shell-counts';
import { TabBar } from './tab-bar';
import { Sidebar } from './sidebar';
import { ShellProvider, usePageChrome, useShellChrome } from './shell-context';

const json = (body: unknown) => ({ ok: true, status: 200, json: () => Promise.resolve(body) }) as Response;

beforeEach(() => {
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  );
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve(json({})))
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const at = (path: string, ui: React.ReactElement) =>
  render(
    <LanguageProvider>
      <MemoryRouter initialEntries={[path]}>{ui}</MemoryRouter>
    </LanguageProvider>
  );

describe('Mapa míst aplikace (nav-config)', () => {
  it('každý klíč navigace a názvu stránky je ve slovníku', () => {
    // t(item.labelKey) is not a literal the i18n test can see, so this does it.
    const dictionary = readFileSync(join(__dirname, '../../context/language-context.tsx'), 'utf8');
    const keys = [
      ...[...PRIMARY_NAV, ...ADMIN_NAV].map((i) => i.labelKey),
      ...['/infrastructure/1', '/infrastructure/1/metric/2/cpu', '/incidents/checks', '/profile'].map(
        (p) => routeMeta(p)!.titleKey
      ),
    ];
    for (const key of keys) expect(dictionary, key).toContain(`  '${key}': {`);
  });

  it('telefonní lišta má přesně tři místa: Přehled, Infrastruktura, Upozornění', () => {
    expect(PRIMARY_NAV.filter((i) => i.tab).map((i) => i.to)).toEqual(['/', '/infrastructure', '/insights']);
  });

  it('šipka zpět vede o úroveň výš v aplikaci, stránka první úrovně ji nemá', () => {
    expect(routeMeta('/')?.parent).toBeNull();
    expect(routeMeta('/insights')?.parent).toBeNull();
    expect(routeMeta('/infrastructure/42')?.parent).toBe('/infrastructure');
    expect(routeMeta('/infrastructure/42/metric/7/cpu_pct')?.parent).toBe('/infrastructure/42');
    expect(routeMeta('/incidents/checks')?.parent).toBe('/incidents');
    expect(routeMeta('/infrastructure/42/')?.parent).toBe('/infrastructure');
    expect(routeMeta('/neexistuje')).toBeNull();
  });

  it('přehled je aktivní jen na „/“, ostatní i na svých podstránkách', () => {
    expect(isActivePath('/', '/')).toBe(true);
    expect(isActivePath('/', '/infrastructure')).toBe(false);
    expect(isActivePath('/infrastructure', '/infrastructure/3')).toBe(true);
    expect(isActivePath('/incidents', '/incidents-x')).toBe(false);
  });
});

describe('Souhrn upozornění pro zvonek (findings&summary=1)', () => {
  it('platný souhrn: počty podle závažnosti; chybějící závažnost po platném total je 0', () => {
    expect(readFindingsSummary({ total: 3, counts: { critical: 1, warning: 2 }, sourceErrors: [] })).toEqual({
      counts: { critical: 1, warning: 2, info: 0 },
      incomplete: false,
    });
  });

  it('cokoli jiného než souhrn je „neznámo“, ne nula', () => {
    expect(readFindingsSummary({ readUpToId: 0 })).toBeNull();
    expect(readFindingsSummary({})).toBeNull();
    expect(readFindingsSummary(null)).toBeNull();
    expect(readFindingsSummary({ total: '3', counts: {} })).toBeNull();
  });

  it('selhaný zdroj: počty platí jako spodní mez, souhrn je neúplný', () => {
    expect(
      readFindingsSummary({ total: 1, counts: { warning: 1 }, sourceErrors: [{ source: 'router', error: 'x' }] })
        ?.incomplete
    ).toBe(true);
  });

  it('k řešení jsou kritická a varování, informace ne', () => {
    expect(attentionCount({ critical: 1, warning: 2, info: 9 })).toBe(3);
    expect(attentionCount(null)).toBeNull();
  });
});

describe('Spodní lišta na telefonu', () => {
  it('tři místa + Více; aktivní má aria-current, počet upozornění i slovy', () => {
    at('/infrastructure/3', <TabBar findings={{ critical: 0, warning: 2, info: 0 }} onMore={() => {}} />);
    const nav = screen.getByRole('navigation', { name: 'Hlavní sekce' });
    const links = within(nav).getAllByRole('link');
    expect(links.map((l) => l.getAttribute('href'))).toEqual(['/', '/infrastructure', '/insights']);
    expect(links[1].getAttribute('aria-current')).toBe('page');
    expect(links[2].getAttribute('aria-label')).toBe('Upozornění. Upozornění k řešení: 2');
    expect(within(nav).getByRole('button', { name: 'Více' }).getAttribute('aria-current')).toBeNull();
    expect(nav.className).toContain('lg:hidden');
  });

  it('na stránce mimo tři místa je aktuální „Více“; klik otevře zásuvku', () => {
    const onMore = vi.fn();
    at('/settings', <TabBar onMore={onMore} />);
    const more = screen.getByRole('button', { name: 'Více' });
    expect(more.getAttribute('aria-current')).toBe('page');
    fireEvent.click(more);
    expect(onMore).toHaveBeenCalledOnce();
  });
});

describe('Postranní panel', () => {
  it('počty: otevřené incidenty a upozornění k řešení, oba i slovy', () => {
    at(
      '/',
      <Sidebar
        collapsed={false}
        onToggle={() => {}}
        incidentCount={2}
        findings={{ critical: 1, warning: 0, info: 5 }}
      />
    );
    expect(screen.getByText('Otevřené incidenty: 2')).toBeTruthy();
    expect(screen.getByText('Upozornění k řešení: 1')).toBeTruthy();
    expect(screen.getByRole('link', { name: /Přehled/ }).getAttribute('aria-current')).toBe('page');
  });

  it('sbalená lišta: jméno položky i s počtem je v aria-label, přepínač říká, co udělá', () => {
    at('/', <Sidebar collapsed onToggle={() => {}} incidentCount={3} />);
    expect(screen.getByRole('link', { name: 'Incidenty. Otevřené incidenty: 3' })).toBeTruthy();
    const toggle = screen.getByRole('button', { name: 'Rozbalit navigaci' });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
  });

  it('přepínač jazyka hlásí stisknutý stav', () => {
    at('/', <Sidebar collapsed={false} onToggle={() => {}} />);
    const group = screen.getByRole('group', { name: 'Jazyk' });
    expect(within(group).getByRole('button', { name: 'Čeština' }).getAttribute('aria-pressed')).toBe('true');
    expect(within(group).getByRole('button', { name: 'English' }).getAttribute('aria-pressed')).toBe('false');
  });

  it('téma je jedno pro celou stránku: přepnutí v jedné liště převrátí i druhou', () => {
    at(
      '/',
      <>
        <Sidebar collapsed={false} onToggle={() => {}} />
        <Sidebar collapsed onToggle={() => {}} />
      </>
    );
    const [first] = screen.getAllByRole('button', { name: 'Přepnout na světlý motiv' });
    fireEvent.click(first);
    expect(screen.getAllByRole('button', { name: 'Přepnout na tmavý motiv' })).toHaveLength(2);
    fireEvent.click(screen.getAllByRole('button', { name: 'Přepnout na tmavý motiv' })[1]);
    expect(screen.getAllByRole('button', { name: 'Přepnout na světlý motiv' })).toHaveLength(2);
  });
});

describe('Stránka mluví s hlavičkou (usePageChrome)', () => {
  function Probe() {
    const chrome = useShellChrome();
    return (
      <p data-testid="probe">
        {chrome.title ?? '-'}|{chrome.freshness ? 'fresh' : 'none'}|{chrome.onRefresh ? 'refresh' : 'none'}
      </p>
    );
  }
  function Page({ onRefresh }: { onRefresh: () => void }) {
    usePageChrome({ title: 'Turris Omnia', freshness: { at: 1, intervalSecs: 60 }, onRefresh });
    return null;
  }

  it('stránka zaregistruje název, čerstvost a obnovení; po odchodu po ní nic nezůstane', () => {
    const onRefresh = vi.fn();
    const { rerender } = render(
      <ShellProvider>
        <Probe />
        <Page onRefresh={onRefresh} />
      </ShellProvider>
    );
    expect(screen.getByTestId('probe').textContent).toBe('Turris Omnia|fresh|refresh');
    rerender(
      <ShellProvider>
        <Probe />
      </ShellProvider>
    );
    expect(screen.getByTestId('probe').textContent).toBe('-|none|none');
  });

  it('obnovení volá vždy nejnovější handler stránky', () => {
    const first = vi.fn();
    const second = vi.fn();
    let call: (() => unknown) | undefined;
    function Grab() {
      call = useShellChrome().onRefresh;
      return null;
    }
    const { rerender } = render(
      <ShellProvider>
        <Grab />
        <Page onRefresh={first} />
      </ShellProvider>
    );
    rerender(
      <ShellProvider>
        <Grab />
        <Page onRefresh={second} />
      </ShellProvider>
    );
    act(() => {
      call?.();
    });
    expect(first).not.toHaveBeenCalled();
    expect(second).toHaveBeenCalledOnce();
  });

  it('mimo shell (veřejná stránka, testy) nedělá nic', () => {
    function Lone() {
      usePageChrome({ title: 'x' });
      return <p>ok</p>;
    }
    render(<Lone />);
    expect(screen.getByText('ok')).toBeTruthy();
  });
});
