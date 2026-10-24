import sitemap from "@astrojs/sitemap";
import vue from "@astrojs/vue";
import { defineConfig } from "astro/config";

/**
 * 单页营销站的构建配置。
 *
 * 只用 Astro 的两件事：构建期把 Vue 组件渲成静态 HTML，以及生成 sitemap。
 * 不给任何组件加 client 指令，所以首屏不下载 Vue 运行时。
 */
export default defineConfig({
  site: "https://www.turaidc.com",
  output: "static",
  outDir: "./dist",
  compressHTML: true,
  integrations: [vue(), sitemap()],
});
