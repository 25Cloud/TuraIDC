import { LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';

// 全局注册一次 ECharts 模块，避免多处重复注册
echarts.use([TooltipComponent, LegendComponent, GridComponent, LineChart, PieChart, CanvasRenderer]);

export default echarts;
