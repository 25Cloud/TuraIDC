import { keys } from 'lodash-es';
import { defineStore } from 'pinia';
import { Color } from 'tvision-color';

import type { TColorSeries } from '@/config/color';
import { DARK_CHART_COLORS, LIGHT_CHART_COLORS } from '@/config/color';
import STYLE_CONFIG from '@/config/style';
import { store } from '@/store';
import type { ModeType } from '@/types/interface';
import { generateColorMap, insertThemeStylesheet } from '@/utils/color';

const state: Record<string, any> = {
  ...STYLE_CONFIG,
  showSettingPanel: false,
  isMobileSidebarVisible: false,
  colorList: {} as TColorSeries,
  chartColors: LIGHT_CHART_COLORS,
};

export type TState = typeof state;
export type TStateKey = keyof typeof state;

export const useSettingStore = defineStore('setting', {
  state: () => state,
  getters: {
    showSidebar: (state) => state.layout !== 'top',
    showSidebarLogo: (state) => state.layout === 'side',
    showHeaderLogo: (state) => state.layout !== 'side' && !state.hideHeaderLogo,
    displayMode: (state): ModeType => {
      if (state.mode === 'auto') {
        const media = window.matchMedia('(prefers-color-scheme:dark)');
        if (media.matches) {
          return 'dark';
        }
        return 'light';
      }
      return state.mode as ModeType;
    },
    displaySideMode(state: TState): ModeType {
      // 侧边栏跟随主主题模式：浅色主题 → 浅色侧边栏；深色主题 → 深色侧边栏。
      // 若 sideMode 被显式配置为深色，则侧边栏独立使用深色（保留独立配色能力）。
      const explicitSideMode = state.sideMode as ModeType;
      if (explicitSideMode === 'dark') {
        return 'dark';
      }
      // displayMode 是 getter 而非 state 字段，必须通过 this 访问。
      return this.displayMode;
    },
  },
  actions: {
    async changeMode(mode: ModeType | 'auto') {
      let theme = mode;

      if (mode === 'auto') {
        theme = this.getMediaColor();
        // auto 模式：监听系统主题变化，切回其他模式时移除监听。
        watchSystemTheme();
      } else {
        unwatchSystemTheme();
      }

      const isDarkMode = theme === 'dark';

      document.documentElement.setAttribute('theme-mode', isDarkMode ? 'dark' : '');

      this.chartColors = isDarkMode ? DARK_CHART_COLORS : LIGHT_CHART_COLORS;
    },
    async changeSideMode(mode: ModeType) {
      const isDarkMode = mode === 'dark';

      document.documentElement.setAttribute('side-mode', isDarkMode ? 'dark' : '');
    },
    getMediaColor() {
      const media = window.matchMedia('(prefers-color-scheme:dark)');

      if (media.matches) {
        return 'dark';
      }
      return 'light';
    },
    changeBrandTheme(brandTheme: string) {
      const mode = this.displayMode;
      // 以主题色加显示模式作为键
      const colorKey = `${brandTheme}[${mode}]`;
      let colorMap = this.colorList[colorKey];
      // 如果不存在色阶，就需要计算
      if (colorMap === undefined) {
        const [{ colors: newPalette, primary: brandColorIndex }] = Color.getColorGradations({
          colors: [brandTheme],
          step: 10,
          remainInput: false, // 是否保留输入 不保留会矫正不合适的主题色
        });
        colorMap = generateColorMap(brandTheme, newPalette, mode, brandColorIndex);
        this.colorList[colorKey] = colorMap;
      }
      // TODO 需要解决不停切换时有反复插入 style 的问题
      insertThemeStylesheet(brandTheme, colorMap, mode);
      document.documentElement.setAttribute('theme-color', brandTheme);
    },
    updateConfig(payload: Partial<TState>) {
      for (const key in payload) {
        if (payload[key as TStateKey] !== undefined) {
          this[key as TStateKey] = payload[key as TStateKey];
        }
        if (key === 'mode') {
          this.changeMode(payload[key] as ModeType);
        }
        if (key === 'sideMode') {
          this.changeSideMode(payload[key] as ModeType);
        }
        if (key === 'brandTheme') {
          this.changeBrandTheme(payload[key]);
        }
      }
    },
  },
  persist: {
    pick: [...keys(STYLE_CONFIG), 'colorList', 'chartColors'],
  },
});

export function getSettingStore() {
  return useSettingStore(store);
}

// auto 模式系统主题监听：模块级变量，不参与持久化。
let systemThemeMedia: MediaQueryList | null = null;
let systemThemeHandler: ((event: MediaQueryListEvent) => void) | null = null;

function watchSystemTheme(): void {
  if (typeof window === 'undefined' || !window.matchMedia) {
    return;
  }
  if (systemThemeMedia && systemThemeHandler) {
    return; // 已监听，避免重复挂载
  }
  systemThemeMedia = window.matchMedia('(prefers-color-scheme:dark)');
  systemThemeHandler = () => {
    // 仅当仍处于 auto 模式时跟随系统主题变化。
    if (getSettingStore().mode === 'auto') {
      getSettingStore().changeMode('auto');
    }
  };
  if (typeof systemThemeMedia.addEventListener === 'function') {
    systemThemeMedia.addEventListener('change', systemThemeHandler);
  } else {
    // 旧浏览器兼容
    systemThemeMedia.addListener(systemThemeHandler);
  }
}

function unwatchSystemTheme(): void {
  if (systemThemeMedia && systemThemeHandler) {
    if (typeof systemThemeMedia.removeEventListener === 'function') {
      systemThemeMedia.removeEventListener('change', systemThemeHandler);
    } else {
      systemThemeMedia.removeListener(systemThemeHandler);
    }
    systemThemeMedia = null;
    systemThemeHandler = null;
  }
}
