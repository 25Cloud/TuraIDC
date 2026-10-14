/**
 * 品牌主题变量（官网侧兜底）
 *
 * 首屏由后端 SeoRenderService 内联 <style> 注入完整变量，不会闪烁。
 * 但用户直接落在 SPA 路由（如 /products）时服务端不渲染，这段要把变量补上，
 * 否则页面会停在 sass 编译期的默认蓝色。
 *
 * 这里只设置主色，hover / focus / disabled 等派生色交给 color-mix 自动跟随，
 * 而不是在前端再实现一遍与后端相同的混色算法——同一套算法存在两处必然漂移。
 * 代价是极老浏览器（无 color-mix）只会拿到主色，派生色回落到默认蓝，属可接受降级。
 */

const BRAND_STYLE_ID = "site-brand-tokens";

/**
 * 规范化品牌色：只接受严格十六进制，三位展开成六位并统一小写。
 *
 * 与后端 SiteConfigPayload::normalizeColor 同口径——该值会被写进 <style>，
 * 放行宽松格式等于放开样式注入。
 */
export function normalizeHexColor(value?: string): string {
  const raw = (value || "").trim();

  if (/^#([0-9a-fA-F]{3})$/.test(raw)) {
    const hex = raw.slice(1);
    return `#${hex[0]}${hex[0]}${hex[1]}${hex[1]}${hex[2]}${hex[2]}`.toLowerCase();
  }

  if (/^#([0-9a-fA-F]{6})$/.test(raw)) {
    return raw.toLowerCase();
  }

  return "";
}

export function applyBrandTokens(color?: string, radius?: string): void {
  const hex = normalizeHexColor(color);
  const parsedRadius = Number.parseInt(radius ?? "", 10);
  const radiusValue =
    Number.isFinite(parsedRadius) && parsedRadius >= 0 && parsedRadius <= 24
      ? parsedRadius
      : null;

  if (!hex && radiusValue === null) return;

  const declarations: string[] = [];

  if (hex) {
    declarations.push(`--el-color-primary:${hex};`);
    declarations.push(`--el-color-primary-light-3:color-mix(in srgb, ${hex} 70%, #fff);`);
    declarations.push(`--el-color-primary-light-5:color-mix(in srgb, ${hex} 50%, #fff);`);
    declarations.push(`--el-color-primary-light-7:color-mix(in srgb, ${hex} 30%, #fff);`);
    declarations.push(`--el-color-primary-light-8:color-mix(in srgb, ${hex} 20%, #fff);`);
    declarations.push(`--el-color-primary-light-9:color-mix(in srgb, ${hex} 10%, #fff);`);
    declarations.push(`--el-color-primary-dark-2:color-mix(in srgb, ${hex} 80%, #000);`);
  }

  if (radiusValue !== null) {
    const small = Math.round(radiusValue / 2);
    declarations.push(`--el-border-radius-base:${radiusValue}px;`);
    declarations.push(`--el-border-radius-small:${small}px;`);
  }

  let style = document.getElementById(BRAND_STYLE_ID) as HTMLStyleElement | null;
  if (!style) {
    style = document.createElement("style");
    style.id = BRAND_STYLE_ID;
    document.head.appendChild(style);
  }

  style.textContent = `:root{${declarations.join("")}}`;
}