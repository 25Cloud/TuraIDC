import type { DropdownOption } from 'tdesign-vue-next';
import en_US from 'tdesign-vue-next/es/locale/en_US';
import zh_CN from 'tdesign-vue-next/es/locale/zh_CN';
import zh_TW from 'tdesign-vue-next/es/locale/zh_TW';
import { computed } from 'vue';
import type { I18nOptions } from 'vue-i18n';
import { createI18n } from 'vue-i18n';
import {
  SUPPORTED_LOCALES,
  applyDocumentLang,
  localeStorageKey,
  resolveInitialLocale,
} from '@turaidc/shared/locale';
import type { SupportedLocale } from '@turaidc/shared/locale';

// 存储 key 与管理端按应用区分：两者同域部署（子路径）时共用 key 会互相覆盖语言偏好。
export const localeConfigKey = localeStorageKey('console');

// 支持的语言列表，添加新语言时只需在此处添加
export const supportedLocales = SUPPORTED_LOCALES;
export type { SupportedLocale };

// 多语言标题类型，用于路由 meta.title 等场景
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

// 首屏语言：显式选择 > 浏览器偏好 > 默认简体中文。
// 原来的实现是 navigator.languages[0].replace(/-/g,'_') 后精确比对，
// en-GB、zh-Hant-HK 这类常见标签全部落空，等于没有生效；
// 且只看第一项，偏好列表里前面是不支持语言时不会往后找。
// 归一化规则收敛在 shared/locale，与后端 SetLocale 中间件同一套语义。
const initialLocale = resolveInitialLocale({ storageKey: localeConfigKey });

// index.html 写死 zh-CN，切换语言后不更新会让无障碍与 SEO 仍按中文理解页面
applyDocumentLang(initialLocale);

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
