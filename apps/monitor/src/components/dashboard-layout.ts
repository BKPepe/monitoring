/**
 * The dashboard's tile list, kept out of the editor so the page can merge a
 * saved layout without loading the editor's lazy chunk.
 */
export interface DashboardTile {
  key: string;
  visible: boolean;
  size: 'normal' | 'wide';
}

export interface CatalogEntry {
  key: string;
  label: string;
  kind: 'panel' | 'metric';
  /** false = nothing is actually collected for this tile, it would show only dashes. */
  available: boolean;
  samples: number | null;
}

// Sections that do not read in a narrow column start wide (the monitor table,
// the heatmap, the insights row, the device cards).
const WIDE_BY_DEFAULT = new Set(['monitors', 'insights', 'uptime_history', 'attention', 'devices']);

/**
 * Catalogue tiles the saved layout has never heard of, appended at the end.
 * New features do not vanish just because the user saved a layout earlier: a
 * tile they turned off is in the list as hidden, so it stays off.
 */
export function withNewTiles(saved: DashboardTile[], catalog: CatalogEntry[]): DashboardTile[] {
  const known = new Set(saved.map((s) => s.key));
  return [
    ...saved,
    ...catalog
      .filter((c) => !known.has(c.key))
      .map((c) => ({
        key: c.key,
        visible: c.kind === 'panel' && c.available,
        size: WIDE_BY_DEFAULT.has(c.key) ? ('wide' as const) : ('normal' as const),
      })),
  ];
}
