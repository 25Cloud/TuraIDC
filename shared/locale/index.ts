/**
 * 三端统一的语言（locale）基建：归一化、首屏自适应与持久化。
 *
 * ## 为什么要收敛到 shared
 *
 * 国际化落地时三端各写了一份：
 *   - 管理端 / 控制台用 tdesign 脚手架遗留的 key `tdesign-starter-locale`，
 *     两端同域部署时互相覆盖；
 *   - 官网用自己的 key `turaidc-locale`，是唯一维护 `<html lang>` 的一端；
 *   - 只有控制端做了浏览器语言探测，且逻辑弱到几乎不生效——`navigator.language`
 *     是 `zh-CN`（连字符）、i18n 是 `zh_CN`（下划线），而实现只做了
 *     `replace(/-/g,'_')` 后精确比对，`en-GB`、`zh-Hant-HK` 全部落空。
 *
 * 结果是三端行为不一致，且没有任何一端真的做到「首屏自动适应客户端语言」。
 *
 * ## 与后端的语义对齐
 *
 * 标签映射与 `App\Http\Middleware\SetLocale::TAGS` 逐条对齐：前端先选语言、
 * 再由请求拦截器带上 `X-Locale`，两边对同一个标签必须得出同一个 locale，
 * 否则用户看到的界面语言与后端返回的消息语言会错开。
 *
 * 优先级：显式持久化选择 > 浏览器偏好 > 默认简体中文。
 * 浏览器偏好只在「用户没有主动选过」时生效——主动选择必须压过自动探测，
 * 否则一个习惯了繁体的用户每次清缓存都会被系统语言拽回去。
 */

/** 内部 locale 代码。与各前端 `src/locales/lang/` 下的 json 文件名一一对应。 */
export const SUPPORTED_LOCALES = ['zh_CN', 'zh_TW', 'zh_HK', 'en_US'] as const;

export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

export const DEFAULT_LOCALE: SupportedLocale = 'zh_CN';

/**
 * locale -> 常见语言标签（小写、连字符）。
 *
 * 顺序有意义：`zh` 归简体中文、`zh-hant` 归香港，与后端 SetLocale 完全一致。
 * 「繁体用户更可能想要哪个地区」是产品决策，三端与后端必须同一份答案。
 */
const TAG_ALIASES: ReadonlyArray<readonly [SupportedLocale, readonly string[]]> = [
  ['zh_CN', ['zh-cn', 'zh-hans-cn', 'zh-hans', 'zh-sg', 'zh']],
  ['zh_TW', ['zh-tw', 'zh-hant-tw', 'zh-hant-hk-tw']],
  ['zh_HK', ['zh-hk', 'zh-mo', 'zh-hant-hk', 'zh-hant']],
  ['en_US', ['en-us', 'en']],
];

const ALIAS_INDEX = new Map<string, SupportedLocale>();
for (const [locale, aliases] of TAG_ALIASES) {
  for (const alias of aliases) {
    ALIAS_INDEX.set(alias, locale);
  }
}

/**
 * 把任意语言标签映射到内部 locale；识别不了返回 null。
 *
 * 兼容三种写法：i18n 的下划线（`zh_HK`）、HTTP 的连字符（`zh-HK`）、
 * 带 q 值的 Accept-Language 片段（`zh-TW;q=0.9`）。
 */
export function normalizeLocaleTag(tag: unknown): SupportedLocale | null {
  if (typeof tag !== 'string') {
    return null;
  }

  // 去 q 值与通配：'zh-TW;q=0.9' → 'zh-TW'，'*' → ''
  let value = tag.trim().toLowerCase().split(';')[0].trim();
  if (value === '' || value === '*') {
    return null;
  }

  // 下划线写法（前端 i18n）统一成连字符（HTTP 标签）
  value = value.replace(/_/g, '-');

  const exact = ALIAS_INDEX.get(value);
  if (exact) {
    return exact;
  }

  // 带子标签的标签逐级去掉末尾段再试：
  //   zh-hant-hk-mo → zh-hant-hk → zh-hant → zh
  //   en-gb         → en
  // 必须逐级而不是只退到主语言：只退主语言时 'zh-hant-hk-mo' 会命中 'zh'
  // 被判成简体，一个繁体用户会看到简体中文界面。
  const parts = value.split('-');
  while (parts.length > 1) {
    parts.pop();
    const fallback = ALIAS_INDEX.get(parts.join('-'));
    if (fallback) {
      return fallback;
    }
  }

  return null;
}

