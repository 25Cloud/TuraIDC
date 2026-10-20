/**
 * 按半角宽度截断文本 + 一级分组图标的展示回退规则。
 *
 * 三端（官网 / 管理端 / 用户控制台）都必须用这一份实现：
 * - 半角字符（英文、数字、半角符号）算 1 个单位；
 * - 全角字符（中文、全角标点、假名、韩文）算 2 个单位；
 * - 累计到上限就停，正好等于上限可以用满，永远不截出「半个字符」。
 *
 * 例：「轻量云」→「轻量」；「GameCloud」→「Game」；「轻量Game」→「轻量」。
 */

/** 一级分组文字图标最多占用的半角宽度单位。 */
export const GROUP_ICON_ABBR_MAX_UNITS = 4

/** 东亚全角（宽字符）码点区间，覆盖中文、假名、韩文、全角标点与常见 Emoji。 */
const FULL_WIDTH_RANGES: ReadonlyArray<readonly [number, number]> = [
  [0x1100, 0x115f], // 韩文字母
  [0x2329, 0x232a],
  [0x2e80, 0x303e], // 中日韩部首、标点
  [0x3040, 0x33ff], // 假名、注音、围字
  [0x3400, 0x4dbf], // 中日韩扩展 A
  [0x4e00, 0x9fff], // 中日韩统一表意文字
  [0xa000, 0xa4cf], // 彝文
  [0xa960, 0xa97f],
  [0xac00, 0xd7a3], // 韩文音节
  [0xf900, 0xfaff], // 兼容表意文字
  [0xfe10, 0xfe19], // 竖排标点
  [0xfe30, 0xfe6f], // 兼容形式
  [0xff00, 0xff60], // 全角 ASCII / 全角标点
  [0xffe0, 0xffe6], // 全角符号
  [0x1b000, 0x1b001],
  [0x1f200, 0x1f251],
  [0x1f300, 0x1f64f], // 常见 Emoji
  [0x1f900, 0x1f9ff],
  [0x1fa70, 0x1faff],
]

/** 判断一个 Unicode 码点是否按 2 个半角宽度显示。 */
export function isFullWidthCodePoint(codePoint: number): boolean {
  if (!Number.isFinite(codePoint) || codePoint < 0) {
    return false
  }

  return FULL_WIDTH_RANGES.some(([start, end]) => codePoint >= start && codePoint <= end)
}

/** 单个字符占用的半角宽度单位：全角 2，半角 1。 */
export function charHalfWidth(char: string): 1 | 2 {
  const codePoint = (char || '').codePointAt(0)

  return codePoint !== undefined && isFullWidthCodePoint(codePoint) ? 2 : 1
}

/**
 * 按半角宽度截断文本。
 *
 * @param value 原始文本，非字符串会被安全转换
 * @param maxUnits 最大半角宽度单位，默认 4
 */
export function truncateByHalfWidth(value: unknown, maxUnits = GROUP_ICON_ABBR_MAX_UNITS): string {
  const source = String(value ?? '').trim()
  const limit = Number.isFinite(maxUnits) ? Math.floor(maxUnits) : GROUP_ICON_ABBR_MAX_UNITS

  if (source === '' || limit <= 0) {
    return ''
  }

  let units = 0
  let result = ''

  for (const char of Array.from(source)) {
    const width = charHalfWidth(char)
    if (units + width > limit) {
      break
    }

    units += width
    result += char
  }

  return result
}

/** 一级分组名缩写：按半角宽度取前 4 个单位。 */
export function abbreviateGroupName(value: unknown, maxUnits = GROUP_ICON_ABBR_MAX_UNITS): string {
  return truncateByHalfWidth(value, maxUnits)
}

export interface ProductGroupIconInput {
  /** 既有内置图标名（TDesign 图标名，如 Server/Gamepad） */
  icon?: unknown
  /** 文字图标，如「轻量」「游戏」 */
  icon_text?: unknown
  /** 图片图标地址（接口 icon_resolved.image / icon_image 已解析为可访问地址） */
  icon_image?: unknown
  /** 一级分组名，缺省时读取 label / name */
  label?: unknown
  name?: unknown
}

export type ProductGroupIconMode = 'image' | 'text' | 'icon' | 'abbr'

export interface ResolvedProductGroupIcon {
  /** image=图片；text=文字图标；icon=内置图标名；abbr=名称缩写 */
  mode: ProductGroupIconMode
  /** 图片地址（有配置时非空，仅 mode=image 时用于渲染） */
  image: string
  /** 文字图标（有配置时非空，仅 mode=text 时用于渲染） */
  text: string
  /** 内置 TDesign 图标名（有配置时非空，仅 mode=icon 时用于渲染） */
  icon_name: string
  /** 一级分组名缩写（按半角宽度最多 4 单位，始终计算，mode=abbr 时展示） */
  abbr: string
}

/**
 * 解析一级分组图标（与后端 App\Support\ProductGroupIcon 同一优先级）：
 *
 * `icon_image` → `icon_text` → `icon`（内置图标名）→ 名称缩写。
 *
 * 三端直接按 `mode` 渲染即可，不需要各自再写一遍回退链；
 * 接口已下发 `icon_resolved` 时优先使用接口值，本函数用于兜底或自算。
 */
export function resolveProductGroupIcon(input?: ProductGroupIconInput | null): ResolvedProductGroupIcon {
  const iconName = String(input?.icon ?? '').trim()
  const iconText = String(input?.icon_text ?? '').trim()
  const image = String(input?.icon_image ?? '').trim()
  const groupName = String(input?.label ?? input?.name ?? '').trim()
  const abbr = abbreviateGroupName(groupName)

  const mode: ProductGroupIconMode =
    image !== '' ? 'image' : iconText !== '' ? 'text' : iconName !== '' ? 'icon' : 'abbr'

  return {
    mode,
    image,
    text: iconText,
    icon_name: iconName,
    abbr,
  }
}
