import { inject, provide } from "vue";
import type { InjectionKey } from "vue";

import type { LocaleEntry, Messages } from "./index";

/**
 * 文案通过 provide/inject 下发，组件不直接 import JSON：
 * 组件不关心当前语言，页面壳只负责注入一次。
 */
export interface SiteContext {
  messages: Messages;
  locale: LocaleEntry;
  locales: LocaleEntry[];
}

const SITE_CONTEXT: InjectionKey<SiteContext> = Symbol("turaidc-site-context");

export function provideSiteContext(context: SiteContext): void {
  provide(SITE_CONTEXT, context);
}

export function useSite(): SiteContext {
  const context = inject(SITE_CONTEXT);

  if (!context) {
    throw new Error("useSite() 必须在 SiteShell 内使用");
  }

  return context;
}
