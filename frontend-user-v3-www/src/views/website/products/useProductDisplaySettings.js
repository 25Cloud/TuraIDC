import { computed, ref } from "vue";
import { useSiteBrandingStore } from "@/app/stores/siteBranding";

/**
 * 选购页展示层配置。
 *
 * 两层来源：
 *  1. 站点默认值——后台「商品展示」页面维护的版心与卡片开关，决定访客首次看到的效果；
 *  2. 访客自己的版心选择——宽屏下选固定宽度（并可选具体宽度）或撑满屏幕，
 *     只存本机 localStorage，不写后端、不影响其他访客。
 * 卡片库存与独立配置页机型对比仍只认站点设置（不提供用户级覆盖）。
 */

/** 访客版心偏好：仅本机浏览器有效 */
export const PRODUCT_PAGE_WIDTH_STORAGE_KEY = "turaidc:shop-page-width";

/** 固定宽度可选档位（与后台允许的 960–1920 区间对齐） */
export const PRODUCT_PAGE_WIDTH_PRESETS = [1200, 1280, 1440, 1600, 1920];

function normalizeLayoutMode(value) {
  return value === "full" ? "full" : "contained";
}

function normalizeMaxWidth(value) {
  const width = Number(value);

  if (!Number.isFinite(width) || width < 960 || width > 1920) {
    return null;
  }

  return Math.round(width);
}

function readStoredWidthPreference() {
  try {
    const raw = window.localStorage.getItem(PRODUCT_PAGE_WIDTH_STORAGE_KEY);
    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw);
    const mode =
      parsed?.mode === "full" || parsed?.mode === "contained"
        ? parsed.mode
        : null;
    const maxWidth = normalizeMaxWidth(parsed?.maxWidth);

    return mode || maxWidth ? { mode, maxWidth } : null;
  } catch {
    // 隐私模式/禁用存储：读不到就跟随站点默认值
    return null;
  }
}

function writeStoredWidthPreference(preference) {
  try {
    if (!preference) {
      window.localStorage.removeItem(PRODUCT_PAGE_WIDTH_STORAGE_KEY);
      return;
    }

    window.localStorage.setItem(
      PRODUCT_PAGE_WIDTH_STORAGE_KEY,
      JSON.stringify(preference),
    );
  } catch {
    // 写不进去只影响下次是否记得，不阻断本次切换
  }
}

/**
 * 版心（页面宽度）开关。
 *
 * 站点默认值来自后台；访客可在选购页右上角「页面宽度」面板里改成固定宽度
 * （并从 1200/1280/1440/1600/1920 里选一档）或撑满屏幕。切换时容器用
 * max-width 过渡，宽屏下能看到平滑展开/收拢。
 */
export function useProductPageWidth() {
  const siteBranding = useSiteBrandingStore();

  const siteLayoutMode = computed(() =>
    siteBranding.productPageLayout === "full" ? "full" : "contained",
  );
  const siteContentMaxWidth = computed(() => {
    const width = normalizeMaxWidth(siteBranding.productPageMaxWidth);

    return width ?? 1280;
  });

  const stored = ref(readStoredWidthPreference());

  const layoutMode = computed(() => {
    const mode = stored.value?.mode;

    return mode ? normalizeLayoutMode(mode) : siteLayoutMode.value;
  });
  const isContained = computed(() => layoutMode.value === "contained");
  const contentMaxWidth = computed(
    () => stored.value?.maxWidth ?? siteContentMaxWidth.value,
  );
  /** 版心宽度通过 CSS 变量下发：固定宽度给具体 px，撑满给 100vw（两者都是长度，可过渡） */
  const contentMaxWidthVar = computed(() =>
    isContained.value ? `${contentMaxWidth.value}px` : "100vw",
  );
  /** 访客是否主动改过版心（决定面板里是否显示「恢复站点默认」） */
  const followsUserWidthChoice = computed(() => stored.value !== null);

  function persist(next) {
    stored.value = next;
    writeStoredWidthPreference(next);
  }

  function setLayoutMode(mode) {
    persist({
      mode: normalizeLayoutMode(mode),
      // 切回固定宽度时沿用访客上次选的档位；没选过就用站点默认
      maxWidth: stored.value?.maxWidth ?? null,
    });
  }

  function setContentMaxWidth(value) {
    const width = normalizeMaxWidth(value);
    if (!width) {
      return;
    }

    persist({ mode: "contained", maxWidth: width });
  }

  function resetLayoutWidth() {
    persist(null);
  }

  return {
    layoutMode,
    isContained,
    contentMaxWidth,
    contentMaxWidthVar,
    followsUserWidthChoice,
    setLayoutMode,
    setContentMaxWidth,
    resetLayoutWidth,
  };
}

/**
 * 选购页与独立配置页共用的展示配置。
 * 版心字段来自 useProductPageWidth（含访客覆盖），卡片库存与机型对比仍是站点级。
 */
export function useProductDisplaySettings() {
  const siteBranding = useSiteBrandingStore();
  const pageWidth = useProductPageWidth();

  const cardShowStock = computed(() =>
    Boolean(siteBranding.productCardShowStock),
  );
  const detailSpecCompare = computed(() =>
    Boolean(siteBranding.productDetailSpecCompare),
  );

  return {
    ...pageWidth,
    cardShowStock,
    detailSpecCompare,
  };
}
