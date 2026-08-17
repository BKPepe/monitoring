import * as React from 'react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Activity, Plus, Pencil, Trash2, ExternalLink, X, Eye, EyeOff } from 'lucide-react';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';

export interface DisplayOptions {
  showRegions: boolean;
  showEvents: boolean;
  showIncidents: boolean;
  showUptime: boolean;
  detailLevel: 'full' | 'status';
}

const DEFAULT_DISPLAY: DisplayOptions = {
  showRegions: true,
  showEvents: true,
  showIncidents: true,
  showUptime: true,
  detailLevel: 'full',
};

interface StatusPage {
  id: number;
  title: string;
  slug: string;
  description: string | null;
  isPublic: boolean;
  /** Prázdné pole = stránka ukazuje všechny monitory. */
  monitorIds: number[];
  displayOptions?: DisplayOptions;
}

/**
 * Správa veřejných status stránek.
 *
 * Dřív tu byla jediná natvrdo složená karta s odkazem na /status/ - stránka
 * se jmenovala „Status Pages", ale žádnou druhou nešlo založit ani vybrat,
 * co na ní bude. Teď jde vytvořit víc stránek, každé přiřadit monitory,
 * vlastní slug a skrýt ji před veřejností.
 */
export function StatusPagesPage() {
  const { t } = useLanguage();
  const { session } = useSession();
  const isAdmin = Boolean(session?.authenticated);

  const [pages, setPages] = React.useState<StatusPage[] | null>(null);
  const [monitors, setMonitors] = React.useState<ApiMonitor[]>([]);
  const [editing, setEditing] = React.useState<StatusPage | 'new' | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [notice, setNotice] = React.useState<string | null>(null);

  const load = React.useCallback(() => {
    fetch('/status/api.php?action=status_pages', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((d) => {
        if (Array.isArray(d?.pages)) setPages(d.pages);
        setError(null);
      })
      .catch(() => setError(t('sp.load_error', 'Status stránky se nepodařilo načíst.')));
  }, [t]);

  React.useEffect(() => {
    load();
    appApi
      .getMonitors()
      .then((rows) => setMonitors(Array.isArray(rows) ? rows : ((rows as any)?.monitors ?? [])))
      .catch(() => {});
  }, [load]);

  const remove = async (page: StatusPage) => {
    if (!window.confirm(t('sp.delete_confirm', { title: page.title }, `Smazat stránku „${page.title}"?`))) return;
    try {
      const res = await fetch('/status/api.php?action=delete_status_page', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: page.id }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      setNotice(t('sp.deleted', 'Stránka smazána.'));
      load();
    } catch (e) {
      setError(e instanceof Error ? e.message : t('sp.delete_error', 'Stránku se nepodařilo smazat.'));
    }
  };

  // Odkaz míří na novou React stránku. Do přepnutí /status/ vedl na legacy
  // PHP, takže si člověk vytvořil stránku tady a proklik ho poslal do staré
  // aplikace.
  const pageUrl = (slug: string) => `${window.location.origin}/app/public?page=${encodeURIComponent(slug)}`;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
            <Activity className="size-5 text-primary" /> {t('sp.title', 'Veřejné status stránky')}
          </h1>
          <p className="text-muted-foreground text-sm">
            {t('sp.subtitle', 'Vyberte, které služby uvidí veřejnost, a pod jakou adresou.')}
          </p>
        </div>
        {isAdmin && (
          <Button size="sm" onClick={() => setEditing('new')} className="gap-1.5 font-semibold">
            <Plus className="size-4" /> {t('sp.new', 'Nová stránka')}
          </Button>
        )}
      </div>

      {error && <p className="text-destructive text-xs font-semibold">{error}</p>}
      {notice && <p className="text-up text-xs font-semibold">{notice}</p>}

      {/* Hlavní veřejná stránka existuje vždy - bez tohohle odkazu se k ní
          nešlo z aplikace prokliknout, dokud člověk nevytvořil vlastní
          stránku (a i pak jen na tu vlastní). */}
      <Card className="flex flex-wrap items-center justify-between gap-3 p-4">
        <div className="min-w-0">
          <p className="text-sm font-semibold">{t('sp.main_page', 'Hlavní veřejná stránka')}</p>
          <p className="text-muted-foreground text-xs">
            {t('sp.main_page_desc', 'Všechny služby, bez filtru - to, co uvidí návštěvník.')}
          </p>
        </div>
        <a
          href="/app/public"
          target="_blank"
          rel="noreferrer"
          className="text-primary inline-flex items-center gap-1.5 text-xs font-semibold hover:underline"
        >
          /app/public <ExternalLink className="size-3.5" />
        </a>
      </Card>

      {/* Vložitelný SVG odznak - živý stav na cizím webu (fórum, wiki, README).
          Náhled je ten samý endpoint, takže co je vidět tady, je přesně to,
          co dostane cizí stránka. */}
      <Card className="space-y-2 p-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="min-w-0">
            <p className="text-sm font-semibold">{t('sp.badge_title', 'Vložitelný odznak stavu')}</p>
            <p className="text-muted-foreground text-xs">
              {t(
                'sp.badge_desc',
                'SVG obrázek s aktuálním stavem — vložte jako <img> kamkoliv. Obnovuje se po minutě.'
              )}
            </p>
          </div>
          <img src="/status/api.php?action=badge" alt={t('sp.badge_alt', 'Odznak stavu')} className="h-5" />
        </div>
        <code className="text-muted-foreground block overflow-x-auto rounded bg-secondary/40 px-2 py-1.5 text-[11px] whitespace-nowrap select-all">
          {`<img src="${window.location.origin}/status/api.php?action=badge" alt="status">`}
        </code>
        <p className="text-muted-foreground text-[11px]">
          {t('sp.badge_monitor_hint', 'Odznak jedné služby: přidejte &monitor_id=ID, anglická verze: &lang=en.')}
        </p>
      </Card>

      {pages === null ? (
        <p className="text-muted-foreground text-sm">{t('sp.loading', 'Načítám…')}</p>
      ) : pages.length === 0 ? (
        <Card className="space-y-2 p-8 text-center">
          <p className="text-foreground text-sm font-semibold">{t('sp.empty_title', 'Zatím žádná status stránka')}</p>
          <p className="text-muted-foreground text-xs">
            {t(
              'sp.empty_desc',
              'Hlavní přehled na /status/ funguje i bez toho. Vlastní stránka se hodí, když chcete zveřejnit jen část služeb — třeba pro zákazníky.'
            )}
          </p>
        </Card>
      ) : (
        <div className="grid gap-3 md:grid-cols-2">
          {pages.map((p) => (
            <Card key={p.id} className="space-y-2 p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="text-sm font-bold">{p.title}</h3>
                    <Badge variant={p.isPublic ? 'up' : 'neutral'}>
                      {p.isPublic ? (
                        <>
                          <Eye className="size-3" /> {t('sp.public', 'Veřejná')}
                        </>
                      ) : (
                        <>
                          <EyeOff className="size-3" /> {t('sp.hidden', 'Skrytá')}
                        </>
                      )}
                    </Badge>
                  </div>
                  {p.description && <p className="text-muted-foreground mt-0.5 text-xs">{p.description}</p>}
                </div>
                {isAdmin && (
                  <div className="flex shrink-0 gap-1">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setEditing(p)}
                      aria-label={t('common.edit', 'Upravit')}
                    >
                      <Pencil />
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => remove(p)}
                      aria-label={t('common.delete', 'Smazat')}
                    >
                      <Trash2 />
                    </Button>
                  </div>
                )}
              </div>

              <p className="text-muted-foreground text-[11px]">
                {p.monitorIds.length === 0
                  ? t('sp.all_monitors', 'Zobrazuje všechny monitory')
                  : t(
                      'sp.selected_monitors',
                      { count: p.monitorIds.length },
                      `Vybráno ${p.monitorIds.length} monitorů`
                    )}
              </p>

              <a
                href={pageUrl(p.slug)}
                target="_blank"
                rel="noreferrer"
                className="text-primary inline-flex items-center gap-1 font-mono text-xs hover:underline"
              >
                /status/?page={p.slug} <ExternalLink className="size-3" />
              </a>
            </Card>
          ))}
        </div>
      )}

      {editing && (
        <PageDialog
          page={editing === 'new' ? null : editing}
          monitors={monitors}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setNotice(t('sp.saved', 'Stránka uložena.'));
            load();
          }}
        />
      )}
    </div>
  );
}

