// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { setCsrfToken } from '@/api/app-api';
import type { WanBottleneckResponse } from '@/api/types';
import omnia from '@/api/omnia-router.fixture';
import { WanBottleneckCard } from './wan-bottleneck-card';

// The tests' history chart is echarts on a canvas, which jsdom lacks; the mock
// keeps what it was handed, so a test can check that each test is a bar.
const charts: { bars?: boolean; points: number }[] = [];
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: ({ data, bars }: { data: { series: { points: unknown[] }[] }; bars?: boolean }) => {
    charts.push({ bars, points: data.series[0].points.length });
    return <div data-testid="speed-chart" />;
  },
}));

const json = (body: unknown, ok = true, status = 200) =>
  ({ ok, status, json: () => Promise.resolve(body) }) as Response;

/** Serves `answer` for the card (and `history` for its tests) and records every plan POST. */
function stubApi(answer: () => unknown, saveOk = true, history: unknown = {}) {
  const posts: Record<string, unknown>[] = [];
  const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input);
    if (url.includes('action=wan_settings_save')) {
      posts.push(JSON.parse(String(init?.body)));
      return Promise.resolve(saveOk ? json({ ok: true, plan: omnia.wanBottleneck.plan }) : json({}, false, 500));
    }
    if (url.includes('action=wan_bottleneck')) return Promise.resolve(json(answer()));
    if (url.includes('action=speedtest_history')) return Promise.resolve(json(history));
    return Promise.resolve(json({}));
  });
  vi.stubGlobal('fetch', fetchMock);
  return { posts, fetchMock };
}

function renderCard() {
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <WanBottleneckCard monitorId={6} />
      </TooltipProvider>
    </LanguageProvider>
  );
}

const base = omnia.wanBottleneck;

/** The plan form lives in a dialog behind "Upravit tarif" (W2-3). */
async function openPlan() {
  fireEvent.click(await screen.findByRole('button', { name: /Upravit tarif|Zadat tarif/ }));
}

/** The fixture with one direction's verdict replaced - the server is the only classifier. */
function withVerdict(dl: WanBottleneckResponse['verdict']['dl'], extra: Partial<WanBottleneckResponse> = {}) {
  return { ...base, verdict: { ...base.verdict, dl }, ...extra };
}

