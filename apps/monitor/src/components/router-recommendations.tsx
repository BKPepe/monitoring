import * as React from 'react';
import { BellOff, Check, ClipboardList, Copy } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states';
import { appApi } from '@/api/app-api';
import type { RecommendationArea, RouterRecommendation, RouterRecommendationsResponse } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { groupRecommendations, severityTone } from '@/lib/router-recs';
import { cn } from '@/lib/utils';

/**
 * Router recommendations: what the weekly engine found on this router.
 *
 * The rules and every sentence are the server's - the page shows exactly what
 * the Monday e-mail says, in the same order. This file only lays them out and
 * offers the mute.
 *
 * One request serves the whole page: the full card on the overview and the
 * compact per-area copies on the other tabs all read the same answer, so they
 * cannot disagree and a mute shows everywhere at once.
 */
export type RouterRecommendationsState =
  | { status: 'loading' }
  // A failed request is its own state: "could not load" must never read as "nothing to do".
  | { status: 'error' }
  | { status: 'ready'; data: RouterRecommendationsResponse };

export interface RouterRecommendationsSource {
  state: RouterRecommendationsState;
  reload: () => void;
}

const LOADING: RouterRecommendationsState = { status: 'loading' };

/** @param monitorId null = not a router, nothing is asked. */
export function useRouterRecommendations(monitorId: number | null): RouterRecommendationsSource {
  const { lang } = useLanguage();
  // The answer remembers which router it is for: the page component survives a
  // move to another router, and that router must not show this one's list
  // while its own is on the way.
  const [answer, setAnswer] = React.useState<{ forId: number; state: RouterRecommendationsState } | null>(null);
  const [token, setToken] = React.useState(0);

  React.useEffect(() => {
    if (monitorId == null) return;
    let active = true;
    appApi
      .getRouterRecommendations(monitorId, lang)
      .then((data) => {
        if (!active) return;
        // A 200 that is not the documented shape is a failure too, not an empty list.
        const ok = data != null && Array.isArray(data.items);
        setAnswer({ forId: monitorId, state: ok ? { status: 'ready', data } : { status: 'error' } });
      })
      .catch(() => {
        if (active) setAnswer({ forId: monitorId, state: { status: 'error' } });
      });
    return () => {
      active = false;
    };
  }, [monitorId, lang, token]);

  const reload = React.useCallback(() => setToken((n) => n + 1), []);
  return { state: answer && answer.forId === monitorId ? answer.state : LOADING, reload };
}

