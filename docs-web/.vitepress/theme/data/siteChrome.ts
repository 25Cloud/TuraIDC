import zhCN from "./siteChrome.zh_CN.json";
import enUS from "./siteChrome.en_US.json";
import zhTW from "./siteChrome.zh_TW.json";
import zhHK from "./siteChrome.zh_HK.json";

/**
 * 站点外壳文案（首页门牌与部署方式选择器）。
 *
 * 四种语言的文案各存一份 JSON：简体是唯一真源，繁体两份由
 * `node backend/scripts/i18n/docs-i18n.mjs trad <locale>` 从简体生成，不要手工改。
 * 英文是人工翻译，链接已经带上 `/en` 前缀。
 */
export type SiteChromeLink = [text: string, href: string];

export interface SiteChromeGroup {
  title: string;
  description: string;
  links: SiteChromeLink[];
}

export interface SiteChromeAudience {
  id: string;
  label: string;
  title: string;
  description: string;
  groups: SiteChromeGroup[];
}

export interface SiteChromeAction {
  text: string;
  href: string;
}

export interface SiteChromeCopy {
  lang: string;
  prefix: string;
  brand: string;
  brandAria: string;
  navAria: string;
  nav: SiteChromeAction[];
  hero: {
    title: string;
    titleTail: string;
    description: string;
    primary: SiteChromeAction;
    secondary: SiteChromeAction;
  };
  audiences: SiteChromeAudience[];
  footer: { copyright: string; repo: string };
  deploySelector: {
    kicker: string;
    title: string;
    description: string;
    link: string;
    action: string;
  }[];
}

const COPIES: Record<string, SiteChromeCopy> = {
  "zh-CN": zhCN as SiteChromeCopy,
  en: enUS as SiteChromeCopy,
  "zh-TW": zhTW as SiteChromeCopy,
  "zh-HK": zhHK as SiteChromeCopy,
};

export function siteChromeFor(lang: string | undefined): SiteChromeCopy {
  return COPIES[lang ?? ""] ?? COPIES.en;
}
