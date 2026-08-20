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