/** A date-only value is a calendar day: parsed as local midnight, so no time zone can move it to the day before. */
function formatDay(value: string | null | undefined, locale: string): string | null {
  if (!value) return null;
  const date = new Date(/^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00` : value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(locale);
}

const ROW_TONE: Record<ReturnType<typeof severityTone>, string> = {
  down: 'border-down/30 bg-down/10',
  warning: 'border-warning/30 bg-warning/10',
  info: 'border-info/30 bg-info/10',
};

function CommandBlock({ command }: { command: string }) {
  const { t } = useLanguage();
  const [copied, setCopied] = React.useState(false);

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(command);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };

  return (
    <div className="mt-1.5 flex items-start gap-2">
      <pre className="border-border bg-muted text-foreground min-w-0 flex-1 overflow-x-auto rounded-lg border p-2 font-mono text-2xs whitespace-pre">
        <code>{command}</code>
      </pre>
      <button
        type="button"
        onClick={() => void copy()}
        className="border-primary/30 bg-primary/10 text-primary hover:bg-primary/20 inline-flex shrink-0 items-center gap-1 rounded border px-2 py-0.5 text-2xs font-semibold"
      >
        {copied ? <Check className="size-3" aria-hidden="true" /> : <Copy className="size-3" aria-hidden="true" />}
        {copied ? t('rec.copied', 'Zkopírováno') : t('rec.copy', 'Kopírovat')}
      </button>
    </div>
  );
}

type TranslateFn = ReturnType<typeof useLanguage>['t'];

/** Spelled out key by key: a composed key could not be checked by the dictionary test. */
function severityLabel(severity: RouterRecommendation['severity'], t: TranslateFn): string {
  if (severity === 'critical') return t('rec.severity_critical', 'Kritické');
  if (severity === 'info') return t('rec.severity_info', 'Pro informaci');
  return t('rec.severity_warning', 'Varování');
}

function RecommendationRow({
  item,
  locale,
  onMute,
}: {
  item: RouterRecommendation;
  locale: string;
  /** Absent for a viewer who may not mute, and in the compact copies. */
  onMute?: (item: RouterRecommendation) => void;
}) {
  const { t } = useLanguage();
  const tone = severityTone(item.severity);
  const since = formatDay(item.openSince, locale);

  return (
    <li className={cn('rounded-lg border p-3 text-xs leading-relaxed', ROW_TONE[tone])}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          <Badge variant={tone}>{severityLabel(item.severity, t)}</Badge>
          <p className="text-sm font-semibold">{item.title ?? item.key}</p>
        </div>
        {onMute && (
          <Button size="sm" variant="outline" onClick={() => onMute(item)} className="shrink-0 gap-1.5">
            <BellOff aria-hidden="true" />
            {t('rec.mute', 'Ztlumit')}
          </Button>
        )}
      </div>
      {item.measured && (
        <p className="mt-1.5">
          <span className="text-muted-foreground">{t('rec.measured', 'Zjištěno')}:</span> {item.measured}
        </p>
      )}
      {item.action && (
        <p>
          <span className="text-muted-foreground">{t('rec.action', 'Co udělat')}:</span> {item.action}
        </p>
      )}
      {item.command && <CommandBlock command={item.command} />}
      {since && (
        <p className="text-muted-foreground mt-1.5 text-2xs">
          {t('rec.open_since', { date: since }, `trvá od ${since}`)}
        </p>
      )}
      {item.wasMuted && (
        <p className="text-muted-foreground text-2xs">{t('rec.was_muted', 'Bylo ztlumené, ale závažnost vzrostla.')}</p>
      )}
    </li>
  );
}

/** A muted item stays on the page with who muted it and why - a mute nobody can see is a lost finding. */
function MutedRow({
  item,
  locale,
  busy,
  failed,
  onUnmute,
}: {
  item: RouterRecommendation;
  locale: string;
  busy: boolean;
  failed: boolean;
  onUnmute?: (item: RouterRecommendation) => void;
}) {
  const { t } = useLanguage();
  const mute = item.mute ?? null;
  const when = formatDay(mute?.at, locale);

  return (
    <li className="border-border bg-secondary/40 rounded-lg border p-3 text-xs leading-relaxed">
      <div className="flex flex-wrap items-start justify-between gap-2">
        {/* A mute whose rule does not fire now has no texts; its key still tells the admin what it is. */}
        <p className={cn('font-semibold', item.title ? 'text-sm' : 'font-mono')}>{item.title ?? item.key}</p>
        {onUnmute && (
          <Button size="sm" variant="outline" disabled={busy} onClick={() => onUnmute(item)} className="shrink-0">
            {t('rec.unmute', 'Zrušit ztlumení')}
          </Button>
        )}
      </div>
      {item.active === false && (
        <p className="text-muted-foreground">
          {t('rec.muted_inactive', 'Toto doporučení teď neplatí; ztlumení zůstává uložené.')}
        </p>
      )}
      {item.measured && (
        <p className="mt-1">
          <span className="text-muted-foreground">{t('rec.measured', 'Zjištěno')}:</span> {item.measured}
        </p>
      )}
      {mute && (
        <p className="text-muted-foreground mt-1 text-2xs">
          {t('rec.muted_by', { who: mute.by, date: when ?? '—' }, `Ztlumil(a) ${mute.by} dne ${when ?? '—'}`)}
          {mute.reason ? ` · ${t('rec.muted_reason', { reason: mute.reason }, `Důvod: ${mute.reason}`)}` : ''}
        </p>
      )}
      {failed && (
        <ErrorState size="inline" className="mt-1" message={t('rec.mute_failed', 'Ztlumení se nepodařilo uložit.')} />
      )}
    </li>
  );
}

function MuteDialog({
  monitorId,
  item,
  onClose,
  onSaved,
}: {
  monitorId: number;
  item: RouterRecommendation;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useLanguage();
  const [reason, setReason] = React.useState('');
  const [saving, setSaving] = React.useState(false);
  const [failed, setFailed] = React.useState(false);
  const reasonId = React.useId();

  const save = async () => {
    setSaving(true);
    setFailed(false);
    try {
      const res = await appApi.muteRouterRecommendation(monitorId, item.key, true, reason);
      // A 200 without the confirmation is not a saved mute.
      if (!res || res.ok !== true) throw new Error('not saved');
      onSaved();
    } catch {
      setFailed(true);
      setSaving(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('rec.mute_title', 'Ztlumit doporučení pro tento router')}</DialogTitle>
          <DialogDescription>
            {t(
              'rec.mute_desc',
              'Ztlumené doporučení zůstane vidět tady v seznamu „Ztlumená“, ale přestane chodit v pondělním e-mailu. Pokud se jeho závažnost zvýší, ozve se znovu.'
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-2 px-5 pb-4">
          <p className="text-sm font-semibold">{item.title ?? item.key}</p>
          <label htmlFor={reasonId} className="text-muted-foreground block text-xs">
            {t('rec.mute_reason', 'Důvod (nepovinný), např. „6 GHz obsluhuje jiný přístupový bod“')}
          </label>
          <textarea
            id={reasonId}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            maxLength={255}
            rows={3}
            className="border-border bg-background w-full rounded-md border px-2.5 py-1.5 text-xs"
          />
          {failed && <ErrorState size="inline" message={t('rec.mute_failed', 'Ztlumení se nepodařilo uložit.')} />}
        </div>
        <DialogFooter>
          <Button variant="outline" size="sm" onClick={onClose}>
            {t('common.cancel', 'Zrušit')}
          </Button>
          <Button size="sm" onClick={() => void save()} disabled={saving} className="font-semibold">
            {saving ? t('common.saving', 'Ukládám…') : t('rec.mute', 'Ztlumit')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function RouterRecommendations({
  monitorId,
  source,
  area,
  compact = false,
  agentVersion,
  onShowAll,
  anchorId = 'router-recommendations',
}: {
  monitorId: number;
  /** The page's single request (`useRouterRecommendations`), shared by every copy of the card. */
  source: RouterRecommendationsSource;
  /** Only the items of one area - for the copy next to the card the items are about. */
  area?: RecommendationArea;
  /** Urgent items only, no mute controls, a link to the full list. Renders nothing when the area is fine. */
  compact?: boolean;
  /** Named in the "agent too old" text. */
  agentVersion?: string | null;
  /** Compact only: takes the reader to the full card. */
  onShowAll?: () => void;
  /** The card's element id. The Insights page lists several routers, and an id must stay unique on a page. */
  anchorId?: string;
}) {
  const { t, lang } = useLanguage();
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  const [muting, setMuting] = React.useState<RouterRecommendation | null>(null);
  const [busyKey, setBusyKey] = React.useState<string | null>(null);
  const [failedKey, setFailedKey] = React.useState<string | null>(null);

  const { state, reload } = source;
  const data = state.status === 'ready' ? state.data : null;
  const inArea = (item: RouterRecommendation) => !area || item.area === area;
  const { urgent, info } = groupRecommendations(data?.items.filter(inArea));
  const muted = (Array.isArray(data?.muted) ? data.muted : []).filter(inArea);

  const unmute = async (item: RouterRecommendation) => {
    setBusyKey(item.key);
    setFailedKey(null);
    try {
      const res = await appApi.muteRouterRecommendation(monitorId, item.key, false);
      if (!res || res.ok !== true) throw new Error('not saved');
      reload();
    } catch {
      setFailedKey(item.key);
    } finally {
      setBusyKey(null);
    }
  };

  const error = (
    <ErrorState
      size={compact ? 'inline' : 'block'}
      message={t('rec.error', 'Doporučení se nepodařilo načíst.')}
      onRetry={reload}
    />
  );

  if (compact) {
    // The compact copy only points at trouble; while it loads, or when the
    // area is fine, it takes no room. A failure is still said: silence here
    // would read as "nothing wrong with the Wi-Fi".
    if (state.status === 'error') return error;
    if (!data || !data.applicable || urgent.length === 0) return null;
    return (
      <div className="space-y-2">
        <ul className="space-y-2">
          {urgent.map((item) => (
            <RecommendationRow key={item.key} item={item} locale={locale} />
          ))}
        </ul>
        {onShowAll && (
          <Button variant="link" size="sm" onClick={onShowAll} className="h-auto px-0">
            {t('rec.all', 'Všechna doporučení')}
          </Button>
        )}
      </div>
    );
  }

  // Not a router: the card has nothing to say, not even "no recommendations".
  if (data && !data.applicable && data.reason !== 'agent_old' && data.reason !== 'silent') return null;

  const from = formatDay(data?.window?.from, locale);
  const to = formatDay(data?.window?.to, locale);
  const days = data?.window?.daysWithData;
  const canMute = data?.canMute === true;

  return (
    <Card id={anchorId} className="space-y-4 p-6">
      <div className="border-border flex items-start gap-3 border-b pb-3">
        <ClipboardList aria-hidden="true" className="text-primary mt-0.5 size-5 shrink-0" />
        <div>
          <h3 className="text-base font-bold">{t('rec.title', 'Doporučení pro router')}</h3>
          {from && to && (
            <p className="text-muted-foreground text-xs">
              {t(
                'rec.subtitle',
                { from, to },
                `Z měření za posledních 7 dní (${from}–${to}) a z aktuálního nastavení. Stejný seznam chodí v pondělním e-mailu.`
              )}
            </p>
          )}
        </div>
      </div>

      {state.status === 'loading' && <LoadingState label={t('net.link_loading', 'Načítám…')} />}
      {state.status === 'error' && error}

      {data && !data.applicable && (
        <EmptyState
          title={
            data.reason === 'agent_old'
              ? t(
                  'rec.agent_old',
                  { version: agentVersion || '—' },
                  `Doporučení posílá agent 0.1.7 a novější (router hlásí ${agentVersion || '—'}).`
                )
              : t('rec.silent', 'Router se delší dobu neozval, doporučení nejde spočítat.')
          }
        />
      )}

      {data && data.applicable && (
        <>
          {typeof days === 'number' && days < 4 && (
            <p className="border-info/30 bg-info/10 text-info rounded-lg border p-2.5 text-2xs leading-relaxed">
              {t('rec.few_days', { days }, `Týdenní doporučení potřebují aspoň 4 dny měření – zatím jsou ${days}.`)}
            </p>
          )}

          {urgent.length === 0 && info.length === 0 && (
            <EmptyState title={t('rec.none', 'Žádné doporučení – vše, co se měří, je v pořádku.')} />
          )}

          {urgent.length > 0 && (
            <ul className="space-y-2">
              {urgent.map((item) => (
                <RecommendationRow
                  key={item.key}
                  item={item}
                  locale={locale}
                  onMute={canMute ? setMuting : undefined}
                />
              ))}
            </ul>
          )}

          {info.length > 0 && (
            <details>
              <summary className="text-muted-foreground hover:text-foreground cursor-pointer text-xs font-medium">
                {t('rec.info_group', { n: info.length }, `Pro informaci (${info.length})`)}
              </summary>
              <ul className="mt-2 space-y-2">
                {info.map((item) => (
                  <RecommendationRow
                    key={item.key}
                    item={item}
                    locale={locale}
                    onMute={canMute ? setMuting : undefined}
                  />
                ))}
              </ul>
            </details>
          )}

          {muted.length > 0 && (
            <details>
              <summary className="text-muted-foreground hover:text-foreground cursor-pointer text-xs font-medium">
                {t('rec.muted_group', { n: muted.length }, `Ztlumená (${muted.length})`)}
              </summary>
              <ul className="mt-2 space-y-2">
                {muted.map((item) => (
                  <MutedRow
                    key={item.key}
                    item={item}
                    locale={locale}
                    busy={busyKey === item.key}
                    failed={failedKey === item.key}
                    onUnmute={canMute ? (it) => void unmute(it) : undefined}
                  />
                ))}
              </ul>
            </details>
          )}
        </>
      )}

      {muting && (
        <MuteDialog
          monitorId={monitorId}
          item={muting}
          onClose={() => setMuting(null)}
          onSaved={() => {
            setMuting(null);
            reload();
          }}
        />
      )}
    </Card>
  );
}
