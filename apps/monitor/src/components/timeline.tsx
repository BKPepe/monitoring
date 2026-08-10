import { Badge, StatusDot } from '@/components/ui/badge';
import type { TimelineEvent } from '@/data/model';
import { Clock, MapPin, Globe } from 'lucide-react';
import { useLanguage } from '@/context/language-context';

const resolutionVariant = {
  Resolved: 'up',
  Info: 'info',
  Open: 'warning',
} as const;

/**
 * Event timeline for a device.
 *
 * Rendered as an <ol> - it's an ordered list in time, which a screen reader
 * announces ("item 2 of 4") and a keyboard user can step through.
 */
export function Timeline({ events }: { events: TimelineEvent[] }) {
  const { t } = useLanguage();
  if (events.length === 0) {
    return (
      <p className="text-muted-foreground py-6 text-center text-sm">{t('timeline.no_events', 'Žádné události.')}</p>
    );
  }

  return (
    <ol className="flex flex-col">
      {events.map((event, index) => (
        <li key={event.id} className="flex gap-3">
          {/* The vertical line connects timeline dots; not drawn for the last one. */}
          <div className="flex flex-col items-center pt-1.5">
            <StatusDot variant={event.severity} />
            {index < events.length - 1 && <span className="bg-border mt-1 w-px flex-1" />}
          </div>

          <div className="min-w-0 flex-1 pb-4 space-y-1.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-sm font-semibold text-foreground">{event.title}</p>
                {event.at && (
                  <span className="inline-flex items-center gap-1 text-[11px] font-mono text-muted-foreground bg-secondary/60 px-2 py-0.5 rounded border border-border shadow-xs">
                    <Clock className="size-3 text-amber-400 shrink-0" />
                    <span>{event.at}</span>
                  </span>
                )}
              </div>
              {event.resolution && <Badge variant={resolutionVariant[event.resolution]}>{event.resolution}</Badge>}
            </div>

            <p className="text-muted-foreground text-xs leading-relaxed">{event.detail}</p>

            {(event.location || event.method) && (
              <div className="flex flex-wrap items-center gap-2 pt-0.5 text-[11px]">
                {event.method && (
                  <span className="inline-flex items-center gap-1.5 font-mono text-muted-foreground bg-secondary/60 px-2 py-0.5 rounded border border-border">
                    <Globe className="size-3 text-sky-400 shrink-0" />
                    <span>
                      {t('timeline.method_label', 'Metoda / Test:')}{' '}
                      <strong className="text-foreground">{event.method}</strong>
                    </span>
                  </span>
                )}
                {event.location && (
                  <span className="inline-flex items-center gap-1.5 font-mono text-muted-foreground bg-secondary/60 px-2 py-0.5 rounded border border-border">
                    <MapPin className="size-3 text-rose-400 shrink-0" />
                    <span>
                      {t('timeline.node_label', 'Uzel:')} <strong className="text-foreground">{event.location}</strong>
                    </span>
                  </span>
                )}
              </div>
            )}
          </div>
        </li>
      ))}
    </ol>
  );
}
