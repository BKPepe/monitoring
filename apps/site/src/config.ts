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

/** Where the agent scripts are developed; "read the script first" links here. */
export const AGENTS_SOURCE_URL = 'https://github.com/BKPepe/monitoring-agent/blob/main/vps-agent';

/**
 * The live demo: the author's own public status page in the app. ?lang asks
 * the app for the page's language on the first visit (the app ignores the
 * browser's language on purpose), so an English page does not open Czech.
 */
export function demoUrl(lang: 'en' | 'cs'): string {
  return `https://bloodkings.eu/app/public?lang=${lang}`;
}

/**
 * The installable package. .github/workflows/release.yml attaches it under
 * this fixed name to every v* release, so the "latest" URL never changes.
 * Before the first release it answers 404, and the page says so
 * (lib/release.ts).
 */
export const RELEASE_ZIP_URL = `${GITHUB_REPO_URL}/releases/latest/download/bloodkings-monitoring.zip`;
export const RELEASE_SUMS_URL = `${GITHUB_REPO_URL}/releases/latest/download/SHA256SUMS`;

/**
 * Minutes a clean install took when timed from the text of /download/ alone.
 * null shows "—" instead of a promise nobody measured.
 *
 * Timed on 2026-09-24 against the flow this guide describes: the release ZIP
 * built by release.yml's own steps, a clean php:8.2-apache + MySQL 8.4 pair,
 * every step scripted in a browser - checksum, upload, empty database,
 * /app/setup (connection, config.php, tables, first account), the cron line
 * it shows, then the wait for the first collector run. 17 and 28 seconds in
 * two runs, bounded by the next cron minute: 1 minute. The guide says that
 * FTP and the hosting panel come on top. Set it back to null if the installer
 * flow changes, until it is timed again.
 */
export const MEASURED_INSTALL_MINUTES: number | null = 1;
