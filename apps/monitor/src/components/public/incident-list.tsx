import { CheckCircle2, Siren } from 'lucide-react';
import { IconTile } from '@/components/ui/icon-tile';
import { Pill } from '@/components/ui/pill';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

interface IncidentUpdate {
  status: string;
  message: string;
  at: string;
}

export interface PublicIncident {
  id: number;
  title: string;
  /** 'open' | 'investigating' | 'resolved' - the state decides, not a guess. */
  status: string;
  impact: string | null;
  /** The monitor the lifecycle opened it for; null = announced by hand for no one monitor. */
  monitorId?: number | null;
  createdAt: string;
  resolvedAt: string | null;
  durationText: string | null;
  /** Resolution progress (investigating -> identified -> ...) from incident_updates. */
  updates?: IncidentUpdate[];
  /** The post-resolution summary - the admin writes it precisely for the public. */
  postmortem?: string | null;
}

/**
 * The incidents as NetPulse alert rows: an icon tile, the title with a chip
 * that SAYS the state ("Probíhá" / "Vyřešeno" - a green dot next to
 * "Outage: ..." read as a contradiction), the times, the progress the admin
 * wrote and, once resolved, the postmortem. An ongoing one gets the lifted
 * ground and the red edge; the chip still carries the word.
 */
export function IncidentList({ incidents }: { incidents: readonly PublicIncident[] }) {
  const { t } = useLanguage();
  return (
    <ul className="flex flex-col gap-2">
      {incidents.map((inc) => {
        const resolved = inc.status === 'resolved';
        return (
          <li
            key={inc.id}
            className={cn(
              'flex gap-3 rounded-lg border-l-2 px-3 py-3',
              resolved ? 'border-l-transparent' : 'bg-raised border-l-down'
            )}
          >
            <IconTile icon={resolved ? CheckCircle2 : Siren} tone={resolved ? 'neutral' : 'down'} size="sm" />
            <div className="min-w-0 flex-1 space-y-1.5">
              <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className="min-w-0 text-sm font-medium">{inc.title}</span>
                <Pill size="sm" dot tone={resolved ? 'up' : 'down'}>
                  {resolved ? t('public.incident_resolved', 'Vyřešeno') : t('public.incident_open', 'Probíhá')}
                </Pill>
              </div>
              {/* Without seconds: with them the range wrapped mid-time on a narrow
                  display. Minute precision is enough here - the duration is stated
                  by durationText. */}
              <p className="figure text-muted-foreground text-xs">
                {noSeconds(inc.createdAt)}
                {resolved && inc.resolvedAt
                  ? ` → ${noSeconds(inc.resolvedAt)}${inc.durationText ? ` (${inc.durationText})` : ''}`
                  : ''}
              </p>
              {/* Resolution progress - the same timeline the admin sees. A status
                  page that can only say "broken/fixed" makes people ask on Discord;
                  this is that answer. */}
              {(inc.updates ?? []).length > 0 && (
                <ul className="space-y-1 border-l border-border pl-3">
                  {(inc.updates ?? []).map((u, i) => (
                    <li key={i} className="text-muted-foreground text-xs">
                      <span className="text-foreground font-medium">{updateStatusLabel(u.status, t)}</span>
                      {u.message ? ` — ${u.message}` : ''}
                      <span className="figure ml-1">({noSeconds(u.at)})</span>
                    </li>
                  ))}
                </ul>
              )}
              {/* The postmortem after resolution: the admin UI could always write it,
                  but the public never saw it - though it is written precisely for them. */}
              {resolved && inc.postmortem && (
                <div className="bg-inset rounded-md border border-border p-2.5">
                  <p className="micro-label mb-1">{t('public.postmortem', 'Co se stalo (postmortem)')}</p>
                  <p className="text-muted-foreground text-xs whitespace-pre-wrap">{inc.postmortem}</p>
                </div>
              )}
            </div>
          </li>
        );
      })}
    </ul>
  );
}

/** States from incident_updates - enumerated, so a missing translation cannot leak into EN. */
function updateStatusLabel(
  status: string,
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string
): string {
  switch (status) {
    case 'open':
      return t('public.upd_open', 'Nahlášeno');
    case 'investigating':
      return t('public.upd_investigating', 'Vyšetřuje se');
    case 'identified':
      return t('public.upd_identified', 'Příčina nalezena');
    case 'monitoring':
      return t('public.upd_monitoring', 'Sledujeme');
    case 'resolved':
      return t('public.upd_resolved', 'Vyřešeno');
    default:
      return status;
  }
}

/** "08.08.2026 00:21:11" -> "08.08.2026 00:21" - seconds add wrap, not meaning. */
function noSeconds(v: string): string {
  return v.replace(/(\d{1,2}:\d{2}):\d{2}/, '$1');
}
