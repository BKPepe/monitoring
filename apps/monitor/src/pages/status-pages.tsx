import { useState, useEffect } from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Activity, ExternalLink, Globe, Plus, Link as LinkIcon } from 'lucide-react';
import { useSession } from '@/api/use-session';
import { Link } from 'react-router-dom';

interface StatusPageItem {
  id: number;
  title: string;
  url: string;
  slug: string;
  isPublic: boolean;
  status: 'active' | 'draft';
}

export function StatusPagesPage() {
  const { session } = useSession();
  const [showModal, setShowModal] = useState(false);
  const [newTitle, setNewTitle] = useState('');
  const [newSlug, setNewSlug] = useState('');
  const [siteUrl, setSiteUrl] = useState(window.location.origin + '/status/');

  const [pages, setPages] = useState<StatusPageItem[]>([]);

  useEffect(() => {
    fetch('/status/api.php?action=get_settings', { credentials: 'include' })
      .then(r => r.json())
      .then(data => {
        const title = data.settings?.site_title || 'Hlavní Status Portál';
        const url = data.settings?.site_url ? `${data.settings.site_url}/` : `${window.location.origin}/status/`;
        setSiteUrl(url);
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

  const handleCreatePage = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newTitle || !newSlug) return;

    const slugified = newSlug.toLowerCase().replace(/[^a-z0-9-]/g, '-');
    const created: StatusPageItem = {
      id: Date.now(),
      title: newTitle,
      url: `${siteUrl}${slugified}`,
      slug: slugified,
      isPublic: true,
      status: 'active',
    };

    setPages((prev) => [...prev, created]);
    setNewTitle('');
    setNewSlug('');
    setShowModal(false);
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Veřejné Status Stránky</h1>
          <p className="text-muted-foreground text-sm">Správa veřejných portálů pro informování uživatelů a zákazníků o stavu služeb.</p>
        </div>
        {session?.authenticated && (
          <button
            type="button"
            onClick={() => setShowModal(true)}
            className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors cursor-pointer"
          >
            <Plus className="size-4" /> Vytvořit novou status stránku
          </button>
        )}
      </div>

      {!session?.authenticated && (
        <Card className="p-4 bg-amber-500/10 border-amber-500/30 flex items-center justify-between">
          <p className="text-xs text-amber-800 dark:text-amber-300 font-medium">
            K prohlížení veřejných portálů přihlášení potřeba není. Pro vytváření a úpravu vlastních status stránek se prosím přihlaste.
          </p>
          <Link to="/setup" className="text-xs font-semibold text-primary hover:underline">
            Přihlásit se →
          </Link>
        </Card>
      )}

      {showModal && (
        <Card className="p-6 border-primary/50 bg-secondary/40">
          <h3 className="font-bold text-base mb-3">Vytvořit novou zákaznickou status stránku</h3>
          <form onSubmit={handleCreatePage} className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <div>
                <label className="block text-xs font-medium text-muted-foreground mb-1">Název status stránky</label>
                <input
                  type="text"
                  placeholder="např. Vývojový Portál Klientských Služeb"
                  value={newTitle}
                  onChange={(e) => setNewTitle(e.target.value)}
                  required
                  className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-muted-foreground mb-1">URL Sloupec (Slug / Cesta)</label>
                <input
                  type="text"
                  placeholder="např. client-portal"
                  value={newSlug}
                  onChange={(e) => setNewSlug(e.target.value)}
                  required
                  className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm font-mono"
                />
              </div>
            </div>
            <div className="flex items-center justify-end gap-2">
              <button
                type="button"
                onClick={() => setShowModal(false)}
                className="px-4 py-2 rounded-md bg-secondary text-sm font-medium hover:bg-secondary/80"
              >
                Zrušit
              </button>
              <button
                type="submit"
                className="px-4 py-2 rounded-md bg-primary text-primary-foreground text-sm font-semibold hover:bg-primary/90"
              >
                Publikovat status stránku
              </button>
            </div>
          </form>
        </Card>
      )}

      {/* Seznam vygenerovaných a aktivních Status stránek */}
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
                <Badge variant="up">Aktivní</Badge>
              </div>

              <p className="text-xs text-muted-foreground leading-relaxed">
                Zobrazuje stav sledovaných služeb v reálném čase, příchozí údržby a automatické zdraví z databáze PostgreSQL.
              </p>
            </div>

            <div className="pt-3 border-t border-border flex items-center justify-between flex-wrap gap-2 text-xs">
              <a
                href="/api/v1/public_status"
                target="_blank"
                rel="noreferrer"
                className="font-medium text-primary hover:underline inline-flex items-center gap-1.5"
              >
                <LinkIcon className="size-3.5" /> API JSON rozhraní
              </a>
              <a
                href={p.url}
                target="_blank"
                rel="noreferrer"
                className="font-semibold text-emerald-400 hover:underline inline-flex items-center gap-1.5"
              >
                Otevřít veřejný portál <ExternalLink className="size-3.5" />
              </a>
            </div>
          </Card>
        ))}

        {session?.authenticated && (
          <Card className="p-6 space-y-4 border-dashed border-2 flex flex-col justify-between">
            <div className="space-y-3">
              <div className="flex items-center gap-3">
                <div className="p-2.5 rounded-xl bg-primary/10 text-primary">
                  <Globe className="size-6" />
                </div>
                <div>
                  <h3 className="font-semibold text-base">Přidat novou status stránku</h3>
                  <p className="text-xs text-muted-foreground">Vytvořte vlastní veřejný portál a značku</p>
                </div>
              </div>
              <p className="text-xs text-muted-foreground">
                Můžete vytvořit více samostatných veřejných status stránek pro různé projekty nebo klienty.
              </p>
            </div>

            <button
              type="button"
              onClick={() => setShowModal(true)}
              className="w-full rounded-md bg-secondary py-2 text-xs font-semibold text-secondary-foreground hover:bg-secondary/80 transition-colors cursor-pointer"
            >
              + Vytvořit novou stránku
            </button>
          </Card>
        )}
      </div>
    </div>
  );
}
