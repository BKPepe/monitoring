import * as React from 'react';
import { BellRing, Check, Save } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { useLanguage } from '@/context/language-context';

interface SubEntry {
  id: number;
  name: string;
  email: number;
  sms: number;
  whatsapp: number;
}

/**
 * Per-monitor notification subscriptions for the logged-in account.
 *
 * Lived on the admin settings page first, but it configures MY notifications,
 * not the system - so it moved to the profile page with the rest of the
 * user-level settings.
 */
export function SubscriptionsCard() {
  const { t } = useLanguage();
  const [subs, setSubs] = React.useState<SubEntry[] | null>(null);
  const [saved, setSaved] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  React.useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=get_subscriptions', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active) setSubs(Array.isArray(d.subscriptions) ? d.subscriptions : []);
      })
      .catch(() => {
        if (active) setSubs([]);
      });
    return () => {
      active = false;
    };
  }, []);

  const toggle = (id: number, field: 'email' | 'sms' | 'whatsapp') => {
    setSubs((prev) => (prev ?? []).map((s) => (s.id === id ? { ...s, [field]: s[field] ? 0 : 1 } : s)));
  };

  const save = async () => {
    setError(null);
    try {
      const res = await fetch('/status/api.php?action=save_subscriptions', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subscriptions: subs ?? [] }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      setSaved(true);
      setTimeout(() => setSaved(false), 3000);
    } catch (e) {
      // Silently swallowing the error would tell the user the subscriptions saved.
      setError(e instanceof Error ? e.message : t('settings.save_error', 'Uložení se nezdařilo.'));
    }
  };

  return (
    <Card className="space-y-5 p-6">
      <div className="flex items-center gap-3 border-b border-border pb-3">
        <BellRing className="size-5 text-violet-400" />
        <div>
          <h3 className="text-sm font-semibold">{t('settings.subs_title', 'Odběr notifikací pro můj účet')}</h3>
          <p className="text-muted-foreground text-[10px]">
            {t(
              'settings.subs_desc',
              'Zvolte, pro které monitory chcete dostávat e-mailové, SMS nebo WhatsApp notifikace při výpadku.'
            )}
          </p>
        </div>
      </div>

      {error && <p className="text-down text-xs font-semibold">{error}</p>}

      {subs === null ? (
        <p className="text-muted-foreground text-xs">{t('settings.subs_loading', 'Načítám odběry…')}</p>
      ) : subs.length === 0 ? (
        <p className="text-muted-foreground text-xs">{t('settings.subs_none', 'Žádné monitory k odběru.')}</p>
      ) : (
        <div className="space-y-2">
          <div className="text-muted-foreground grid grid-cols-[1fr_60px_60px_70px] gap-2 px-1 text-[10px] font-bold tracking-wider uppercase">
            <span>{t('settings.subs_col_monitor', 'Monitor')}</span>
            <span className="text-center">E-mail</span>
            <span className="text-center">SMS</span>
            <span className="text-center">WhatsApp</span>
          </div>
          {subs.map((s) => (
            <div
              key={s.id}
              className="bg-secondary/30 grid grid-cols-[1fr_60px_60px_70px] items-center gap-2 rounded-lg border border-border/50 p-2 text-xs"
            >
              <span className="truncate font-medium" title={s.name}>
                {s.name}
              </span>
              {(['email', 'sms', 'whatsapp'] as const).map((field) => (
                <label key={field} className="flex cursor-pointer justify-center">
                  <input
                    type="checkbox"
                    checked={s[field] === 1}
                    onChange={() => toggle(s.id, field)}
                    className="rounded border-border"
                  />
                </label>
              ))}
            </div>
          ))}
          <div className="flex justify-end pt-2">
            <button
              type="button"
              onClick={save}
              className="inline-flex items-center gap-1.5 rounded bg-violet-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-colors hover:bg-violet-500"
            >
              {saved ? <Check className="size-3.5" /> : <Save className="size-3.5" />}
              {saved ? t('settings.subs_saved', 'Uloženo!') : t('settings.subs_save', 'Uložit odběry')}
            </button>
          </div>
        </div>
      )}
    </Card>
  );
}
