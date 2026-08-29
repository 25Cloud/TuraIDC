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
 * 启动屏品牌名的缓存键。
 *
 * 启动屏是纯静态 HTML，在 Vue 挂载、站点配置拉回来之前就已经渲染，
 * 那一刻只能显示构建期写死的默认品牌。这里把上一次拿到的站点名缓存下来，
 * 供 index.html 里的内联脚本在首帧前覆盖，自建站就不会一直显示别人的品牌名。
 *
 * 注意：`frontend-user-v3-www/index.html` 的内联脚本无法 import 本模块（它在打包产物之外），
 * 只能硬编码同一个字符串；`shared/tests/branding-splash.test.mjs` 会校验两者没有漂移。
 */
export const SPLASH_BRANDING_STORAGE_KEY = "turaidc:splash-branding";

/** 缓存的品牌名长度上限：启动屏是单行居中排版，过长会撑破布局。 */
export const SPLASH_BRANDING_MAX_LENGTH = 40;

function resolveStorage(storage?: Storage | null): Storage | null {
  if (storage) {
    return storage;
  }

  return typeof window !== "undefined" && window.localStorage
    ? window.localStorage
    : null;
}

export function persistSplashBranding(
  siteName: string,
  storage?: Storage | null,
) {
  const target = resolveStorage(storage);
  if (!target) {
    return;
  }

  const normalized = String(siteName || "")
    .trim()
    .slice(0, SPLASH_BRANDING_MAX_LENGTH);

  try {
    if (normalized === "") {
      // 站点名被清空时一并清掉缓存，否则启动屏会一直停在旧品牌上。
      target.removeItem(SPLASH_BRANDING_STORAGE_KEY);
      return;
    }

    target.setItem(SPLASH_BRANDING_STORAGE_KEY, normalized);
  } catch {
    // 隐私模式 / storage 被禁用 / 配额写满：保持默认品牌即可，不影响主流程。
  }
}

export function readSplashBranding(storage?: Storage | null): string {
  const target = resolveStorage(storage);
  if (!target) {
    return "";
  }

  try {
    return String(target.getItem(SPLASH_BRANDING_STORAGE_KEY) || "")
      .trim()
      .slice(0, SPLASH_BRANDING_MAX_LENGTH);
  } catch {
    return "";
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

  // 硬编码 SEO 标题以默认品牌开头（如 "图拉云 - 稳定、安全…"）→ 视为未规范化的基础标题，整体替换为站点名
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
