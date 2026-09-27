import { Moon, Sun } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { useTheme } from '@/lib/use-theme';
import { cn } from '@/lib/utils';

/**
 * The slim bar of the public page: the brand, the language and the theme -
 * the only things an anonymous visitor needs from a header. The private
 * app's sidebar and bell stay out (the page is deliberately outside
 * AppShell); the brand is a name, not a heading, because the page's one h1
 * is the page title in the verdict below.
 */
export function PublicHeader({
  siteTitle,
  logoUrl,
  portalUrl,
}: {
  /** null while ui_config is on the way: the name is left empty rather than guessed. */
  siteTitle: string | null;
  logoUrl: string;
  portalUrl: string;
}) {
  const { t, lang, setLang } = useLanguage();
  const { theme, toggle } = useTheme();
  const themeLabel =
    theme === 'dark'
      ? t('header.switch_light', 'Přepnout na světlý motiv')
      : t('header.switch_dark', 'Přepnout na tmavý motiv');

  const brand = (
    <>
      <span className="bg-inset grid size-9 shrink-0 place-items-center rounded-xl border border-border">
        <img
          src={logoUrl || '/status/assets/bk-mark.svg'}
          alt=""
          aria-hidden="true"
          className="size-6 object-contain"
        />
      </span>
      <span className="min-w-0 leading-tight">
        <span className="block truncate text-sm font-semibold">{siteTitle}</span>
        <span className="micro-label block truncate">{t('public.title', 'Stav služeb')}</span>
      </span>
    </>
  );

  return (
    <header className="bg-background/80 sticky top-0 z-30 border-b border-border backdrop-blur-md print:static">
      <div className="mx-auto flex h-14 max-w-6xl items-center gap-3 px-4 sm:h-16 sm:px-6">
        {portalUrl ? (
          <a
            href={portalUrl}
            className="focus-visible:ring-ring flex min-w-0 items-center gap-2.5 rounded-lg focus-visible:ring-2 focus-visible:outline-none"
          >
            {brand}
          </a>
        ) : (
          <div className="flex min-w-0 items-center gap-2.5">{brand}</div>
        )}
        <div className="ml-auto flex shrink-0 items-center gap-2">
          {/* CS / EN: the pressed one is said out loud, not only painted. */}
          <div
            role="group"
            aria-label={t('sidebar.language', 'Jazyk')}
            className="bg-inset flex rounded-lg border border-border p-0.5"
          >
            {(['cs', 'en'] as const).map((code) => (
              <button
                key={code}
                type="button"
                lang={code}
                onClick={() => setLang(code)}
                aria-pressed={lang === code}
                title={code === 'cs' ? 'Čeština' : 'English'}
                className={cn(
                  'focus-visible:ring-ring figure grid h-7 min-w-8 place-items-center rounded-md px-1.5 text-2xs font-semibold uppercase transition-colors focus-visible:ring-2 focus-visible:outline-none',
                  lang === code ? 'bg-raised text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'
                )}
              >
                {code.toUpperCase()}
              </button>
            ))}
          </div>
          <button
            type="button"
            onClick={toggle}
            aria-label={themeLabel}
            title={themeLabel}
            className="text-muted-foreground hover:bg-raised hover:text-foreground focus-visible:ring-ring grid size-9 shrink-0 place-items-center rounded-lg border border-border transition-colors focus-visible:ring-2 focus-visible:outline-none"
          >
            {theme === 'dark' ? (
              <Moon aria-hidden="true" className="size-4" />
            ) : (
              <Sun aria-hidden="true" className="size-4" />
            )}
          </button>
        </div>
      </div>
    </header>
  );
}
