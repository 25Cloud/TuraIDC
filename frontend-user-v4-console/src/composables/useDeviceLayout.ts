import { useBreakpoints } from '@vueuse/core';

/**
 * 控制台三档响应式布局
 *
 * 断点与 `src/style/variables.less` 中的 `@screen-sm-rem / @screen-md-rem / @screen-lg-rem` 对齐：
 * - mobile:  < 48rem  (768px)
 * - tablet:  48rem ~ 64rem (768px ~ 1024px)
 * - desktop: ≥ 64rem (1024px)
 * - wide:    ≥ 75rem (1200px)
 *
 * 用法：
 * ```ts
 * const { isMobile, isTablet, isDesktop, isWide } = useDeviceLayout();
 * ```
 *
 * 需要在视口变化时切换 DOM 结构（如 table ↔ card、菜单折叠）的场景应使用本 composable，
 * 纯样式切换仍优先用 CSS `@media` 引用 `@screen-*-rem` 变量。
 */
const BREAKPOINTS = {
  mobile: 48 * 16, // 48rem → 768px
  tablet: 64 * 16, // 64rem → 1024px
  wide: 75 * 16, // 75rem → 1200px
} as const;

export function useDeviceLayout() {
  const breakpoints = useBreakpoints(BREAKPOINTS);
  const isMobile = breakpoints.smaller('mobile');
  const isTablet = breakpoints.between('mobile', 'tablet');
  const isDesktop = breakpoints.greaterOrEqual('tablet');
  const isWide = breakpoints.greaterOrEqual('wide');

  return {
    isMobile,
    isTablet,
    isDesktop,
    isWide,
  };
}
