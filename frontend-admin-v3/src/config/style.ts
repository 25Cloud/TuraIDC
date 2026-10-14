export default {
  showFooter: true,
  isSidebarCompact: false,
  showBreadcrumb: false,
  menuAutoCollapsed: false,
  mode: 'light',
  layout: 'side',
  splitMenu: false,
  sideMode: 'light',
  isFooterAside: false,
  isSidebarFixed: true,
  isHeaderFixed: true,
  isUseTabsRouter: false,
  showHeader: true,
  hideHeaderLogo: false,
  // 兜底值，与后端 SiteConfigPayload::DEFAULT_BRAND_PRIMARY_COLOR 保持一致：
  // 站点配置拉到之前先用它顶着，避免先闪一下旧品牌色再跳成后台配的颜色。
  brandTheme: '#165DFF',
};
