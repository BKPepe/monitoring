/**
 * Keeps a signed-out account from lingering on screen.
 *
 * Signing out reloads the tab it was clicked in, but two other copies of the
 * signed-in app can survive: the page the browser keeps in its back/forward
 * cache (Back brings it back from memory, with no request to the server) and
 * every other open tab. A page restored from that cache and a tab told about
 * the sign-out both reload, and the fresh load asks the server who is signed in.
 */
const SIGNED_OUT_KEY = 'bk-signed-out';

/** Tells the app's other tabs that this browser just signed out. */
export function announceSignOut(storage: Pick<Storage, 'setItem'> | null = browserStorage()): void {
  try {
    storage?.setItem(SIGNED_OUT_KEY, String(Date.now()));
  } catch {
    // Storage blocked (private mode, a policy): other tabs find out on their next load.
  }
}

/** Installs the listeners once for the whole app. Returns a cleanup for tests. */
export function installSessionGuards(
  target: Pick<Window, 'addEventListener' | 'removeEventListener'> = window,
  reload: () => void = () => window.location.reload()
): () => void {
  const onPageShow = (event: Event) => {
    if ((event as PageTransitionEvent).persisted) reload();
  };
  const onStorage = (event: Event) => {
    const storageEvent = event as StorageEvent;
    if (storageEvent.key === SIGNED_OUT_KEY && storageEvent.newValue) reload();
  };
  target.addEventListener('pageshow', onPageShow);
  target.addEventListener('storage', onStorage);
  return () => {
    target.removeEventListener('pageshow', onPageShow);
    target.removeEventListener('storage', onStorage);
  };
}

function browserStorage(): Storage | null {
  try {
    return window.localStorage;
  } catch {
    return null;
  }
}