describe('WanBottleneckCard', () => {
  beforeEach(() => setCsrfToken('t'));

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    setCsrfToken(null);
  });

  it('a failed request shows the error and never claims the line is fine', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(json({ error: 'boom' }, false, 500)))
    );
    renderCard();
    // The verdict and the tests fail on their own, and each says so.
    const alerts = await screen.findAllByRole('alert');
    expect(alerts.map((a) => a.textContent).join(' ')).toContain('Vyhodnocení linky se nepodařilo načíst.');
    expect(alerts.map((a) => a.textContent).join(' ')).toContain('Naměřené rychlosti se nepodařilo načíst.');
    expect(screen.queryByText('Bez omezení')).toBeNull();
  });

  it('a 200 that is not the documented shape is an error too, not an empty card', async () => {
    stubApi(() => ({ monitorId: 6 }));
    renderCard();
    expect((await screen.findByRole('alert')).textContent).toContain('Vyhodnocení linky se nepodařilo načíst.');
  });

  it('no result yet: says so instead of drawing an empty verdict', async () => {
    stubApi(() => ({
      ...base,
      tests: [],
      verdict: {
        dl: { class: 'inconclusive', reason: 'no_result' },
        ul: { class: 'inconclusive', reason: 'no_result' },
      },
    }));
    renderCard();
    expect(await screen.findAllByText('Zatím žádné měření.')).toHaveLength(2);
    expect(screen.getAllByText(/Zatím není žádný použitelný výsledek měření/)).toHaveLength(2);
  });

  it('inconclusive carries its reason, not just the word', async () => {
    stubApi(() => base);
    renderCard();
    // The Omnia's download: a Turris-started test, so nobody can say who held it back.
    expect(await screen.findByText(/nejsou data o vytížení jader/)).toBeTruthy();
    expect(screen.getByText('Neprůkazné')).toBeTruthy();
    // The upload reached the plan.
    expect(screen.getByText('Bez omezení')).toBeTruthy();
    expect(screen.getByText(/Měření dosáhlo tarifu/)).toBeTruthy();
  });

  it('without a plan nothing is called slow, and the bar has no zero mark', async () => {
    stubApi(() => omnia.wanBottleneckNoPlan);
    renderCard();
    expect(await screen.findAllByText(/Není zadaná rychlost tarifu/)).toHaveLength(2);
    expect(screen.queryByText('Tarif')).toBeNull();
    expect(screen.queryByText('0 Mbit/s')).toBeNull();
  });

  it('the WAN port ceiling is information, not a fault', async () => {
    stubApi(() =>
      withVerdict({ class: 'link_limited', reason: 'wan_port', confidence: 'high', numbers: { speed_mbps: 2110 } })
    );
    renderCard();
    expect(await screen.findByText('Strop linky')).toBeTruthy();
    expect(screen.getByText(/odpovídá stropu WAN portu/)).toBeTruthy();
    expect(screen.getByText('vysoká jistota')).toBeTruthy();
  });

  it('a slow line is only said with a plan, and says how sure it is', async () => {
    stubApi(() =>
      withVerdict({ class: 'line_limited', reason: 'below_plan', confidence: 'low', numbers: { speed_mbps: 1200 } })
    );
    renderCard();
    expect(await screen.findByText('Omezuje linka poskytovatele')).toBeTruthy();
    expect(screen.getByText(/zůstalo pod tarifem/)).toBeTruthy();
    expect(screen.getByText('nízká jistota')).toBeTruthy();
  });

  it('a reached plan without headroom still says the router has nothing to spare', async () => {
    stubApi(() =>
      withVerdict({ class: 'none', reason: 'plan_reached', numbers: { speed_mbps: 1900, no_cpu_headroom: true } })
    );
    renderCard();
    expect(await screen.findByText(/rezervu nemá/)).toBeTruthy();
  });

  it('a router-limited download shows the busy core and what the average would have hidden', async () => {
    // A probe result, the shape the agent will send once the probe ships: the
    // card already renders it, so the two cannot drift apart later.
    stubApi(() =>
      withVerdict(
        { class: 'cpu_limited', reason: 'packet_path', confidence: 'medium', numbers: { speed_mbps: 1400 } },
        {
          tests: [
            {
              ...base.tests[0],
              startedBy: 'agent',
              diagnostics: {
                v: 1,
                cpu_measured: true,
                path_verified: true,
                background_dl_mbps: 0,
                dl: {
                  secs: 15,
                  core: 0,
                  core_busy_pct: 100,
                  core_user_pct: 20,
                  core_system_pct: 5,
                  core_irq_softirq_pct: 75,
                  all_cores_avg_pct: 52,
                  squeeze_per_s: 3,
                  softnet_dropped: 0,
                },
              },
            },
          ],
        }
      )
    );
    renderCard();
    expect(await screen.findByText('Omezuje router (CPU)')).toBeTruthy();
    expect(screen.getByText(/zpracování paketů v jádře systému/)).toBeTruthy();
    expect(screen.getByText(/jádro 0/)).toBeTruthy();
    expect(screen.getByText(/Zpracování paketů 75 %/)).toBeTruthy();
    // 100 % on one core against an average of 52 % - the line exists exactly for this.
    expect(screen.getByText('Průměr přes všechna jádra (52 %) by tohle schoval.')).toBeTruthy();
  });

  it('a test started by Turris OS says why there is no per-core data', async () => {
    stubApi(() => base);
    renderCard();
    expect(await screen.findByText(/tenhle test spustil Turris OS/)).toBeTruthy();
    expect(screen.queryByText(/jádro 0/)).toBeNull();
  });

  it('a Turris test whose answer carries no cpu flag still says who started it', async () => {
    // `cpu_measured` is the server's flag; who started the test is a column of
    // the row itself. Without the second check a response that omits the flag
    // would fall back to "no core data", which does not say why there is none.
    stubApi(() => ({ ...base, tests: [{ ...base.tests[0], diagnostics: { v: 1 } }] }));
    renderCard();
    expect(await screen.findByText(/tenhle test spustil Turris OS/)).toBeTruthy();
    expect(screen.queryByText(/nejsou data o jádrech/)).toBeNull();
  });

  it('evidence the test did not carry reads "neměřeno", never 0', async () => {
    stubApi(() => base);
    renderCard();
    // The fixture's only test is a Turris one: no phase counters at all.
    await screen.findAllByText('Provoz na pozadí');
    expect(screen.getAllByText('neměřeno').length).toBeGreaterThanOrEqual(6);
    expect(screen.queryByText('0/s')).toBeNull();
  });

  it('the packet path tells a configured offload from a loaded one, and an unchecked SQM from an off one', async () => {
    stubApi(() => base);
    renderCard();
    await screen.findByText('Cesta paketů');
    // The Omnia: flow offloading configured AND a flowtable in the ruleset, sqm: [] = checked, nothing shapes it.
    expect(screen.getByText('zapnuto · flowtable zapnuto')).toBeTruthy();
    expect(screen.getByText('eth2 · 2500 Mbit/s')).toBeTruthy();
    cleanup();

    stubApi(() => ({ ...base, wanPath: { ...omnia.wanPath, sqm: null, flowtable_active: null } }));
    renderCard();
    await screen.findByText('Cesta paketů');
    expect(screen.getByText('zapnuto · flowtable neznámo')).toBeTruthy();
  });

  it('the fixed caveats are a help popover that names the wired ceiling only when the router knows it', async () => {
    // Radix positions the popover with a ResizeObserver, which jsdom lacks.
    const noResize = class {
      observe() {}
      unobserve() {}
      disconnect() {}
    };
    vi.stubGlobal('ResizeObserver', noResize);
    // Capture C5 made the ports knowable: the conduit eth1 runs at 1000F.
    stubApi(() => base);
    renderCard();
    // Closed, the caveats take no room: the help button carries their title.
    const help = (await screen.findByText('Co z toho nepoznáte')).closest('button') as HTMLButtonElement;
    expect(screen.queryByText(/Rychlost Wi-Fi ani rychlost jednotlivých zařízení/)).toBeNull();
    fireEvent.click(help);
    expect((await screen.findAllByText('Drátové porty do LAN zvládnou nejvýš 1000 Mbit/s.')).length).toBeGreaterThan(0);
    cleanup();

    // A router with no DSA, or one whose ubus answers nothing, still sends
    // null - and then the popover must say nothing rather than guess (X5).
    stubApi(() => ({ ...base, wanPath: { ...omnia.wanPath, lan_port_cap_mbit: null } }));
    vi.stubGlobal('ResizeObserver', noResize);
    renderCard();
    fireEvent.click((await screen.findByText('Co z toho nepoznáte')).closest('button') as HTMLButtonElement);
    expect((await screen.findAllByText(/Rychlost Wi-Fi ani rychlost/)).length).toBeGreaterThan(0);
    expect(screen.queryByText(/Drátové porty/)).toBeNull();
  });

  it('the plan form saves the three fields and nothing about a probe', async () => {
    const { posts } = stubApi(() => base);
    renderCard();
    await openPlan();
    const down = (await screen.findByLabelText('Stahování (Mbit/s)')) as HTMLInputElement;
    // The stored plan is what the fields start from.
    expect(down.value).toBe('2000');
    expect((screen.getByLabelText('Odesílání (Mbit/s)') as HTMLInputElement).value).toBe('1000');
    // Empty means "not set", which the server reads as the default 85 %.
    expect((screen.getByLabelText('Podíl tarifu, který se počítá jako dodaný (%)') as HTMLInputElement).value).toBe('');

    fireEvent.change(down, { target: { value: '2500' } });
    fireEvent.change(screen.getByLabelText('Podíl tarifu, který se počítá jako dodaný (%)'), {
      target: { value: '60' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Uložit tarif' }));

    await waitFor(() => expect(posts).toHaveLength(1));
    expect(posts[0]).toEqual({ monitor_id: 6, plan_down_mbit: 2500, plan_up_mbit: 1000, plan_ok_pct: 60 });
    // Wave 1 has no probe: the key is never sent, so the server keeps what it stored.
    expect('probe_enabled' in posts[0]).toBe(false);
    expect(await screen.findByText('Uloženo.')).toBeTruthy();
  });

  it('this release offers no test button and no probe consent', async () => {
    stubApi(() => base);
    renderCard();
    await openPlan();
    await screen.findByText('Rychlost tarifu');
    expect(screen.queryByText(/Spustit test/)).toBeNull();
    expect(screen.queryByRole('checkbox')).toBeNull();
    expect(screen.queryByText(/GB/)).toBeNull();
  });

  it('an empty field clears the plan, a nonsense one is refused before anything is sent', async () => {
    const { posts } = stubApi(() => base);
    renderCard();
    await openPlan();
    const down = (await screen.findByLabelText('Stahování (Mbit/s)')) as HTMLInputElement;
    fireEvent.change(down, { target: { value: '0' } });
    fireEvent.click(screen.getByRole('button', { name: 'Uložit tarif' }));
    expect((await screen.findByRole('alert')).textContent).toContain('celým číslem 1–100000');
    expect(posts).toHaveLength(0);

    // Cleared fields are a plan nobody set - sent as null, never as 0.
    fireEvent.change(down, { target: { value: '' } });
    fireEvent.change(screen.getByLabelText('Odesílání (Mbit/s)'), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Uložit tarif' }));
    await waitFor(() => expect(posts).toHaveLength(1));
    expect(posts[0]).toEqual({ monitor_id: 6, plan_down_mbit: null, plan_up_mbit: null, plan_ok_pct: null });
  });

  it('a share outside 30-100 % is refused, because the classifier would read it as a contract', async () => {
    const { posts } = stubApi(() => base);
    renderCard();
    await openPlan();
    await screen.findByText('Rychlost tarifu');
    fireEvent.change(screen.getByLabelText('Podíl tarifu, který se počítá jako dodaný (%)'), {
      target: { value: '10' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Uložit tarif' }));
    expect((await screen.findByRole('alert')).textContent).toContain('podíl 30–100');
    expect(posts).toHaveLength(0);
  });

  it('a failed save says so and keeps what was typed', async () => {
    stubApi(() => base, false);
    renderCard();
    await openPlan();
    const down = (await screen.findByLabelText('Stahování (Mbit/s)')) as HTMLInputElement;
    fireEvent.change(down, { target: { value: '900' } });
    fireEvent.click(screen.getByRole('button', { name: 'Uložit tarif' }));
    expect((await screen.findByRole('alert')).textContent).toContain('Tarif se nepodařilo uložit.');
    expect(down.value).toBe('900');
    expect(screen.queryByText('Uloženo.')).toBeNull();
  });

  it('a viewer who may not edit gets the verdict without the form', async () => {
    stubApi(() => ({ ...base, canEdit: false }));
    renderCard();
    expect(await screen.findByText('Neprůkazné')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Upravit tarif|Zadat tarif/ })).toBeNull();
    expect(screen.queryByText('Rychlost tarifu')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Uložit tarif' })).toBeNull();
  });
});

/** Five tests a day apart, newest first, and the period averages the server computes. */
function historyOf(n: number) {
  const measurements = Array.from({ length: n }, (_, i) => ({
    ...omnia.speedtestHistory[0],
    measuredAt: `2026-09-${String(20 - i).padStart(2, '0')}T05:23:41+02:00`,
  }));
  const week = {
    days: 7,
    samples: 7,
    downloadMbps: 1107,
    uploadMbps: 900,
    pingMs: 2,
    downloadMinMbps: 950,
    downloadMaxMbps: 1350,
    measuredSince: null,
  };
  return { measurements, averages: { week, month: { ...week, days: 30, samples: 20 } } };
}

describe('Rychlost linky: jedna karta místo dvou (W2-3)', () => {
  beforeEach(() => setCsrfToken('t'));

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    setCsrfToken(null);
    charts.length = 0;
  });

  it('neprůkazný verdikt řekne jednu větu týdne proti tarifu, ne stránku pomlček', async () => {
    stubApi(() => base, true, historyOf(3));
    renderCard();
    // 1107 of the 2000 Mbit/s plan is 55 %; the week stands on 7 tests.
    expect(await screen.findByText('Týden: průměr 1107 Mbit/s = 55 % tarifu · 7 měření')).toBeTruthy();
    // Why there is no verdict is still said, once, under the line.
    expect(screen.getByText(/nejsou data o vytížení jader/)).toBeTruthy();
  });

  it('neměřené ukazatele a důvod bez jader jsou ve sbaleném „zatím neměřeno"', async () => {
    stubApi(() => base, true, historyOf(3));
    renderCard();
    const fold = (await screen.findByTestId('wan-unmeasured')) as HTMLDetailsElement;
    expect(fold.open).toBe(false);
    // Download 6 + upload 5 counters + the per-core load = 12.
    expect(fold.querySelector('summary')?.textContent).toBe('12 ukazatelů zatím neměřeno');
    expect(screen.getByText(/tenhle test spustil Turris OS/).closest('details')).toBe(fold);
    // The CPU box itself is gone while nothing was sampled.
    expect(screen.getByText('Vytížení jader během měření').closest('details')).toBe(fold);
  });

  it('každý test je vlastní sloupec; pod grafem poslední tři a „Zobrazit vše"', async () => {
    stubApi(() => base, true, historyOf(5));
    renderCard();
    await screen.findByTestId('speed-chart');
    // A bar per test: a line between two tests a week apart invents the speeds in between (charts-12).
    expect(charts[charts.length - 1]).toEqual({ bars: true, points: 5 });
    expect(screen.getByText('Posledních 3 měření')).toBeTruthy();
    expect(screen.queryByText('Měsíc')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Zobrazit vše (5)' }));
    expect(screen.getByText('Posledních 5 měření')).toBeTruthy();
    // The period averages come with the full list.
    expect(screen.getByText('Měsíc')).toBeTruthy();
  });

  it('bez tarifu týdenní řádek nic nepočítá z nuly', async () => {
    stubApi(() => omnia.wanBottleneckNoPlan, true, historyOf(3));
    renderCard();
    expect(await screen.findByText('Týden: průměr 1107 Mbit/s · 7 měření')).toBeTruthy();
    expect(screen.queryByText(/% tarifu/)).toBeNull();
  });
});
