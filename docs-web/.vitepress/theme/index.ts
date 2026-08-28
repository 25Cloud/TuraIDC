import DefaultTheme from "vitepress/theme";
import type { Options } from "@nolebase/vitepress-plugin-enhanced-readabilities";
import {
  InjectionKey,
  LayoutMode,
} from "@nolebase/vitepress-plugin-enhanced-readabilities";
import { defaultZhCNLocale } from "@nolebase/vitepress-plugin-enhanced-readabilities/locales";
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
        locales: {
          "zh-CN": defaultZhCNLocale,
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
