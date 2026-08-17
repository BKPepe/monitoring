import * as React from 'react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Layers, Plus, Pencil, Trash2, X } from 'lucide-react';
import { useLanguage } from '@/context/language-context';

export interface MetricPreset {
  id: number;
  name: string;
  description: string | null;
  serviceType: string | null;
  metrics: string[];
  /** null = the preset does not govern the threshold and leaves it to the monitor. */
  cpuThreshold: number | null;
  ramThreshold: number | null;
  hddThreshold: number | null;
  usedBy: number;
}

interface CatalogEntry {
  label: string;
  metrics: { key: string; label: string; recommended: boolean }[];
}

/**
 * Metric preset management.
 *
 * A preset is a named set of "what shows for this service and from when it is
 * a problem". Such a set used to be hardcoded in PHP (get_service_profiles) and
 * thresholds were set monitor by monitor; now you can create your own,
 * assign it to several monitors and change it in one place.
 */
export function PresetManager() {
  const { t } = useLanguage();
  const [presets, setPresets] = React.useState<MetricPreset[] | null>(null);
  const [catalog, setCatalog] = React.useState<Record<string, CatalogEntry>>({});
  const [editing, setEditing] = React.useState<MetricPreset | 'new' | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [notice, setNotice] = React.useState<string | null>(null);

  const load = React.useCallback(() => {
    fetch('/status/api.php?action=presets', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((d) => {
        if (Array.isArray(d?.presets)) setPresets(d.presets);
        if (d?.catalog) setCatalog(d.catalog);
        setError(null);
      })
      .catch(() => setError(t('presets.load_error', 'Presety se nepodařilo načíst.')));
  }, [t]);

  React.useEffect(() => {
    load();
  }, [load]);

  const remove = async (preset: MetricPreset) => {
    const question = t(
      'presets.delete_confirm',
      { name: preset.name, count: preset.usedBy },
      `Smazat preset „${preset.name}"? Používá ho ${preset.usedBy} monitorů — ty se vrátí ke svému vlastnímu nastavení.`
    );
    if (!window.confirm(question)) return;
    try {
      const res = await fetch('/status/api.php?action=delete_preset', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: preset.id }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      setNotice(t('presets.deleted', 'Preset smazán.'));
      load();
    } catch (e) {
      setError(e instanceof Error ? e.message : t('presets.delete_error', 'Preset se nepodařilo smazat.'));
    }
  };

  return (
    <Card className="p-6 space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
        <div className="flex items-center gap-3">
          <Layers className="size-5 text-primary" />
          <div>
            <h3 className="text-base font-bold">{t('presets.title', 'Presety metrik')}</h3>
            <p className="text-muted-foreground text-xs">
              {t(
                'presets.subtitle',
                'Pojmenovaná sada zobrazených metrik a prahů. Přiřadíte ji více monitorům a měníte na jednom místě.'
              )}
            </p>
          </div>
        </div>
        <Button size="sm" onClick={() => setEditing('new')} className="gap-1.5 font-semibold">
          <Plus className="size-4" /> {t('presets.new', 'Nový preset')}
        </Button>
      </div>

      {error && <p className="text-destructive text-xs font-semibold">{error}</p>}
      {notice && <p className="text-up text-xs font-semibold">{notice}</p>}

      {presets === null ? (
        <p className="text-muted-foreground text-sm">{t('presets.loading', 'Načítám presety…')}</p>
      ) : presets.length === 0 ? (
        <p className="text-muted-foreground py-4 text-center text-sm">
          {t('presets.empty', 'Zatím žádný preset. Monitory používají doporučené výchozí nastavení svého typu.')}
        </p>
      ) : (
        <div className="flex flex-col gap-2">
          {presets.map((p) => (
            <div
              key={p.id}
              className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border p-3"
            >
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-semibold">{p.name}</span>
                  {p.serviceType && <Badge variant="neutral">{catalog[p.serviceType]?.label ?? p.serviceType}</Badge>}
                  <span className="text-muted-foreground text-xs">
                    {t('presets.used_by', { count: p.usedBy }, `používá ${p.usedBy} monitorů`)}
                  </span>
                </div>
                {p.description && <p className="text-muted-foreground mt-0.5 text-xs">{p.description}</p>}
                <div className="text-muted-foreground mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px]">
                  <span>{t('presets.metrics_count', { count: p.metrics.length }, `${p.metrics.length} metrik`)}</span>
                  {/* An unset threshold is not printed - the preset simply does not govern it. */}
                  {p.cpuThreshold != null && <span>CPU ≥ {p.cpuThreshold} %</span>}
                  {p.ramThreshold != null && <span>RAM ≥ {p.ramThreshold} %</span>}
                  {p.hddThreshold != null && <span>Disk ≥ {p.hddThreshold} %</span>}
                </div>
              </div>
              <div className="flex gap-1">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setEditing(p)}
                  aria-label={t('common.edit', 'Upravit')}
                >
                  <Pencil />
                </Button>
                <Button variant="ghost" size="sm" onClick={() => remove(p)} aria-label={t('common.delete', 'Smazat')}>
                  <Trash2 />
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}

      {editing && (
        <PresetDialog
          preset={editing === 'new' ? null : editing}
          catalog={catalog}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setNotice(t('presets.saved', 'Preset uložen.'));
            load();
          }}
        />
      )}
    </Card>
  );
}

function PresetDialog({
  preset,
  catalog,
  onClose,
  onSaved,
}: {
  preset: MetricPreset | null;
  catalog: Record<string, CatalogEntry>;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useLanguage();
  const [name, setName] = React.useState(preset?.name ?? '');
  const [description, setDescription] = React.useState(preset?.description ?? '');
  const [serviceType, setServiceType] = React.useState(preset?.serviceType ?? '');
  const [metrics, setMetrics] = React.useState<string[]>(preset?.metrics ?? []);
  const [cpu, setCpu] = React.useState(preset?.cpuThreshold?.toString() ?? '');
  const [ram, setRam] = React.useState(preset?.ramThreshold?.toString() ?? '');
  const [hdd, setHdd] = React.useState(preset?.hddThreshold?.toString() ?? '');
  const [saving, setSaving] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const available = serviceType && catalog[serviceType] ? catalog[serviceType].metrics : [];

  const save = async () => {
    if (!name.trim()) {
      setError(t('presets.name_required', 'Zadejte název presetu.'));
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const res = await fetch('/status/api.php?action=save_preset', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: preset?.id ?? 0,
          name,
          description,
          serviceType,
          metrics,
          // An empty field = do not govern the threshold; '' is sent and the server turns it into null.
          cpuThreshold: cpu,
          ramThreshold: ram,
          hddThreshold: hdd,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      onSaved();
    } catch (e) {
      setError(e instanceof Error ? e.message : t('presets.save_error', 'Preset se nepodařilo uložit.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
      <Card className="max-h-[85vh] w-full max-w-lg space-y-4 overflow-y-auto p-6">
        <div className="flex items-start justify-between gap-3 border-b border-border pb-3">
          <h3 className="text-base font-bold">
            {preset ? t('presets.edit_title', 'Upravit preset') : t('presets.new_title', 'Nový preset')}
          </h3>
          <button type="button" onClick={onClose} className="text-muted-foreground hover:text-foreground">
            <X className="size-4" />
          </button>
        </div>

        {error && <p className="text-destructive text-xs font-semibold">{error}</p>}

        <div className="space-y-3">
          <Field label={t('presets.field_name', 'Název')}>
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={t('presets.name_placeholder', 'např. Produkční web')}
              className="border-border bg-background w-full rounded-md border px-3 py-2 text-sm"
            />
          </Field>

          <Field label={t('presets.field_description', 'Popis')}>
            <input
              value={description ?? ''}
              onChange={(e) => setDescription(e.target.value)}
              className="border-border bg-background w-full rounded-md border px-3 py-2 text-sm"
            />
          </Field>

          <Field label={t('presets.field_type', 'Typ služby')}>
            <select
              value={serviceType ?? ''}
              onChange={(e) => {
                setServiceType(e.target.value);
                setMetrics([]);
              }}
              className="border-border bg-background w-full rounded-md border px-3 py-2 text-sm"
            >
              <option value="">{t('presets.type_any', 'Jakýkoli (jen prahy)')}</option>
              {Object.entries(catalog).map(([key, entry]) => (
                <option key={key} value={key}>
                  {entry.label}
                </option>
              ))}
            </select>
          </Field>

          {available.length > 0 && (
            <Field label={t('presets.field_metrics', 'Zobrazené metriky')}>
              <div className="flex flex-col gap-1.5">
                {available.map((m) => (
                  <label key={m.key} className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={metrics.includes(m.key)}
                      onChange={(e) =>
                        setMetrics((prev) => (e.target.checked ? [...prev, m.key] : prev.filter((k) => k !== m.key)))
                      }
                    />
                    <span>{m.label}</span>
                    {m.recommended && (
                      <span className="text-muted-foreground text-[10px]">
                        {t('presets.recommended', 'doporučeno')}
                      </span>
                    )}
                  </label>
                ))}
              </div>
            </Field>
          )}

          <div className="grid grid-cols-3 gap-2">
            {(
              [
                ['CPU', cpu, setCpu],
                ['RAM', ram, setRam],
                [t('presets.disk', 'Disk'), hdd, setHdd],
              ] as const
            ).map(([label, value, setter]) => (
              <Field key={label} label={`${label} (%)`}>
                <input
                  type="number"
                  min={0}
                  max={100}
                  value={value}
                  onChange={(e) => (setter as (v: string) => void)(e.target.value)}
                  placeholder={t('presets.threshold_placeholder', 'neřešit')}
                  className="border-border bg-background w-full rounded-md border px-3 py-2 text-sm"
                />
              </Field>
            ))}
          </div>
          <p className="text-muted-foreground text-[11px] leading-relaxed">
            {t(
              'presets.threshold_hint',
              'Prázdné pole znamená, že preset práh neřeší a ponechá hodnotu nastavenou u monitoru.'
            )}
          </p>
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

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="text-muted-foreground mb-1 block text-xs font-medium">{label}</span>
      {children}
    </label>
  );
}
