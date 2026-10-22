import { lazyRouteView } from "@turaidc/shared/runtime";
import {
  buildSeoLandingRouteMeta,
  seoLandingMetaPages,
} from "@/data/seoLandingMeta";
import type { RouteRecordRaw } from "vue-router";
import { t } from "@/locales";

const seoLandingRoutes: RouteRecordRaw[] = seoLandingMetaPages.map((page) => ({
  path: page.path.replace(/^\/+/, ""),
  name: page.routeName,
  component: lazyRouteView(
    () => import("@/pages/website/seo-landing/index.vue"),
  ),
  meta: buildSeoLandingRouteMeta(page),
}));

export const clientRoutes: RouteRecordRaw[] = [
  {
    path: "/",
    component: lazyRouteView(() => import("@/layout/WebsiteLayout.vue")),
    children: [
      {
        path: "",
        name: "WwwHome",
        component: lazyRouteView(
          () => import("@/pages/website/home/index.vue"),
        ),
        meta: {
          // 首页标题由站点配置的 browser_title 动态提供，不硬编码品牌名
          title: "",
          description: t("seo.home.description"),
          canonical: "/",
        },
      },
      {
        path: "products",
        name: "WwwProducts",
        component: lazyRouteView(
          () => import("@/pages/website/products/index.vue"),
        ),
        meta: {
          title: t("seo.products.title"),
          description: t("seo.products.description"),
          canonical: "/products",
        },
      },
      ...seoLandingRoutes,
      {
        // 商品地址（4 段）：列表式在选购页内联配置，卡片式渲染独立配置页。
        // 两种形态共用这一套地址，展示方式只由本机偏好/后台默认决定。
        path: "products/:typeSlug([A-Za-z][A-Za-z0-9_-]*)/:groupId(\\d+)/:childGroupId(\\d+)/:productId(\\d+)",
        name: "WwwProductsPurchase",
        component: lazyRouteView(
          () => import("@/pages/website/products/ProductsRoute.vue"),
        ),
        meta: {
          title: t("seo.products.title"),
          noSitemap: true,
          // 商品详情页（卡片式）用的就是这条四段地址。商品页是官网最值钱的落地页，
          // 爬虫执行 JS 后地址会被规范到四段路径：这里若带 noindex，渲染后的页面会被
          // 判成死链，sitemap 里收录的短地址 /products/{id} 也进不了索引。
          // 目录态（其余三段形态）保持 noindex，避免同一批商品被多个目录地址重复收录。
        },
      },
      {
        // 三段路径的第三段：三级分组 ID（目录态）或旧商品深链的商品 ID（历史兼容）。
        // 路由层形状相同无法区分，参数名用 thirdId，由页面按目录数据判定。
        path: "products/:typeSlug([A-Za-z][A-Za-z0-9_-]*)/:groupId(\\d+)/:thirdId(\\d+)",
        name: "WwwProductsChild",
        component: lazyRouteView(
          () => import("@/pages/website/products/ProductsRoute.vue"),
        ),
        meta: {
          title: t("seo.products.title"),
          noSitemap: true,
          robots: "noindex,nofollow",
        },
      },
      {
        // 目录态（二级分组）：卡片式展示该分组下的商品卡；列表式会自动补全
        // 到第一个商品，把地址推进到四段。
        path: "products/:typeSlug([A-Za-z][A-Za-z0-9_-]*)/:groupId(\\d+)",
        name: "WwwProductsGroup",
        component: lazyRouteView(
          () => import("@/pages/website/products/ProductsRoute.vue"),
        ),
        meta: {
          title: t("seo.products.title"),
          noSitemap: true,
          robots: "noindex,nofollow",
        },
      },
      {
        // 一级分组目录态：card 式自动补全到第一个二级/三级分组展示卡片，
        // 但不改写地址；list 式会自动补全到第一个商品。
        path: "products/:typeSlug([A-Za-z][A-Za-z0-9_-]*)",
        name: "WwwProductsType",
        component: lazyRouteView(
          () => import("@/pages/website/products/ProductsRoute.vue"),
        ),
        meta: {
          title: t("seo.products.title"),
          noSitemap: true,
          robots: "noindex,nofollow",
        },
      },
      {
        // 旧版卡片式配置页地址：/products/{商品 ID}。保留兼容，页面会把地址
        // 规范成 /products/{typeSlug}/{groupId}/{childGroupId}/{productId}。
        path: "products/:id(\\d+)",
        name: "WwwProductDetail",
        component: lazyRouteView(
          () => import("@/pages/website/ProductDetail/index.vue"),
        ),
        meta: { title: t("seo.productDetail.title") },
      },
      {
        path: "about",
        name: "WwwAbout",
        component: lazyRouteView(
          () => import("@/pages/website/about/index.vue"),
        ),
        meta: {
          title: t("seo.about.title"),
          description: t("seo.about.description"),
          canonical: "/about",
        },
      },
      {
        // 开源与商业许可：AGPL 免费路径的强制义务、源码获取入口与商业授权说明。
        // 页脚「开源版本」入口指向这里，AGPL 第 13 条的源码提供入口也由本页承载。
        path: "open-source",
        name: "WwwOpenSource",
        component: lazyRouteView(
          () => import("@/views/website/OpenSource/index.vue"),
        ),
        meta: {
          title: t("openSource.title"),
          description: t("openSource.subtitle"),
          canonical: "/open-source",
        },
      },
      {
        path: "terms",
        name: "WwwTerms",
        component: lazyRouteView(
          () => import("@/pages/website/legal-document/index.vue"),
        ),
        meta: {
          title: t("seo.terms.title"),
          description: t("seo.terms.description"),
          canonical: "/terms",
          documentKey: "terms",
        },
      },
      {
        path: "privacy",
        name: "WwwPrivacy",
        component: lazyRouteView(
          () => import("@/pages/website/legal-document/index.vue"),
        ),
        meta: {
          title: t("seo.privacy.title"),
          description: t("seo.privacy.description"),
          canonical: "/privacy",
          documentKey: "privacy",
        },
      },
      {
        path: "notices",
        name: "WwwNotices",
        component: lazyRouteView(
          () => import("@/pages/website/notices/index.vue"),
        ),
        meta: {
          title: t("seo.notices.title"),
          description: t("seo.notices.description"),
          canonical: "/notices",
        },
      },
      {
        path: "notices/:id",
        name: "WwwNoticeDetail",
        component: lazyRouteView(
          () => import("@/pages/website/notice-detail/index.vue"),
        ),
        meta: { title: t("seo.noticeDetail.title") },
      },
      {
        path: "help",
        name: "WwwHelp",
        component: lazyRouteView(
          () => import("@/pages/website/help/index.vue"),
        ),
        meta: {
          title: t("seo.help.title"),
          description: t("seo.help.description"),
          canonical: "/help",
        },
      },
      {
        path: "help/:id",
        name: "WwwHelpDetail",
        component: lazyRouteView(
          () => import("@/pages/website/help-detail/index.vue"),
        ),
        meta: { title: t("seo.helpDetail.title") },
      },
    ],
  },
  {
    path: "/:pathMatch(.*)*",
    name: "WwwNotFound",
    component: lazyRouteView(() => import("@/pages/common/NotFound.vue")),
    meta: { title: "404", robots: "noindex,nofollow" },
  },
];
