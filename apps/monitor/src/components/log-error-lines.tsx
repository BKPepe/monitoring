import { useLanguage } from '@/context/language-context';
import type { LogLinesView } from '@/lib/log-lines';

/**
 * The error lines behind the router's log count (W1-C3).
 *
 * "8 chyb v logu" was a number nobody could act on: which program, what did it
 * say, is it one line repeated eight times or eight different faults. The
 * lines arrive masked by the agent (`<ipv4>`, `<mac>`, `<host>`), so they are
 * printed as they are - never unmasked or guessed at here.
 *
 * `open` starts the list unfolded (the metric page, where the lines ARE the
 * content); the overview keeps them folded under the row.
 */
export function LogErrorLines({ view, open = false }: { view: LogLinesView; open?: boolean }) {
  const { t, lang } = useLanguage();
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';

  if (view.kind === 'absent') return null;
  if (view.kind !== 'lines') {
    const text =
      view.kind === 'off'
        ? view.where === 'monitor'
          ? t('net.log_lines_off_monitor', 'Odesílání řádků je vypnuté (u monitoru)')
          : t('net.log_lines_off_router', 'Odesílání řádků je vypnuté (na routeru)')
        : view.kind === 'none'
          ? t('net.log_lines_none', 'žádná chyba v logu')
          : // Sending is on, yet no list came: the log was not readable.
            `${t('net.log_lines_label', 'Chybové řádky')}: —`;
    return (
      <p data-testid="log-lines" className="text-muted-foreground border-border/40 border-b py-1.5 text-2xs">
        {text}
      </p>
    );
  }

  const now = new Date();
  const when = (ts: number | null) => {
    if (ts == null) return '—';
    const d = new Date(ts * 1000);
    const sameDay = d.toDateString() === now.toDateString();
    return sameDay
      ? d.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })
      : d.toLocaleString(locale, { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' });
  };

  return (
    <details data-testid="log-lines" open={open} className="border-border/40 border-b py-1.5 text-xs">
      <summary className="text-muted-foreground hover:text-foreground cursor-pointer text-2xs">
        {t('net.log_lines_summary', { count: view.lines.length }, `Poslední chybové řádky (${view.lines.length})`)}
      </summary>
      <ul className="mt-1.5 space-y-1">
        {view.lines.map((line, i) => (
          <li key={i} className="bg-muted/40 rounded px-2 py-1 font-mono text-2xs leading-relaxed break-words">
            <span className="text-muted-foreground">{when(line.ts)}</span>
            {line.prog && <span className="text-muted-foreground"> · {line.prog}</span>}
            {line.count > 1 && (
              <span className="text-muted-foreground">
                {' · '}
                {t('net.log_lines_repeat', { count: line.count }, `${line.count}×`)}
              </span>
            )}
            <span className="block">{line.msg}</span>
          </li>
        ))}
      </ul>
      <p className="text-muted-foreground pt-1 text-2xs">
        {t('net.log_lines_masked', 'Adresy, MAC a jména zařízení router zamaskoval ještě před odesláním.')}
      </p>
    </details>
  );
}
