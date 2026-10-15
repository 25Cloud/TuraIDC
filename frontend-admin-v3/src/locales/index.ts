import type { DropdownOption } from 'tdesign-vue-next';
import zh_CN from 'tdesign-vue-next/es/locale/zh_CN';
import zh_TW from 'tdesign-vue-next/es/locale/zh_TW';
import en_US from 'tdesign-vue-next/es/locale/en_US';
import { computed } from 'vue';
import type { I18nOptions } from 'vue-i18n';
import { createI18n } from 'vue-i18n';

export const localeConfigKey = 'tdesign-starter-locale';

// 当前后台只开放简体中文，避免浏览器偏好把用户界面切到英文。
export const supportedLocales = ['zh_CN', 'zh_TW', 'zh_HK', 'en_US'] as const;
export type SupportedLocale = (typeof supportedLocales)[number];

// 路由历史数据可能仍带英文标题；运行时只启用 zh_CN，类型层兼容旧字段避免批量改动路由表。
export type LocalizedTitle = Partial<Record<SupportedLocale, string>>;

// TDesign 没有独立的香港语言包（只有 zh_TW），香港版复用它。
const tdesignLocaleMap = { zh_CN, zh_TW, zh_HK: zh_TW, en_US };

const langModules = import.meta.glob<{ default: Record<string, unknown> }>('./lang/*.json', { eager: true });

const langCode: SupportedLocale[] = [];
const messages: I18nOptions['messages'] = {};
const langList: DropdownOption[] = [];

Object.entries(langModules).forEach(([path, module]) => {
  const code = path.match(/\.\/lang\/([^.]+)\.json$/)?.[1] as SupportedLocale | undefined;
  if (!code || !supportedLocales.includes(code)) return;
  langCode.push(code);
  messages[code] = { ...module.default, componentsLocale: tdesignLocaleMap[code] };
  langList.push({ content: module.default.lang as string, value: code });
});

export { langCode };

// 获取初始语言：仅使用已支持的简体中文配置。
const getInitialLocale = (): SupportedLocale => {
  const stored = localStorage.getItem(localeConfigKey);
  if (stored && supportedLocales.includes(stored as SupportedLocale)) {
    return stored as SupportedLocale;
  }
  return 'zh_CN';
};

const initialLocale = getInitialLocale();

export const i18n = createI18n({
  legacy: false,
  locale: initialLocale,
  fallbackLocale: 'zh_CN',
  messages,
  globalInjection: true,
});

export const languageList = computed(() => langList);
export const { t } = i18n.global;
export default i18n;
