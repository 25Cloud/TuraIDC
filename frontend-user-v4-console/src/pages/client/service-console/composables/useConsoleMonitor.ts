import { computed, ref } from 'vue';

import type { MonitorChartRecord, MonitorSummaryItem } from '@/types/client';

interface MonitorSeries {
  key: string;
  name: string;
  rawPoints: MonitorPoint[];
}
interface MonitorPoint {
  time: string;
  timestamp: number;
  value: number;
  displayValue: string;
}
type MonitorRenderPoint = MonitorPoint & { key: string; index: number; x: number; y: number };
interface MonitorRenderSeries {
  key: string;
  name: string;
  color: string;
  lineWidth: number;
  points: MonitorRenderPoint[];
  path: string;
}
interface MonitorAxisTick {
  key: string;
  label: string;
  y: number;
  top: number;
}
interface ActiveMonitorSeriesPoint {
  key: string;
  name: string;
  color: string;
  x: number;
  y: number;
  valueText: string;
}
interface ActiveMonitorPoint {
  x: number;
  y: number;
  time: string;
  seriesPoints: ActiveMonitorSeriesPoint[];
}
interface MonitorChartView {
  key: string;
  label: string;
  message: string;
  latestText: string;
  latestTime: string;
  averageText: string;
  peakText: string;
  lowestText: string;
  yAxisTicks: MonitorAxisTick[];
  xAxisLabels: { start: string; middle: string; end: string };
  series: MonitorRenderSeries[];
}

export const MONITOR_CHART_WIDTH = 320;
export const MONITOR_CHART_HEIGHT = 140;
export const MONITOR_CHART_TOP = 14;
export const MONITOR_CHART_BOTTOM = 116;
export const MONITOR_AXIS_SEGMENTS = 3;

const monitorPalette = ['#0052d9', '#00a870', '#e37318', '#7b61ff'];

