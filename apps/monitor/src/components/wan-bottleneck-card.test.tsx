// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { setCsrfToken } from '@/api/app-api';
import type { WanBottleneckResponse } from '@/api/types';
import omnia from '@/api/omnia-router.fixture';
import { WanBottleneckCard } from './wan-bottleneck-card';

const json = (body: unknown, ok = true, status = 200) =>
  ({ ok, status, json: () => Promise.resolve(body) }) as Response;

/** Serves `answer` for the card and records every plan POST. */
function stubApi(answer: () => unknown, saveOk = true) {
  const posts: Record<string, unknown>[] = [];
  const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input);
    if (url.includes('action=wan_settings_save')) {
      posts.push(JSON.parse(String(init?.body)));
      return Promise.resolve(saveOk ? json({ ok: true, plan: omnia.wanBottleneck.plan }) : json({}, false, 500));
    }
    if (url.includes('action=wan_bottleneck')) return Promise.resolve(json(answer()));
    return Promise.resolve(json({}));
  });
  vi.stubGlobal('fetch', fetchMock);
  return { posts, fetchMock };
}

function renderCard() {
  return render(
    <LanguageProvider>
      <WanBottleneckCard monitorId={6} />
    </LanguageProvider>
  );
}

const base = omnia.wanBottleneck;

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
    expect((await screen.findByRole('alert')).textContent).toContain('Vyhodnocení linky se nepodařilo načíst.');
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
    expect(screen.queryByText('0 Mb/s')).toBeNull();
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
    expect(screen.getByText('eth2 · 2500 Mb/s')).toBeTruthy();
    cleanup();

    stubApi(() => ({ ...base, wanPath: { ...omnia.wanPath, sqm: null, flowtable_active: null } }));
    renderCard();
    await screen.findByText('Cesta paketů');
    expect(screen.getByText('zapnuto · flowtable neznámo')).toBeTruthy();
  });

  it('the fixed box names the wired ceiling only when the router knows it', async () => {
    // Capture C5 made the ports knowable: the conduit eth1 runs at 1000F.
    stubApi(() => base);
    renderCard();
    expect(await screen.findByText('Co z toho nepoznáte')).toBeTruthy();
    expect(screen.getByText('Drátové porty do LAN zvládnou nejvýš 1000 Mb/s.')).toBeTruthy();
    cleanup();

    // A router with no DSA, or one whose ubus answers nothing, still sends
    // null - and then the box must say nothing rather than guess (X5).
    stubApi(() => ({ ...base, wanPath: { ...omnia.wanPath, lan_port_cap_mbit: null } }));
    renderCard();
    expect(await screen.findByText('Co z toho nepoznáte')).toBeTruthy();
    expect(screen.queryByText(/Drátové porty/)).toBeNull();
  });

  it('the plan form saves the three fields and nothing about a probe', async () => {
    const { posts } = stubApi(() => base);
    renderCard();
    const down = (await screen.findByLabelText('Stahování (Mb/s)')) as HTMLInputElement;
    // The stored plan is what the fields start from.
    expect(down.value).toBe('2000');
    expect((screen.getByLabelText('Odesílání (Mb/s)') as HTMLInputElement).value).toBe('1000');
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
    await screen.findByText('Rychlost tarifu');
    expect(screen.queryByText(/Spustit test/)).toBeNull();
    expect(screen.queryByRole('checkbox')).toBeNull();
    expect(screen.queryByText(/GB/)).toBeNull();
  });

  it('an empty field clears the plan, a nonsense one is refused before anything is sent', async () => {
    const { posts } = stubApi(() => base);
    renderCard();
    const down = (await screen.findByLabelText('Stahování (Mb/s)')) as HTMLInputElement;
    fireEvent.change(down, { target: { value: '0' } });
    fireEvent.click(screen.getByRole('button', { name: 'Uložit tarif' }));
    expect((await screen.findByRole('alert')).textContent).toContain('celým číslem 1–100000');
    expect(posts).toHaveLength(0);

    // Cleared fields are a plan nobody set - sent as null, never as 0.
    fireEvent.change(down, { target: { value: '' } });
    fireEvent.change(screen.getByLabelText('Odesílání (Mb/s)'), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Uložit tarif' }));
    await waitFor(() => expect(posts).toHaveLength(1));
    expect(posts[0]).toEqual({ monitor_id: 6, plan_down_mbit: null, plan_up_mbit: null, plan_ok_pct: null });
  });

  it('a share outside 30-100 % is refused, because the classifier would read it as a contract', async () => {
    const { posts } = stubApi(() => base);
    renderCard();
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
    const down = (await screen.findByLabelText('Stahování (Mb/s)')) as HTMLInputElement;
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
    expect(screen.queryByText('Rychlost tarifu')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Uložit tarif' })).toBeNull();
  });
});
