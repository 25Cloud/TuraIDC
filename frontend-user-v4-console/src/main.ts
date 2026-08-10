/* eslint-disable simple-import-sort/imports */
import { createApp } from 'vue';

import App from './App.vue';
import router from './router';
import { store } from './store';
import i18n from './locales';
import { initClientRuntimeConnectionHints } from './app/runtime/network';
import { initClientSessionActivityTracking } from './app/runtime/session';

// TDesign 组件按需引入（见 vite.config.ts 的 unplugin-vue-components + TDesignResolver），
// 不再全量 app.use(TDesign)。以下两类样式无法被模板自动引入，需显式加载：
// 1) 函数式插件（MessagePlugin / DialogPlugin）渲染的弹层样式；
import 'tdesign-vue-next/es/message/style/index.css';
import 'tdesign-vue-next/es/dialog/style/index.css';
import 'tdesign-vue-next/es/popup/style/index.css';
import 'tdesign-vue-next/es/loading/style/index.css';
import '@/style/index.less';
import './permission';

const app = createApp(App);

initClientSessionActivityTracking();
initClientRuntimeConnectionHints();

app.use(store);
app.use(router);
app.use(i18n);

app.mount('#app');
