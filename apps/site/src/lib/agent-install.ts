/**
 * The agent install steps, from the app's own source.
 *
 * apps/monitor/src/lib/agent-install.ts builds the steps the app's "add
 * monitor" dialog prints with the user's address and key. The site kept a
 * hand-written copy of them, and the copy drifted: it downloaded from and
 * registered against the author's own server. Now the site imports that file
 * at build time (it has no imports of its own, so nothing else of the app
 * comes along) and renders the same steps with a placeholder domain and key.
 */
import {
  AGENT_INSTALL_TEXT,
  agentInstallSteps,
  genericInstallTarget,
  type AgentPlatform,
  type InstallStep,
} from '../../../monitor/src/lib/agent-install';

export type { AgentPlatform, InstallStep };
export type Lang = 'en' | 'cs';

/** What the reader replaces: their own server and the monitor's key. */
export const DOMAIN_PLACEHOLDER = 'https://YOUR-DOMAIN';
export const KEY_PLACEHOLDER = 'YOUR_AGENT_KEY';
export const TOKEN_PLACEHOLDER = 'YOUR_REGISTRATION_TOKEN';

const TEXT: Record<string, { cs: string; en: string }> = AGENT_INSTALL_TEXT;

/**
 * The app's t() signature over the shared table. A key the table lacks would
 * be a new text in the app: the build stops instead of printing the key.
 */
export function siteTranslator(lang: Lang) {
  return (key: string): string => {
    const entry = TEXT[key];
    if (!entry) throw new Error(`agent-install: no ${lang} text for "${key}" in AGENT_INSTALL_TEXT`);
    return entry[lang];
  };
}

export const siteInstallTarget = () => genericInstallTarget(DOMAIN_PLACEHOLDER, KEY_PLACEHOLDER);

/** The steps exactly as the app builds them, for the placeholder domain and key. */
export function siteInstallSteps(platform: AgentPlatform, lang: Lang): InstallStep[] {
  return agentInstallSteps(platform, siteInstallTarget(), siteTranslator(lang));
}

/**
 * Registration is a Bash and OpenWrt feature, run after the download step:
 * `--register TOKEN URL`, both positional (the form docs/api.md names, and
 * the one both agents accept). Without the URL the agent registers against
 * its localhost default.
 */
export function registerCommand(platform: 'shell' | 'openwrt'): string {
  const script = platform === 'openwrt' ? '/usr/bin/agent_openwrt.sh' : '/opt/bk-agent/agent.sh';
  return `${script} --register ${TOKEN_PLACEHOLDER} ${siteInstallTarget().apiUrl}`;
}

/**
 * App labels the site names when it points into the app ("Infrastructure →
 * Add New Monitor"). Copied here because the site cannot load the app's
 * dictionary; agent-install.test.ts fails when the app renames one, so the
 * site never sends people to a button that no longer exists.
 */
export const APP_LABELS = {
  'nav.infrastructure': { cs: 'Infrastruktura', en: 'Infrastructure' },
  'infra.add_agent': { cs: 'Přidat nový monitor', en: 'Add New Monitor' },
  'infra.type_openwrt': { cs: 'OpenWrt Router', en: 'OpenWrt Router' },
  'infra.type_vps': { cs: 'VPS Agent', en: 'VPS Agent' },
  'nav.settings': { cs: 'Nastavení', en: 'Settings' },
  'settings.agent_token_label': { cs: 'Token pro auto-registraci agentů', en: 'Agent Auto-Registration Token' },
  'settings.agent_offline_timeout_label': {
    cs: 'Časový limit pro označení agenta za offline (minuty)',
    en: 'Timeout to Mark Agent Offline (minutes)',
  },
} as const satisfies Record<string, { cs: string; en: string }>;

/** The server's default for that setting (get_setting('agent_offline_timeout', '50') in cron.php). */
export const AGENT_OFFLINE_DEFAULT_MINUTES = 50;
