/**
 * Recovery from a deploy that happened while the app was open (W1-F4).
 *
 * The pages are loaded lazily from hashed files. A deploy replaces those
 * files, so a tab opened before it asks for a chunk that no longer exists.
 * Chromium used to reload by itself, Firefox and Safari got a manual "the app
 * was updated" screen, and nothing stopped a reload loop when the chunk was
 * missing for another reason. Now every browser reloads once per build, and a
 * second failure of the same build shows the screen instead of looping.
 */

/** What each engine says when a lazily imported file cannot be loaded. */
const CHUNK_ERROR_MESSAGES = [
  // Chromium
  'Failed to fetch dynamically imported module',
  // Firefox
  'error loading dynamically imported module',
  // Safari / WebKit
  'Importing a module script failed',
  // Vite's own preload helper (a missing CSS file of a chunk)
  'Unable to preload CSS',
  // webpack-style wording some extensions and older bundles still use
  'Loading chunk',
];

/** Whether an error means "a file of this build could not be loaded". */
export function isChunkLoadError(error: unknown): boolean {
  const message = error instanceof Error ? error.message : typeof error === 'string' ? error : '';
  return CHUNK_ERROR_MESSAGES.some((known) => message.includes(known));
}

const RELOAD_KEY_PREFIX = 'bk-stale-build-reload:';

type ReloadStorage = Pick<Storage, 'getItem' | 'setItem'>;

/**
 * Reloads the page once for this build. Returns false - and reloads nothing -
 * when this build already had its reload in this tab, so a chunk that is
 * missing after a fresh load too ends on the error screen, not in a loop.
 * The key is the build, so the next deploy gets its own single reload.
 */
export function reloadOncePerBuild(
  build: string,
  storage: ReloadStorage | null = tabStorage(),
  reload: () => void = () => window.location.reload()
): boolean {
  const key = RELOAD_KEY_PREFIX + build;
  try {
    if (!storage || storage.getItem(key)) return false;
    storage.setItem(key, String(Date.now()));
  } catch {
    // Storage blocked: without the guard a reload could loop, so none happens.
    return false;
  }
  reload();
  return true;
}

/**
 * Installs the listener for Vite's `vite:preloadError`, which fires when a
 * lazily loaded file or its CSS cannot be fetched. Returns a cleanup for tests.
 */
export function installStaleBuildRecovery(
  build: string,
  target: Pick<Window, 'addEventListener' | 'removeEventListener'> = window,
  storage: ReloadStorage | null = tabStorage(),
  reload: () => void = () => window.location.reload()
): () => void {
  const onPreloadError = (event: Event) => {
    // Handled only when the reload really happens; otherwise the error goes
    // on to the error boundary, which shows the screen with a button.
    if (reloadOncePerBuild(build, storage, reload)) event.preventDefault();
  };
  target.addEventListener('vite:preloadError', onPreloadError);
  return () => target.removeEventListener('vite:preloadError', onPreloadError);
}

function tabStorage(): Storage | null {
  try {
    return window.sessionStorage;
  } catch {
    return null;
  }
}
