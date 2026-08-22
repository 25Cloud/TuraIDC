/* eslint-disable simple-import-sort/imports */
import { createApp } from 'vue';

import App from './App.vue';
import router from './router';
import { getSettingStore, store } from './store';
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
// 引入全量 TDesign 组件样式：TDesignResolver (esm) 不自动注入 CSS，
// 布局/菜单/按钮/头像等组件需显式加载样式。
import 'tdesign-vue-next/es/style/index.css';
import '@/style/index.less';
import './permission';

const app = createApp(App);

initClientSessionActivityTracking();
initClientRuntimeConnectionHints();

app.use(store);
app.use(router);
app.use(i18n);

// 恢复持久化的主题模式与主题色：pinia persist 已在 store 实例化时恢复 state，
// 这里把 theme-mode / theme-color 同步到 <html>，保证刷新后仍保持暗色/主题色一致。
const settingStore = getSettingStore();
if (settingStore.mode) {
  settingStore.changeMode(settingStore.mode as 'light' | 'dark' | 'auto');
}
if (settingStore.brandTheme) {
  settingStore.changeBrandTheme(settingStore.brandTheme as string);
}

app.mount('#app');
