import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Terminal, Copy, Check, ShieldCheck, Lock, Cpu, Server, Router, Globe, Container } from 'lucide-react';
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
    appApi.getMonitors().then((rows) => {
      const list = Array.isArray(rows) ? rows : (rows as any)?.monitors ?? [];
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
    }).catch(() => {}).finally(() => setLoading(false));
  }, []);

  if (!session?.authenticated) {
    return (
      <Card className="grid place-items-center gap-4 p-16 text-center">
        <div className="space-y-1">
          <p className="font-semibold text-lg">{t('common.login_required', 'Přihlášení vyžadováno')}</p>
          <p className="text-muted-foreground text-sm max-w-md">
            {t('api_agents.login_required_desc', 'Správa API klíčů a instalačních skriptů je přístupná pouze přihlášeným administrátorům.')}
          </p>
        </div>
        <Link to="/setup" className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors">
          {t('btn.login', 'Přejít na přihlášení')}
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
      desc: 'Automatický sběr CPU, RAM, zátěže disku, běžících procesů a služeb pro Debian, Ubuntu, CentOS a RHEL.',
      command: 'curl -sSL https://bloodkings.eu/status/agent.sh | bash -s -- --server=https://bloodkings.eu --key=YOUR_MONITOR_KEY',
      extraNote: 'Skript automaticky nainstaluje systémovou službu systemd (bk-agent.service) a spustí pozadí polling.',
    },
    {
      id: 'openwrt',
      name: 'OpenWrt Router',
      badge: 'Shell + ubus',
      icon: Router,
      desc: 'Lehký shell agent přímo pro routery OpenWrt/LEDE. Využívá ubus, iwinfo, /proc a podporuje bezpečné Remote Actions (potvrzovací pingy).',
      command: 'wget -O /usr/bin/agent_openwrt.sh https://bloodkings.eu/status/agent_openwrt.sh && chmod +x /usr/bin/agent_openwrt.sh',
      extraNote: 'Do /etc/crontabs/root přidejte řádek: * * * * * /usr/bin/agent_openwrt.sh >/dev/null 2>&1',
    },
    {
      id: 'windows',
      name: 'Windows Server',
      badge: 'PowerShell',
      icon: Terminal,
      desc: 'PowerShell agent pro Windows Server 2016 / 2019 / 2022 s automatickou registrací do Windows Task Scheduler.',
      command: 'iwr -useb https://bloodkings.eu/status/agent.ps1 | iex',
      extraNote: 'Spusťte v PowerShell okénku správce (Run as Administrator).',
    },
    {
      id: 'cpanel',
      name: 'cPanel / Web Hosting',
      badge: 'PHP Stats API',
      icon: Globe,
      desc: 'Stáhněte cpanel_stats.php do kořenového adresáře hostingu pro veřejný sběr diskového prostoru, RAM a MySQL zátěže.',
      command: 'wget -O cpanel_stats.php https://bloodkings.eu/status/cpanel_stats.php',
      extraNote: 'URL k souboru s vaším tajným klíčem následně zadejte v detailu monitoru v záložce Nastavení Webu.',
    },
    {
      id: 'docker',
      name: 'Docker Container',
      badge: 'Docker Run / Compose',
      icon: Container,
      desc: 'Izolovaný Docker kontejner pro provoz v prostředí Docker / Kubernetes bez zásahu do hostitelského OS.',
      command: 'docker run -d --name bk-agent --restart=always -v /proc:/host/proc:ro -e SERVER_URL=https://bloodkings.eu -e AGENT_KEY=YOUR_MONITOR_KEY bloodkings/agent:latest',
      extraNote: 'Kontejner mapuje pouze /proc v režimu jen pro čtení (read-only) pro nulové bezpečnostní riziko.',
    },
  ];

  const currentPlatform = platforms.find((p) => p.id === selectedPlatform) ?? platforms[0];

  const handleCopy = (id: string, text: string) => {
    navigator.clipboard.writeText(text);
    setCopiedKey(id);
    setTimeout(() => setCopiedKey(null), 2000);
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">{t('api_agents.title', 'API Klíče, Bezpečnost & Správa Agentů')}</h1>
          <p className="text-muted-foreground text-sm">{t('api_agents.subtitle', 'Verze agentů, kontrola bezpečnostních aktualizací, HMAC klíče a instalace.')}</p>
        </div>
      </div>

      {/* Rychlá instalace agenta dle platformy */}
      <Card className="p-6 space-y-5">
        <div className="flex items-center justify-between border-b border-border pb-3 flex-wrap gap-2">
          <div className="flex items-center gap-3">
            <Terminal className="size-5 text-primary" />
            <div>
              <h3 className="font-bold text-base">Instalační skripty agentů dle platformy</h3>
              <p className="text-xs text-muted-foreground">Vyberte váš cílový systém pro zobrazení správného příkazu a postupu instalace</p>
            </div>
          </div>
        </div>

        {/* Výběr platformy (Tabs) */}
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
                  <Badge variant="info" className="text-[9px] px-1.5 py-0">{p.badge}</Badge>
                </div>
                <p className="font-bold text-xs leading-tight">{p.name}</p>
              </button>
            );
          })}
        </div>

        {/* Instalační instrukce pro vybranou platformu */}
        <div className="p-4 rounded-xl bg-slate-900 border border-slate-700/80 space-y-3 text-slate-200">
          <div className="flex items-center justify-between flex-wrap gap-2">
            <div className="flex items-center gap-2">
              <currentPlatform.icon className="size-5 text-primary" />
              <h4 className="font-bold text-sm text-white">{currentPlatform.name}</h4>
              <Badge variant="up" className="text-[10px]">{currentPlatform.badge}</Badge>
            </div>
            <button
              type="button"
              onClick={() => handleCopy(currentPlatform.id, currentPlatform.command)}
              className="inline-flex items-center gap-1.5 rounded px-2.5 py-1 bg-primary/20 text-primary text-xs font-semibold hover:bg-primary/30 transition-colors cursor-pointer border border-primary/40"
            >
              {copiedKey === currentPlatform.id ? <Check className="size-3.5 text-emerald-400" /> : <Copy className="size-3.5" />}
              {copiedKey === currentPlatform.id ? 'Zkopírováno!' : 'Kopírovat příkaz'}
            </button>
          </div>

          <p className="text-xs text-slate-300 leading-relaxed">{currentPlatform.desc}</p>

          <div className="p-3 rounded-lg bg-slate-950 font-mono text-xs text-emerald-400 flex items-center justify-between overflow-x-auto border border-slate-800 break-all select-all">
            <code>{currentPlatform.command}</code>
          </div>

          {currentPlatform.extraNote && (
            <p className="text-[11px] text-amber-300/90 bg-amber-950/40 p-2.5 rounded-md border border-amber-800/40 font-mono">
              💡 <strong>Poznámka k nastavení:</strong> {currentPlatform.extraNote}
            </p>
          )}
        </div>
      </Card>

      {/* Status verzí nainstalovaných agentů */}
      <Card className="p-6 space-y-4">
        <div className="flex items-center justify-between border-b border-border pb-3">
          <div className="flex items-center gap-2.5">
            <Cpu className="size-5 text-primary" />
            <h3 className="font-bold text-base">Stav verzí a aktualizací spuštěných agentů ({agents.length})</h3>
          </div>
          <Badge variant={agents.every(a => a.status === 'up') ? "up" : "warning"}>
            {agents.every(a => a.status === 'up') ? "Všichni agenti OK ✅" : "Některý agent vyžaduje pozornost"}
          </Badge>
        </div>

        <div className="space-y-3">
          {loading ? (
            <p className="text-xs text-muted-foreground py-4 text-center">Načítám agenty…</p>
          ) : agents.length === 0 ? (
            <p className="text-xs text-muted-foreground py-4 text-center">Žádní registrovaní agenti v databázi.</p>
          ) : (
            agents.map(a => (
              <div key={a.id} className="p-3.5 rounded-lg bg-secondary/30 border border-border flex items-center justify-between flex-wrap gap-2 text-xs">
                <div>
                  <div className="flex items-center gap-2">
                    <p className="font-bold text-foreground text-sm">{a.name}</p>
                    <Badge variant="info" className="font-mono text-[10px]">{a.type.toUpperCase()}</Badge>
                  </div>
                  <p className="text-muted-foreground font-mono mt-1">
                    OS: <span className="text-foreground font-semibold">{a.os || '—'}</span> · Verze agenta: <span className="text-emerald-400 font-bold">{a.details?.agent_version || a.details?.version || '—'}</span> · Cíl: {a.target}
                  </p>
                </div>
                <div className="flex items-center gap-3">
                  <Badge variant={a.status === 'up' ? 'up' : 'down'}>
                    {a.status === 'up' ? 'Aktivní' : 'Neaktivní'}
                  </Badge>
                </div>
              </div>
            ))
          )}
        </div>
      </Card>

      {/* Bezpečnost & Soukromí */}
      <div className="grid gap-4 md:grid-cols-2">
        <Card className="p-6 space-y-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2.5">
              <Lock className="size-5 text-primary" />
              <h4 className="font-semibold text-sm">Záruka Soukromí & Zero Telemetry</h4>
            </div>
            <Badge variant="up">100% Private</Badge>
          </div>
          <p className="text-xs text-muted-foreground leading-relaxed">
            0 % naměřených dat neopouští vaše servery ani není odesíláno třetím stranám. Všechny metriky se ukládají lokálně ve vaší MySQL/PostgreSQL databázi pod vaší plnou kontrolou.
          </p>
        </Card>

        <Card className="p-6 space-y-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2.5">
              <ShieldCheck className="size-5 text-primary" />
              <h4 className="font-semibold text-sm">Autentizace agentů</h4>
            </div>
            <Badge variant="up">Klíč + TLS</Badge>
          </div>
          <p className="text-xs text-muted-foreground leading-relaxed">
            Běžný telemetrický report agent posílá s unikátním klíčem monitoru přes TLS. Vzdálené příkazy pro OpenWrt routery (Remote Actions) jsou navíc podepsané HMAC-SHA256 s časovým razítkem.
          </p>
        </Card>
      </div>
    </div>
  );
}
