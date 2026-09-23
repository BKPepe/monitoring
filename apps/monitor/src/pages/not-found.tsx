import { Link, useNavigate } from 'react-router';
import { Card } from '@/components/ui/card';
import { FileQuestion, Home, ArrowLeft, Activity } from 'lucide-react';
import { useLanguage } from '@/context/language-context';

/**
 * "Not found" for an address the app does not know.
 *
 * `public` is the variant for visitors without an account (an unknown
 * address under /public, or anywhere when nobody is signed in): it leads to
 * the public status page instead of the dashboard behind the login (W1-F5).
 * Route-like addresses answer HTTP 200 (the app decides, not the server), so
 * the page tells search engines itself not to index it.
 */
export function NotFoundPage({ variant = 'app' }: { variant?: 'app' | 'public' }) {
  const { t } = useLanguage();
  const navigate = useNavigate();
  const isPublic = variant === 'public';

  return (
    <div
      className={
        isPublic
          ? 'min-h-screen bg-background text-foreground flex items-center justify-center p-4'
          : 'min-h-[70vh] flex items-center justify-center p-4'
      }
    >
      <title>{t('not_found.doc_title', 'Stránka nenalezena · Blood Kings')}</title>
      <meta name="robots" content="noindex" />
      <Card className="w-full max-w-lg p-8 text-center space-y-6 border-primary/20 shadow-2xl bg-secondary/30 backdrop-blur-xl">
        <div className="relative mx-auto w-20 h-20 rounded-2xl bg-primary/10 border border-primary/30 flex items-center justify-center">
          <FileQuestion className="size-10 text-primary" />
          <span className="absolute -top-1 -right-1 flex h-4 w-4">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-warning opacity-75"></span>
            <span className="relative inline-flex rounded-full h-4 w-4 bg-warning"></span>
          </span>
        </div>

        <div className="space-y-2">
          <BadgeText>{t('not_found.badge', 'CHYBA 404 — STRÁNKA NENALEZENA')}</BadgeText>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">
            {isPublic
              ? t('not_found.public_title', 'Stránka nenalezena')
              : t('not_found.title', 'Požadovaná stránka neexistuje')}
          </h1>
          <p className="text-sm text-muted-foreground leading-relaxed max-w-md mx-auto">
            {t(
              'not_found.desc',
              'Adresa, kterou jste zadali, pravděpodobně nebyla nalezena, byla přejmenována nebo přesunuta.'
            )}
          </p>
        </div>

        <div className="flex items-center justify-center gap-3 pt-2 flex-wrap sm:flex-nowrap">
          <button
            type="button"
            onClick={() => navigate(-1)}
            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-secondary px-4 py-2.5 text-sm font-medium text-secondary-foreground hover:bg-secondary/80 transition-colors"
          >
            <ArrowLeft className="size-4" /> {t('not_found.go_back', 'Zpět na předchozí stránku')}
          </button>

          {isPublic ? (
            <Link
              to="/public"
              className="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors"
            >
              <Activity className="size-4" /> {t('not_found.go_public', 'Stav služeb')}
            </Link>
          ) : (
            <Link
              to="/"
              className="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors"
            >
              <Home className="size-4" /> {t('not_found.go_dashboard', 'Hlavní Dashboard')}
            </Link>
          )}
        </div>
      </Card>
    </div>
  );
}

function BadgeText({ children }: { children: React.ReactNode }) {
  return (
    <span className="inline-block text-3xs font-bold tracking-widest uppercase text-warning bg-warning/10 px-3 py-1 rounded-full border border-warning/20">
      {children}
    </span>
  );
}
