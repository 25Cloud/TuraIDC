import { computed } from "vue";

import type { SupportedLocale } from "@/locales/index";
import { i18n, localeConfigKey, supportedLocales } from "@/locales/index";

/** locale -> html lang 属性值 */
const HTML_LANG: Record<SupportedLocale, string> = {
  zh_CN: "zh-CN",
  zh_TW: "zh-TW",
  zh_HK: "zh-HK",
  en_US: "en",
};

function applyHtmlLang(locale: SupportedLocale) {
  if (typeof document === "undefined") return;
  document.documentElement.setAttribute("lang", HTML_LANG[locale]);
}

export function useLocale() {
  const locale = computed({
    get: () => i18n.global.locale.value,
    set: (val: string) => {
      i18n.global.locale.value = val;
    },
  });

  const changeLocale = (lang: string) => {
    const valid = supportedLocales.includes(lang as SupportedLocale)
      ? (lang as SupportedLocale)
      : "zh_CN";
    locale.value = valid;
    if (typeof window !== "undefined") {
      window.localStorage.setItem(localeConfigKey, valid);
    }
    applyHtmlLang(valid);
  };

  // 供 Element Plus 的 config-provider 使用
  const getComponentsLocale = computed(() => {
    const message = i18n.global.getLocaleMessage(locale.value) as unknown as Record<
      string,
      unknown
    >;
    return message.componentsLocale;
  });

  applyHtmlLang(locale.value as SupportedLocale);

  return { changeLocale, getComponentsLocale, locale };
}