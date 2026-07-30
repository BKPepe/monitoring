import * as React from 'react';
import * as echarts from 'echarts/core';
import { LineChart } from 'echarts/charts';
import {
  DataZoomComponent,
  GridComponent,
  LegendComponent,
  MarkAreaComponent,
  MarkLineComponent,
  TooltipComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { EChartsCoreOption } from 'echarts/core';
import { cn } from '@/lib/utils';

/**
 * Registrujeme jen to, co opravdu kreslíme.
 *
 * `import * as echarts from 'echarts'` by přitáhlo celý balík (~1 MB) včetně
 * map, 3D a grafů, které tu nikdy nebudou. Tenhle výběr drží přírůstek na
 * zlomku a nová komponenta si případný modul doregistruje sama.
 *
 * MarkLine/MarkArea a DataZoom tu jsou dopředu — Sprint 5 (zoom, brush,
 * event markery) je bude potřebovat a doregistrovat je později znamená
 * hledat, proč se anotace tiše nevykreslují.
 */
echarts.use([
  LineChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  DataZoomComponent,
  MarkLineComponent,
  MarkAreaComponent,
  CanvasRenderer,
]);

export { echarts };

export interface ChartProps {
  option: EChartsCoreOption;
  /**
   * Textová alternativa. Canvas je pro čtečky prázdný, takže bez tohohle
   * popisku je graf pro nevidomého uživatele neexistující prvek.
   */
  ariaLabel: string;
  /** Shrnutí dat (min/max/průměr) — čte ho čtečka místo canvasu. */
  summary?: string;
  height?: number;
  className?: string;
  /** Vypne animace (respekt k prefers-reduced-motion řeší volající). */
  animate?: boolean;
}

export function Chart({
  option,
  ariaLabel,
  summary,
  height = 200,
  className,
  animate = true,
}: ChartProps) {
  const containerRef = React.useRef<HTMLDivElement>(null);
  const instanceRef = React.useRef<echarts.ECharts | null>(null);

  // Init + úklid. ECharts instance drží canvas a listenery — bez dispose()
  // po odmountování zůstane v paměti a při návratu na stránku se navrství.
  React.useEffect(() => {
    if (!containerRef.current) return;

    const instance = echarts.init(containerRef.current, undefined, {
      renderer: 'canvas',
    });
    instanceRef.current = instance;

    // ECharts se sám nepřekresluje při změně velikosti kontejneru; sleduje
    // se jen window.resize. V gridu, který mění šířku bez změny okna
    // (sbalení sidebaru), je proto potřeba ResizeObserver.
    const observer = new ResizeObserver(() => instance.resize());
    observer.observe(containerRef.current);

    return () => {
      observer.disconnect();
      instance.dispose();
      instanceRef.current = null;
    };
  }, []);

  React.useEffect(() => {
    instanceRef.current?.setOption(option, {
      // notMerge: staré série se musí zahodit, jinak by po změně dat
      // zůstaly viset zbytky předchozí konfigurace.
      notMerge: true,
      silent: !animate,
    });
  }, [option, animate]);

  return (
    <figure className={cn('relative', className)}>
      <div
        ref={containerRef}
        style={{ height }}
        role="img"
        aria-label={ariaLabel}
        className="w-full"
      />
      {summary && <figcaption className="sr-only">{summary}</figcaption>}
    </figure>
  );
}
