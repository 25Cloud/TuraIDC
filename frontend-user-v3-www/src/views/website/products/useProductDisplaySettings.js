import { computed } from "vue";
import { useSiteBrandingStore } from "@/app/stores/siteBranding";

/**
 * 选购页展示层的站点级配置（后台「商品展示」独立页面维护）。
 *
 * 版心、卡片库存、独立配置页机型对比都是**站点经营方的决策**，不提供用户级覆盖：
 * 同一站点只有一种版心，运营才能确认自己配出来的效果。
 * （展示方式不是这类——它由站点默认值兜底，访客可在选购页自行切换，见
 * useProductDisplayMode.js。）
 */
export function useProductDisplaySettings() {
  const siteBranding = useSiteBrandingStore();

  const layoutMode = computed(() =>
    siteBranding.productPageLayout === "full" ? "full" : "contained",
  );
  const isContained = computed(() => layoutMode.value === "contained");
  const contentMaxWidth = computed(() => {
    const width = Number(siteBranding.productPageMaxWidth);

    return Number.isFinite(width) && width > 0 ? width : 1280;
  });
  /** 版心宽度通过 CSS 变量下发，页面样式只需 `max-width: var(--xxx)` */
  const contentMaxWidthVar = computed(() => `${contentMaxWidth.value}px`);

  const cardShowStock = computed(() =>
    Boolean(siteBranding.productCardShowStock),
  );
  const detailSpecCompare = computed(() =>
    Boolean(siteBranding.productDetailSpecCompare),
  );

  return {
    layoutMode,
    isContained,
    contentMaxWidth,
    contentMaxWidthVar,
    cardShowStock,
    detailSpecCompare,
  };
}
