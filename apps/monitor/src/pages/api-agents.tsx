import { Panel } from '@/components/ui/panel';
import { Pill } from '@/components/ui/pill';
import { IconTile } from '@/components/ui/icon-tile';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/layout/page-header';
import {
  Terminal,
  Copy,
  Check,
  ShieldCheck,
  Lock,
  Cpu,
  Server,
  Router,
  Globe,
  Container,
  AlertTriangle,
  RefreshCw,
  Lightbulb,
} from 'lucide-react';
import { useState, useEffect } from 'react';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { appApi } from '@/api/app-api';
import { Link, useSearchParams } from 'react-router';
import { cn } from '@/lib/utils';
import { EmptyState, LoadingState } from '@/components/ui/states';
import { AgentInstallSteps } from '@/components/agent-install-steps';
import type { AgentPlatform } from '@/lib/agent-install';

type PlatformId = 'linux' | 'openwrt' | 'windows' | 'cpanel' | 'docker';
const PLATFORM_IDS: readonly PlatformId[] = ['linux', 'openwrt', 'windows', 'cpanel', 'docker'];

interface PlatformInstaller {
  id: PlatformId;
  name: string;
  badge: string;
  icon: any;
  desc: string;
  /** One command - only for what is not an agent (cPanel stats). */
  command?: string;
  extraNote?: string;
  /** Agent platforms get the full install steps instead of one command. */
  steps?: AgentPlatform[];
}

