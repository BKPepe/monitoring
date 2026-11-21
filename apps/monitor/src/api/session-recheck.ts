/**
 * "Ask the server again who is signed in."
 *
 * The session used to be read once per page load and trusted until the tab
 * closed. A session that expired or was deleted in the meantime left a
 * signed-in shell fed by anonymous answers - public-scope data under a user's
 * name - until the next full reload (W1-A7).
 *
 * Callers that notice a sign of it (a 401 from any api.php call, public-scope
 * data in a signed-in view, the tab getting focus back) call
 * requestSessionRecheck(); useSession listens and refetches. It lives apart
 * from use-session.ts because the fetch wrapper and app-api raise it, and
 * use-session imports app-api.
 */
type Listener = () => void;

const listeners = new Set<Listener>();

/** One re-check per burst: a page firing ten requests that all get 401 needs one answer. */
const MIN_GAP_MS = 5_000;
let lastRecheckAt = Number.NEGATIVE_INFINITY;

export function requestSessionRecheck(now: number = Date.now()): void {
  if (now - lastRecheckAt < MIN_GAP_MS) return;
  lastRecheckAt = now;
  for (const listener of listeners) listener();
}

export function onSessionRecheck(listener: Listener): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}
