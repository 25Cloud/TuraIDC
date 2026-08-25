// CPU 品牌识别：按优先级匹配中英文，返回徽章信息；无法识别时兜底为 Intel。
// 优先级：Core Ultra > 铂金(Platinum) > 金牌(Gold) > AMD(EPYC/Ryzen) > Xeon/E5(志强) > 兜底 Intel。
const BRAND_RULES = [
  {
    brand: "core-ultra",
    className: "--core-ultra",
    icon: "UT",
    label: "Core Ultra",
    pattern: /Core\s*Ultra|酷睿\s*Ultra/i,
  },
  {
    brand: "platinum",
    className: "--platinum",
    icon: "铂",
    label: "铂金",
    pattern: /铂金|Platinum/i,
  },
  {
    brand: "gold",
    className: "--gold",
    icon: "金",
    label: "金牌",
    pattern: /金牌|Gold/i,
  },
  {
    brand: "amd",
    className: "--amd",
    icon: "AMD",
    label: "AMD",
    pattern: /AMD|EPYC|Ryzen|霄龙|锐龙/i,
  },
  {
    brand: "xeon",
    className: "--xeon",
    icon: "至",
    label: "至强",
    pattern: /Xeon|E5|志强|至强/i,
  },
  {
    brand: "intel",
    className: "--intel",
    icon: "酷",
    label: "酷睿",
    pattern: /Intel|酷睿|赛扬|Pentium/i,
  },
];

/**
 * 识别 CPU 型号文本对应的品牌徽章信息。
 * @param {string|undefined|null} label CPU 型号文本
 * @returns {{ brand: string, icon: string, label: string, className: string } | null}
 */
export function resolveCpuBrandBadge(label) {
  const text = String(label || "").trim();
  if (!text) {
    return null;
  }

  for (const rule of BRAND_RULES) {
    if (rule.pattern.test(text)) {
      return {
        brand: rule.brand,
        icon: rule.icon,
        label: rule.label,
        className: rule.className,
      };
    }
  }

  return {
    brand: "intel",
    icon: "酷",
    label: "酷睿",
    className: "--intel",
  };
}
