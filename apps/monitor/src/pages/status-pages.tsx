import { useState, useEffect } from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Activity, ExternalLink, Link as LinkIcon } from 'lucide-react';
import { useLanguage } from '@/context/language-context';

interface StatusPageItem {
  id: number;
  title: string;
  url: string;
  slug: string;
  isPublic: boolean;
  status: 'active' | 'draft';
}

export function StatusPagesPage() {
  const { t } = useLanguage();
  const [pages, setPages] = useState<StatusPageItem[]>([]);

  useEffect(() => {
    fetch('/status/api.php?action=get_settings', { credentials: 'include' })
      .then(r => r.json())
      .then(data => {
        const title = data.settings?.site_title || 'Hlavní Status Portál';
        const url = data.settings?.site_url ? `${data.settings.site_url}/` : `${window.location.origin}/status/`;
        setPages([
          {
            id: 1,
            title,
            url,
            slug: 'default',
            isPublic: true,
            status: 'active',
          }
        ]);
      })
      .catch(() => {
        setPages([
          {
            id: 1,
            title: 'Hlavní Status Portál',
            url: `${window.location.origin}/status/`,
            slug: 'default',
            isPublic: true,
            status: 'active',
          }
        ]);
      });
  }, []);

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">{t('nav.status-pages', 'Veřejná Status Stránka')}</h1>
          <p className="text-muted-foreground text-sm">
            {t('status_pages.subtitle', 'Zobrazuje veřejnou status stránku dostupnosti všech sledovaných služeb a plánovaných údržeb v reálném čase.')}
          </p>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        {pages.map((p) => (
          <Card key={p.id} className="p-6 space-y-4 flex flex-col justify-between">
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-400">
                    <Activity className="size-6" />
                  </div>
                  <div>
                    <h3 className="font-semibold text-base">{p.title}</h3>
                    <p className="text-xs text-muted-foreground font-mono">{p.url}</p>
                  </div>
                </div>
                <Badge variant="up">{t('common.healthy', 'Aktivní')}</Badge>
              </div>

              <p className="text-xs text-muted-foreground leading-relaxed">
                {t('status_pages.desc', 'Zobrazuje stav sledovaných služeb v reálném čase a příchozí plánované údržby.')}
              </p>
            </div>

            <div className="pt-3 border-t border-border flex items-center justify-between flex-wrap gap-2 text-xs">
              <a
                href="/status/api.php?action=public_status"
                target="_blank"
                rel="noreferrer"
                className="font-medium text-primary hover:underline inline-flex items-center gap-1.5"
              >
                <LinkIcon className="size-3.5" /> API JSON
              </a>
              <a
                href={p.url}
                target="_blank"
                rel="noreferrer"
                className="font-semibold text-emerald-400 hover:underline inline-flex items-center gap-1.5"
              >
                {t('common.open_details', 'Otevřít veřejný portál')} <ExternalLink className="size-3.5" />
              </a>
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}
