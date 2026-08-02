import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Terminal, Copy, Check, ShieldCheck, Lock, Cpu } from 'lucide-react';
import { useState, useEffect } from 'react';
import { useSession } from '@/api/use-session';
import { appApi } from '@/api/app-api';
import { Link } from 'react-router';

export function ApiAgentsPage() {
  const { session } = useSession();
  const [copied, setCopied] = useState(false);
  const [agents, setAgents] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    appApi.getMonitors().then((rows) => {
      const list = Array.isArray(rows) ? rows : (rows as any)?.monitors ?? [];
      const agentMonitors = list.filter((m: any) => {
        const t = (m.type || '').toLowerCase();
        const n = (m.name || '').toLowerCase();
        return (
          t === 'openwrt' ||
          t === 'vps' ||
          t === 'agent' ||
          t === 'router' ||
          t === 'teamspeak' ||
          t === 'minecraft' ||
          n.includes('donald') ||
          n.includes('router') ||
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
          <p className="font-semibold text-lg">Přihlášení vyžadováno</p>
          <p className="text-muted-foreground text-sm max-w-md">
            Správa API klíčů a instalačních skriptů je přístupná pouze přihlášeným administrátorům.
          </p>
        </div>
        <Link to="/setup" className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors">
          Přejít na přihlášení
        </Link>
      </Card>
    );
  }

  const command = 'curl -sSL https://bloodkings.eu/agent.sh | bash -s -- --key=YOUR_AGENT_KEY';

  const copyCommand = () => {
    navigator.clipboard.writeText(command);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">API Klíče, Bezpečnost & Správa Agentů</h1>
          <p className="text-muted-foreground text-sm">Verze agentů, kontrola bezpečnostních aktualizací, HMAC klíče a instalace.</p>
        </div>
      </div>

      {/* Rychlá instalace agenta */}
      <Card className="p-6 space-y-4">
        <div className="flex items-center gap-3 border-b border-border pb-3">
          <Terminal className="size-5 text-primary" />
          <h3 className="font-semibold text-base">Rychlá instalace agenta (Linux / OpenWrt / Raspberry Pi)</h3>
        </div>

        <p className="text-sm text-muted-foreground">
          Spusťte tento jednorázový příkaz v terminálu vašeho serveru pro automatickou instalaci a spuštění agenta.
        </p>

        <div className="p-3 rounded-lg bg-slate-950 font-mono text-xs text-slate-200 flex items-center justify-between overflow-x-auto border border-border">
          <code>{command}</code>
          <button type="button" onClick={copyCommand} className="ml-3 p-1.5 rounded hover:bg-slate-800 text-slate-400 hover:text-slate-200 transition-colors shrink-0">
            {copied ? <Check className="size-4 text-emerald-400" /> : <Copy className="size-4" />}
          </button>
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
