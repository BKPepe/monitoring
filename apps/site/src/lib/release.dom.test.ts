// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fillReleaseInfo, resetReleaseCache } from './release';

afterEach(() => {
  vi.unstubAllGlobals();
  resetReleaseCache();
});

const page = `
  <a class="btn" data-release="download" href="https://example.test/latest.zip">Download</a>
  <p data-release="line" data-lang="en" data-word-line="Latest release:" data-word-none="No release yet."
     data-word-error="Could not be loaded.">Latest release: —</p>`;

describe('tlačítko stažení a řádek s vydáním (V-21)', () => {
  it('bez vydání tlačítko nic nenabízí a řádek to řekne', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response(JSON.stringify({ monitoring: null, latestReleaseDate: null })))
    );
    document.body.innerHTML = page;
    fillReleaseInfo(document);
    const line = document.querySelector('[data-release="line"]')!;
    await vi.waitFor(() => expect(line.getAttribute('data-state')).toBe('none'));
    expect(line.textContent).toBe('No release yet.');
    const button = document.querySelector('[data-release="download"]')!;
    expect(button.hasAttribute('href')).toBe(false);
    expect(button.getAttribute('aria-disabled')).toBe('true');
  });

  it('po chybě tlačítko zůstane, vydání může existovat', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('{}', { status: 503 }))
    );
    document.body.innerHTML = page;
    fillReleaseInfo(document);
    const line = document.querySelector('[data-release="line"]')!;
    await vi.waitFor(() => expect(line.getAttribute('data-state')).toBe('error'));
    expect(line.textContent).toBe('Could not be loaded.');
    expect(document.querySelector('[data-release="download"]')!.getAttribute('href')).toBe(
      'https://example.test/latest.zip'
    );
  });
});
