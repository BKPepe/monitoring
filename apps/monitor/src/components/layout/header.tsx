import { useState, useRef, useEffect } from 'react';
import { Link } from 'react-router';
import { Bell, Menu, Moon, Sun, AlertTriangle, CheckCircle2, ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { SearchCommand, type SearchResult } from '@/components/ui/search-command';
import { useTheme } from '@/lib/use-theme';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

export function Header({
  searchResults,
  onSearchSelect,
  alertCount: propAlertCount,
  onOpenMobileNav,
}: {
  searchResults?: SearchResult[];
  onSearchSelect?: (result: SearchResult) => void;
  alertCount?: number;
  onOpenMobileNav?: () => void;
}) {
  const { theme, toggle } = useTheme();
  const { lang, setLang, t } = useLanguage();
  const [showNotifications, setShowNotifications] = useState(false);
  const [activeAlerts, setActiveAlerts] = useState<any[]>([]);
  // Zvonek dřív počítal VŠECHNY historické down události jako "aktivní" -
  // svítilo pak třeba 20 dávno vyřešených výpadků bez možnosti je odbavit.
  // Skutečně probíhající výpadky (propAlertCount) svítí vždy.
  // Přečtenost se drží na serveru u uživatele - localStorage platil jen pro
  // jeden prohlížeč, takže "označit vše jako přečtené" se na jiném počítači
  // (nebo po smazání dat prohlížeče) nikdy neprojevilo.
  const [readUpToId, setReadUpToId] = useState<number>(0);
  useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=alerts_read_state', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        if (active && typeof d?.readUpToId === 'number') setReadUpToId(d.readUpToId);
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, []);
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setShowNotifications(false);
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  useEffect(() => {
    fetch('/status/api.php?action=events&limit=20', { credentials: 'include' })
      .then((r) => r.json())
      .then((data) => {
        if (Array.isArray(data.events)) {
          const downList = data.events.filter((e: any) => e.isDown);
          setActiveAlerts(downList);
        }
      })
      .catch(() => {});
  }, [showNotifications]);

  const unreadAlerts = activeAlerts.filter((e) => typeof e.id === 'number' && e.id > readUpToId);
  const currentlyDown = propAlertCount ?? 0;
  const alertCount = Math.max(unreadAlerts.length, currentlyDown);

  const markAllRead = () => {
    const maxId = activeAlerts.reduce((mx, e) => (typeof e.id === 'number' && e.id > mx ? e.id : mx), readUpToId);
    setReadUpToId(maxId);
    fetch('/status/api.php?action=alerts_read_state', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ readUpToId: maxId }),
    }).catch(() => {});
  };

  return (
    <header className="bg-background/80 sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-border px-4 backdrop-blur-md print:hidden">
      <Button
        variant="ghost"
        size="icon"
        className="lg:hidden"
        onClick={onOpenMobileNav}
        aria-label={t('header.open_nav', 'Otevřít navigaci')}
      >
        <Menu />
      </Button>

      <div className="mx-auto w-full max-w-md">
        <SearchCommand results={searchResults} onSelect={onSearchSelect} />
      </div>

      <div className="ml-auto flex items-center gap-2 relative" ref={dropdownRef}>
        {/* CS / EN language switcher */}
        <div className="flex items-center rounded-md border border-border bg-secondary/50 p-0.5 text-xs font-semibold">
          <button
            type="button"
            onClick={() => setLang('cs')}
            className={cn(
              'px-2 py-1 rounded transition-colors text-[11px]',
              lang === 'cs' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'
            )}
            title={t('settings.lang_cs', 'Čeština')}
          >
            🇨🇿 CS
          </button>
          <button
            type="button"
            onClick={() => setLang('en')}
            className={cn(
              'px-2 py-1 rounded transition-colors text-[11px]',
              lang === 'en' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'
            )}
            title="English"
          >
            🇬🇧 EN
          </button>
        </div>

        <Button
          variant="ghost"
          size="icon"
          onClick={toggle}
          aria-label={
            theme === 'dark'
              ? t('header.switch_light', 'Přepnout na světlý motiv')
              : t('header.switch_dark', 'Přepnout na tmavý motiv')
          }
        >
          {theme === 'dark' ? <Moon /> : <Sun />}
        </Button>

        {/* Bell notification button + Dropdown */}
        <div className="relative">
          <Button
            variant="ghost"
            size="icon"
            className="relative cursor-pointer"
            aria-label={t('header.notifications_aria', 'Upozornění')}
            onClick={() => setShowNotifications(!showNotifications)}
          >
            <Bell />
            {alertCount > 0 && (
              <>
                <span className="bg-destructive text-destructive-foreground absolute top-1 right-1 grid size-4 place-items-center rounded-full text-[10px] font-semibold">
                  {alertCount > 9 ? '9+' : alertCount}
                </span>
                <span className="sr-only">
                  {t('header.unread_alerts', { count: alertCount }, `${alertCount} nepřečtených upozornění`)}
                </span>
              </>
            )}
          </Button>

          {/* Notification popover menu */}
          {showNotifications && (
            <div className="absolute right-0 mt-2 w-80 sm:w-96 rounded-xl bg-card border border-border shadow-2xl p-4 z-50 animate-in fade-in-50 zoom-in-95">
              <div className="flex items-center justify-between border-b border-border pb-3 mb-3">
                <div className="flex items-center gap-2">
                  <Bell className="size-4 text-primary" />
                  <h4 className="font-bold text-sm">{t('settings.notifications', 'Notifikace & Upozornění')}</h4>
                </div>
                <span className="text-[11px] text-muted-foreground font-mono">
                  {currentlyDown > 0
                    ? `${currentlyDown} ${t('header.active_alerts', 'aktivní')}`
                    : unreadAlerts.length > 0
                      ? t('header.unread_count', { count: unreadAlerts.length }, `${unreadAlerts.length} nepřečtených`)
                      : t('header.all_ok', 'Vše OK')}
                </span>
              </div>

              <div className="space-y-2.5 max-h-64 overflow-y-auto pr-1">
                {activeAlerts.length > 0 ? (
                  activeAlerts.map((evt) => (
                    <div
                      key={evt.id}
                      className={cn(
                        'p-3 rounded-lg border flex items-start gap-3 text-xs',
                        evt.id > readUpToId
                          ? 'bg-rose-500/10 border-rose-500/20'
                          : 'bg-secondary/40 border-border opacity-70'
                      )}
                    >
                      <AlertTriangle
                        className={cn(
                          'size-4 shrink-0 mt-0.5',
                          evt.id > readUpToId ? 'text-rose-500 dark:text-rose-400' : 'text-muted-foreground'
                        )}
                      />
                      <div>
                        <p
                          className={cn(
                            'font-semibold',
                            evt.id > readUpToId ? 'text-rose-700 dark:text-rose-400' : 'text-foreground'
                          )}
                        >
                          🔴 {t('header.outage_label', 'Výpadek')}: {evt.monitorName}
                        </p>
                        <p className="text-muted-foreground mt-0.5">
                          {evt.errorMsg ||
                            t('header.target_unresponsive', { target: evt.target }, `${evt.target} neodpovídá.`)}
                        </p>
                        <span className="text-[10px] text-muted-foreground block mt-1">{evt.time}</span>
                      </div>
                    </div>
                  ))
                ) : (
                  <div className="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center gap-3 text-xs font-medium text-emerald-800 dark:text-emerald-300">
                    <CheckCircle2 className="size-4 shrink-0" />
                    <span>{t('header.all_nodes_ok', 'Všechny monitorované uzly fungují bez závad.')}</span>
                  </div>
                )}
              </div>

              <div className="border-t border-border mt-3 pt-2 flex items-center justify-between gap-2">
                {unreadAlerts.length > 0 ? (
                  <button
                    type="button"
                    onClick={markAllRead}
                    className="inline-flex items-center gap-1 text-xs font-semibold text-muted-foreground hover:text-foreground"
                  >
                    <CheckCircle2 className="size-3.5" /> {t('header.mark_all_read', 'Označit vše jako přečtené')}
                  </button>
                ) : (
                  <span />
                )}
                <Link
                  to="/incidents"
                  onClick={() => setShowNotifications(false)}
                  className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                >
                  {t('header.view_all_incidents', 'Zobrazit všechny incidenty')} <ArrowRight className="size-3" />
                </Link>
              </div>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
