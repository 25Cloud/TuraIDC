<template>
  <t-row :gutter="16" class="row-container">
    <t-col :xs="12" :xl="9">
      <t-card
        :title="t('pages.dashboardBase.topPanel.analysis.title')"
        :subtitle="currentMonth"
        class="dashboard-chart-card"
        :bordered="false"
      >
        <template #actions>
          <div class="dashboard-chart-title-container card-date-picker-pair">
            <t-date-picker
              v-model="currencyStartDate"
              class="card-date-picker-container"
              clearable
              theme="primary"
              mode="date"
              format="YYYY-MM-DD"
              value-type="YYYY-MM-DD"
              placeholder="开始日期"
              @change="onCurrencyDateChange"
            />
            <t-date-picker
              v-model="currencyEndDate"
              class="card-date-picker-container"
              clearable
              theme="primary"
              mode="date"
              format="YYYY-MM-DD"
              value-type="YYYY-MM-DD"
              placeholder="结束日期"
              @change="onCurrencyDateChange"
            />
          </div>
        </template>
        <div
          id="monitorContainer"
          class="dashboard-chart-container"
          :style="{ width: '100%', height: `${resizeTime * 326}px` }"
        />
      </t-card>
    </t-col>
    <t-col :xs="12" :xl="3">
      <t-card
        :title="t('pages.dashboardBase.topPanel.analysis.channels')"
        :subtitle="currentMonth"
        class="dashboard-chart-card"
        :bordered="false"
      >
        <div
          id="countContainer"
          class="dashboard-chart-container"
          :style="{ width: `${resizeTime * 326}px`, height: `${resizeTime * 326}px`, margin: '0 auto' }"
        />
      </t-card>
    </t-col>
  </t-row>
</template>
<script setup lang="ts">
import { useWindowSize } from '@vueuse/core';
import { LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { computed, nextTick, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue';

import { t } from '@/locales';
import { useSettingStore } from '@/store';
import { changeChartsTheme } from '@/utils/color';
import { LAST_7_DAYS } from '@/utils/date';

import { getLineChartDataSet, getPieChartDataSet } from '../index';

echarts.use([TooltipComponent, LegendComponent, PieChart, GridComponent, LineChart, CanvasRenderer]);

const getThisMonth = (checkedValues?: string[]) => {
  let date: Date;
  if (!checkedValues || checkedValues.length === 0) {
    date = new Date();
    return `${date.getFullYear()}-${date.getMonth() + 1}`;
  }
  date = new Date(checkedValues[0]);
  const date2 = new Date(checkedValues[1]);

  const startMonth = date.getMonth() + 1 > 9 ? date.getMonth() + 1 : `0${date.getMonth() + 1}`;
  const endMonth = date2.getMonth() + 1 > 9 ? date2.getMonth() + 1 : `0${date2.getMonth() + 1}`;
  return `${date.getFullYear()}-${startMonth}  至  ${date2.getFullYear()}-${endMonth}`;
};

const store = useSettingStore();
const resizeTime = ref(1);
const currencyStartDate = ref(LAST_7_DAYS[0]);
const currencyEndDate = ref(LAST_7_DAYS[1]);

const chartColors = computed(() => store.chartColors);

// monitorChart
let monitorContainer: HTMLElement;
let monitorChart: echarts.ECharts;
const renderMonitorChart = () => {
  if (!monitorContainer) {
    monitorContainer = document.getElementById('monitorContainer');
  }
  monitorChart = echarts.init(monitorContainer);
  monitorChart.setOption(getLineChartDataSet({ ...chartColors.value }));
};

// monitorChart
let countContainer: HTMLElement;
let countChart: echarts.ECharts;
let resizeFrame = 0;
const renderCountChart = () => {
  if (!countContainer) {
    countContainer = document.getElementById('countContainer');
  }
  countChart = echarts.init(countContainer);
  countChart.setOption(getPieChartDataSet(chartColors.value));

  // 取消之前高亮的图形
  countChart.dispatchAction({
    type: 'downplay',
    seriesIndex: 0,
    dataIndex: -1,
  });
  // 高亮当前图形
  countChart.dispatchAction({
    type: 'highlight',
    seriesIndex: 0,
    dataIndex: 1,
  });
  // 显示 tooltip
  countChart.dispatchAction({
    type: 'showTip',
    seriesIndex: 0,
    dataIndex: 1,
  });
};

const renderCharts = () => {
  renderMonitorChart();
  renderCountChart();
};

// chartSize update
const updateContainer = () => {
  if (document.documentElement.clientWidth >= 1400 && document.documentElement.clientWidth < 1920) {
    resizeTime.value = Number((document.documentElement.clientWidth / 2080).toFixed(2));
  } else if (document.documentElement.clientWidth < 1080) {
    resizeTime.value = Number((document.documentElement.clientWidth / 1080).toFixed(2));
  } else {
    resizeTime.value = 1;
  }

  monitorChart.resize({
    width: monitorContainer.clientWidth,
    height: resizeTime.value * 326,
  });
  countChart.resize({
    width: resizeTime.value * 326,
    height: resizeTime.value * 326,
  });
};

function scheduleUpdateContainer() {
  if (resizeFrame) return;
  resizeFrame = window.requestAnimationFrame(() => {
    resizeFrame = 0;
    updateContainer();
  });
}

onMounted(() => {
  renderCharts();
  nextTick(() => {
    scheduleUpdateContainer();
  });
});

const { width, height } = useWindowSize();
watch([width, height], () => {
  scheduleUpdateContainer();
});

onBeforeUnmount(() => {
  if (resizeFrame) {
    window.cancelAnimationFrame(resizeFrame);
    resizeFrame = 0;
  }
});

onDeactivated(() => {
  storeModeWatch();
  storeBrandThemeWatch();
  storeSidebarCompactWatch();
});

const currentMonth = ref(getThisMonth());

const storeBrandThemeWatch = watch(
  () => store.brandTheme,
  () => {
    changeChartsTheme([monitorChart, countChart]);
  },
);

const storeSidebarCompactWatch = watch(
  () => store.isSidebarCompact,
  () => {
    if (store.isSidebarCompact) {
      nextTick(() => {
        scheduleUpdateContainer();
      });
    } else {
      setTimeout(() => {
        scheduleUpdateContainer();
      }, 180);
    }
  },
);

const storeModeWatch = watch(
  () => store.mode,
  () => {
    [monitorChart, countChart].forEach((item) => {
      item.dispose();
    });

    renderCharts();
  },
);

const onCurrencyChange = (checkedValues: string[]) => {
  currentMonth.value = getThisMonth(checkedValues);
  monitorChart.setOption(getLineChartDataSet({ dateTime: checkedValues, ...chartColors.value }));
};

const onCurrencyDateChange = () => {
  onCurrencyChange([currencyStartDate.value, currencyEndDate.value].filter(Boolean));
};
</script>
<style lang="less" scoped>
.card-date-picker-pair {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
}

.card-date-picker-container {
  width: 150px;
}

.dashboard-chart-card {
  padding: var(--td-comp-paddingTB-xxl) var(--td-comp-paddingLR-xxl);

  :deep(.t-card__header) {
    padding: 0;
  }

  :deep(.t-card__body) {
    padding: 0;
    margin-top: var(--td-comp-margin-xxl);
  }

  :deep(.t-card__title) {
    font: var(--td-font-title-large);
    font-weight: 400;
  }
}
</style>
