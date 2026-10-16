import {
  DEFAULT_LOCALE,
  applyDocumentLang,
  isSupportedLocale,
  writeStoredLocale,
} from '@turaidc/shared/locale';
import type { GlobalConfigProvider } from 'tdesign-vue-next';
import { computed } from 'vue';

import type { SupportedLocale } from '@/locales/index';
import { i18n, localeConfigKey } from '@/locales/index';
import { useNotificationStore } from '@/store/modules/notification';

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
    // 与首屏读取共用 shared/locale 的存储封装，隐私模式下统一静默失败
    writeStoredLocale(localeConfigKey, validLang);
    applyDocumentLang(validLang);
    // 刷新持久化的翻译数据
    useNotificationStore().refreshMsgData();
  };

  const getComponentsLocale = computed(() => {
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
