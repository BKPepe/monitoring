import type * as React from 'react';
import { Download, FileSpreadsheet, RotateCcw } from 'lucide-react';
import { OverflowMenu } from '@/components/ui/overflow-menu';
import { useLanguage } from '@/context/language-context';

/** What the "⋯" menu does with a chart (C-5). */
export interface MetricChartActions {
  exportPng: () => void;
  exportCsv: () => void;
  resetZoom: () => void;
}

/** The "⋯" menu: download PNG, export CSV, reset the zoom. */
export function ChartMenu({ actions }: { actions: MetricChartActions | React.RefObject<MetricChartActions | null> }) {
  const { t } = useLanguage();
  // A card hands in a ref that is filled once its chart has mounted.
  const run = (pick: (a: MetricChartActions) => void) => () => {
    const target = 'current' in actions ? actions.current : actions;
    if (target) pick(target);
  };
  return (
    <OverflowMenu
      label={t('chart.menu', 'Akce grafu')}
      items={[
        { label: t('chart.tool_png', 'Uložit PNG'), icon: Download, onSelect: run((a) => a.exportPng()) },
        { label: t('chart.tool_csv', 'Export CSV'), icon: FileSpreadsheet, onSelect: run((a) => a.exportCsv()) },
        { label: t('chart.tool_restore', 'Obnovit'), icon: RotateCcw, onSelect: run((a) => a.resetZoom()) },
      ]}
    />
  );
}
