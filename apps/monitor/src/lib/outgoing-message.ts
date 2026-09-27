/**
 * How one row of the outgoing message log reads.
 *
 * Shared by the log page and the per-monitor card on a monitor's detail, so
 * the same kind cannot be called two different things in two places.
 */
import type { AlertTone, Delivery } from '@/api/types';

/**
 * What the message was, in two words.
 *
 * An explicit map, not a composed t() key: the i18n test forbids those,
 * because a missing translation would then slip past it. A kind this app
 * does not know keeps the server's own word instead of being renamed to
 * something friendlier that would be a guess.
 *
 * Every status change is stored as kind `alert`, recoveries included, so an
 * alert row is named by its tone. Maintenance is checked first: its class is
 * `warn`, and "Warning" would misname a planned switch. Without a tone (the
 * filter's option, or a server that does not send one) the label says only
 * what is certain: the state changed.
 */
export function kindLabel(
  kind: string,
  t: (key: string, fallback?: string) => string,
  tone?: AlertTone | null,
  status?: string | null
): string {
  if (kind === 'alert') {
    if (status === 'maintenance') return t('outgoing.kind_alert_maintenance', 'Údržba');
    if (tone === 'bad') return t('outgoing.kind_alert_bad', 'Výstraha výpadku');
    if (tone === 'warn') return t('outgoing.kind_alert_warn', 'Varování');
    if (tone === 'good') return t('outgoing.kind_alert_good', 'Obnovení');
  }
  const byKind: Record<string, string> = {
    alert: t('outgoing.kind_alert', 'Změna stavu'),
    daily_reminder: t('outgoing.kind_daily_reminder', 'Denní připomínka'),
    digest: t('outgoing.kind_digest', 'Souhrnný report'),
    digest_preview: t('outgoing.kind_digest_preview', 'Zkušební souhrn'),
    invitation: t('outgoing.kind_invitation', 'Pozvánka'),
    password_reset: t('outgoing.kind_password_reset', 'Nastavení hesla'),
    subscriber_confirm: t('outgoing.kind_subscriber_confirm', 'Potvrzení odběru'),
    subscriber_broadcast: t('outgoing.kind_subscriber_broadcast', 'Zpráva odběratelům'),
    admin_notice: t('outgoing.kind_admin_notice', 'Upozornění administrátorům'),
    test: t('outgoing.kind_test', 'Testovací zpráva'),
    other: t('outgoing.kind_other', 'Ostatní'),
  };
  return byKind[kind] ?? kind;
}

/** Channel names are brand names; only these two read wrong in lower case. */
export function channelLabel(channel: string, t: (key: string, fallback?: string) => string): string {
  if (channel === 'email') return t('outgoing.channel_email', 'E-mail');
  if (channel === 'sms') return 'SMS';
  // 'none' is the daily reminder's quiet-day row: no channel carried it anywhere.
  if (channel === 'none') return t('outgoing.channel_none', 'žádný kanál');
  return channel;
}

/**
 * How the e-mail left the machine. `smtp` means a mail server acknowledged
 * it, `fallback` only that PHP handed it to the local mailer - which says
 * nothing about whether anything accepted it afterwards. Worth telling
 * apart when somebody asks why a message never arrived.
 */
export function methodLabel(method: string | null, t: (key: string, fallback?: string) => string): string | null {
  if (method === 'smtp') return t('outgoing.method_smtp', 'ověřené SMTP');
  if (method === 'fallback') return t('outgoing.method_fallback', 'místní doručovatel');
  return method;
}

/**
 * What is known about a row's delivery.
 *
 * The quiet reminder day is `skipped` whatever else it says. A server that
 * sends no `delivery` (an older deploy) knows only `ok`, which covered an
 * unconfirmed hand-off too - so it reads as unknown or failed, never as sent.
 */
export function deliveryOf(e: { status: string | null; ok: boolean; delivery?: Delivery }): Delivery {
  if (e.status === 'skipped') return 'skipped';
  if (e.delivery) return e.delivery;
  return e.ok ? 'unknown' : 'failed';
}

/** The result in words: what the badge on the page and the detail say. */
export function deliveryLabel(d: Delivery, t: (key: string, fallback?: string) => string): string {
  if (d === 'sent') return t('outgoing.result_sent', 'Odesláno');
  if (d === 'unknown') return t('outgoing.result_unknown', 'Nepotvrzeno');
  if (d === 'skipped') return t('outgoing.result_skipped', 'Neodesláno, nebylo co hlásit');
  return t('outgoing.result_failed', 'Neodesláno');
}
