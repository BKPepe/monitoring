import type { APIRoute } from 'astro';
// The same generator the bloodkings.eu root deploy uses (root/deploy.mjs), so
// both hosts publish one contact and one format and differ only in Canonical.
import { securityTxt } from '../../../../../root/lib.mjs';

// Vite resolves the glob against this source file at build time. A path read
// through import.meta.url would point into the bundled output instead and
// never find the repository's SECURITY.md.
const hasPolicy = Object.keys(import.meta.glob('../../../../../SECURITY.md')).length > 0;

// Rendered at build time: Expires counts from this deploy (+11 months), so a
// file kept in git cannot quietly go stale. The weekly check in quality.yml
// fails before it lapses.
export const GET: APIRoute = () => {
  const body = securityTxt({
    canonical: 'https://monitoring.bloodkings.eu/.well-known/security.txt',
    now: new Date(),
    withPolicy: hasPolicy,
  });
  return new Response(body, { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
};
