import { lazyRouteView } from "@turaidc/shared/runtime";
import {
  buildSeoLandingRouteMeta,
  seoLandingMetaPages,
} from "@/data/seoLandingMeta";
import type { RouteRecordRaw } from "vue-router";

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
            "图拉云提供云服务器、独立服务器、云电脑与 IDC 服务，覆盖香港、美国与国内多地节点。",
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
          title: "产品与服务",
          description:
            "浏览图拉云云服务器、独立服务器、云电脑与 IDC 产品方案。",
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
          title: "产品与服务",
          noSitemap: true,
          robots: "noindex,nofollow",
        },
      },
      {
        path: "products/:typeId/:groupId(\\d+)/:productId(\\d+)",
        name: "WwwProductsPurchase",
        component: lazyRouteView(
          () => import("@/pages/website/products/index.vue"),
        ),
        meta: {
          title: "产品与服务",
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
        meta: { title: "商品详情" },
      },
      {
        path: "about",
        name: "WwwAbout",
        component: lazyRouteView(
          () => import("@/pages/website/about/index.vue"),
        ),
        meta: {
          title: "关于我们",
          description: "了解图拉云的 IDC 服务能力、节点覆盖和平台优势。",
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
          title: "服务条款",
          description: "查看图拉云服务条款。",
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
          title: "隐私政策",
          description: "查看图拉云隐私政策。",
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
          title: "官方公告",
          description: "查看图拉云平台公告和服务通知。",
          canonical: "/notices",
        },
      },
      {
        path: "notices/:id",
        name: "WwwNoticeDetail",
        component: lazyRouteView(
          () => import("@/pages/website/notice-detail/index.vue"),
        ),
        meta: { title: "公告详情" },
      },
      {
        path: "help",
        name: "WwwHelp",
        component: lazyRouteView(
          () => import("@/pages/website/help/index.vue"),
        ),
        meta: {
          title: "帮助中心",
          description: "查看图拉云产品购买、账单支付和服务管理帮助。",
          canonical: "/help",
        },
      },
      {
        path: "help/:id",
        name: "WwwHelpDetail",
        component: lazyRouteView(
          () => import("@/pages/website/help-detail/index.vue"),
        ),
        meta: { title: "帮助详情" },
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
