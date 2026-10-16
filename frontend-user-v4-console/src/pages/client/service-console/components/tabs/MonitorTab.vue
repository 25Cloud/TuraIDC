<template>
  <section class="console-panel-section">
    <t-card :title="$t('serviceConsole.monitorInfo')" :bordered="false">
      <template #actions>
        <t-space>
          <t-radio-group v-model="monitorState.range" variant="default-filled" @change="() => loadMonitor(true)">
            <t-radio-button value="3h">{{ $t('serviceConsole.range3h') }}</t-radio-button>
            <t-radio-button value="24h">{{ $t('serviceConsole.range24h') }}</t-radio-button>
            <t-radio-button value="7d">{{ $t('serviceConsole.range7d') }}</t-radio-button>
            <t-radio-button value="30d">{{ $t('serviceConsole.range30d') }}</t-radio-button>
          </t-radio-group>
          <t-button :loading="monitorState.loading" @click="loadMonitor(true)">{{
            $t('serviceConsole.refresh')
          }}</t-button>
        </t-space>
      </template>

      <!-- 加载中盖整块遮罩 + 骨架；错误、空数据、上游不支持分成三种可区分的视图 -->
      <panel-state
        :loading="monitorState.loading"
        :loaded="monitorState.loaded"
        :error="monitorState.error"
        :unsupported="monitorState.supported === false"
        :unsupported-text="monitorState.message || $t('serviceConsole.monitor.unsupported')"
        :empty="monitorState.charts.length === 0"
        :empty-text="$t('serviceConsole.monitor.empty')"
        :skeleton="4"
        @retry="loadMonitor(true)"
      >
        <div class="monitor-grid">
          <article v-for="chart in monitorChartViews" :key="chart.key" class="monitor-card">
            <header class="monitor-card-header">
              <div>
                <span>{{ chart.label }}</span>
                <strong>{{ chart.latestText }}</strong>
              </div>
              <small v-if="chart.latestTime">{{ chart.latestTime }}</small>
            </header>

            <div v-if="chart.series.length" class="monitor-chart-shell">
              <div class="monitor-y-axis">
                <span v-for="tick in chart.yAxisTicks" :key="tick.key" :style="{ top: `${tick.top}%` }">
                  {{ tick.label }}
                </span>
              </div>
              <div class="monitor-chart-main">
                <div class="monitor-chart-plot">
                  <svg
                    class="monitor-chart"
                    :viewBox="`0 0 ${MONITOR_CHART_WIDTH} ${MONITOR_CHART_HEIGHT}`"
                    role="img"
                    :aria-label="$t('serviceConsole.monitor.chartLabel', { name: chart.label })"
                    preserveAspectRatio="none"
                    @mousemove="handleMonitorPointerMove($event, chart)"
                    @mouseleave="clearMonitorPointer"
                  >
                    <g class="monitor-chart-grid">
                      <line
                        v-for="tick in chart.yAxisTicks"
                        :key="`${tick.key}-line`"
                        x1="0"
                        :y1="tick.y"
                        :x2="MONITOR_CHART_WIDTH"
                        :y2="tick.y"
                      />
                    </g>
                    <line
                      x1="0"
                      :y1="MONITOR_CHART_BOTTOM"
                      :x2="MONITOR_CHART_WIDTH"
                      :y2="MONITOR_CHART_BOTTOM"
                      class="monitor-chart-axis"
                    />
                    <line x1="0" :y1="MONITOR_CHART_TOP" x2="0" :y2="MONITOR_CHART_BOTTOM" class="monitor-chart-axis" />
                    <path
                      v-for="series in chart.series"
                      :key="series.key"
                      class="monitor-chart-line"
                      :d="series.path"
                      :stroke="series.color"
                      :stroke-width="series.lineWidth"
                    />
                    <g v-if="resolveActiveMonitorPoint(chart)" class="monitor-chart-pointer">
                      <line
                        :x1="resolveActiveMonitorPoint(chart)?.x"
                        :y1="MONITOR_CHART_TOP"
                        :x2="resolveActiveMonitorPoint(chart)?.x"
                        :y2="MONITOR_CHART_BOTTOM"
                      />
                      <template
                        v-for="seriesPoint in resolveActiveMonitorPoint(chart)?.seriesPoints || []"
                        :key="`${seriesPoint.key}-point`"
                      >
                        <circle
                          class="monitor-point-halo"
                          :cx="seriesPoint.x"
                          :cy="seriesPoint.y"
                          r="4.8"
                          :stroke="seriesPoint.color"
                          stroke-width="1.5"
                        />
                        <circle :cx="seriesPoint.x" :cy="seriesPoint.y" r="2.2" :fill="seriesPoint.color" />
                      </template>
                    </g>
                  </svg>
                  <div
                    v-if="resolveActiveMonitorPoint(chart)"
                    class="monitor-tooltip"
                    :style="resolveMonitorTooltipStyle(chart)"
                  >
                    <strong>{{ resolveActiveMonitorPoint(chart)?.time }}</strong>
                    <span
                      v-for="seriesPoint in resolveActiveMonitorPoint(chart)?.seriesPoints || []"
                      :key="seriesPoint.key"
                      class="monitor-tooltip-row"
                    >
                      <i :style="{ backgroundColor: seriesPoint.color }"></i>
                      <b>{{ seriesPoint.name || chart.label }}</b>
                      <em>{{ seriesPoint.valueText }}</em>
                    </span>
                  </div>
                </div>
                <div class="monitor-x-axis">
                  <span>{{ chart.xAxisLabels.start }}</span>
                  <span>{{ chart.xAxisLabels.middle }}</span>
                  <span>{{ chart.xAxisLabels.end }}</span>
                </div>
              </div>
            </div>
            <div v-else class="monitor-chart-empty">{{ chart.message || $t('serviceConsole.monitor.noTrend') }}</div>
            <div class="monitor-metrics">
              <span
                >平均 <strong>{{ chart.averageText }}</strong></span
              >
              <span
                >峰值 <strong>{{ chart.peakText }}</strong></span
              >
              <span
                >最低 <strong>{{ chart.lowestText }}</strong></span
              >
            </div>
          </article>
        </div>
      </panel-state>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import {
  MONITOR_CHART_BOTTOM,
  MONITOR_CHART_HEIGHT,
  MONITOR_CHART_TOP,
  MONITOR_CHART_WIDTH,
  useConsoleMonitor,
} from '../../composables/useConsoleMonitor';
import { useServiceConsoleContext } from '../context';
import PanelState from '../PanelState.vue';

const { monitorState, loadMonitor } = useServiceConsoleContext();

const {
  monitorChartViews,
  handleMonitorPointerMove,
  clearMonitorPointer,
  resolveActiveMonitorPoint,
  resolveMonitorTooltipStyle,
} = useConsoleMonitor(monitorState);
</script>
