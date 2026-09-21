import * as React from 'react';
import { AlertOctagon, Mail, RefreshCw, Search } from 'lucide-react';
import { appApi } from '@/api/app-api';
import type { OutgoingMessage, OutgoingMessagePage } from '@/api/types';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { PageHeader } from '@/components/layout/page-header';
import { StatBlock } from '@/components/stat-block';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { channelLabel, kindLabel, methodLabel } from '@/lib/outgoing-message';

/** One screen of the log. Enough to cover a busy day without a second request. */
const PAGE_SIZE = 50;

/**
 * Failures of the last day among the rows already loaded - a LOWER bound,
 * used only when the server sent no summary. It reads the clock, so it is
 * called when data arrives, never while rendering.
 */
function failuresInLastDay(entries: OutgoingMessage[]): number {
  const dayAgo = Date.now() - 24 * 60 * 60 * 1000;
  return entries.filter((e) => !e.ok && new Date(e.atIso).getTime() >= dayAgo).length;
}

/**
 * A delivery that failed in the last day, said out loud above the table.
 *
 * `exact` is false when the server sent no summary (an older deploy): the
 * count is then only what this page happens to have loaded, so it is
 * announced as a lower bound instead of a number that could be a lie.
 */
function FailureBanner({ failed, exact }: { failed: number; exact: boolean }) {
  const { t } = useLanguage();
  if (failed <= 0) return null;
  return (
    <div role="alert" className="space-y-1 rounded-lg border-2 border-down/60 bg-down/10 p-4">
      <div className="flex items-center gap-2">
        <AlertOctagon aria-hidden="true" className="text-down size-5 shrink-0" />
        <h2 className="text-down text-sm font-bold">
          {exact
            ? t(
                'outgoing.banner_title',
                { n: failed },
                `${failed} messages could not be delivered in the last 24 hours`
              )
            : t(
                'outgoing.banner_title_atleast',
                { n: failed },
                `at least ${failed} messages could not be delivered in the last 24 hours`
              )}
        </h2>
      </div>
      <p className="text-muted-foreground text-xs">
        {t(
          'outgoing.banner_desc',
          'Zpráva nedošla ke svému příjemci. Výpadek, o kterém takhle nepřišla výstraha, jste se nemuseli dozvědět vůbec.'
        )}
      </p>
    </div>
  );
}

/**
 * Everything the monitoring sent out: what it was, where it went and whether
 * it went at all.
 *
 * It exists because the question "did that e-mail actually go out?" had no
 * answer anywhere - the log was written from a single one of the nine places
 * that send mail, and the only way to see it was a card on one monitor's
 * detail page.
 *
 * Admin only, here and on the server: every row names a recipient, which is
 * personal data. Bodies are never stored, so they cannot be shown.
 */
