import { Link, useNavigate } from 'react-router';
import { Panel } from '@/components/ui/panel';
import { Pill } from '@/components/ui/pill';
import { IconTile } from '@/components/ui/icon-tile';
import { Button } from '@/components/ui/button';
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
      <Panel className="w-full max-w-lg" bodyClassName="space-y-6 text-center sm:p-8">
        <div className="relative mx-auto w-fit">
          <IconTile icon={FileQuestion} tone="primary" size="lg" className="size-16 rounded-2xl [&>svg]:size-8" />
          <span aria-hidden="true" className="absolute -top-1 -right-1 flex size-3.5">
            <span className="bg-warning absolute inline-flex size-full animate-ping rounded-full opacity-75 motion-reduce:animate-none" />
            <span className="bg-warning relative inline-flex size-3.5 rounded-full" />
          </span>
        </div>

        <div className="space-y-2">
          <Pill tone="warning">{t('not_found.badge', 'CHYBA 404 — STRÁNKA NENALEZENA')}</Pill>
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
          <Button variant="outline" onClick={() => navigate(-1)} className="w-full sm:w-auto">
            <ArrowLeft aria-hidden="true" /> {t('not_found.go_back', 'Zpět na předchozí stránku')}
          </Button>

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
      </Panel>
    </div>
  );
}
