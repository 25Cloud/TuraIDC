import { applyDocumentLang, DEFAULT_LOCALE, isSupportedLocale, writeStoredLocale } from '@turaidc/shared/locale';
import type { GlobalConfigProvider } from 'tdesign-vue-next';
import { computed } from 'vue';

import type { SupportedLocale } from '@/locales/index';
import { i18n, localeConfigKey } from '@/locales/index';

export function useLocale() {
  const locale = computed({
    get: () => i18n.global.locale.value,
    set: (val: string) => {
      i18n.global.locale.value = val;
    },
  });
  const changeLocale = (lang: string) => {
    const validLang: SupportedLocale = isSupportedLocale(lang) ? lang : DEFAULT_LOCALE;
    locale.value = validLang;
    // 持久化走 shared/locale 的存储封装，与首屏读取同一套实现：
    // 之前这里用 vueuse 的 useLocalStorage，与官网的裸 localStorage 写法不一致，
    // 隐私模式下也不会统一地静默失败。
    writeStoredLocale(localeConfigKey, validLang);
    applyDocumentLang(validLang);
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
