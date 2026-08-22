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
    // vue-i18n 11 的 getLocaleMessage 返回类型不含业务自定义字段，做类型收窄
    const message = i18n.global.getLocaleMessage(locale.value) as unknown as Record<
      string,
      GlobalConfigProvider | undefined
    >;
    return message.componentsLocale as GlobalConfigProvider;
  });

  return {
    changeLocale,
    getComponentsLocale,
    locale,
  };
}