export function useConsoleMonitor(monitorState: { charts: MonitorChartRecord[] }) {
  const monitorChartViews = computed(() =>
    monitorState.charts.map((chart, index) => buildMonitorChartView(chart, index)),
  );
  const activeMonitorPoint = ref<{ chartKey: string; index: number } | null>(null);

  function buildMonitorChartView(chart: MonitorChartRecord, index: number): MonitorChartView {
    const summary = chart.summary || {};
    const series = normalizeMonitorSeries(chart);
    const range = resolveMonitorValueRange(series);
    const latestPoint = series[0]?.rawPoints[series[0].rawPoints.length - 1];
    const firstSeriesPoints = series[0]?.rawPoints || [];

    return {
      key: String(chart.type || chart.label || index),
      label: String(chart.label || chart.type || `指标 ${index + 1}`),
      message: String(chart.error || chart.message || ''),
      latestText: resolveMonitorSummaryText(summary.latest || null, series, 'latest') || '--',
      latestTime: String(summary.latest?.time || latestPoint?.time || ''),
      averageText: resolveMonitorSummaryText(summary.average || null, series, 'average') || '--',
      peakText: resolveMonitorSummaryText(summary.peak || null, series, 'peak') || '--',
      lowestText: resolveMonitorSummaryText(summary.lowest || null, series, 'lowest') || '--',
      yAxisTicks: buildMonitorYAxisTicks(range, resolveMonitorUnit(chart)),
      xAxisLabels: buildMonitorXAxisLabels(firstSeriesPoints),
      series: series
        .map((item, seriesIndex) => {
          const points = buildMonitorRenderPoints(item.rawPoints, range, item.key);
          return {
            key: item.key || `${index}-${seriesIndex}`,
            name: item.name,
            color: monitorPalette[seriesIndex % monitorPalette.length],
            lineWidth: seriesIndex === 0 ? 1.6 : 1.25,
            points,
            path: buildMonitorSmoothPath(points),
          };
        })
        .filter((item) => item.path),
    };
  }

  function normalizeMonitorSeries(chart: MonitorChartRecord): MonitorSeries[] {
    const chartData = chart.chart || {};
    const sourceSeries =
      Array.isArray(chartData.series) && chartData.series.length
        ? chartData.series
        : [
            {
              key: chart.type || 'series',
              name: chart.label || chart.type || '',
              list: Array.isArray(chartData.list) ? chartData.list : [],
            },
          ];

    return sourceSeries
      .map((series, index) => {
        const rawPoints = Array.isArray(series.list)
          ? series.list
              .map((point) => ({
                time: String(point?.time || ''),
                timestamp: normalizeMonitorTimestamp(point?.timestamp),
                value: Number(point?.value),
                displayValue: String(point?.display_value || point?.text || point?.value || '--'),
              }))
              .filter((point) => Number.isFinite(point.value))
          : [];

        return {
          key: String(series.key || series.name || index),
          name: String(series.name || series.key || ''),
          rawPoints,
        };
      })
      .filter((series) => series.rawPoints.length > 0);
  }

  function resolveMonitorValueRange(series: MonitorSeries[]) {
    const values = series
      .flatMap((item) => item.rawPoints.map((point) => point.value))
      .filter((value) => Number.isFinite(value));
    if (!values.length) return { min: 0, max: 1, range: 1 };
    const dataMin = Math.min(...values);
    const dataMax = Math.max(...values);
    const min = dataMin >= 0 ? 0 : dataMin;
    const max = dataMax === min ? min + 1 : buildNiceMonitorAxisMax(dataMax, min);
    return { min, max, range: max - min || 1 };
  }

  function buildMonitorRenderPoints(
    points: MonitorPoint[],
    range: { min: number; max: number; range: number },
    seriesKey: string,
  ): MonitorRenderPoint[] {
    if (!points.length) return [];
    const height = MONITOR_CHART_BOTTOM - MONITOR_CHART_TOP;
    const denominator = Math.max(points.length - 1, 1);

    return points.map((point, index) => {
      const x = points.length === 1 ? MONITOR_CHART_WIDTH / 2 : (index / denominator) * MONITOR_CHART_WIDTH;
      const normalized = (point.value - range.min) / range.range;
      const y = MONITOR_CHART_BOTTOM - normalized * height;
      return {
        ...point,
        key: `${seriesKey}-${point.timestamp || point.time}-${index}`,
        index,
        x: Number(x.toFixed(2)),
        y: Number(Math.min(Math.max(y, MONITOR_CHART_TOP), MONITOR_CHART_BOTTOM).toFixed(2)),
      };
    });
  }

  function buildMonitorYAxisTicks(range: { min: number; max: number; range: number }, unit: string): MonitorAxisTick[] {
    return Array.from({ length: MONITOR_AXIS_SEGMENTS + 1 }, (_, index) => {
      const ratio = index / MONITOR_AXIS_SEGMENTS;
      const value = range.max - range.range * ratio;
      const y = MONITOR_CHART_TOP + (MONITOR_CHART_BOTTOM - MONITOR_CHART_TOP) * ratio;
      const top = (y / MONITOR_CHART_HEIGHT) * 100;
      return {
        key: `tick-${index}`,
        y: Number(y.toFixed(2)),
        top: Number(top.toFixed(2)),
        label: formatMonitorAxisValue(value, unit),
      };
    });
  }

  function buildMonitorXAxisLabels(points: MonitorPoint[]) {
    if (!points.length) return { start: '--', middle: '--', end: '--' };
    const middle = points[Math.floor((points.length - 1) / 2)];
    const sameDay = isSameMonitorDate(points[0], points.at(-1));
    return {
      start: formatMonitorAxisTime(points[0], sameDay),
      middle: formatMonitorAxisTime(middle, sameDay),
      end: formatMonitorAxisTime(points.at(-1), sameDay),
    };
  }

  function buildMonitorSmoothPath(points: MonitorRenderPoint[]) {
    if (!points.length) return '';
    if (points.length === 1) return `M ${points[0].x},${points[0].y}`;

    const segments = [`M ${points[0].x},${points[0].y}`];
    for (let index = 0; index < points.length - 1; index += 1) {
      const previous = points[Math.max(index - 1, 0)];
      const current = points[index];
      const next = points[index + 1];
      const afterNext = points[Math.min(index + 2, points.length - 1)];
      const controlOne = {
        x: clampMonitorNumber(current.x + (next.x - previous.x) / 6, current.x, next.x),
        y: clampMonitorNumber(current.y + (next.y - previous.y) / 6, MONITOR_CHART_TOP, MONITOR_CHART_BOTTOM),
      };
      const controlTwo = {
        x: clampMonitorNumber(next.x - (afterNext.x - current.x) / 6, current.x, next.x),
        y: clampMonitorNumber(next.y - (afterNext.y - current.y) / 6, MONITOR_CHART_TOP, MONITOR_CHART_BOTTOM),
      };
      segments.push(
        `C ${formatMonitorChartNumber(controlOne.x)},${formatMonitorChartNumber(controlOne.y)} ${formatMonitorChartNumber(controlTwo.x)},${formatMonitorChartNumber(controlTwo.y)} ${next.x},${next.y}`,
      );
    }
    return segments.join(' ');
  }

  function resolveMonitorSummaryText(
    summaryItem: MonitorSummaryItem | null,
    series: MonitorSeries[],
    mode: 'latest' | 'average' | 'peak' | 'lowest',
  ) {
    if (summaryItem && typeof summaryItem === 'object' && summaryItem.text) {
      return String(summaryItem.text);
    }

    const values = series
      .flatMap((item) => item.rawPoints.map((point) => point.value))
      .filter((value) => Number.isFinite(value));
    if (!values.length) return '';
    if (mode === 'latest') {
      const latestPoint = series[0]?.rawPoints[series[0].rawPoints.length - 1];
      return latestPoint?.displayValue || '';
    }
    const value =
      mode === 'average'
        ? values.reduce((sum, item) => sum + item, 0) / values.length
        : mode === 'peak'
          ? Math.max(...values)
          : Math.min(...values);
    return Number.isInteger(value) ? String(value) : value.toFixed(2);
  }

  function handleMonitorPointerMove(event: MouseEvent, chart: MonitorChartView) {
    const points = chart.series[0]?.points || [];
    if (!points.length) return;
    const bounds = (event.currentTarget as SVGElement).getBoundingClientRect();
    if (!bounds.width) return;
    const pointerX = ((event.clientX - bounds.left) / bounds.width) * MONITOR_CHART_WIDTH;
    let nearestIndex = 0;
    let nearestDistance = Number.POSITIVE_INFINITY;
    points.forEach((point, index) => {
      const distance = Math.abs(point.x - pointerX);
      if (distance < nearestDistance) {
        nearestDistance = distance;
        nearestIndex = index;
      }
    });
    activeMonitorPoint.value = { chartKey: chart.key, index: nearestIndex };
  }

  function clearMonitorPointer() {
    activeMonitorPoint.value = null;
  }

  function resolveActiveMonitorPoint(chart: MonitorChartView): ActiveMonitorPoint | null {
    if (!activeMonitorPoint.value || activeMonitorPoint.value.chartKey !== chart.key) return null;
    const firstPoint = chart.series[0]?.points[activeMonitorPoint.value.index];
    if (!firstPoint) return null;
    const seriesPoints = chart.series
      .map((series) => {
        const point = series.points[Math.min(activeMonitorPoint.value?.index || 0, series.points.length - 1)];
        if (!point) return null;
        return {
          key: series.key,
          name: series.name,
          color: series.color,
          x: point.x,
          y: point.y,
          valueText: point.displayValue || formatMonitorAxisValue(point.value, ''),
        };
      })
      .filter((item): item is ActiveMonitorSeriesPoint => item !== null);

    return {
      x: firstPoint.x,
      y: firstPoint.y,
      time: resolveMonitorPointTimeText(firstPoint),
      seriesPoints,
    };
  }

  function resolveMonitorTooltipStyle(chart: MonitorChartView) {
    const activePoint = resolveActiveMonitorPoint(chart);
    if (!activePoint) return { display: 'none' };
    const minY = Math.min(
      ...activePoint.seriesPoints.map((point) => Number(point.y)).filter((value) => Number.isFinite(value)),
      activePoint.y,
    );
    return {
      left: `${(activePoint.x / MONITOR_CHART_WIDTH) * 100}%`,
      top: `${(minY / MONITOR_CHART_HEIGHT) * 100}%`,
    };
  }

  return {
    monitorChartViews,
    activeMonitorPoint,
    handleMonitorPointerMove,
    clearMonitorPointer,
    resolveActiveMonitorPoint,
    resolveMonitorTooltipStyle,
  };
}

