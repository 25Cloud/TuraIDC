import {
  DEFAULT_LOCALE,
  applyDocumentLang,
  isSupportedLocale,
  writeStoredLocale,
} from "@turaidc/shared/locale";
import { computed } from "vue";

import type { SupportedLocale } from "@/locales/index";
import { i18n, localeConfigKey } from "@/locales/index";

export function useLocale() {
  const locale = computed({
    get: () => i18n.global.locale.value,
    set: (val: string) => {
      i18n.global.locale.value = val;
    },
  });

  const changeLocale = (lang: string) => {
    const valid: SupportedLocale = isSupportedLocale(lang) ? lang : DEFAULT_LOCALE;
    locale.value = valid;
    // 存储与 <html lang> 都收敛到 shared/locale，另两端是同一套实现
    writeStoredLocale(localeConfigKey, valid);
    applyDocumentLang(valid);
  };

  // 供 Element Plus 的 config-provider 使用
  const getComponentsLocale = computed(() => {
    const message = i18n.global.getLocaleMessage(locale.value) as unknown as Record<
      string,
      unknown
    >;
    return message.componentsLocale;
  });

  applyDocumentLang(locale.value as SupportedLocale);

  return { changeLocale, getComponentsLocale, locale };
}