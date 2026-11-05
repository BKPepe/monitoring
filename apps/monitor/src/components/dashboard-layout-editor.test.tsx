// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { DashboardLayoutEditor } from './dashboard-layout-editor';

/**
 * Save writes the list on screen. A catalogue that did not load leaves that
 * list empty, and saving it would replace the user's layout with nothing.
 */
const answer = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const catalog = [{ key: 'monitors', label: 'Monitory', kind: 'panel', available: true, samples: null }];

function open(load: () => Promise<Response>) {
  const posts: unknown[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn((_url: RequestInfo | URL, init?: RequestInit) => {
      if (init?.method === 'POST') {
        posts.push(JSON.parse(String(init.body)));
        return Promise.resolve(answer({ success: true }));
      }
      return load();
    })
  );
  const saved = vi.fn();
  render(
    <LanguageProvider>
      <DashboardLayoutEditor open onClose={() => {}} onSaved={saved} />
    </LanguageProvider>
  );
  return { posts, saved };
}

const saveButton = () => screen.getByRole('button', { name: /Uložit rozložení/ }) as HTMLButtonElement;

describe('DashboardLayoutEditor', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it.each([
    ['a refused request', () => Promise.resolve(answer({ error: 'nope' }, 500))],
    ['a dead network', () => Promise.reject(new Error('offline'))],
    ['an answer with no catalogue', () => Promise.resolve(answer({ tiles: [] }))],
    ['an empty catalogue', () => Promise.resolve(answer({ catalog: [], tiles: [] }))],
  ])('says so and offers no save after %s', async (_name, load) => {
    const { posts } = open(load);
    await screen.findByText(/Katalog dlaždic se nepodařilo načíst/);
    expect(saveButton().disabled).toBe(true);
    fireEvent.click(saveButton());
    expect(posts).toEqual([]);
  });

  it('saves what the catalogue offered', async () => {
    const { posts, saved } = open(() =>
      Promise.resolve(answer({ catalog, tiles: [{ key: 'monitors', visible: true, size: 'wide' }] }))
    );
    await waitFor(() => expect(saveButton().disabled).toBe(false));
    fireEvent.click(saveButton());
    await waitFor(() => expect(saved).toHaveBeenCalled());
    expect(posts).toEqual([{ tiles: [{ key: 'monitors', visible: true, size: 'wide' }] }]);
  });
});