function buildNiceMonitorAxisMax(max: number, min: number) {
  const range = Math.max(max - min, Math.abs(max), 1);
  const roughMax = max + range * 0.08;
  const magnitude = 10 ** Math.floor(Math.log10(roughMax));
  const normalized = roughMax / magnitude;
  let niceNormalized = 10;
  if (normalized <= 1) niceNormalized = 1;
  else if (normalized <= 2) niceNormalized = 2;
  else if (normalized <= 5) niceNormalized = 5;
  return niceNormalized * magnitude;
}

function resolveMonitorUnit(chart: MonitorChartRecord) {
  const chartData = chart.chart || {};
  return String(chartData.unit || chart.type || '').trim();
}

function formatMonitorAxisValue(value: number, unit: string) {
  if (!Number.isFinite(value)) return '--';
  const abs = Math.abs(value);
  let text = '';
  if (abs >= 100) text = trimMonitorZeros(value.toFixed(0));
  else if (abs >= 10) text = trimMonitorZeros(value.toFixed(1));
  else if (abs >= 1) text = trimMonitorZeros(value.toFixed(2));
  else text = trimMonitorZeros(value.toFixed(3));
  return unit ? `${text} ${unit}` : text;
}

function trimMonitorZeros(text: string) {
  return String(text)
    .replace(/(\.\d*?[1-9])0+$/u, '$1')
    .replace(/\.0+$/u, '');
}

