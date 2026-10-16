import elementEn from "element-plus/es/locale/lang/en";
import elementZhCn from "element-plus/es/locale/lang/zh-cn";
import elementZhTw from "element-plus/es/locale/lang/zh-tw";
import elementZhHk from "element-plus/es/locale/lang/zh-hk";
import { computed } from "vue";
import type { I18nOptions } from "vue-i18n";
import { createI18n } from "vue-i18n";
import {
  SUPPORTED_LOCALES,
  applyDocumentLang,
  localeStorageKey,
  resolveInitialLocale,
} from "@turaidc/shared/locale";
import type { SupportedLocale } from "@turaidc/shared/locale";

// 存储 key 与另两端统一到 turaidc-locale 前缀，并按应用区分
// （管理端 / 控制台同域部署时共用 key 会互相覆盖语言偏好）。
export const localeConfigKey = localeStorageKey("www");

// 支持四语言：简体中文 / 繁體中文（台灣）/ 繁體中文（香港）/ English。
// 语言名称取各词条的 lang 字段（语言名用自身语言显示，不做翻译）。
export const supportedLocales = SUPPORTED_LOCALES;
export type { SupportedLocale };

const elementLocaleMap = {
  zh_CN: elementZhCn,
  zh_TW: elementZhTw,
  zh_HK: elementZhHk,
  en_US: elementEn,
};

const langModules = import.meta.glob<{ default: Record<string, unknown> }>(
  "./lang/*.json",
  { eager: true },
);

const langCode: SupportedLocale[] = [];
const messages: I18nOptions["messages"] = {};
interface LanguageOption { content: string; value: SupportedLocale }

const langList: LanguageOption[] = [];

Object.entries(langModules).forEach(([file, module]) => {
  const code = file.match(/\.\/lang\/([^.]+)\.json$/)?.[1] as SupportedLocale | undefined;
  if (!code || !supportedLocales.includes(code)) return;
  langCode.push(code);
  messages[code] = { ...module.default, componentsLocale: elementLocaleMap[code] };
  langList.push({ content: String(module.default.lang ?? code), value: code });
});

export { langCode };

// 首屏语言：显式选择 > 浏览器偏好 > 默认简体中文。
// 此前完全不看浏览器偏好，海外访客首屏一律是中文。
// shared/locale 内部已处理 SSR（无 window 时回落默认）与隐私模式的存储异常，
// 归一化规则与后端 SetLocale 中间件一致。
function getInitialLocale(): SupportedLocale {
  return resolveInitialLocale({ storageKey: localeConfigKey });
}

// 与另两端对齐：首帧就把 <html lang> 设成实际语言。
// 原先只有 useLocale() 被调用时才设置，挂在布局里的组件晚于首帧执行。
applyDocumentLang(getInitialLocale());

export const i18n = createI18n({
  legacy: false,
  locale: getInitialLocale(),
  fallbackLocale: "zh_CN",
  messages,
  globalInjection: true,
});

export const languageList = computed(() => langList);
export const { t } = i18n.global;
export default i18n;