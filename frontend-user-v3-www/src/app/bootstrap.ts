import { createApp } from "vue";
import { createPinia } from "pinia";
import { provideGlobalConfig } from "element-plus/es/components/config-provider/index.mjs";
// 用 sass 版样式走 Vite scss 管线（经 additionalData 注入主题变量编译），
// 避免引入 element-plus/theme-chalk 默认主题 base.css（含未覆盖的 --el-color-primary-rgb 死块）。
import "element-plus/es/components/message/style/index";

import App from "@/App.vue";
import { createClientRouter } from "@/app/router";
import {
  initClientRuntimeConnectionHints,
  primeClientConnectionHints,
} from "@/app/runtime/network";
import { i18n } from "@/locales";
import { useAppStore } from "@/stores/app";
import "@/assets/styles/global.scss";

const SITE_CONFIG_PREFETCH_TIMEOUT = 1200;

function isHomeRoute(pathname: string) {
  return pathname === "/" || pathname === "";
}

async function preloadSiteConfig(appStore: ReturnType<typeof useAppStore>) {
  if (typeof window === "undefined" || isHomeRoute(window.location.pathname)) {
    return false;
  }

  const timeoutPromise = new Promise<void>((resolve) => {
    window.setTimeout(resolve, SITE_CONFIG_PREFETCH_TIMEOUT);
  });

  const result = await Promise.race([
    appStore.fetchSiteConfig().catch(() => undefined),
    timeoutPromise,
  ]);

  return Boolean(result);
}

export function bootstrapClientApp() {
  const app = createApp(App);
  const pinia = createPinia();
  const router = createClientRouter();

  app.use(pinia);
  app.use(i18n);

  // 组件语言（分页/日期等文案）由 App.vue 的 <el-config-provider> 响应式承载，
  // 这里只负责全局尺寸与 zIndex。
  provideGlobalConfig({ size: "default", zIndex: 3200 }, app, true);
  app.use(router);

  initClientRuntimeConnectionHints({
    apiBaseUrl: import.meta.env.VITE_API_BASE_URL,
  });

  const appStore = useAppStore();
  const splash = document.getElementById("app-splash");
  let splashHidden = false;

  const hideSplash = () => {
    if (!splash || splashHidden) return;
    splashHidden = true;
    splash.classList.add("fade-out");
    splash.addEventListener("transitionend", () => splash.remove(), {
      once: true,
    });
    setTimeout(() => splash.remove(), 400);
  };

  /**
   * 首屏骨架（index.html 的 #app-splash）必须撑到首个路由真正渲染完成再淡出。
   *
   * 之前 mount 后立即移除：限速下会先露出「通用路由骨架」，再跳成页面自己的骨架，
   * 用户连续看到 2-3 种加载态。现在改成等 router.isReady（首个懒加载路由组件已解析）
   * 后再等两帧（页面骨架已提交渲染）才淡出；splash 与页面骨架同形同节奏，淡出无缝。
   * 兜底 8s：极端情况下首个 chunk 失败也不让骨架永久盖住页面。
   */
  const revealApp = () => {
    requestAnimationFrame(() => {
      requestAnimationFrame(hideSplash);
    });
  };

  const revealAfterRouteReady = () => {
    const ready = router.isReady ? router.isReady() : Promise.resolve();
    Promise.resolve(ready).then(revealApp).catch(revealApp);
  };
  setTimeout(hideSplash, 8000);

  // 性能：先挂载应用再并行预热站点配置，避免非首页入口（SEO 落地页/products/help）
  // 的首屏被 config 预热（最多 1200ms）串行阻塞，FCP/LCP 直接受影响。
  app.mount("#app");
  revealAfterRouteReady();

  const primeHints = () => {
    primeClientConnectionHints({
      urls: [appStore.siteLogo, appStore.siteFavicon],
    });
  };

  // 首页 config 虽内嵌在 /v2/site/home 响应中，但仍需提前并行拉取，
  // 使首屏标题/图标尽快替换硬编码的默认品牌（preloadSiteConfig 对首页立即返回，不阻塞首屏）。
  void preloadSiteConfig(appStore).then((preloaded) => {
    if (preloaded) {
      primeHints();
      return;
    }
    void appStore.fetchSiteConfig().finally(primeHints);
  });
}
