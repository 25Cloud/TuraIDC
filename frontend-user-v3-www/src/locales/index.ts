import elementEn from "element-plus/es/locale/lang/en";
import elementZhCn from "element-plus/es/locale/lang/zh-cn";
import elementZhTw from "element-plus/es/locale/lang/zh-tw";
import elementZhHk from "element-plus/es/locale/lang/zh-hk";
import { computed } from "vue";
import type { I18nOptions } from "vue-i18n";
import { createI18n } from "vue-i18n";

export const localeConfigKey = "turaidc-locale";

// 支持三语言：简体中文 / 繁體中文 / English。
// 语言名称取各词条的 lang 字段（语言名用自身语言显示，不做翻译）。
export const supportedLocales = ["zh_CN", "zh_TW", "zh_HK", "en_US"] as const;
export type SupportedLocale = (typeof supportedLocales)[number];

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

function getInitialLocale(): SupportedLocale {
  if (typeof window === "undefined") return "zh_CN";
  const stored = window.localStorage.getItem(localeConfigKey);
  if (stored && supportedLocales.includes(stored as SupportedLocale)) {
    return stored as SupportedLocale;
  }
  return "zh_CN";
}

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