function PageDialog({
  page,
  monitors,
  onClose,
  onSaved,
}: {
  page: StatusPage | null;
  monitors: ApiMonitor[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useLanguage();
  const [title, setTitle] = React.useState(page?.title ?? '');
  const [slug, setSlug] = React.useState(page?.slug ?? '');
  const [description, setDescription] = React.useState(page?.description ?? '');
  const [isPublic, setIsPublic] = React.useState(page?.isPublic ?? true);
  const [selected, setSelected] = React.useState<number[]>(page?.monitorIds ?? []);
  const [display, setDisplay] = React.useState<DisplayOptions>(page?.displayOptions ?? DEFAULT_DISPLAY);
  const [saving, setSaving] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const save = async () => {
    if (!title.trim()) {
      setError(t('sp.title_required', 'Zadejte název stránky.'));
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const res = await fetch('/status/api.php?action=save_status_page', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: page?.id ?? 0,
          title,
          slug,
          description,
          isPublic,
          monitorIds: selected,
          displayOptions: display,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      onSaved();
    } catch (e) {
      setError(e instanceof Error ? e.message : t('sp.save_error', 'Stránku se nepodařilo uložit.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
      <Card className="max-h-[85vh] w-full max-w-lg space-y-4 overflow-y-auto p-6">
        <div className="flex items-start justify-between gap-3 border-b border-border pb-3">
          <h3 className="text-base font-bold">
            {page ? t('sp.edit_title', 'Upravit stránku') : t('sp.new_title', 'Nová status stránka')}
          </h3>
          <button type="button" onClick={onClose} className="text-muted-foreground hover:text-foreground">
            <X className="size-4" />
          </button>
        </div>

        {error && <p className="text-destructive text-xs font-semibold">{error}</p>}

        <label className="block">
          <span className="text-muted-foreground mb-1 block text-xs font-medium">{t('sp.field_title', 'Název')}</span>
          <Input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder={t('sp.title_placeholder', 'např. Herní servery')}
          />
        </label>

        <label className="block">
          <span className="text-muted-foreground mb-1 block text-xs font-medium">
            {t('sp.field_slug', 'Adresa (slug)')}
          </span>
          <Input
            value={slug}
            onChange={(e) => setSlug(e.target.value)}
            placeholder={t('sp.slug_placeholder', 'odvodí se z názvu')}
          />
          <span className="text-muted-foreground mt-1 block text-[11px]">
            {t('sp.slug_hint', 'Použije se v adrese stránky (?page=…). Bez vyplnění se vytvoří z názvu.')}
          </span>
        </label>

        <label className="block">
          <span className="text-muted-foreground mb-1 block text-xs font-medium">
            {t('sp.field_description', 'Popis')}
          </span>
          <Input value={description ?? ''} onChange={(e) => setDescription(e.target.value)} />
        </label>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={isPublic}
            onChange={(e) => setIsPublic(e.target.checked)}
            className="size-4"
          />
          {t('sp.field_public', 'Veřejně přístupná')}
        </label>

        {/* Co stránka ukáže. Výchozí je všechno - vypíná se, ne zapíná,
            aby stránky založené před touto volbou vypadaly stejně. */}
        <div>
          <span className="text-muted-foreground mb-1 block text-xs font-medium">
            {t('sp.field_display', 'Zobrazené sekce')}
          </span>
          <div className="space-y-1.5">
            {(
              [
                ['showRegions', t('sp.opt_regions', 'Místa měření')],
                ['showEvents', t('sp.opt_events', 'Poslední události')],
                ['showIncidents', t('sp.opt_incidents', 'Incidenty')],
                ['showUptime', t('sp.opt_uptime', 'Pásy dostupnosti (30 dní)')],
              ] as const
            ).map(([key, label]) => (
              <label key={key} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={display[key]}
                  onChange={(e) => setDisplay((d) => ({ ...d, [key]: e.target.checked }))}
                  className="size-4"
                />
                {label}
              </label>
            ))}
          </div>
          <label className="mt-2 block">
            <span className="text-muted-foreground mb-1 block text-xs font-medium">
              {t('sp.opt_detail_level', 'Detail služeb')}
            </span>
            <select
              value={display.detailLevel}
              onChange={(e) => setDisplay((d) => ({ ...d, detailLevel: e.target.value as 'full' | 'status' }))}
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
            >
              <option value="full">{t('sp.detail_full', 'Stav + rozbalovací detail a vytížení')}</option>
              <option value="status">{t('sp.detail_status', 'Jen stav a dostupnost')}</option>
            </select>
          </label>
        </div>

        <div>
          <span className="text-muted-foreground mb-1 block text-xs font-medium">
            {t('sp.field_monitors', 'Zobrazené monitory')}
          </span>
          <p className="text-muted-foreground mb-1.5 text-[11px]">
            {t('sp.monitors_hint', 'Nevyberete-li nic, stránka ukáže všechny monitory.')}
          </p>
          <div className="max-h-48 space-y-1 overflow-y-auto rounded-md border border-border p-2">
            {monitors.map((m) => (
              <label key={m.id} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={selected.includes(m.id)}
                  onChange={(e) =>
                    setSelected((prev) => (e.target.checked ? [...prev, m.id] : prev.filter((id) => id !== m.id)))
                  }
                />
                <span className="truncate">{m.name}</span>
                <span className="text-muted-foreground ml-auto shrink-0 text-[11px]">{m.type}</span>
              </label>
            ))}
          </div>
        </div>

        <div className="flex justify-end gap-2 border-t border-border pt-3">
          <Button variant="outline" size="sm" onClick={onClose}>
            {t('common.cancel', 'Zrušit')}
          </Button>
          <Button size="sm" onClick={save} disabled={saving} className="font-semibold">
            {saving ? t('common.saving', 'Ukládám…') : t('common.save', 'Uložit')}
          </Button>
        </div>
      </Card>
    </div>
  );
}
