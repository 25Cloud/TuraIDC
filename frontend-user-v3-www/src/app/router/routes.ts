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
          description:
            "提供云服务器、独立服务器、云电脑与 IDC 服务，覆盖香港、美国与国内多地节点。",
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
        path: "products/:typeId/:groupId(\\d+)/:childGroupId(\\d+)/:productId(\\d+)",
        name: "WwwProductsPurchaseWithChild",
        component: lazyRouteView(
          () => import("@/pages/website/products/index.vue"),
        ),
        meta: {
          title: t("seo.products.title"),
          noSitemap: true,
          robots: "noindex,nofollow",
        },
      },
      {
        // 三段路径的第三段：三级分组 ID（目录态）或旧商品深链的商品 ID（历史兼容）。
        // 路由层形状相同无法区分，参数名用 thirdId，由页面按目录数据判定。
        path: "products/:typeId/:groupId(\\d+)/:thirdId(\\d+)",
        name: "WwwProductsPurchase",
        component: lazyRouteView(
          () => import("@/pages/website/products/index.vue"),
        ),
        meta: {
          title: t("seo.products.title"),
          noSitemap: true,
          robots: "noindex,nofollow",
        },
      },
      {
        // 目录态（未选中商品）：卡片式浏览时也要把类型、分组与三级分组留在地址里，
        // 否则刷新/从详情页返回都会掉回 /products、丢掉当前分类。
        path: "products/:typeId/:groupId(\\d+)",
        name: "WwwProductsGroup",
        component: lazyRouteView(
          () => import("@/pages/website/products/index.vue"),
        ),
        meta: {
          title: t("seo.products.title"),
          noSitemap: true,
          robots: "noindex,nofollow",
        },
      },
      {
        path: "products/:id",
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
