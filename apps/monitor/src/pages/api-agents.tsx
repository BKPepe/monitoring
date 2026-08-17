import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
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
} from 'lucide-react';
import { useState, useEffect } from 'react';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { appApi } from '@/api/app-api';
import { Link } from 'react-router';
import { cn } from '@/lib/utils';

type PlatformId = 'linux' | 'openwrt' | 'windows' | 'cpanel' | 'docker';

interface PlatformInstaller {
  id: PlatformId;
  name: string;
  badge: string;
  icon: any;
  desc: string;
  command: string;
  extraNote?: string;
}

export function ApiAgentsPage() {
  const { t } = useLanguage();
  const { session } = useSession();
  const [selectedPlatform, setSelectedPlatform] = useState<PlatformId>('linux');
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
      <Card className="grid place-items-center gap-4 p-16 text-center">
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
      </Card>
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
      command:
        'curl -sSL https://bloodkings.eu/status/agent.sh | bash -s -- --server=https://bloodkings.eu --key=YOUR_MONITOR_KEY',
      extraNote: t(
        'api_agents.note_linux',
        'Skript automaticky nainstaluje systémovou službu systemd (bk-agent.service) a spustí pozadí polling.'
      ),
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
      command:
        'wget -O /usr/bin/agent_openwrt.sh https://bloodkings.eu/status/agent_openwrt.sh && chmod +x /usr/bin/agent_openwrt.sh',
      extraNote: t(
        'api_agents.note_openwrt',
        'Do /etc/crontabs/root přidejte řádek: * * * * * /usr/bin/agent_openwrt.sh >/dev/null 2>&1'
      ),
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
      command: 'iwr -useb https://bloodkings.eu/status/agent.ps1 | iex',
      extraNote: t('api_agents.note_windows', 'Spusťte v PowerShell okénku správce (Run as Administrator).'),
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
      command: 'wget -O cpanel_stats.php https://bloodkings.eu/status/cpanel_stats.php',
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
      command:
        'docker run -d --name bk-agent --restart=always -v /proc:/host/proc:ro -e SERVER_URL=https://bloodkings.eu -e AGENT_KEY=YOUR_MONITOR_KEY bloodkings/agent:latest',
      extraNote: t(
        'api_agents.note_docker',
        'Kontejner mapuje pouze /proc v režimu jen pro čtení (read-only) pro nulové bezpečnostní riziko.'
      ),
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

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">
            {t('api_agents.title', 'API Klíče, Bezpečnost & Správa Agentů')}
          </h1>
          <p className="text-muted-foreground text-sm">
            {t('api_agents.subtitle', 'Verze agentů, kontrola bezpečnostních aktualizací, HMAC klíče a instalace.')}
          </p>
        </div>
      </div>

      {/* Quick agent installation by platform */}
      <Card className="p-6 space-y-5">
        <div className="flex items-center justify-between border-b border-border pb-3 flex-wrap gap-2">
          <div className="flex items-center gap-3">
            <Terminal className="size-5 text-primary" />
            <div>
              <h3 className="font-bold text-base">
                {t('api_agents.install_scripts_title', 'Instalační skripty agentů dle platformy')}
              </h3>
              <p className="text-xs text-muted-foreground">
                {t(
                  'api_agents.install_scripts_desc',
                  'Vyberte váš cílový systém pro zobrazení správného příkazu a postupu instalace'
                )}
              </p>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
          {platforms.map((p) => {
            const Icon = p.icon;
            const isSelected = selectedPlatform === p.id;
            return (
              <button
                key={p.id}
                type="button"
                onClick={() => setSelectedPlatform(p.id)}
                className={cn(
                  'flex flex-col items-start p-3 rounded-xl border text-left transition-all cursor-pointer space-y-1.5',
                  isSelected
                    ? 'border-primary bg-primary/10 text-foreground ring-1 ring-primary shadow-sm'
                    : 'border-border bg-secondary/30 text-muted-foreground hover:text-foreground hover:bg-secondary/60'
                )}
              >
                <div className="flex items-center justify-between w-full">
                  <Icon className={cn('size-4', isSelected ? 'text-primary' : 'text-muted-foreground')} />
                  <Badge variant="info" className="text-[9px] px-1.5 py-0">
                    {p.badge}
                  </Badge>
                </div>
                <p className="font-bold text-xs leading-tight">{p.name}</p>
              </button>
            );
          })}
        </div>

        {/* The container respects the theme; only the terminal block with the
            command stays dark (a dark background is convention there, not an accident). */}
        <div className="p-4 rounded-xl bg-secondary/40 border border-border space-y-3">
          <div className="flex items-center justify-between flex-wrap gap-2">
            <div className="flex items-center gap-2">
              <currentPlatform.icon className="size-5 text-primary" />
              <h4 className="font-bold text-sm text-foreground">{currentPlatform.name}</h4>
              <Badge variant="up" className="text-[10px]">
                {currentPlatform.badge}
              </Badge>
            </div>
            <button
              type="button"
              onClick={() => handleCopy(currentPlatform.id, currentPlatform.command)}
              className="inline-flex items-center gap-1.5 rounded px-2.5 py-1 bg-primary/20 text-primary text-xs font-semibold hover:bg-primary/30 transition-colors cursor-pointer border border-primary/40"
            >
              {copiedKey === currentPlatform.id ? (
                <Check className="size-3.5 text-emerald-400" />
              ) : (
                <Copy className="size-3.5" />
              )}
              {copiedKey === currentPlatform.id
                ? t('common.copied', 'Zkopírováno!')
                : t('api_agents.copy_cmd', 'Kopírovat příkaz')}
            </button>
          </div>

          <p className="text-xs text-muted-foreground leading-relaxed">{currentPlatform.desc}</p>

          <div className="p-3 rounded-lg bg-slate-950 font-mono text-xs text-emerald-400 flex items-center justify-between overflow-x-auto border border-slate-800 break-all select-all">
            <code>{currentPlatform.command}</code>
          </div>

          {currentPlatform.extraNote && (
            <p className="text-[11px] text-amber-800 dark:text-amber-300 bg-amber-500/10 p-2.5 rounded-md border border-amber-500/30 font-mono">
              💡 <strong>{t('api_agents.setup_note_label', 'Poznámka k nastavení:')}</strong>{' '}
              {currentPlatform.extraNote}
            </p>
          )}
        </div>
      </Card>

      {/* Agent version status and automatic updates */}
      <Card className="p-6 space-y-4">
        <div className="flex items-center justify-between border-b border-border pb-3 flex-wrap gap-2">
          <div className="flex items-center gap-2.5">
            <Cpu className="size-5 text-primary" />
            <div>
              <h3 className="font-bold text-base">
                {t(
                  'api_agents.version_status_title',
                  { count: agents.length },
                  `Stav verzí & Automatické aktualizace agentů (${agents.length})`
                )}
              </h3>
              <p className="text-xs text-muted-foreground">
                {t(
                  'api_agents.recommended_version_hint',
                  'Doporučená verze se určuje podle skriptu agenta nasazeného na serveru.'
                )}
              </p>
            </div>
          </div>
          <Badge variant={hasOutdatedAgent ? 'down' : hasDisabledAutoUpdate ? 'warning' : 'up'}>
            {hasOutdatedAgent
              ? t('api_agents.status_outdated', '🔴 Zjištěna neaktuální verze agenta!')
              : hasDisabledAutoUpdate
                ? t('api_agents.status_auto_update_off', '⚠️ U některých agentů vypnuty auto-updates')
                : t('api_agents.status_all_ok', 'Všichni agenti aktuální & auto-updates OK ✅')}
          </Badge>
        </div>

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
            <p className="text-xs text-muted-foreground py-4 text-center">
              {t('api_agents.loading', 'Načítám agenty…')}
            </p>
          ) : agents.length === 0 ? (
            <p className="text-xs text-muted-foreground py-4 text-center">
              {t('api_agents.no_agents', 'Žádní registrovaní agenti v databázi.')}
            </p>
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
                    'p-4 rounded-xl border transition-colors space-y-2 text-xs',
                    isOutdated
                      ? 'bg-rose-500/10 border-rose-500/40'
                      : autoUpdateKnown && !autoUpdateEnabled
                        ? 'bg-amber-500/10 border-amber-500/30'
                        : 'bg-secondary/30 border-border'
                  )}
                >
                  <div className="flex items-center justify-between flex-wrap gap-2">
                    <div className="flex items-center gap-2">
                      <p className="font-bold text-foreground text-sm">{a.name}</p>
                      <Badge variant="info" className="font-mono text-[10px]">
                        {a.type.toUpperCase()}
                      </Badge>
                      <Badge variant={a.status === 'up' ? 'up' : 'down'}>
                        {a.status === 'up' ? t('infra.active_since', 'Aktivní') : t('api_agents.inactive', 'Neaktivní')}
                      </Badge>
                    </div>

                    {/* High-contrast version badge */}
                    <div className="flex items-center gap-2 flex-wrap">
                      {version ? (
                        isOutdated ? (
                          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md font-mono font-bold text-xs bg-rose-500/25 text-rose-300 border border-rose-500/50 shadow-sm animate-pulse">
                            {t(
                              'api_agents.version_outdated',
                              { version, latest: latestVersion ?? '' },
                              `🔴 v${version} (Neaktuální — Doporučeno v${latestVersion})`
                            )}
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md font-mono font-bold text-xs bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 shadow-sm">
                            {t('api_agents.version_current', { version }, `🟢 v${version} (Aktuální verze)`)}
                          </span>
                        )
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded font-mono text-muted-foreground text-xs bg-secondary">
                          {t('api_agents.version_unreported', 'Verze nehlášena')}
                        </span>
                      )}

                      {/* Auto-update status indicator */}
                      {autoUpdateEnabled ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded font-mono text-[11px] font-semibold bg-emerald-500/15 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30">
                          <RefreshCw className="size-3" /> {t('api_agents.auto_update_on', 'Auto-updates: Zapnuto')}
                        </span>
                      ) : autoUpdateKnown ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded font-mono text-[11px] font-semibold bg-amber-500/20 text-amber-800 dark:text-amber-300 border border-amber-500/40">
                          <AlertTriangle className="size-3" />{' '}
                          {t('api_agents.auto_update_off', 'Auto-updates: VYPNUTO')}
                        </span>
                      ) : null}
                    </div>
                  </div>

                  <p className="text-muted-foreground font-mono text-[11px]">
                    OS: <span className="text-foreground font-semibold">{a.os || '—'}</span> ·{' '}
                    {t('common.target', 'Cíl')}: <span className="text-foreground">{a.target}</span>
                  </p>

                  {/* Warning message for outdated version or disabled auto-updates */}
                  {isOutdated && (
                    <div className="p-2.5 rounded-lg bg-rose-500/10 border border-rose-500/40 text-rose-800 dark:text-rose-200 text-xs flex items-center gap-2">
                      <AlertTriangle className="size-4 text-rose-400 shrink-0" />
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
                    <div className="p-2.5 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-800 dark:text-amber-200 text-[11px] flex items-center gap-2">
                      <AlertTriangle className="size-3.5 text-amber-400 shrink-0" />
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
      </Card>

      {/* Security & Privacy */}
      <div className="grid gap-4 md:grid-cols-2">
        <Card className="p-6 space-y-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2.5">
              <Lock className="size-5 text-primary" />
              <h4 className="font-semibold text-sm">
                {t('api_agents.privacy_title', 'Záruka Soukromí & Zero Telemetry')}
              </h4>
            </div>
            <Badge variant="up">100% Private</Badge>
          </div>
          <p className="text-xs text-muted-foreground leading-relaxed">
            {t(
              'api_agents.privacy_desc',
              '0 % naměřených dat neopouští vaše servery ani není odesíláno třetím stranám. Všechny metriky se ukládají lokálně ve vaší MySQL/PostgreSQL databázi pod vaší plnou kontrolou.'
            )}
          </p>
        </Card>

        <Card className="p-6 space-y-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2.5">
              <ShieldCheck className="size-5 text-primary" />
              <h4 className="font-semibold text-sm">
                {t('api_agents.auth_title', 'Autentizace agentů & Notifikace verze')}
              </h4>
            </div>
            <Badge variant="up">{t('api_agents.key_hmac_badge', 'Klíč + HMAC-SHA256')}</Badge>
          </div>
          <p className="text-xs text-muted-foreground leading-relaxed">
            {t(
              'api_agents.auth_desc',
              'Při detekci zastaralé verze agenta nebo selhání Remote Action systém vygeneruje varovný incident v sekci Incidenty a odešle e-mailovou/SMS výstrahu administrátorům.'
            )}
          </p>
        </Card>
      </div>
    </div>
  );
}