export function ApiAgentsPage() {
  const { t } = useLanguage();
  const { session } = useSession();
  // ?platform= preselects a card: the dashboard's first-run "Připojit router"
  // lands on OpenWrt (site W1-5 conv-12). Anything unknown keeps the default.
  const [searchParams] = useSearchParams();
  const [selectedPlatform, setSelectedPlatform] = useState<PlatformId>(() => {
    const asked = searchParams.get('platform');
    return (PLATFORM_IDS as readonly string[]).includes(asked ?? '') ? (asked as PlatformId) : 'linux';
  });
  const [copiedKey, setCopiedKey] = useState<string | null>(null);
  const [agents, setAgents] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  useEffect(() => {
    appApi
      .getMonitors()
      .then((rows) => {
        const list = Array.isArray(rows) ? rows : ((rows as any)?.monitors ?? []);
        const agentMonitors = list.filter((m: any) => {
          const type = (m.type || '').toLowerCase();
          const name = (m.name || '').toLowerCase();
          return (
            type === 'openwrt' ||
            type === 'vps' ||
            type === 'agent' ||
            type === 'router' ||
            type === 'teamspeak' ||
            type === 'minecraft' ||
            name.includes('donald') ||
            name.includes('router') ||
            m.agentLastSeen != null ||
            Boolean(m.details?.agent_version) ||
            Boolean(m.details?.cpanel_stats)
          );
        });
        setAgents(agentMonitors);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (!session?.authenticated) {
    return (
      <Panel bodyClassName="grid place-items-center gap-4 py-12 text-center">
        <div className="space-y-1">
          <p className="font-semibold text-lg">{t('api_agents.login_required_title', 'Přihlášení vyžadováno')}</p>
          <p className="text-muted-foreground text-sm max-w-md">
            {t(
              'api_agents.login_required_desc',
              'Správa API klíčů a instalačních skriptů je přístupná pouze přihlášeným administrátorům.'
            )}
          </p>
        </div>
        <Link
          to="/setup"
          className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors"
        >
          {t('settings.go_to_login', 'Přejít na přihlášení')}
        </Link>
      </Panel>
    );
  }

  const platforms: PlatformInstaller[] = [
    {
      id: 'linux',
      name: 'Linux / VPS Server',
      badge: 'Python 3 / Bash',
      icon: Server,
      desc: t(
        'api_agents.desc_linux',
        'Automatický sběr CPU, RAM, zátěže disku, běžících procesů a služeb pro Debian, Ubuntu, CentOS a RHEL.'
      ),
      steps: ['shell', 'python'],
    },
    {
      id: 'openwrt',
      name: 'OpenWrt Router',
      badge: 'Shell + ubus',
      icon: Router,
      desc: t(
        'api_agents.desc_openwrt',
        'Lehký shell agent přímo pro routery OpenWrt/LEDE. Využívá ubus, iwinfo, /proc a podporuje bezpečné Remote Actions (potvrzovací pingy).'
      ),
      steps: ['openwrt'],
    },
    {
      id: 'windows',
      name: 'Windows Server',
      badge: 'PowerShell',
      icon: Terminal,
      desc: t(
        'api_agents.desc_windows',
        'PowerShell agent pro Windows Server 2016 / 2019 / 2022 s automatickou registrací do Windows Task Scheduler.'
      ),
      steps: ['windows'],
    },
    {
      id: 'cpanel',
      name: 'cPanel / Web Hosting',
      badge: 'PHP Stats API',
      icon: Globe,
      desc: t(
        'api_agents.desc_cpanel',
        'Stáhněte cpanel_stats.php do kořenového adresáře hostingu pro veřejný sběr diskového prostoru, RAM a MySQL zátěže.'
      ),
      command: `wget -O cpanel_stats.php ${window.location.origin}/status/cpanel_stats.php`,
      extraNote: t(
        'api_agents.note_cpanel',
        'URL k souboru s vaším tajným klíčem následně zadejte v detailu monitoru v záložce Nastavení Webu.'
      ),
    },
    {
      id: 'docker',
      name: 'Docker Container',
      badge: 'Docker Run / Compose',
      icon: Container,
      desc: t(
        'api_agents.desc_docker',
        'Izolovaný Docker kontejner pro provoz v prostředí Docker / Kubernetes bez zásahu do hostitelského OS.'
      ),
      steps: ['docker'],
    },
  ];

  const currentPlatform = platforms.find((p) => p.id === selectedPlatform) ?? platforms[0];

  const handleCopy = (id: string, text: string) => {
    navigator.clipboard.writeText(text);
    setCopiedKey(id);
    setTimeout(() => setCopiedKey(null), 2000);
  };

  // The server is the source of truth: it compares the reported version with the
  // agent FILE's version (agentUpdateAvailable/agentLatestVersion). This used to
  // compare against a hardcoded "3.13.8" - the TeamSpeak SERVER's version, not
  // the agent's - with a string '<' on top.
  const hasOutdatedAgent = agents.some((a) => Boolean(a.agentUpdateAvailable));

  // The disabled-updates warning only makes sense for monitors that really
  // have an agent reporting its auto-update state. It used to say
  // "disabled" even for an agentless cPanel site (user report).
  const hasDisabledAutoUpdate = agents.some((a) => {
    if (!a.details?.agent_version) return false;
    const au = a.details?.auto_update;
    return au === 0 || au === '0' || au === false;
  });

  // "All current" only when every agent said its version: an agent that never
  // reported one is not known to be current (it may not run at all).
  const unreported = agents.filter((a) => !a.details?.agent_version).length;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('api_agents.title', 'API & Agenti')}
        subtitle={t(
          'api_agents.subtitle',
          'Verze agentů, kontrola bezpečnostních aktualizací, HMAC klíče a instalace.'
        )}
      />

      {/* Quick agent installation by platform */}
      <Panel
        icon={Terminal}
        title={t('api_agents.install_scripts_title', 'Instalační skripty agentů dle platformy')}
        hint={t(
          'api_agents.install_scripts_desc',
          'Vyberte váš cílový systém pro zobrazení správného příkazu a postupu instalace'
        )}
        bodyClassName="space-y-4"
      >
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
          {platforms.map((p) => {
            const Icon = p.icon;
            const isSelected = selectedPlatform === p.id;
            return (
              <button
                key={p.id}
                type="button"
                aria-pressed={isSelected}
                onClick={() => setSelectedPlatform(p.id)}
                className={cn(
                  'focus-visible:ring-ring flex flex-col items-start gap-2 rounded-lg border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none',
                  isSelected
                    ? 'border-primary/40 bg-primary/12 text-foreground'
                    : 'bg-inset text-muted-foreground hover:text-foreground hover:border-border-strong border-border'
                )}
              >
                <IconTile icon={Icon} size="sm" tone={isSelected ? 'primary' : 'neutral'} />
                <p className="text-xs leading-tight font-semibold">{p.name}</p>
                <span className="text-muted-foreground figure text-2xs">{p.badge}</span>
              </button>
            );
          })}
        </div>

        {/* The container respects the theme; only the terminal block with the
            command stays dark (a dark background is convention there, not an accident). */}
        <div className="bg-inset space-y-3 rounded-lg border border-border p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2.5">
              <IconTile icon={currentPlatform.icon} />
              <h3 className="text-sm font-semibold">{currentPlatform.name}</h3>
              <Pill size="sm">{currentPlatform.badge}</Pill>
            </div>
            {currentPlatform.command && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => handleCopy(currentPlatform.id, currentPlatform.command ?? '')}
              >
                {copiedKey === currentPlatform.id ? <Check aria-hidden="true" /> : <Copy aria-hidden="true" />}
                {copiedKey === currentPlatform.id
                  ? t('common.copied', 'Zkopírováno!')
                  : t('api_agents.copy_cmd', 'Kopírovat příkaz')}
              </Button>
            )}
          </div>

          <p className="text-xs text-muted-foreground leading-relaxed">{currentPlatform.desc}</p>

          {currentPlatform.command && (
            <div className="bg-card flex items-center justify-between overflow-x-auto rounded-lg border border-border p-3 font-mono text-xs break-all select-all">
              <code>{currentPlatform.command}</code>
            </div>
          )}
          {currentPlatform.steps && <AgentInstallSteps key={currentPlatform.id} platforms={currentPlatform.steps} />}

          {currentPlatform.extraNote && (
            <p className="text-warning bg-warning/10 border-warning/30 flex items-start gap-2 rounded-md border p-2.5 text-2xs">
              <Lightbulb aria-hidden="true" className="mt-px size-3.5 shrink-0" />
              <span>
                <strong>{t('api_agents.setup_note_label', 'Poznámka k nastavení:')}</strong>{' '}
                <span className="font-mono">{currentPlatform.extraNote}</span>
              </span>
            </p>
          )}
        </div>
      </Panel>

      {/* Agent version status and automatic updates */}
      <Panel
        icon={Cpu}
        title={t(
          'api_agents.version_status_title',
          { count: agents.length },
          `Stav verzí & Automatické aktualizace agentů (${agents.length})`
        )}
        hint={t(
          'api_agents.recommended_version_hint',
          'Doporučená verze se určuje podle skriptu agenta nasazeného na serveru.'
        )}
        chip={
          !loading && agents.length > 0 ? (
            <Pill
              tone={hasOutdatedAgent ? 'down' : hasDisabledAutoUpdate ? 'warning' : unreported > 0 ? 'neutral' : 'up'}
              dot
            >
              {hasOutdatedAgent
                ? t('api_agents.status_outdated', 'Zjištěna neaktuální verze agenta')
                : hasDisabledAutoUpdate
                  ? t('api_agents.status_auto_update_off', 'U některých agentů vypnuty auto-updates')
                  : unreported > 0
                    ? t(
                        'api_agents.status_unreported',
                        { n: unreported, total: agents.length },
                        `Verze nehlášena: ${unreported} z ${agents.length}`
                      )
                    : t('api_agents.status_all_ok', 'Všichni agenti aktuální, auto-updates OK')}
            </Pill>
          ) : undefined
        }
      >
        {/*
          There used to be a toggle here, "Send email warnings when an outdated
          agent version is detected", with a badge reading "email alerts on 📧".
          Nothing about it worked: it saved in a shape save_settings rejects with
          400 (the `settings` wrapper was missing), the response was swallowed by
          an empty .catch(), and no code sends an email about an outdated agent
          anyway. So the badge claimed the alerts were running, and the toggle
          jumped back on the next page load.

          The system does detect an outdated version and reports it with the badge
          above - that is what we truly know about agents, and nothing more should
          be promised here.
        */}

        <div className="space-y-3">
          {loading ? (
            <LoadingState size="inline" label={t('api_agents.loading', 'Načítám agenty…')} />
          ) : agents.length === 0 ? (
            <EmptyState
              boxed
              size="inline"
              title={t('api_agents.no_agents', 'Žádní registrovaní agenti v databázi.')}
            />
          ) : (
            agents.map((a) => {
              // The agent's version - never details.version (that is the SERVICE's, e.g. TS3).
              const version = a.details?.agent_version ?? null;
              const latestVersion = a.agentLatestVersion ?? null;
              const isOutdated = Boolean(a.agentUpdateAvailable);
              const autoUpdateRaw = a.details?.auto_update;
              const autoUpdateKnown = autoUpdateRaw !== undefined && autoUpdateRaw !== null;
              const autoUpdateEnabled = autoUpdateRaw === 1 || autoUpdateRaw === '1' || autoUpdateRaw === true;

              return (
                <div
                  key={a.id}
                  className={cn(
                    'bg-inset space-y-2 rounded-lg border border-l-2 border-border p-3.5 text-xs',
                    isOutdated
                      ? 'border-l-down'
                      : autoUpdateKnown && !autoUpdateEnabled
                        ? 'border-l-warning'
                        : 'border-l-transparent'
                  )}
                >
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="text-sm font-semibold">{a.name}</p>
                      <Pill size="sm">{a.type}</Pill>
                      <Pill size="sm" dot tone={a.status === 'up' ? 'up' : 'down'}>
                        {a.status === 'up' ? t('infra.active_since', 'Aktivní') : t('api_agents.inactive', 'Neaktivní')}
                      </Pill>
                    </div>

                    {/* The chips sit on the plain page ground, not on a tint of their own
                        colour: stacked on the card's tint they fell to about 4.1:1. */}
                    <div className="flex items-center gap-2 flex-wrap">
                      {version ? (
                        isOutdated ? (
                          <span className="figure text-down border-down/40 bg-card inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-2xs font-semibold">
                            {t(
                              'api_agents.version_outdated',
                              { version, latest: latestVersion ?? '' },
                              `v${version} (Neaktuální — Doporučeno v${latestVersion})`
                            )}
                          </span>
                        ) : (
                          <span className="figure text-up border-up/40 bg-card inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-2xs font-semibold">
                            {t('api_agents.version_current', { version }, `v${version} (Aktuální verze)`)}
                          </span>
                        )
                      ) : (
                        <span className="figure text-muted-foreground bg-card inline-flex items-center gap-1 rounded-md border border-border px-2 py-0.5 text-2xs">
                          {t('api_agents.version_unreported', 'Verze nehlášena')}
                        </span>
                      )}

                      {/* Auto-update status indicator */}
                      {autoUpdateEnabled ? (
                        <span className="figure text-up border-up/30 bg-card inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-2xs font-semibold">
                          <RefreshCw className="size-3" /> {t('api_agents.auto_update_on', 'Auto-updates: Zapnuto')}
                        </span>
                      ) : autoUpdateKnown ? (
                        <span className="figure text-warning border-warning/40 bg-card inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-2xs font-semibold">
                          <AlertTriangle className="size-3" />{' '}
                          {t('api_agents.auto_update_off', 'Auto-updates: VYPNUTO')}
                        </span>
                      ) : null}
                    </div>
                  </div>

                  <p className="text-muted-foreground figure text-2xs">
                    OS: <span className="text-foreground font-semibold">{a.os || '—'}</span> ·{' '}
                    {t('common.target', 'Cíl')}: <span className="text-foreground">{a.target}</span>
                  </p>

                  {/* Warning message for outdated version or disabled auto-updates */}
                  {isOutdated && (
                    <div className="p-2.5 rounded-lg bg-down/10 border border-down/40 text-down text-xs flex items-center gap-2">
                      <AlertTriangle className="size-4 text-down shrink-0" />
                      <span>
                        <strong>{t('api_agents.outdated_warning_title', 'Agent je neaktuální!')}</strong>{' '}
                        {t(
                          'api_agents.outdated_warning_desc',
                          { version, latest: latestVersion ?? '' },
                          `Používá verzi v${version}, na serveru je připravená v${latestVersion}. Agent se aktualizuje sám, pokud má AUTO_UPDATE="1"; jinak stáhněte novou verzi ručně.`
                        )}
                      </span>
                    </div>
                  )}

                  {autoUpdateKnown && !autoUpdateEnabled && (
                    <div className="p-2.5 rounded-lg bg-warning/10 border border-warning/30 text-warning text-2xs flex items-center gap-2">
                      <AlertTriangle className="size-3.5 text-warning shrink-0" />
                      <span>
                        <strong>
                          {t('api_agents.auto_update_disabled_title', 'Automatické aktualizace jsou vypnuty:')}
                        </strong>{' '}
                        {t(
                          'api_agents.auto_update_disabled_desc',
                          'Doporučujeme v konfiguraci agenta (`agent.cfg` nebo `agent_openwrt.cfg`) nastavit'
                        )}{' '}
                        <code>AUTO_UPDATE="1"</code>
                        {t(
                          'api_agents.auto_update_disabled_desc_suffix',
                          ', aby se bezpečnostní záplaty a opravy instalovaly automaticky bez nutnosti ručního zásahu.'
                        )}
                      </span>
                    </div>
                  )}
                </div>
              );
            })
          )}
        </div>
      </Panel>

      {/* Security & Privacy */}
      <div className="grid gap-4 md:grid-cols-2">
        <Panel
          icon={Lock}
          title={t('api_agents.privacy_title', 'Záruka Soukromí & Zero Telemetry')}
          chip={<Pill tone="up">100% Private</Pill>}
        >
          <p className="text-muted-foreground text-xs leading-relaxed">
            {t(
              'api_agents.privacy_desc',
              '0 % naměřených dat neopouští vaše servery ani není odesíláno třetím stranám. Všechny metriky se ukládají lokálně ve vaší MySQL/PostgreSQL databázi pod vaší plnou kontrolou.'
            )}
          </p>
        </Panel>

        <Panel
          icon={ShieldCheck}
          title={t('api_agents.auth_title', 'Autentizace agentů & Notifikace verze')}
          chip={<Pill tone="up">{t('api_agents.key_hmac_badge', 'Klíč + HMAC-SHA256')}</Pill>}
        >
          <p className="text-muted-foreground text-xs leading-relaxed">
            {t(
              'api_agents.auth_desc',
              'Při detekci zastaralé verze agenta nebo selhání Remote Action systém vygeneruje varovný incident v sekci Incidenty a odešle e-mailovou/SMS výstrahu administrátorům.'
            )}
          </p>
        </Panel>
      </div>
    </div>
  );
}