export function OutgoingMessagesPage() {
  const { t, lang } = useLanguage();
  const { session, loading: sessionLoading, isAdmin } = useSession();

  const [kind, setKind] = React.useState('');
  const [channel, setChannel] = React.useState('');
  const [failedOnly, setFailedOnly] = React.useState(false);
  /** What was typed; it filters only once submitted, so no request per keystroke. */
  const [search, setSearch] = React.useState('');
  const [recipient, setRecipient] = React.useState('');

  const [entries, setEntries] = React.useState<OutgoingMessage[]>([]);
  const [summary, setSummary] = React.useState<OutgoingMessagePage['summary']>(null);
  const [options, setOptions] = React.useState<{ kinds: string[]; channels: string[] }>({ kinds: [], channels: [] });
  const [cursor, setCursor] = React.useState<number | null>(null);
  const [fallbackFailed, setFallbackFailed] = React.useState(0);
  const [loading, setLoading] = React.useState(true);
  const [loadingMore, setLoadingMore] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [detail, setDetail] = React.useState<OutgoingMessage | null>(null);
  /**
   * Which request the screen belongs to. Flipping two filters quickly can
   * land the answers out of order, and the older one would then overwrite
   * the newer - a table that does not match the filter above it.
   */
  const request = React.useRef(0);

  const load = React.useCallback(async () => {
    if (!isAdmin) return;
    const mine = ++request.current;
    setLoading(true);
    setError(null);
    try {
      const data = await appApi.getOutgoingMessages({
        kind: kind || undefined,
        channel: channel || undefined,
        failedOnly: failedOnly || undefined,
        q: recipient || undefined,
        limit: PAGE_SIZE,
        summary: true,
      });
      if (mine !== request.current) return;
      setEntries(data.entries ?? []);
      setCursor(data.nextCursor ?? null);
      setSummary(data.summary ?? null);
      setFallbackFailed(failuresInLastDay(data.entries ?? []));
      // The lists are the values present in the WHOLE log, so a filter never
      // removes the option that would undo it.
      if (data.kinds?.length || data.channels?.length) {
        setOptions({ kinds: data.kinds ?? [], channels: data.channels ?? [] });
      }
    } catch (err) {
      if (mine !== request.current) return;
      // A failed read is not proof that nothing was sent - so the table stays
      // away and the failure is what the page shows.
      setError(err instanceof Error ? err.message : String(err));
      setEntries([]);
      setCursor(null);
      setSummary(null);
      setFallbackFailed(0);
    } finally {
      if (mine === request.current) setLoading(false);
    }
  }, [isAdmin, kind, channel, failedOnly, recipient]);

  React.useEffect(() => {
    if (sessionLoading) return;
    void load();
  }, [sessionLoading, load]);

  const loadMore = async () => {
    if (cursor === null) return;
    const mine = request.current;
    setLoadingMore(true);
    try {
      const data = await appApi.getOutgoingMessages({
        kind: kind || undefined,
        channel: channel || undefined,
        failedOnly: failedOnly || undefined,
        q: recipient || undefined,
        beforeId: cursor,
        limit: PAGE_SIZE,
      });
      // A filter changed while the older page was on its way: those rows
      // belong to a list nobody is looking at any more.
      if (mine !== request.current) return;
      setEntries((prev) => [...prev, ...(data.entries ?? [])]);
      // The pages are disjoint, so the lower bound only ever grows by what
      // this page brought.
      setFallbackFailed((prev) => prev + failuresInLastDay(data.entries ?? []));
      setCursor(data.nextCursor ?? null);
    } catch (err) {
      if (mine !== request.current) return;
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setLoadingMore(false);
    }
  };

  if (sessionLoading) {
    return <LoadingState size="page" label={t('outgoing.loading', 'Načítám odchozí zprávy…')} />;
  }

  if (!session?.authenticated || !isAdmin) {
    // The rows name recipients, so this is not a matter of tidiness.
    return (
      <Card className="grid place-items-center gap-2 p-16 text-center">
        <p className="text-lg font-semibold">{t('outgoing.admin_only_title', 'Jen pro administrátora')}</p>
        <p className="text-muted-foreground max-w-md text-sm">
          {t(
            'outgoing.admin_only_desc',
            'Protokol odchozích zpráv obsahuje adresy příjemců, proto je přístupný pouze účtům s rolí administrátor.'
          )}
        </p>
      </Card>
    );
  }

  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  const failed24h = summary ? summary.last24h.failed : fallbackFailed;
  const filtered = kind !== '' || channel !== '' || failedOnly || recipient !== '';
  const selectCls = 'bg-secondary/60 border-input h-9 rounded-md border px-2 text-xs';

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('outgoing.title', 'Odchozí zprávy')}
        subtitle={t(
          'outgoing.subtitle',
          'Co monitoring odeslal, kterým kanálem a jestli to odešlo. Obsah zpráv se neukládá.'
        )}
        icon={<Mail aria-hidden="true" className="text-muted-foreground size-5" />}
        actions={
          <Button variant="outline" size="sm" onClick={() => void load()} disabled={loading} className="gap-2">
            <RefreshCw aria-hidden="true" className={loading ? 'animate-spin' : undefined} />
            {t('outgoing.refresh', 'Obnovit')}
          </Button>
        }
      />

      <FailureBanner failed={failed24h} exact={summary !== null} />

      {/* A dash, not a zero: without a summary from the server nothing was
          measured here, and "0 sent" would be an invented answer. */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatBlock
          label={t('outgoing.stat_24h_total', 'Odesláno za 24 h')}
          value={summary ? summary.last24h.total : null}
        />
        <StatBlock
          label={t('outgoing.stat_24h_failed', 'Neodesláno za 24 h')}
          value={summary ? summary.last24h.failed : null}
          className={summary && summary.last24h.failed > 0 ? 'border-down/40 bg-down/10' : undefined}
        />
        <StatBlock
          label={t('outgoing.stat_7d_total', 'Odesláno za 7 dní')}
          value={summary ? summary.last7d.total : null}
        />
        <StatBlock
          label={t('outgoing.stat_7d_failed', 'Neodesláno za 7 dní')}
          value={summary ? summary.last7d.failed : null}
          className={summary && summary.last7d.failed > 0 ? 'border-down/40 bg-down/10' : undefined}
        />
      </div>

      <Card className="space-y-4 p-4">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label htmlFor="outgoing-kind" className="text-muted-foreground mb-1 block text-2xs font-medium">
              {t('outgoing.filter_kind', 'Druh zprávy')}
            </label>
            <select id="outgoing-kind" value={kind} onChange={(e) => setKind(e.target.value)} className={selectCls}>
              <option value="">{t('outgoing.filter_all', 'Vše')}</option>
              {options.kinds.map((k) => (
                <option key={k} value={k}>
                  {kindLabel(k, t)}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label htmlFor="outgoing-channel" className="text-muted-foreground mb-1 block text-2xs font-medium">
              {t('outgoing.filter_channel', 'Kanál')}
            </label>
            <select
              id="outgoing-channel"
              value={channel}
              onChange={(e) => setChannel(e.target.value)}
              className={selectCls}
            >
              <option value="">{t('outgoing.filter_all', 'Vše')}</option>
              {options.channels.map((c) => (
                <option key={c} value={c}>
                  {channelLabel(c, t)}
                </option>
              ))}
            </select>
          </div>

          <Button
            variant={failedOnly ? 'destructive' : 'outline'}
            size="sm"
            aria-pressed={failedOnly}
            onClick={() => setFailedOnly((v) => !v)}
          >
            {t('outgoing.filter_failed_only', 'Jen neodeslané')}
          </Button>

          <form
            className="flex items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              setRecipient(search.trim());
            }}
          >
            <div>
              <label htmlFor="outgoing-q" className="text-muted-foreground mb-1 block text-2xs font-medium">
                {t('outgoing.filter_recipient', 'Příjemce')}
              </label>
              <Input
                id="outgoing-q"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t('outgoing.filter_recipient_ph', 'část adresy')}
                className="h-9 w-48 text-xs"
              />
            </div>
            <Button type="submit" variant="secondary" size="sm" className="gap-1.5">
              <Search aria-hidden="true" />
              {t('outgoing.filter_apply', 'Hledat')}
            </Button>
          </form>
        </div>

        {error ? (
          <ErrorState
            message={t('outgoing.load_failed', { err: error }, `The message log could not be loaded: ${error}`)}
            onRetry={() => void load()}
          />
        ) : loading ? (
          <LoadingState label={t('outgoing.loading', 'Načítám odchozí zprávy…')} />
        ) : entries.length === 0 ? (
          <EmptyState
            title={
              filtered
                ? t('outgoing.empty_filtered', 'Tomuto filtru neodpovídá žádná zpráva.')
                : t('outgoing.empty', 'Zatím neodešla žádná zpráva.')
            }
            hint={
              filtered
                ? undefined
                : t('outgoing.empty_hint', 'Záznam vzniká při každém pokusu o odeslání, včetně neúspěšných.')
            }
          />
        ) : (
          <>
            <Table dense aria-label={t('outgoing.title', 'Odchozí zprávy')}>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('outgoing.col_time', 'ČAS')}</TableHead>
                  <TableHead>{t('outgoing.col_kind', 'DRUH')}</TableHead>
                  <TableHead>{t('outgoing.col_channel', 'KANÁL')}</TableHead>
                  <TableHead>{t('outgoing.col_recipient', 'PŘÍJEMCE')}</TableHead>
                  <TableHead className="hidden md:table-cell">{t('outgoing.col_subject', 'PŘEDMĚT')}</TableHead>
                  <TableHead>{t('outgoing.col_result', 'VÝSLEDEK')}</TableHead>
                  <TableHead className="hidden lg:table-cell">{t('outgoing.col_error', 'CHYBA')}</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {entries.map((e) => (
                  // A failed row is tinted as well as badged: in a screenful of
                  // rows the eye finds the colour first and the badge second.
                  <TableRow key={e.id} className={e.ok ? undefined : 'bg-down/10'}>
                    <TableCell className="text-muted-foreground font-mono whitespace-nowrap">
                      {new Date(e.atIso).toLocaleString(locale)}
                    </TableCell>
                    <TableCell className="whitespace-nowrap">{kindLabel(e.kind, t)}</TableCell>
                    <TableCell>
                      <Badge variant="neutral">{channelLabel(e.channel, t)}</Badge>
                    </TableCell>
                    <TableCell className="max-w-[14rem] truncate font-mono">{e.recipient ?? '—'}</TableCell>
                    <TableCell className="hidden max-w-[18rem] truncate md:table-cell">{e.subject ?? '—'}</TableCell>
                    <TableCell className="whitespace-nowrap">
                      {/* A skipped row is not a delivery: the daily reminder writes one
                          every quiet day so the silence is provable. Painting it green
                          "Odesláno" would claim a message nobody ever received. */}
                      <Badge variant={e.status === 'skipped' ? 'neutral' : e.ok ? 'up' : 'down'}>
                        {e.status === 'skipped'
                          ? t('outgoing.result_skipped', 'Neodesláno, nebylo co hlásit')
                          : e.ok
                            ? t('outgoing.result_sent', 'Odesláno')
                            : t('outgoing.result_failed', 'Neodesláno')}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-down hidden max-w-[16rem] truncate lg:table-cell">
                      {e.ok ? '' : (e.error ?? t('outgoing.no_error_text', 'kanál zprávu nepřijal'))}
                    </TableCell>
                    <TableCell className="text-right">
                      <Button variant="ghost" size="sm" onClick={() => setDetail(e)}>
                        {t('outgoing.detail', 'Detail')}
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            <div className="flex items-center justify-between gap-3">
              <p className="text-muted-foreground text-2xs">
                {t('outgoing.loaded_count', { n: entries.length }, `${entries.length} messages loaded`)}
              </p>
              {cursor !== null && (
                <Button variant="outline" size="sm" onClick={() => void loadMore()} disabled={loadingMore}>
                  {loadingMore ? t('outgoing.loading_more', 'Načítám…') : t('outgoing.load_more', 'Načíst starší')}
                </Button>
              )}
            </div>
          </>
        )}
      </Card>

      {/* The detail exists because the interesting part - the whole error from
          the channel - does not fit in a table cell and must not be cut off. */}
      <Dialog open={detail !== null} onOpenChange={(open) => !open && setDetail(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{detail ? kindLabel(detail.kind, t) : ''}</DialogTitle>
          </DialogHeader>
          {detail && (
            <dl className="space-y-2 px-5 pb-5 text-xs">
              <DetailRow label={t('outgoing.col_time', 'ČAS')} value={new Date(detail.atIso).toLocaleString(locale)} />
              <DetailRow label={t('outgoing.col_channel', 'KANÁL')} value={channelLabel(detail.channel, t)} />
              <DetailRow label={t('outgoing.col_recipient', 'PŘÍJEMCE')} value={detail.recipient} />
              <DetailRow label={t('outgoing.col_subject', 'PŘEDMĚT')} value={detail.subject} />
              <DetailRow label={t('outgoing.detail_method', 'ZPŮSOB ODESLÁNÍ')} value={methodLabel(detail.method, t)} />
              <DetailRow label={t('outgoing.detail_monitor', 'MONITOR')} value={detail.monitorName} />
              <DetailRow label={t('outgoing.detail_status', 'STAV MONITORU')} value={detail.status} />
              <div className="flex gap-2">
                <dt className="text-muted-foreground w-40 shrink-0">{t('outgoing.col_result', 'VÝSLEDEK')}</dt>
                <dd>
                  <Badge variant={detail.ok ? 'up' : 'down'}>
                    {detail.ok ? t('outgoing.result_sent', 'Odesláno') : t('outgoing.result_failed', 'Neodesláno')}
                  </Badge>
                </dd>
              </div>
              {!detail.ok && (
                <div className="flex gap-2">
                  <dt className="text-muted-foreground w-40 shrink-0">{t('outgoing.col_error', 'CHYBA')}</dt>
                  <dd className="text-down break-words">
                    {detail.error ?? t('outgoing.no_error_text', 'kanál zprávu nepřijal')}
                  </dd>
                </div>
              )}
              <p className="text-muted-foreground pt-2 text-2xs">
                {t('outgoing.detail_no_body', 'Obsah zprávy se neukládá, protokol drží jen předmět a výsledek.')}
              </p>
            </dl>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}

/** One line of the detail. A value nobody recorded is a dash, never a blank. */
function DetailRow({ label, value }: { label: string; value: string | null }) {
  return (
    <div className="flex gap-2">
      <dt className="text-muted-foreground w-40 shrink-0">{label}</dt>
      <dd className="min-w-0 break-words">{value ?? '—'}</dd>
    </div>
  );
}
