import { createApp } from "vue";
import { createPinia } from "pinia";
import { provideGlobalConfig } from "element-plus/es/components/config-provider/index.mjs";
import zhCn from "element-plus/es/locale/lang/zh-cn";
// 用 sass 版样式走 Vite scss 管线（经 additionalData 注入主题变量编译），
// 避免引入 element-plus/theme-chalk 默认主题 base.css（含未覆盖的 --el-color-primary-rgb 死块）。
import "element-plus/es/components/message/style/index";

import App from "@/App.vue";
import { createClientRouter } from "@/app/router";
import {
  initClientRuntimeConnectionHints,
  primeClientConnectionHints,
} from "@/app/runtime/network";
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
  provideGlobalConfig(
    { locale: zhCn, size: "default", zIndex: 3200 },
    app,
    true,
  );
  app.use(router);

  initClientRuntimeConnectionHints({
    apiBaseUrl: import.meta.env.VITE_API_BASE_URL,
  });

  const appStore = useAppStore();
  const splash = document.getElementById("app-splash");

  const hideSplash = () => {
    if (!splash) return;
    splash.classList.add("fade-out");
    splash.addEventListener("transitionend", () => splash.remove(), {
      once: true,
    });
    setTimeout(() => splash.remove(), 400);
  };

  // 性能：先挂载应用再并行预热站点配置，避免非首页入口（SEO 落地页/products/help）
  // 的首屏被 config 预热（最多 1200ms）串行阻塞，FCP/LCP 直接受影响。
  app.mount("#app");
  hideSplash();

  if (isHomeRoute(window.location.pathname)) {
    // 首页 config 已内嵌在 /v2/site/home 响应的 site_config 中，无需单独预热
    return;
  }

  const primeHints = () => {
    primeClientConnectionHints({
      urls: [appStore.siteLogo, appStore.siteFavicon],
    });
  };

  void preloadSiteConfig(appStore).then((preloaded) => {
    if (preloaded) {
      primeHints();
      return;
    }
    void appStore.fetchSiteConfig().finally(primeHints);
  });
}
