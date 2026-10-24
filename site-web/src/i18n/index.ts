import en_US from "./en_US.json";
import zh_CN from "./zh_CN.json";
import zh_HK from "./zh_HK.json";
import zh_TW from "./zh_TW.json";

/**
 * 站点文案按语言分文件，键结构与系统三端语言包一致（按模块嵌套，
 * 不用扁平 key）。语言代码沿用 shared/locale 的 zh_CN / zh_TW / zh_HK / en_US，
 * 但路径用短写法，避免 URL 里出现下划线。
 */
export const SITE_URL = "https://www.turaidc.com";

export type LocaleCode = "zh_CN" | "zh_TW" | "zh_HK" | "en_US";
export type Messages = typeof zh_CN;

export interface LocaleEntry {
  code: LocaleCode;
  /** 该语言在站内的根路径，默认语言为 "/" */
  path: string;
  label: string;
  htmlLang: string;
  ogLocale: string;
  messages: Messages;
}

export const LOCALES: LocaleEntry[] = [
  {
    code: "zh_CN",
    path: "/",
    label: zh_CN.lang,
    htmlLang: zh_CN.htmlLang,
    ogLocale: "zh_CN",
    messages: zh_CN,
  },
  {
    code: "zh_TW",
    path: "/zh-tw/",
    label: zh_TW.lang,
    htmlLang: zh_TW.htmlLang,
    ogLocale: "zh_TW",
    messages: zh_TW as Messages,
  },
  {
    code: "zh_HK",
    path: "/zh-hk/",
    label: zh_HK.lang,
    htmlLang: zh_HK.htmlLang,
    ogLocale: "zh_HK",
    messages: zh_HK as Messages,
  },
  {
    code: "en_US",
    path: "/en/",
    label: en_US.lang,
    htmlLang: en_US.htmlLang,
    ogLocale: "en_US",
    messages: en_US as Messages,
  },
];

export const DEFAULT_LOCALE: LocaleCode = "zh_CN";

export function getLocale(code: string): LocaleEntry {
  return (
    LOCALES.find((entry) => entry.code === code) ??
    LOCALES.find((entry) => entry.code === DEFAULT_LOCALE)!
  );
}

export function absoluteUrl(entry: LocaleEntry): string {
  return `${SITE_URL}${entry.path}`;
}
