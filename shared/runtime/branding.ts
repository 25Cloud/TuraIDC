export function deriveInitials(name = "") {
  const trimmed = String(name || "").trim();

  if (!trimmed) {
    return "IF";
  }

  const latinParts = trimmed
    .replace(/[^A-Za-z0-9\s]/g, " ")
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  if (latinParts.length >= 2) {
    return `${latinParts[0][0]}${latinParts[1][0]}`.toUpperCase();
  }

  if (latinParts.length === 1) {
    return latinParts[0].slice(0, 2).toUpperCase();
  }

  return trimmed.slice(0, 2).toUpperCase();
}

/**
 * 站点配置缓存的存储键与字段白名单。
 *
 * index.html 里的注入脚本在 Vue 挂载、站点配置接口返回之前就要把站点名 /
 * favicon 应用到首帧，那一刻只能读上一次访问留下的缓存；本模块负责写入，
 * 两侧共用同一份键名与字段，避免漂移。
 *
 * 注意：`frontend-user-v3-www/index.html` 的内联脚本无法 import 本模块（它在打包产物之外），
 * 只能硬编码同样的字符串；`shared/tests/branding-splash.test.mjs` 会校验两者没有漂移。
 */
export const SPLASH_BRANDING_STORAGE_KEY = "turaidc:site-config";

/** 缓存的站点名长度上限：用于页面标题等单行排版，过长会撑破布局。 */
export const SPLASH_BRANDING_MAX_LENGTH = 40;

/** 缓存的资源地址长度上限：站点名是短文本，URL 可能带长路径与签名参数。 */
export const SPLASH_BRANDING_URL_MAX_LENGTH = 2048;

/** 允许写入缓存的站点配置字段（首帧替换需要的最小集合）。 */
export const SPLASH_BRANDING_FIELDS = [
  "site_name",
  "browser_title",
  "site_logo",
  "site_favicon",
] as const;

/** 资源地址字段：按 URL 上限截断，套用站点名的短上限会把链接截坏。 */
const SPLASH_BRANDING_URL_FIELDS = new Set<string>(["site_logo", "site_favicon"]);

export type SplashBrandingField = (typeof SPLASH_BRANDING_FIELDS)[number];

export type SplashBrandingConfig = Partial<Record<SplashBrandingField, string>>;

function resolveStorage(storage?: Storage | null): Storage | null {
  if (storage) {
    return storage;
  }

  // 浏览器禁止存储访问时（"阻止所有 Cookie"、无 allow-same-origin 的 iframe 等），
  // 读取 window.localStorage 这个属性本身就会抛 SecurityError，而不是返回 null。
  // 必须在这里兜住：否则异常会穿过 hydrateSiteConfig 冒泡到 fetchSiteConfig 的
  // .catch() 分支，让一次纯装饰性的缓存写入把整个站点配置请求判成失败。
  try {
    return typeof window !== "undefined" && window.localStorage
      ? window.localStorage
      : null;
  } catch {
    return null;
  }
}

function normalizeSplashBrandingConfig(
  config: SplashBrandingConfig | null | undefined,
): SplashBrandingConfig {
  const normalized: SplashBrandingConfig = {};

  for (const field of SPLASH_BRANDING_FIELDS) {
    const raw = config?.[field];
    if (raw === undefined || raw === null) {
      continue;
    }

    const maxLength = SPLASH_BRANDING_URL_FIELDS.has(field)
      ? SPLASH_BRANDING_URL_MAX_LENGTH
      : SPLASH_BRANDING_MAX_LENGTH;
    const value = String(raw).trim().slice(0, maxLength);
    if (value !== "") {
      normalized[field] = value;
    }
  }

  return normalized;
}

export function persistSplashBranding(
  config: SplashBrandingConfig | null | undefined,
  storage?: Storage | null,
) {
  const target = resolveStorage(storage);
  if (!target) {
    return;
  }

  const normalized = normalizeSplashBrandingConfig(config);

  try {
    if (Object.keys(normalized).length === 0) {
      // 站点名被清空时一并清掉缓存，避免页面一直停在旧品牌上。
      target.removeItem(SPLASH_BRANDING_STORAGE_KEY);
      return;
    }

    target.setItem(SPLASH_BRANDING_STORAGE_KEY, JSON.stringify(normalized));
  } catch {
    // 隐私模式 / storage 被禁用 / 配额写满：保持默认品牌即可，不影响主流程。
  }
}

export function readSplashBranding(storage?: Storage | null): SplashBrandingConfig {
  const target = resolveStorage(storage);
  if (!target) {
    return {};
  }

  try {
    const raw = target.getItem(SPLASH_BRANDING_STORAGE_KEY);
    if (!raw) {
      return {};
    }

    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
      return {};
    }

    return normalizeSplashBrandingConfig(parsed as SplashBrandingConfig);
  } catch {
    return {};
  }
}

export function updateFavicon(href: string, fallbackHref: string) {
  if (typeof document === "undefined") {
    return;
  }

  const resolvedHref = href || fallbackHref;
  const resolvedType = resolvedHref.endsWith(".svg")
    ? "image/svg+xml"
    : "image/png";
  const icons = document.querySelectorAll("link[rel*='icon']");

  if (icons.length === 0) {
    const icon = document.createElement("link");
    icon.rel = "icon";
    icon.href = resolvedHref;
    icon.type = resolvedType;
    document.head.appendChild(icon);
    return;
  }

  // 同时更新所有 icon 链接（index.html 常同时声明 16x16 / 32x32），
  // 避免浏览器仍命中旧的静态 favicon。
  icons.forEach((node) => {
    const link = node as HTMLLinkElement;
    link.href = resolvedHref;
    link.type = resolvedType;
  });
}

export function applyDocumentTitle(
  pageTitle: string,
  baseTitle: string,
  faviconHref: string,
  fallbackFavicon: string,
) {
  if (typeof document === "undefined") {
    return;
  }

  document.title = pageTitle ? `${pageTitle} - ${baseTitle}` : baseTitle;
  updateFavicon(faviconHref, fallbackFavicon);
}

export function syncDocumentTitle(
  baseTitle: string,
  previousBaseTitle: string,
  defaultSiteName: string,
) {
  if (typeof document === "undefined") {
    return;
  }

  const nextBaseTitle = String(baseTitle || "").trim();
  if (!nextBaseTitle) {
    return;
  }

  const currentTitle = String(document.title || "").trim();
  const previousBase = String(previousBaseTitle || "").trim();
  const normalizedDefault = String(defaultSiteName || "").trim();

  // 空标题 / 直接就是旧基名 / 默认品牌名 → 整体替换
  if (
    !currentTitle ||
    currentTitle === previousBase ||
    (normalizedDefault !== "" && currentTitle === normalizedDefault)
  ) {
    document.title = nextBaseTitle;
    return;
  }

  // 硬编码 SEO 标题以默认品牌开头 → 视为未规范化的基础标题，整体替换为站点名
  if (
    normalizedDefault !== "" &&
    currentTitle.startsWith(`${normalizedDefault} - `)
  ) {
    document.title = nextBaseTitle;
    return;
  }

  if (previousBase && currentTitle.endsWith(` - ${previousBase}`)) {
    document.title = `${currentTitle.slice(0, -` - ${previousBase}`.length)} - ${nextBaseTitle}`;
    return;
  }

  const separatorIndex = currentTitle.indexOf(" - ");
  if (separatorIndex > 0) {
    document.title = `${currentTitle.slice(0, separatorIndex)} - ${nextBaseTitle}`;
  }
}
