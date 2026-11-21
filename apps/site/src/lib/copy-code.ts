/**
 * Adds a Copy button to every code block under `root` that matches `selector`.
 *
 * The buttons are created here rather than written into the markup: copying
 * needs JavaScript anyway, and a button that cannot work should not render.
 * A failed copy says so on the button instead of only logging to the console.
 */
export interface CopyLabels {
  copy: string;
  copied: string;
  failed: string;
}

const RESET_AFTER_MS = 2000;

export function addCopyButtons(root: ParentNode, selector: string, labels: CopyLabels): void {
  for (const block of root.querySelectorAll<HTMLElement>(selector)) {
    const code = block.querySelector('code');
    if (!code || block.querySelector(':scope > .copy-code-btn')) continue;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'copy-code-btn';
    button.textContent = labels.copy;
    let timer: ReturnType<typeof setTimeout> | undefined;

    button.addEventListener('click', async () => {
      let ok = true;
      try {
        await navigator.clipboard.writeText(code.textContent ?? '');
      } catch {
        ok = false;
      }
      button.textContent = ok ? labels.copied : labels.failed;
      button.dataset.state = ok ? 'copied' : 'failed';
      clearTimeout(timer);
      timer = setTimeout(() => {
        button.textContent = labels.copy;
        delete button.dataset.state;
      }, RESET_AFTER_MS);
    });

    // The <pre> scrolls sideways on its own (a long command on a phone), and
    // a keyboard can only scroll what it can focus: without this the hidden
    // part of a command could be copied but never read (PA-2).
    const pre = block.matches('pre') ? block : block.querySelector('pre');
    if (pre && !pre.hasAttribute('tabindex')) pre.tabIndex = 0;

    block.classList.add('has-copy');
    block.append(button);
  }
}
