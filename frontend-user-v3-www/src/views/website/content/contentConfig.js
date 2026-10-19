import {
  Bell,
  Box,
  Download,
  Files,
  Notification,
  Reading,
} from "@element-plus/icons-vue";
import { t } from "@/locales";

export const CONTENT_TYPE_NOTICE = "notice";
export const CONTENT_TYPE_HELP = "help";
export const CONTENT_SCOPE_CLIENT = "client";
export const CONTENT_SCOPE_SITE = "site";

export function getContentConfig(contentType, scope = CONTENT_SCOPE_CLIENT) {
  const isSiteScope = scope === CONTENT_SCOPE_SITE;
  const configMap = {
    [CONTENT_TYPE_NOTICE]: {
      contentType: CONTENT_TYPE_NOTICE,
      pageTitle: t("content.notice.pageTitle"),
      detailTitle: t("content.notice.detailTitle"),
      heroTitle: t("content.notice.pageTitle"),
      heroDescription: t("content.nav.newsDesc"),
      searchPlaceholder: t("content.search.placeholder"),
      emptyText: t("content.notice.empty"),
      // 与帮助中心页及控制台端统一用「全部分类」，此前公告页写「所有分类」，
      // 同一套内容页里两种叫法混用。
      allCategoryLabel: t("content.nav.allCategories"),
      hotTitle: t("content.article.hot"),
      secondaryTitle: t("content.activity.latest"),
      sidebarCategoryTitle: t("content.nav.moreSections"),
      categoryTitle: t("layout.notice.categoryTitle"),
      routeBasePath: isSiteScope ? "/notices" : "/client/notices",
      detailRouteName: isSiteScope ? "WwwNoticeDetail" : "ClientNoticeDetail",
      apiListMethod: "notices",
      apiDetailMethod: "noticeDetail",
      overviewCategoryKey: "notice_categories",
      overviewArticleKey: "notices",
      keywordSuggestions: [
        "服务条款",
        "公告通知",
        "云服务器",
        "容器系统",
        "007ACS",
      ],
      shortcuts: isSiteScope
        ? [
            {
              key: "notices",
              label: t("content.nav.news"),
              icon: Notification,
              route: "/notices",
            },
            {
              key: "help",
              label: t("content.nav.docs"),
              icon: Files,
              route: "/help",
            },
            {
              key: "products",
              label: t("layout.nav.productCatalog"),
              icon: Box,
              route: "/products",
            },
          ]
        : [
            {
              key: "notices",
              label: t("content.nav.news"),
              icon: Notification,
              route: "/client/notices",
            },
            {
              key: "help",
              label: t("content.nav.docs"),
              icon: Files,
              route: "/client/help",
            },
            {
              key: "tools",
              label: t("content.nav.downloads"),
              icon: Download,
              route: "/client/tools",
            },
          ],
    },
    [CONTENT_TYPE_HELP]: {
      contentType: CONTENT_TYPE_HELP,
      pageTitle: t("content.help.pageTitle"),
      detailTitle: t("content.help.detailTitle"),
      heroTitle: t("content.help.pageTitle"),
      heroDescription: t("content.help.subtitle"),
      searchPlaceholder: t("content.help.searchPlaceholder"),
      emptyText: t("content.help.empty"),
      allCategoryLabel: t("content.nav.allCategories"),
      hotTitle: t("content.help.hotDocuments"),
      secondaryTitle: t("content.help.recentlyUpdated"),
      sidebarCategoryTitle: t("content.nav.moreSections"),
      categoryTitle: t("content.help.categories"),
      routeBasePath: isSiteScope ? "/help" : "/client/help",
      detailRouteName: isSiteScope ? "WwwHelpDetail" : "ClientHelpDetail",
      apiListMethod: "helpArticles",
      apiDetailMethod: "helpDetail",
      overviewCategoryKey: "help_categories",
      overviewArticleKey: "help_articles",
      keywordSuggestions: [
        "新手入门",
        "支付账单",
        "服务管理",
        "续费说明",
        "实例控制台",
      ],
      shortcuts: isSiteScope
        ? [
            {
              key: "help",
              label: t("content.nav.docs"),
              icon: Reading,
              route: "/help",
            },
            {
              key: "notices",
              label: t("content.nav.news"),
              icon: Bell,
              route: "/notices",
            },
            {
              key: "products",
              label: t("layout.nav.productCatalog"),
              icon: Box,
              route: "/products",
            },
          ]
        : [
            {
              key: "help",
              label: t("content.nav.docs"),
              icon: Reading,
              route: "/client/help",
            },
            {
              key: "notices",
              label: t("content.nav.news"),
              icon: Bell,
              route: "/client/notices",
            },
            {
              key: "tools",
              label: t("content.nav.downloads"),
              icon: Download,
              route: "/client/tools",
            },
          ],
    },
  };

  return configMap[contentType] || configMap[CONTENT_TYPE_NOTICE];
}
