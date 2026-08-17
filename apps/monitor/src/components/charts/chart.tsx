import * as React from 'react';
import * as echarts from 'echarts/core';
import { LineChart } from 'echarts/charts';
import {
  DataZoomComponent,
  GridComponent,
  LegendComponent,
  MarkAreaComponent,
  MarkLineComponent,
  ToolboxComponent,
  TooltipComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { EChartsCoreOption } from 'echarts/core';
import { cn } from '@/lib/utils';

/**
 * Only what we actually draw gets registered.
 *
 * `import * as echarts from 'echarts'` would pull the whole bundle (~1 MB)
 * including maps, 3D and chart types that will never live here. This selection
 * keeps the increment to a fraction and a new component registers its own module.
 *
 * MarkLine/MarkArea and DataZoom are here ahead of time — Sprint 5 (zoom,
 * brush, event markers) will need them and registering them later means
 * hunting down why annotations silently do not draw.
 */
echarts.use([
  LineChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  DataZoomComponent,
  MarkLineComponent,
  MarkAreaComponent,
  ToolboxComponent,
  CanvasRenderer,
]);

export { echarts };

export interface ChartProps {
  option: EChartsCoreOption;
  /**
   * Text alternative. A canvas is empty for screen readers, so without this
   * label the chart is a nonexistent element for a blind user.
   */
  ariaLabel: string;
  /** Data summary (min/max/avg) — read by the screen reader instead of the canvas. */
  summary?: string;
  height?: number;
  className?: string;
  /** Disables animations (the caller handles prefers-reduced-motion). */
  animate?: boolean;
  /**
   * Charts sharing a group share the tooltip cursor and zoom
   * (echarts.connect) - hovering CPU shows the same moment in the RAM chart.
   */
  group?: string;
  /**
   * Clicking into the chart area returns the time the user pointed at (ms).
   *
   * Used for the "what was running at this moment" query - the chart shows
   * a spike, clicking it fills in the reason.
   */
  onPickTime?: (timestampMs: number) => void;
}

export function Chart({
  option,
  ariaLabel,
  summary,
  height = 200,
  className,
  animate = true,
  group,
  onPickTime,
}: ChartProps) {
  const containerRef = React.useRef<HTMLDivElement>(null);
  const instanceRef = React.useRef<echarts.ECharts | null>(null);

  // Init + cleanup. An ECharts instance holds the canvas and listeners — without
  // dispose() it stays in memory after unmount and stacks up on returning to the page.
  React.useEffect(() => {
    if (!containerRef.current) return;

    const instance = echarts.init(containerRef.current, undefined, {
      renderer: 'canvas',
    });
    instanceRef.current = instance;

    if (group) {
      instance.group = group;
      echarts.connect(group);
    }

    // ECharts does not redraw itself when the container resizes; it only
    // watches window.resize. In a grid that changes width without a window
    // change (sidebar collapse), a ResizeObserver is needed.
    const observer = new ResizeObserver(() => instance.resize());
    observer.observe(containerRef.current);

    return () => {
      observer.disconnect();
      instance.dispose();
      instanceRef.current = null;
    };
  }, [group]);

  // A click anywhere in the area, not just on a data point: the user aims at a
  // moment in time, not a specific sample. `zr` (ZRender) provides coordinates
  // even where no series is, and convertFromPixel maps them onto the X axis.
  React.useEffect(() => {
    const instance = instanceRef.current;
    if (!instance || !onPickTime) return;

    const zr = instance.getZr();
    const handler = (event: { offsetX: number; offsetY: number }) => {
      const point = [event.offsetX, event.offsetY];
      if (!instance.containPixel({ gridIndex: 0 }, point)) return;
      const [x] = instance.convertFromPixel({ gridIndex: 0 }, point) as number[];
      if (Number.isFinite(x)) onPickTime(x);
    };
    zr.on('click', handler);
    return () => {
      zr.off('click', handler);
    };
  }, [onPickTime]);

  React.useEffect(() => {
    instanceRef.current?.setOption(option, {
      // notMerge: old series must be dropped, otherwise leftovers of the
      // previous configuration survive a data change.
      notMerge: true,
      silent: !animate,
    });
  }, [option, animate]);

  return (
    <figure className={cn('relative', className)}>
      <div ref={containerRef} style={{ height }} role="img" aria-label={ariaLabel} className="w-full" />
      {summary && <figcaption className="sr-only">{summary}</figcaption>}
    </figure>
  );
}
