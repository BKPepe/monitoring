/**
 * Sdílená konfigurace klientských volání.
 *
 * Origin API je na jednom místě (dřív byl čtyřikrát zaduplikovaný v
 * komponentách) a jde přebít proměnnou prostředí pro lokální vývoj:
 *
 *   PUBLIC_API_ORIGIN=http://localhost:8787 npm run dev:site
 *
 * Hodnota musí sedět s `connect-src` v CSP (astro.config.mjs) — jiná origin
 * se v prohlížeči neprovolá.
 */
export const API_ORIGIN: string = import.meta.env.PUBLIC_API_ORIGIN ?? 'https://api.bloodkings.eu';

/** Repozitář, ze kterého worker agreguje statistiky a changelog. */
export const GITHUB_REPO_URL = 'https://github.com/BKPepe/monitoring';
