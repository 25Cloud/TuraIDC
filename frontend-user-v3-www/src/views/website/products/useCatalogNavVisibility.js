import { ref } from "vue";

/**
 * 商品目录侧栏的显示偏好。
 *
 * 选购页与卡片式配置页共用同一个本机偏好（localStorage），
 * 在任一页收起目录后，另一页也保持收起——两页是同一套目录，
 * 不该出现「列表页收起了、配置页又弹出来」的反复。
 */
export const CATALOG_NAV_VISIBLE_STORAGE_KEY = "turaidc:shop-nav-visible";

function readStoredVisibility() {
  try {
    return window.localStorage.getItem(CATALOG_NAV_VISIBLE_STORAGE_KEY) !== "0";
  } catch {
    return true;
  }
}

export function useCatalogNavVisibility() {
  const catalogNavVisible = ref(readStoredVisibility());

  function setCatalogNavVisible(visible) {
    catalogNavVisible.value = Boolean(visible);
    try {
      window.localStorage.setItem(
        CATALOG_NAV_VISIBLE_STORAGE_KEY,
        catalogNavVisible.value ? "1" : "0",
      );
    } catch {
      // 写不进去只影响下次是否记得，不阻断本次切换
    }
  }

  function toggleCatalogNav() {
    setCatalogNavVisible(!catalogNavVisible.value);
  }

  return {
    catalogNavVisible,
    setCatalogNavVisible,
    toggleCatalogNav,
  };
}
