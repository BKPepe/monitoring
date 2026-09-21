/**
 * How one row of the outgoing message log reads.
 *
 * Shared by the log page and the per-monitor card on a monitor's detail, so
 * the same kind cannot be called two different things in two places.
 */

/**
 * What the message was, in two words.
 *
 * An explicit map, not a composed t() key: the i18n test forbids those,
 * because a missing translation would then slip past it. A kind this app
 * does not know keeps the server's own word instead of being renamed to
 * something friendlier that would be a guess.
 */
export function kindLabel(kind: string, t: (key: string, fallback?: string) => string): string {
  const byKind: Record<string, string> = {
    alert: t('outgoing.kind_alert', 'Výstraha výpadku'),
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
