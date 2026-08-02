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
  alertCount: propAlertCount,
  onOpenMobileNav,
}: {
  searchResults?: SearchResult[];
  alertCount?: number;
  onOpenMobileNav?: () => void;
}) {
  const { theme, toggle } = useTheme();
  const { lang, setLang } = useLanguage();
  const [showNotifications, setShowNotifications] = useState(false);
  const [activeAlerts, setActiveAlerts] = useState<any[]>([]);
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
      .then(r => r.json())
      .then(data => {
        if (Array.isArray(data.events)) {
          const downList = data.events.filter((e: any) => e.isDown);
          setActiveAlerts(downList);
        }
      })
      .catch(() => {});
  }, [showNotifications]);

  const alertCount = activeAlerts.length > 0 ? activeAlerts.length : (propAlertCount ?? 0);

  return (
    <header className="bg-background/80 sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-border px-4 backdrop-blur-md">
      <Button
        variant="ghost"
        size="icon"
        className="lg:hidden"
        onClick={onOpenMobileNav}
        aria-label="Otevřít navigaci"
      >
        <Menu />
      </Button>

      <div className="mx-auto w-full max-w-md">
        <SearchCommand results={searchResults} />
      </div>

      <div className="ml-auto flex items-center gap-2 relative" ref={dropdownRef}>
        {/* Přepínač jazyka CS / EN */}
        <div className="flex items-center rounded-md border border-border bg-secondary/50 p-0.5 text-xs font-semibold">
          <button
            type="button"
            onClick={() => setLang('cs')}
            className={cn(
              "px-2 py-1 rounded transition-colors text-[11px]",
              lang === 'cs' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'
            )}
            title="Čeština"
          >
            🇨🇿 CS
          </button>
          <button
            type="button"
            onClick={() => setLang('en')}
            className={cn(
              "px-2 py-1 rounded transition-colors text-[11px]",
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
          aria-label={theme === 'dark' ? 'Přepnout na světlý motiv' : 'Přepnout na tmavý motiv'}
        >
          {theme === 'dark' ? <Moon /> : <Sun />}
        </Button>

        {/* Bell notification button + Dropdown */}
        <div className="relative">
          <Button
            variant="ghost"
            size="icon"
            className="relative cursor-pointer"
            aria-label="Upozornění"
            onClick={() => setShowNotifications(!showNotifications)}
          >
            <Bell />
            {alertCount > 0 && (
              <>
                <span className="bg-destructive text-destructive-foreground absolute top-1 right-1 grid size-4 place-items-center rounded-full text-[10px] font-semibold">
                  {alertCount > 9 ? '9+' : alertCount}
                </span>
                <span className="sr-only">{alertCount} nepřečtených upozornění</span>
              </>
            )}
          </Button>

          {/* Notifikační Popover Menu */}
          {showNotifications && (
            <div className="absolute right-0 mt-2 w-80 sm:w-96 rounded-xl bg-card border border-border shadow-2xl p-4 z-50 animate-in fade-in-50 zoom-in-95">
              <div className="flex items-center justify-between border-b border-border pb-3 mb-3">
                <div className="flex items-center gap-2">
                  <Bell className="size-4 text-primary" />
                  <h4 className="font-bold text-sm">Notifikace & Upozornění</h4>
                </div>
                <span className="text-[11px] text-muted-foreground font-mono">
                  {alertCount > 0 ? `${alertCount} aktivní` : 'Vše OK'}
                </span>
              </div>

              <div className="space-y-2.5 max-h-64 overflow-y-auto pr-1">
                {activeAlerts.length > 0 ? (
                  activeAlerts.map(evt => (
                    <div key={evt.id} className="p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 flex items-start gap-3 text-xs">
                      <AlertTriangle className="size-4 text-rose-400 shrink-0 mt-0.5" />
                      <div>
                        <p className="font-semibold text-rose-400">🔴 Výpadek: {evt.monitorName}</p>
                        <p className="text-muted-foreground mt-0.5">
                          {evt.errorMsg || `${evt.target} neodpovídá.`}
                        </p>
                        <span className="text-[10px] text-muted-foreground block mt-1">{evt.time}</span>
                      </div>
                    </div>
                  ))
                ) : (
                  <div className="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center gap-3 text-xs text-emerald-400">
                    <CheckCircle2 className="size-4 shrink-0" />
                    <span>Všechny monitorované uzly fungují bez závad.</span>
                  </div>
                )}
              </div>

              <div className="border-t border-border mt-3 pt-2 text-right">
                <Link
                  to="/incidents"
                  onClick={() => setShowNotifications(false)}
                  className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                >
                  Zobrazit všechny incidenty <ArrowRight className="size-3" />
                </Link>
              </div>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
