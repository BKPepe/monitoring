import { Card } from '@/components/ui/card';
import { Gamepad2, Users, Gauge, MessageSquare } from 'lucide-react';
import { useLanguage } from '@/context/language-context';

/**
 * Detail Minecraft serveru.
 *
 * MOTD, verze, seznam hráčů a TPS se sbíraly při každé kontrole, ale
 * v aplikaci se nezobrazovalo nic z toho - jediné, co o serveru šlo poznat,
 * byla odezva.
 *
 * TPS umí vrátit jen RCON. Když nakonfigurované není, hodnoty zůstávají
 * prázdné a karta řekne proč, místo aby předstírala plynulý běh.
 */
export function MinecraftCard({ d }: { d: Record<string, any> }) {
  const { t } = useLanguage();

  const online = typeof d.players_online === 'number' ? d.players_online : null;
  const max = typeof d.players_max === 'number' ? d.players_max : null;
  const list: string[] = Array.isArray(d.players_list)
    ? d.players_list.filter((p: unknown) => typeof p === 'string')
    : [];

  const tps = [
    { key: '1m', label: t('mc.tps_1m', '1 min'), value: d.tps_1m },
    { key: '5m', label: t('mc.tps_5m', '5 min'), value: d.tps_5m },
    { key: '15m', label: t('mc.tps_15m', '15 min'), value: d.tps_15m },
  ];
  const hasTps = tps.some((x) => typeof x.value === 'number');

  // 20 TPS je strop; níž znamená, že server nestíhá tikat včas.
  const tpsTone = (v: number) => (v >= 19.5 ? 'text-up' : v >= 18 ? 'text-warning' : 'text-down');

  const fill = online != null && max != null && max > 0 ? (online / max) * 100 : null;

  return (
    <Card className="space-y-4 p-6">
      <div className="flex items-center gap-3 border-b border-border pb-3">
        <Gamepad2 className="size-5 text-emerald-500" />
        <div className="min-w-0">
          <h3 className="text-base font-bold">{t('mc.title', 'Minecraft server')}</h3>
          <p className="text-muted-foreground text-xs">
            {d.version
              ? t('mc.version', { version: d.version }, `Verze ${d.version}`)
              : t('mc.subtitle', 'Stav serveru')}
          </p>
        </div>
      </div>

      {d.motd && (
        <div className="rounded-lg border border-border p-3">
          <div className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
            <MessageSquare className="size-3.5" /> MOTD
          </div>
          <p className="mt-1 font-mono text-sm">{d.motd}</p>
        </div>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        {/* Hráči -------------------------------------------------------- */}
        <div className="rounded-lg border border-border p-3">
          <div className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
            <Users className="size-3.5" /> {t('mc.players', 'Hráči')}
          </div>
          <p className="mt-1 text-2xl font-bold tracking-tight">
            {online == null ? '—' : online}
            {max != null && <span className="text-muted-foreground text-sm font-medium"> / {max}</span>}
          </p>
          {fill != null && (
            <div className="bg-muted mt-2 h-1.5 w-full overflow-hidden rounded-full">
              <div className="bg-primary h-full rounded-full" style={{ width: `${Math.min(100, fill)}%` }} />
            </div>
          )}
          {list.length > 0 ? (
            <div className="mt-2 flex flex-wrap gap-1">
              {list.map((p) => (
                <span key={p} className="bg-secondary/50 rounded-md px-2 py-0.5 text-xs">
                  {p}
                </span>
              ))}
            </div>
          ) : online === 0 ? (
            <p className="text-muted-foreground mt-2 text-[11px]">{t('mc.nobody', 'Nikdo není připojen')}</p>
          ) : (
            online != null && (
              // Server hlásí počet, ale jmenný seznam ne - běžné u serverů
              // se skrytým seznamem hráčů.
              <p className="text-muted-foreground mt-2 text-[11px]">
                {t('mc.list_hidden', 'Server jmenný seznam hráčů neposkytuje')}
              </p>
            )
          )}
        </div>

        {/* TPS ---------------------------------------------------------- */}
        <div className="rounded-lg border border-border p-3">
          <div className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
            <Gauge className="size-3.5" /> {t('mc.tps', 'TPS (tiků za sekundu)')}
          </div>
          {hasTps ? (
            <div className="mt-1.5 flex gap-4">
              {tps.map((x) => (
                <div key={x.key}>
                  <p className="text-muted-foreground text-[11px]">{x.label}</p>
                  <p className={`font-mono text-lg font-bold ${typeof x.value === 'number' ? tpsTone(x.value) : ''}`}>
                    {typeof x.value === 'number' ? x.value.toFixed(1) : '—'}
                  </p>
                </div>
              ))}
            </div>
          ) : (
            <>
              <p className="text-muted-foreground mt-1 text-sm">—</p>
              <p className="text-muted-foreground mt-1 text-[11px] leading-relaxed">
                {t(
                  'mc.tps_needs_rcon',
                  'TPS umí vrátit jen RCON. Doplňte RCON port a heslo v nastavení monitoru a hodnoty se začnou sbírat.'
                )}
              </p>
            </>
          )}
        </div>
      </div>
    </Card>
  );
}
