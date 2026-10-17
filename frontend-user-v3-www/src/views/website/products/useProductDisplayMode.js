import { computed } from 'vue'
import { useSiteBrandingStore } from '@/app/stores/siteBranding'

// 全站商品展示方式：list=商品表格（列表式）/ card=商品卡片（卡片式）。
//
// 只认后台「站点设置 → 基础设置」的配置，**不提供用户级覆盖**：
// 排版是站点经营方的决策，用户不该各自看到一个不同的形态——此前那套
// 「用户可用 cookie 临时改一次」的写法（对齐魔方 cookie("cart_theme")）
// 会让同一站点出现两种排版，运营无法确认自己配置的效果，已移除。
export const DISPLAY_MODE_LIST = 'list'
export const DISPLAY_MODE_CARD = 'card'

function normalizeMode(value) {
  return String(value ?? '')
    .trim()
    .toLowerCase() === DISPLAY_MODE_CARD
    ? DISPLAY_MODE_CARD
    : DISPLAY_MODE_LIST
}

export function useProductDisplayMode() {
  const siteBranding = useSiteBrandingStore()
  // 站点配置未下发或写脏时回落到列表式：列表式是既有行为，必须始终可售
  const displayMode = computed(() => normalizeMode(siteBranding.productDisplayMode))

  return { displayMode }
}
