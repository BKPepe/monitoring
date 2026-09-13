import * as React from 'react';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { ChevronDown, ChevronUp, Eye, EyeOff, LayoutGrid } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';
import { LoadingState, ErrorState } from '@/components/ui/states';

/** Sekce, ktere davaji smysl jen pres celou sirku. */
const WIDE_BY_DEFAULT = new Set(['monitors', 'insights', 'uptime_history', 'attention']);

export interface DashboardTile {
  key: string;
  visible: boolean;
  size: 'normal' | 'wide';
}

interface CatalogEntry {
  key: string;
  label: string;
  kind: 'panel' | 'metric';
  /** false = nothing is actually collected for this tile, it would show only dashes. */
  available: boolean;
  samples: number | null;
}

/**
 * Dashboard layout editor.
 *
 * The tile catalogue comes from the server and derives from ACTUALLY measured
 * data - a metric without a single sample is offered as unavailable instead of
 * letting the user enable a tile that stays forever empty.
 */
export function DashboardLayoutEditor({
  open,
  onClose,
  onSaved,
}: {
  open: boolean;
  onClose: () => void;
  onSaved: (tiles: DashboardTile[]) => void;
}) {
  const { t } = useLanguage();
  const [catalog, setCatalog] = React.useState<CatalogEntry[]>([]);
  const [tiles, setTiles] = React.useState<DashboardTile[]>([]);
  const [loading, setLoading] = React.useState(true);
  const [saving, setSaving] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (!open) return;
    let active = true;
    setLoading(true);
    fetch('/status/api.php?action=dashboard_layout', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (!active || !data) return;
        const cat: CatalogEntry[] = Array.isArray(data.catalog) ? data.catalog : [];
        setCatalog(cat);
        const saved: DashboardTile[] = Array.isArray(data.tiles) ? data.tiles : [];
        // Catalogue tiles the user never ordered are appended at the end -
        // new features do not vanish just because the user saved their
        // layout earlier.
        const known = new Set(saved.map((s) => s.key));
        const merged = [
          ...saved.filter((s) => cat.some((c) => c.key === s.key)),
          ...cat
            .filter((c) => !known.has(c.key))
            .map((c) => ({
              key: c.key,
              visible: c.kind === 'panel' && c.available,
              // Sekce, ktere se v uzkem sloupci necetly by (tabulka monitoru,
              // heatmapa, radek insightu), zacinaji jako siroke.
              size: WIDE_BY_DEFAULT.has(c.key) ? ('wide' as const) : ('normal' as const),
            })),
        ];
        setTiles(merged);
      })
      .catch(() => {
        if (active) setError(t('layout.load_error', 'Katalog dlaždic se nepodařilo načíst.'));
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [open, t]);

  const entry = (key: string) => catalog.find((c) => c.key === key);

  const move = (index: number, delta: number) => {
    setTiles((prev) => {
      const next = [...prev];
      const target = index + delta;
      if (target < 0 || target >= next.length) return prev;
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  };

  const toggle = (index: number) => {
    setTiles((prev) => prev.map((tl, i) => (i === index ? { ...tl, visible: !tl.visible } : tl)));
  };

  const resize = (index: number) => {
    setTiles((prev) =>
      prev.map((tl, i) => (i === index ? { ...tl, size: tl.size === 'wide' ? 'normal' : 'wide' } : tl))
    );
  };

  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      const res = await fetch('/status/api.php?action=dashboard_layout', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tiles }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      onSaved(tiles);
      onClose();
    } catch (e) {
      setError(e instanceof Error ? e.message : t('layout.save_error', 'Rozložení se nepodařilo uložit.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      {/* The tile order and visibility are unsaved edits - a stray click on the
          backdrop must not throw them away. Escape and the close button still
          close the dialog, those are deliberate. */}
      <DialogContent
        className="flex max-h-[85dvh] flex-col"
        onPointerDownOutside={(e) => e.preventDefault()}
        onInteractOutside={(e) => e.preventDefault()}
      >
        <DialogHeader className="flex-row items-center gap-3 border-b border-border">
          <LayoutGrid aria-hidden="true" className="size-6 shrink-0 text-primary" />
          <div>
            <DialogTitle>{t('layout.title', 'Rozložení dashboardu')}</DialogTitle>
            <DialogDescription className="text-xs">
              {t(
                'layout.subtitle',
                'Vyberte, co se má zobrazovat a v jakém pořadí. Nabízí se jen to, pro co se opravdu sbírají data.'
              )}
            </DialogDescription>
          </div>
        </DialogHeader>

        {/* Only the list scrolls; the header and the save row stay reachable on a
            phone even with two dozen tiles. */}
        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
          {error && <ErrorState size="inline" message={error} />}

          {loading ? (
            <LoadingState size="inline" label={t('layout.loading', 'Načítám katalog dlaždic…')} />
          ) : (
            <div className="space-y-1.5">
              {tiles.map((tile, i) => {
                const info = entry(tile.key);
                const unavailable = info && !info.available;
                return (
                  <div
                    key={tile.key}
                    className={cn(
                      'flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-xs',
                      tile.visible && !unavailable ? 'bg-secondary/40' : 'bg-transparent opacity-70'
                    )}
                  >
                    <span className="flex flex-col leading-tight">
                      <span className="font-semibold">{info?.label ?? tile.key}</span>
                      {unavailable ? (
                        <span className="text-muted-foreground text-3xs">
                          {t('layout.no_data', 'Zatím se pro tuhle položku nesbírají žádná data')}
                        </span>
                      ) : info?.samples != null ? (
                        <span className="text-muted-foreground text-3xs">
                          {t('layout.samples', { count: info.samples }, `${info.samples} naměřených vzorků`)}
                        </span>
                      ) : null}
                    </span>

                    <div className="ml-auto flex items-center gap-1">
                      <button
                        type="button"
                        onClick={() => resize(i)}
                        disabled={!tile.visible}
                        className="rounded px-1.5 py-1 text-3xs font-semibold text-muted-foreground hover:text-foreground disabled:opacity-40"
                        title={t('layout.size_hint', 'Přepnout šířku dlaždice')}
                      >
                        {tile.size === 'wide' ? t('layout.size_wide', 'široká') : t('layout.size_normal', 'běžná')}
                      </button>
                      <button
                        type="button"
                        onClick={() => toggle(i)}
                        disabled={unavailable}
                        className="rounded p-1 text-muted-foreground hover:text-foreground disabled:opacity-40"
                        title={tile.visible ? t('layout.hide', 'Skrýt') : t('layout.show', 'Zobrazit')}
                      >
                        {tile.visible ? <Eye className="size-3.5" /> : <EyeOff className="size-3.5" />}
                      </button>
                      <button
                        type="button"
                        onClick={() => move(i, -1)}
                        disabled={i === 0}
                        className="rounded p-1 text-muted-foreground hover:text-foreground disabled:opacity-30"
                      >
                        <ChevronUp className="size-3.5" />
                      </button>
                      <button
                        type="button"
                        onClick={() => move(i, 1)}
                        disabled={i === tiles.length - 1}
                        className="rounded p-1 text-muted-foreground hover:text-foreground disabled:opacity-30"
                      >
                        <ChevronDown className="size-3.5" />
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" size="sm" onClick={onClose}>
            {t('common.cancel', 'Zrušit')}
          </Button>
          <Button size="sm" onClick={save} disabled={saving || loading} className="font-semibold">
            {saving ? t('layout.saving', 'Ukládám…') : t('layout.save', 'Uložit rozložení')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
