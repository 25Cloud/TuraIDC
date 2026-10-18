import { computed, ref } from "vue";
import { useSiteBrandingStore } from "@/app/stores/siteBranding";

// 商品展示方式：list=商品表格（列表式）/ card=商品卡片（卡片式）。
//
// 两层来源：
//  1. 站点默认值——后台「商品展示」配置，决定访客第一次打开看到的形态；
//  2. 访客自己的选择——选购页右上角的切换。只存在本机 localStorage，
//     不写后端、不影响别人，同一浏览器下次打开仍是上次选的形态。
// 运营仍然可以通过站点默认值决定首屏效果，访客想按自己的习惯看则由自己切。
export const DISPLAY_MODE_LIST = "list";
export const DISPLAY_MODE_CARD = "card";

/** 访客展示方式偏好：仅本机浏览器有效，不做跨端/跨账号同步 */
export const DISPLAY_MODE_USER_STORAGE_KEY = "turaidc:shop-display-mode";

function normalizeMode(value) {
  return String(value ?? "")
    .trim()
    .toLowerCase() === DISPLAY_MODE_CARD
    ? DISPLAY_MODE_CARD
    : DISPLAY_MODE_LIST;
}

function readUserMode() {
  try {
    const stored = window.localStorage.getItem(DISPLAY_MODE_USER_STORAGE_KEY);
    return stored === DISPLAY_MODE_CARD || stored === DISPLAY_MODE_LIST
      ? stored
      : null;
  } catch {
    // 隐私模式/禁用存储：读不到就跟随站点默认值，不影响浏览
    return null;
  }
}

function writeUserMode(mode) {
  try {
    window.localStorage.setItem(DISPLAY_MODE_USER_STORAGE_KEY, mode);
  } catch {
    // 写不进去只影响下次是否记得，不阻断本次切换
  }
}

export function useProductDisplayMode() {
  const siteBranding = useSiteBrandingStore();
  // 站点配置未下发或写脏时回落到列表式：列表式是既有行为，必须始终可售
  const siteDisplayMode = computed(() =>
    normalizeMode(siteBranding.productDisplayMode),
  );
  // 访客覆盖值；null 表示没选过，跟随站点默认
  const userDisplayMode = ref(readUserMode());
  const displayMode = computed(
    () => userDisplayMode.value || siteDisplayMode.value,
  );
  /** 访客是否主动选过（用于提示「已按你的选择展示」这类文案，暂未使用） */
  const followsUserChoice = computed(() => userDisplayMode.value !== null);

  function setDisplayMode(value) {
    const next = normalizeMode(value);
    userDisplayMode.value = next;
    writeUserMode(next);
  }

  return {
    displayMode,
    siteDisplayMode,
    followsUserChoice,
    setDisplayMode,
  };
}
