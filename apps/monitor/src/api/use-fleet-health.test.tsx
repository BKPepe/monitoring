// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import { useFleetHealth } from './use-fleet-health';

/**
 * The one reader of `action=health` for the dashboard and the device list:
 * asks through appApi.getHealth() in the page's language, keeps the last
 * answer when a refresh fails (and says it failed), and asks again on retry.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const ANSWER = {
  network: { score: 80, grade: 'fair', formulaVersion: 1, components: [] },
  assets: [{ monitorId: 1, score: 92, grade: 'good', formulaVersion: 1, components: [] }],
};

function stubFetch(...answers: Response[]) {
  let i = 0;
  const fetchMock = vi.fn((_input: RequestInfo | URL, _init?: RequestInit) =>
    Promise.resolve(answers[Math.min(i++, answers.length - 1)])
  );
  vi.stubGlobal('fetch', fetchMock);
  return () => fetchMock.mock.calls.map(([u]) => String(u));
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('useFleetHealth: jeden hook pro přehled i seznam zařízení', () => {
  it('ptá se přes api.php v jazyce stránky a čte skóre po monitorech', async () => {
    const urls = stubFetch(json(ANSWER));
    const { result } = renderHook(() => useFleetHealth('en', 0));
    expect(result.current.status).toBe('loading');

    await waitFor(() => expect(result.current.status).toBe('ok'));
    expect(result.current.data?.network.score).toBe(80);
    expect(result.current.data?.assets.get(1)?.score).toBe(92);
    expect(urls()).toEqual(['/status/api.php?action=health&lang=en']);
  });

  it('dokud není povoleno, nic nenačítá', async () => {
    const urls = stubFetch(json(ANSWER));
    const { result, rerender } = renderHook(({ on }) => useFleetHealth('cs', 0, on), {
      initialProps: { on: false },
    });
    await act(async () => {});
    expect(result.current.status).toBe('loading');
    expect(urls()).toHaveLength(0);

    rerender({ on: true });
    await waitFor(() => expect(result.current.status).toBe('ok'));
    expect(urls()).toHaveLength(1);
  });

  it('selhané obnovení nechá poslední odpověď a hlásí failed; retry se zeptá znovu', async () => {
    const urls = stubFetch(json(ANSWER), json({ error: 'health_unavailable' }, 500), json(ANSWER));
    const { result, rerender } = renderHook(({ tick }) => useFleetHealth('cs', tick), {
      initialProps: { tick: 0 },
    });
    await waitFor(() => expect(result.current.status).toBe('ok'));

    rerender({ tick: 1 });
    await waitFor(() => expect(result.current.status).toBe('failed'));
    expect(result.current.data?.network.score).toBe(80);

    act(() => result.current.retry());
    await waitFor(() => expect(result.current.status).toBe('ok'));
    expect(urls()).toHaveLength(3);
  });

  it('první odpověď ve špatném tvaru je selhání bez dat, ne prázdná flotila', async () => {
    stubFetch(json({}));
    const { result } = renderHook(() => useFleetHealth('cs', 0));
    await waitFor(() => expect(result.current.status).toBe('failed'));
    expect(result.current.data).toBeNull();
  });
});