function clampMonitorNumber(value: number, min: number, max: number) {
  return Math.min(Math.max(value, min), max);
}

function formatMonitorChartNumber(value: number) {
  return Number(value.toFixed(2));
}

function normalizeMonitorTimestamp(value: unknown) {
  const numeric = Number(value);
  if (!Number.isFinite(numeric) || numeric <= 0) return 0;
  if (numeric >= 1e12) return Math.round(numeric);
  if (numeric >= 1e9) return Math.round(numeric * 1000);
  return 0;
}

function resolveMonitorPointTimeText(point: MonitorPoint) {
  if (point.timestamp > 0) return formatMonitorDateTime(point.timestamp);
  return String(point.time || '--').trim() || '--';
}

function formatMonitorAxisTime(point: MonitorPoint, sameDay: boolean) {
  if (point.timestamp > 0) {
    const date = new Date(point.timestamp);
    if (!Number.isNaN(date.getTime())) {
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      const hour = String(date.getHours()).padStart(2, '0');
      const minute = String(date.getMinutes()).padStart(2, '0');
      return sameDay ? `${hour}:${minute}` : `${month}-${day} ${hour}:${minute}`;
    }
  }
  const text = String(point.time || '--').trim();
  if (!text || text === '--') return '--';
  const timeMatch = text.match(/(\d{2}:\d{2})(?::\d{2})?$/u);
  if (sameDay && timeMatch) return timeMatch[1];
  const dateTimeMatch = text.match(/(\d{2}-\d{2})\s+(\d{2}:\d{2})/u);
  return dateTimeMatch ? `${dateTimeMatch[1]} ${dateTimeMatch[2]}` : text;
}

function isSameMonitorDate(left: MonitorPoint, right: MonitorPoint) {
  if (left.timestamp > 0 && right.timestamp > 0) {
    const leftDate = new Date(left.timestamp);
    const rightDate = new Date(right.timestamp);
    return (
      leftDate.getFullYear() === rightDate.getFullYear() &&
      leftDate.getMonth() === rightDate.getMonth() &&
      leftDate.getDate() === rightDate.getDate()
    );
  }
  const leftText = String(left.time || '').slice(0, 10);
  const rightText = String(right.time || '').slice(0, 10);
  return leftText !== '' && leftText === rightText;
}

function formatMonitorDateTime(timestamp: number) {
  const date = new Date(timestamp);
  if (Number.isNaN(date.getTime())) return '--';
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  const hour = String(date.getHours()).padStart(2, '0');
  const minute = String(date.getMinutes()).padStart(2, '0');
  const second = String(date.getSeconds()).padStart(2, '0');
  return `${year}-${month}-${day} ${hour}:${minute}:${second}`;
}
