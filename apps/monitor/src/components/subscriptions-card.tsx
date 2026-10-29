import * as React from 'react';
import { BellRing, Check, Save } from 'lucide-react';
import { Panel } from '@/components/ui/panel';
import { useLanguage } from '@/context/language-context';
import { LoadingState, ErrorState } from '@/components/ui/states';

interface SubEntry {
  id: number;
  name: string;
  email: number;
  sms: number;
  whatsapp: number;
}

type Shown = Pick<SubEntry, 'email' | 'sms' | 'whatsapp'>;

const shownOf = (list: SubEntry[]) =>
  new Map<number, Shown>(list.map((s) => [s.id, { email: s.email, sms: s.sms, whatsapp: s.whatsapp }]));

/**
 * Per-monitor notification subscriptions for the logged-in account.
 *
 * Lived on the admin settings page first, but it configures MY notifications,
 * not the system - so it moved to the profile page with the rest of the
 * user-level settings.
 *
 * `profileSwitches` stands for the profile's SMS and WhatsApp switches. A
 * monitor without a stored choice follows them, so after a profile save that
 * flips one of them the card loads again; with boxes changed and not saved yet
 * it says so instead and loads again after its own save.
 */
export function SubscriptionsCard({ profileSwitches = '' }: { profileSwitches?: string }) {
  const { t } = useLanguage();
  const [subs, setSubs] = React.useState<SubEntry[] | null>(null);
  const [saved, setSaved] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  // The last reload after a profile change failed; cleared by one that succeeds.
  const [reloadFailed, setReloadFailed] = React.useState(false);
  // What each box showed when the card loaded (or last saved). The save posts
  // it so the server stores only the boxes changed here: a profile or monitor
  // switch flipped while the card stays open must not be pinned for every
  // monitor with the value the card still shows.
  const [shown, setShown] = React.useState(new Map<number, Shown>());
  // The profile switches the boxes were last loaded under.
  const [loadedFor, setLoadedFor] = React.useState<string | null>(null);
  // A load the profile change left waiting (or that failed) runs after a save.
  const [saves, setSaves] = React.useState(0);
  const unsaved = (subs ?? []).some((s) => {
    const was = shown.get(s.id);
    return !was || was.email !== s.email || was.sms !== s.sms || was.whatsapp !== s.whatsapp;
  });

  React.useEffect(() => {
    // Loaded under these switches already, or boxes changed here and not
    // saved yet, which a load would overwrite.
    if (loadedFor === profileSwitches || unsaved) return;
    let active = true;
    fetch('/status/api.php?action=get_subscriptions', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (!active) return;
        const list: SubEntry[] = Array.isArray(d.subscriptions) ? d.subscriptions : [];
        setShown(shownOf(list));
        setSubs(list);
        setLoadedFor(profileSwitches);
        setReloadFailed(false);
      })
      .catch(() => {
        if (!active) return;
        if (loadedFor === null) setSubs([]);
        else setReloadFailed(true);
      });
    return () => {
      active = false;
    };
  }, [loadedFor, profileSwitches, unsaved, saves]);

  const toggle = (id: number, field: 'email' | 'sms' | 'whatsapp') => {
    setSubs((prev) => (prev ?? []).map((s) => (s.id === id ? { ...s, [field]: s[field] ? 0 : 1 } : s)));
  };

  const save = async () => {
    setError(null);
    const posted = subs ?? [];
    try {
      const res = await fetch('/status/api.php?action=save_subscriptions', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subscriptions: posted.map((s) => ({ ...s, shown: shown.get(s.id) })) }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      // A box changed again after this save is a new change.
      setShown(shownOf(posted));
      setSaves((n) => n + 1);
      setSaved(true);
      setTimeout(() => setSaved(false), 3000);
    } catch (e) {
      // Silently swallowing the error would tell the user the subscriptions saved.
      setError(e instanceof Error ? e.message : t('settings.save_error', 'Uložení se nezdařilo.'));
    }
  };

  return (
    <Panel
      icon={BellRing}
      title={t('settings.subs_title', 'Odběr notifikací pro můj účet')}
      hint={t(
        'settings.subs_desc',
        'Zvolte, pro které monitory chcete dostávat e-mailové, SMS nebo WhatsApp notifikace při výpadku.'
      )}
      bodyClassName="space-y-5"
    >
      {error && <ErrorState size="inline" message={error} />}
      {reloadFailed && (
        <ErrorState
          size="inline"
          message={t(
            'settings.subs_reload_error',
            'Odběry se po změně profilu nepodařilo znovu načíst; zaškrtnutí mohou ukazovat stav před ní.'
          )}
        />
      )}
      {loadedFor !== null && loadedFor !== profileSwitches && unsaved && (
        <ErrorState
          size="inline"
          tone="warning"
          message={t(
            'settings.subs_profile_changed',
            'Profil změnil přepínač SMS nebo WhatsApp. Zaškrtnutí mohou ukazovat stav před touto změnou; po uložení odběrů se načtou znovu.'
          )}
        />
      )}

      {subs === null ? (
        <LoadingState label={t('settings.subs_loading', 'Načítám odběry…')} size="inline" />
      ) : subs.length === 0 ? (
        <p className="text-muted-foreground text-xs">{t('settings.subs_none', 'Žádné monitory k odběru.')}</p>
      ) : (
        <div className="space-y-2">
          <div className="text-muted-foreground grid grid-cols-[1fr_60px_60px_70px] gap-2 px-1 text-3xs font-bold tracking-wider uppercase">
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
              className="inline-flex items-center gap-1.5 rounded bg-primary text-primary-foreground hover:bg-primary/90 px-3 py-1.5 text-xs font-bold shadow-sm transition-colors"
            >
              {saved ? <Check className="size-3.5" /> : <Save className="size-3.5" />}
              {saved ? t('settings.subs_saved', 'Uloženo!') : t('settings.subs_save', 'Uložit odběry')}
            </button>
          </div>
        </div>
      )}
    </Panel>
  );
}