export function isSupportedLocale(value: unknown): value is SupportedLocale {
  return typeof value === 'string' && (SUPPORTED_LOCALES as readonly string[]).includes(value);
}

/**
 * 三端各自的存储 key。
 *
 * 不共用同一个 key：管理端与控制台部署在同一域名时（子路径部署），
 * 共用 localStorage key 会让两边互相覆盖登录用户的语言偏好。
 * 统一到 `turaidc-locale:` 前缀，去掉 tdesign 脚手架遗留的 key。
 */
export const LOCALE_STORAGE_PREFIX = 'turaidc-locale';

export function localeStorageKey(app: 'admin' | 'console' | 'www'): string {
  return `${LOCALE_STORAGE_PREFIX}:${app}`;
}

/** 读取用户显式选择的语言；没有选过返回 null（表示「按浏览器偏好走」）。 */
export function readStoredLocale(storageKey: string, storage?: Storage | null): SupportedLocale | null {
  const store = storage ?? safeLocalStorage();
  if (!store) {
    return null;
  }

  try {
    const raw = store.getItem(storageKey);
    return isSupportedLocale(raw) ? raw : null;
  } catch {
    return null;
  }
}

export function writeStoredLocale(storageKey: string, locale: SupportedLocale, storage?: Storage | null): void {
  const store = storage ?? safeLocalStorage();
  if (!store) {
    return;
  }

  try {
    store.setItem(storageKey, locale);
  } catch {
    // 隐私模式 / 配额满：语言本次会话仍然生效，只是下次不再记住
  }
}

export function clearStoredLocale(storageKey: string, storage?: Storage | null): void {
  const store = storage ?? safeLocalStorage();
  if (!store) {
    return;
  }

  try {
    store.removeItem(storageKey);
  } catch {
    // 同上
  }
}

/**
 * 首屏语言：显式选择 > 浏览器偏好 > 默认。
 *
 * `navigator.languages` 按偏好降序，逐个试而不是只看第一个——
 * 只看 `navigator.language` 会让 `['ja','zh-TW']` 这种偏好列表直接落到默认值。
 */
export function resolveInitialLocale(options: {
  storageKey: string;
  storage?: Storage | null;
  navigatorLike?: { language?: string; languages?: readonly string[] } | null;
}): SupportedLocale {
  const stored = readStoredLocale(options.storageKey, options.storage);
  if (stored) {
    return stored;
  }

  const nav = options.navigatorLike ?? safeNavigator();
  const preferences = [...(nav?.languages ?? []), nav?.language ?? ''];

  for (const preference of preferences) {
    const matched = normalizeLocaleTag(preference);
    if (matched) {
      return matched;
    }
  }

  return DEFAULT_LOCALE;
}

/**
 * 同步 `<html lang>`。
 *
 * 三端里只有官网在维护它，管理端与控制台的 index.html 永远写死 `zh-CN`，
 * 屏幕阅读器与搜索引擎据此判断页面语言，用户切到英文后读到的仍是中文声明。
 */
export function applyDocumentLang(locale: SupportedLocale, doc?: Document | null): void {
  const target = doc ?? safeDocument();
  const html = target?.documentElement;
  if (!html) {
    return;
  }

  // BCP 47 用连字符，zh_CN → zh-CN
  html.setAttribute('lang', locale.replace('_', '-'));
}

function safeLocalStorage(): Storage | null {
  if (typeof window === 'undefined') {
    return null;
  }

  try {
    return window.localStorage;
  } catch {
    // Safari 无痕模式访问 localStorage 会直接抛 SecurityError
    return null;
  }
}

function safeNavigator(): { language?: string; languages?: readonly string[] } | null {
  if (typeof navigator === 'undefined') {
    return null;
  }

  return navigator;
}

function safeDocument(): Document | null {
  if (typeof document === 'undefined') {
    return null;
  }

  return document;
}