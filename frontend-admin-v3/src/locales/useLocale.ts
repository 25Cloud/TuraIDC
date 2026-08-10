import { useLocalStorage } from '@vueuse/core';
import type { GlobalConfigProvider } from 'tdesign-vue-next';
import { computed } from 'vue';

import type { SupportedLocale } from '@/locales/index';
import { i18n, localeConfigKey, supportedLocales } from '@/locales/index';

export function useLocale() {
  const locale = computed({
    get: () => i18n.global.locale.value,
    set: (val: string) => {
      i18n.global.locale.value = val;
    },
  });
  const storedLocale = useLocalStorage<SupportedLocale>(localeConfigKey, 'zh_CN');

  const changeLocale = (lang: string) => {
    const validLang = supportedLocales.includes(lang as SupportedLocale) ? (lang as SupportedLocale) : 'zh_CN';
    locale.value = validLang;
    storedLocale.value = validLang;
  };

  const getComponentsLocale = computed(() => {
    return i18n.global.getLocaleMessage(locale.value).componentsLocale as GlobalConfigProvider;
  });

  return {
    changeLocale,
    getComponentsLocale,
    locale,
  };
}
