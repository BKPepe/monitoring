import * as React from 'react';
import * as echarts from 'echarts/core';
import { BarChart, LineChart } from 'echarts/charts';
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
  // The value histogram on the metric detail - the only bar form drawn so far.
  BarChart,
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
  /**
   * The window the user zoomed to, in milliseconds. The numbers beside a chart
   * describe the whole period, so zooming into one hour left the tiles and the
   * histogram talking about the other twenty-three - the chart said one thing
   * and the statistics another.
   */
  onZoom?: (window: { from: number; to: number } | null) => void;
}

export function Chart({ option, ariaLabel, summary, height = 200, className, group, onPickTime, onZoom }: ChartProps) {
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
    const instance = instanceRef.current;
    if (!instance || !onZoom) return;
    const handler = () => {
      const zoom = (
        instance.getOption() as
          { dataZoom?: { startValue?: number; endValue?: number; start?: number; end?: number }[] } | undefined
      )?.dataZoom?.[0];
      const zoomed = zoom != null && ((zoom.start ?? 0) > 0 || (zoom.end ?? 100) < 100);
      // startValue/endValue are the axis values ECharts resolved for the
      // current window - the percentages alone would need the data to convert.
      // Not zoomed = no window, and the callers go back to describing the whole
      // period rather than a slice that happens to equal it.
      onZoom(
        zoomed && typeof zoom.startValue === 'number' && typeof zoom.endValue === 'number'
          ? { from: zoom.startValue, to: zoom.endValue }
          : null
      );
    };
    instance.on('dataZoom', handler);
    return () => {
      instance.off('dataZoom', handler);
    };
  }, [onZoom]);

  React.useEffect(() => {
    const instance = instanceRef.current;
    if (!instance) return;

    // notMerge throws the whole option away, dataZoom included, so any zoom
    // the user set was lost whenever the option changed identity: saving a
    // note, switching the rate unit, or changing the UI language. Remember
    // where they were and put them back.
    const previous = (instance.getOption() as { dataZoom?: { start?: number; end?: number }[] } | undefined)
      ?.dataZoom?.[0];
    const wasZoomed = previous != null && ((previous.start ?? 0) > 0 || (previous.end ?? 100) < 100);

    instance.setOption(option, {
      // notMerge: old series must be dropped, otherwise leftovers of the
      // previous configuration survive a data change.
      notMerge: true,
    });

    if (wasZoomed) {
      instance.dispatchAction({ type: 'dataZoom', start: previous.start, end: previous.end });
    }
  }, [option]);

  return (
    <figure className={cn('relative', className)}>
      <div ref={containerRef} style={{ height }} role="img" aria-label={ariaLabel} className="w-full" />
      {summary && <figcaption className="sr-only">{summary}</figcaption>}
    </figure>
  );
}
