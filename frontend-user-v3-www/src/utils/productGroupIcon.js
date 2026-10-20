import {
  Box,
  Connection,
  Cpu,
  DataLine,
  Grid,
  Monitor,
  Platform,
  Tickets,
} from "@element-plus/icons-vue";

/**
 * 一级分组图标（官网侧）。
 *
 * 后端 Site 资源已经把图标解析成统一契约 `icon_resolved = { mode, image, text, icon_name, abbr }`
 * （优先级：图片 → 文字 → 内置图标名 → 名称缩写），这里只负责把它翻译成官网能渲染的形态：
 *  - image：直接 <img>
 *  - text / abbr：文字徽标
 *  - icon：把 TDesign 图标名映射到 Element Plus 图标；映射不到时退回名称缩写
 *
 * 缩写口径与后端 / shared/text/abbr.ts 一致：中文/全角算 2 单位，英文/数字/半角算 1 单位，
 * 最多 4 单位，不截半个字符。
 */

// TDesign 图标名 → Element Plus 图标组件
const ICON_NAME_MAP = {
  Platform,
  Gamepad: Cpu,
  Monitor,
  Desktop: Monitor,
  Grid,
  Node: Connection,
  Server: Box,
  Network: Connection,
  DataLine,
  Tickets,
  Box,
};

function isFullWidth(codePoint) {
  return (
    (codePoint >= 0x1100 && codePoint <= 0x115f) ||
    (codePoint >= 0x2e80 && codePoint <= 0x303e) ||
    (codePoint >= 0x3040 && codePoint <= 0x33ff) ||
    (codePoint >= 0x3400 && codePoint <= 0x4dbf) ||
    (codePoint >= 0x4e00 && codePoint <= 0x9fff) ||
    (codePoint >= 0xa000 && codePoint <= 0xa4cf) ||
    (codePoint >= 0xac00 && codePoint <= 0xd7a3) ||
    (codePoint >= 0xf900 && codePoint <= 0xfaff) ||
    (codePoint >= 0xfe30 && codePoint <= 0xfe6f) ||
    (codePoint >= 0xff00 && codePoint <= 0xff60) ||
    (codePoint >= 0xffe0 && codePoint <= 0xffe6) ||
    (codePoint >= 0x1f300 && codePoint <= 0x1faff)
  );
}

/** 按半角宽度截断：中文/全角=2，英文/数字/半角=1，最多 maxUnits 单位，不截半个字符 */
export function abbrByWidth(label, maxUnits = 4) {
  const text = String(label || "").trim();
  if (!text) {
    return "";
  }

  let units = 0;
  let result = "";

  for (const char of text) {
    const width = isFullWidth(char.codePointAt(0)) ? 2 : 1;
    if (units + width > maxUnits) {
      break;
    }
    units += width;
    result += char;
  }

  return result || text.slice(0, 1);
}

/**
 * 把 icon_resolved 翻译成渲染描述。
 *
 * @param {object|null|undefined} iconResolved 后端 icon_resolved
 * @param {string} fallbackLabel 没有图标数据时用于生成缩写的名称
 * @returns {{kind: 'image'|'text'|'icon', image?: string, text?: string, component?: object|string}}
 */
export function resolveGroupIcon(iconResolved, fallbackLabel = "") {
  const resolved =
    iconResolved && typeof iconResolved === "object" ? iconResolved : null;

  if (resolved) {
    const mode = String(resolved.mode || "").trim();
    const image = String(resolved.image || "").trim();
    const text = String(resolved.text || "").trim();
    const abbr = String(resolved.abbr || "").trim();
    const iconName = String(resolved.icon_name || "").trim();

    if (mode === "image" && image) {
      return { kind: "image", image };
    }
    if (mode === "text" && text) {
      return { kind: "text", text };
    }
    if (mode === "icon" && iconName) {
      const component = ICON_NAME_MAP[iconName];
      if (component) {
        return { kind: "icon", component, text: abbr };
      }
      // 映射不到的内置图标名（后续新增的 TDesign 图标）退回缩写，至少不空白
      if (abbr) {
        return { kind: "text", text: abbr };
      }
    }
    if (mode === "abbr" && abbr) {
      return { kind: "text", text: abbr };
    }
  }

  return { kind: "text", text: abbrByWidth(fallbackLabel) };
}

/** 从 types 列表构建「类型值 → 图标数据」映射（卡片角标用） */
export function buildTypeIconMap(types = []) {
  const map = {};
  for (const type of Array.isArray(types) ? types : []) {
    if (!type || typeof type !== "object") {
      continue;
    }
    const icon = {
      icon_resolved: type.icon_resolved || null,
      label: String(type.label || type.name || "").trim(),
    };
    for (const key of [type.value, type.id, type.code, type.slug]) {
      if (key !== undefined && key !== null && String(key).trim() !== "") {
        map[String(key)] = icon;
      }
    }
  }
  return map;
}

/** 商品卡片按一级分组字段取图标 */
export function resolveProductTypeIcon(product, typeIconMap = {}) {
  if (!typeIconMap || typeof typeIconMap !== "object") {
    return null;
  }

  // 只用「一级分组」的标识匹配。product_type 是业务类型枚举（虚拟主机/NAT 等都可能归到
  // other），拿它当分组键会把卡片角标匹配到别的分组（例如 NAT 商品显示「虚拟」）。
  const candidates = [
    product?.first_product_group_id,
    product?.first_product_group_code,
    product?.first_product_group_slug,
  ];

  for (const key of candidates) {
    if (key === undefined || key === null || String(key).trim() === "") {
      continue;
    }
    const matched = typeIconMap[String(key)];
    if (matched) {
      return resolveGroupIcon(
        matched.icon_resolved,
        matched.label || product?.first_product_group_name || "",
      );
    }
  }

  // 接口只给了一级分组名时按名称反查一次，仍匹配不到就不显示图标（不猜）
  const groupName = String(product?.first_product_group_name || "").trim();
  if (!groupName) {
    return null;
  }
  for (const entry of Object.values(typeIconMap)) {
    if (entry && String(entry.label || "").trim() === groupName) {
      return resolveGroupIcon(entry.icon_resolved, entry.label);
    }
  }
  return null;
}
