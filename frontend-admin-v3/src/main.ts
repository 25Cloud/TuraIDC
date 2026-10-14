/* eslint-disable simple-import-sort/imports */
import { createApp } from 'vue';
import TDesign from 'tdesign-vue-next';

import App from './App.vue';
import { initAdminSessionActivityTracking } from './app/runtime/session';
import { siteApi } from './api/site';
import router from './router';
import { getSettingStore, store } from './store';
import i18n from './locales';

import 'tdesign-vue-next/es/style/index.css';
import '../../theme.css';
import '@/style/index.less';
import './permission';

const app = createApp(App);

initAdminSessionActivityTracking();

app.use(TDesign);
app.use(store);
app.use(router);
app.use(i18n);

// 恢复持久化的主题模式与主题色：pinia persist 在 store 实例化时恢复 state，
// 这里把 theme-mode / theme-color 同步到 <html>，避免刷新后丢失暗色与主题色。
const settingStore = getSettingStore();
if (settingStore.mode) {
  settingStore.changeMode(settingStore.mode as 'light' | 'dark' | 'auto');
}
if (settingStore.brandTheme) {
  settingStore.changeBrandTheme(settingStore.brandTheme as string);
}

// 管理端固定跟随站点的品牌主色与圆角，与对外官网保持同一套品牌视觉。
// 站点配置接口失败时不阻断使用：上面已用本地持久化的值兜底。
void siteApi
  .config()
  .then((config) => {
    settingStore.applySiteBrandTokens({
      color: config?.brand_primary_color,
      radius: config?.brand_radius,
    });
  })
  .catch(() => {
    // 忽略：沿用构建期默认色
  });

app.mount('#app');
