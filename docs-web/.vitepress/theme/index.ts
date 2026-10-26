import DefaultTheme from "vitepress/theme";
import type { Options } from "@nolebase/vitepress-plugin-enhanced-readabilities";
import {
  InjectionKey,
  LayoutMode,
} from "@nolebase/vitepress-plugin-enhanced-readabilities";
import {
  defaultEnLocale,
  defaultZhCNLocale,
} from "@nolebase/vitepress-plugin-enhanced-readabilities/locales";
import "@nolebase/vitepress-plugin-enhanced-readabilities/client/style.css";
import "@nolebase/vitepress-plugin-highlight-targeted-heading/client/style.css";
import Layout from "./Layout.vue";
import DeploymentSelector from "./components/DeploymentSelector.vue";

export default {
  extends: DefaultTheme,
  Layout,
  enhanceApp({ app }) {
    app
      .provide<Options>(InjectionKey, {
        // 增强阅读面板跟随站点语言；该插件没有繁体语言包，繁体站点沿用简体文案。
        locales: {
          "zh-CN": defaultZhCNLocale,
          "zh-TW": defaultZhCNLocale,
          "zh-HK": defaultZhCNLocale,
          en: defaultEnLocale,
        },
        layoutSwitch: {
          defaultMode: LayoutMode.FullWidth,
        },
        spotlight: {
          defaultToggle: true,
        },
      } as Options)
      .component("DeploymentSelector", DeploymentSelector);
  },
};
