// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { addCopyButtons } from './copy-code';

const labels = { copy: 'Copy', copied: 'Copied', failed: 'Copy failed' };

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('tlačítko Kopírovat u bloku kódu', () => {
  it('rolující <pre> jde ovládat klávesnicí (PA-2)', () => {
    document.body.innerHTML =
      '<div class="code"><pre><code>php -q /home/USER/public_html/status/cron.php</code></pre></div>';
    addCopyButtons(document, '.code', labels);
    const pre = document.querySelector('pre')!;
    expect(pre.tabIndex).toBe(0);
    expect(document.querySelectorAll('.copy-code-btn')).toHaveLength(1);
  });

  it('tabindex z markupu nechá a druhé tlačítko nepřidá', () => {
    document.body.innerHTML = '<pre class="code" tabindex="-1"><code>x</code></pre>';
    addCopyButtons(document, '.code', labels);
    addCopyButtons(document, '.code', labels);
    expect(document.querySelector('pre')!.getAttribute('tabindex')).toBe('-1');
    expect(document.querySelectorAll('.copy-code-btn')).toHaveLength(1);
  });

  it('nepovedené kopírování řekne na tlačítku, ne jen v konzoli', async () => {
    vi.stubGlobal('navigator', {
      clipboard: {
        writeText: vi.fn(async () => {
          throw new Error('denied');
        }),
      },
    });
    document.body.innerHTML = '<div class="code"><pre><code>crontab -l</code></pre></div>';
    addCopyButtons(document, '.code', labels);
    const button = document.querySelector<HTMLButtonElement>('.copy-code-btn')!;
    button.click();
    await vi.waitFor(() => expect(button.dataset.state).toBe('failed'));
    expect(button.textContent).toBe('Copy failed');
  });
});
