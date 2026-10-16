import type { DropdownOption } from 'tdesign-vue-next';
import zh_CN from 'tdesign-vue-next/es/locale/zh_CN';
import zh_TW from 'tdesign-vue-next/es/locale/zh_TW';
import en_US from 'tdesign-vue-next/es/locale/en_US';
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

// 存储 key 从 tdesign 脚手架遗留值换成按应用区分的 key：
// 管理端与控制台同域部署（子路径）时共用 localStorage 会互相覆盖语言偏好。
export const localeConfigKey = localeStorageKey('admin');

export const supportedLocales = SUPPORTED_LOCALES;
export type { SupportedLocale };

// 路由历史数据可能仍带英文标题；类型层兼容旧字段避免批量改动路由表。
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
// 归一化与回退规则在 shared/locale 内，与后端 SetLocale 中间件同一套语义，
// 避免界面语言与后端业务消息语言错开。
const initialLocale = resolveInitialLocale({ storageKey: localeConfigKey });

// index.html 里写死的是 zh-CN，切换语言后不更新会让屏幕阅读器与搜索引擎
// 继续按中文理解页面。
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
