import { Color } from 'tvision-color';

import type { TColorToken } from '@/config/color';
import type { ModeType } from '@/types/interface';

/**
 * 依据主题类型获取颜色
 *
 * @export
 * @returns {Array<string>} themeColorList
 */
export function getColorFromTheme(): Array<string> {
  const theme = getComputedStyle(document.documentElement).getPropertyValue('--td-brand-color').trim();
  const themeColorList = Color.getRandomPalette({
    color: theme,
    colorGamut: 'bright',
    number: 8,
  });

  return themeColorList;
}

/** 图表颜色 */
export function getChartListColor(): Array<string> {
  return getColorFromTheme();
}

/**
 * 根据当前主题色、模式等情景 计算最后生成的色阶
 */
export function generateColorMap(theme: string, colorPalette: Array<string>, mode: ModeType, brandColorIdx: number) {
  const isDarkMode = mode === 'dark';

  if (isDarkMode) {
    colorPalette.reverse().map((color) => {
      const [h, s, l] = Color.colorTransform(color, 'hex', 'hsl');
      return Color.colorTransform([h, Number(s) - 4, l], 'hsl', 'hex');
    });
    brandColorIdx = 5;
    colorPalette[0] = `${colorPalette[brandColorIdx]}20`;
  }

  const colorMap: TColorToken = {
    '--td-brand-color': colorPalette[brandColorIdx], // 主题色
    '--td-brand-color-1': colorPalette[0], // light
    '--td-brand-color-2': colorPalette[1], // focus
    '--td-brand-color-3': colorPalette[2], // disabled
    '--td-brand-color-4': colorPalette[3],
    '--td-brand-color-5': colorPalette[4],
    '--td-brand-color-6': colorPalette[5],
    '--td-brand-color-7': brandColorIdx > 0 ? colorPalette[brandColorIdx - 1] : theme, // hover
    '--td-brand-color-8': colorPalette[brandColorIdx], // 主题色
    '--td-brand-color-9': brandColorIdx > 8 ? theme : colorPalette[brandColorIdx + 1], // click
    '--td-brand-color-10': colorPalette[9],
  };
  return colorMap;
}

/**
 * 将生成的样式嵌入头部
 */
export function insertThemeStylesheet(theme: string, colorMap: TColorToken, mode: ModeType) {
  const isDarkMode = mode === 'dark';
  const root = !isDarkMode ? `:root[theme-color='${theme}']` : `:root[theme-color='${theme}'][theme-mode='dark']`;

  const styleSheet = document.createElement('style');
  styleSheet.type = 'text/css';
  styleSheet.textContent = `${root}{
    --td-brand-color: ${colorMap['--td-brand-color']};
    --td-brand-color-1: ${colorMap['--td-brand-color-1']};
    --td-brand-color-2: ${colorMap['--td-brand-color-2']};
    --td-brand-color-3: ${colorMap['--td-brand-color-3']};
    --td-brand-color-4: ${colorMap['--td-brand-color-4']};
    --td-brand-color-5: ${colorMap['--td-brand-color-5']};
    --td-brand-color-6: ${colorMap['--td-brand-color-6']};
    --td-brand-color-7: ${colorMap['--td-brand-color-7']};
    --td-brand-color-8: ${colorMap['--td-brand-color-8']};
    --td-brand-color-9: ${colorMap['--td-brand-color-9']};
    --td-brand-color-10: ${colorMap['--td-brand-color-10']};
  }`;

  document.head.appendChild(styleSheet);
}

/**
 * 规范化后台下发的品牌色：只接受严格十六进制，三位展开成六位并统一小写。
 *
 * 与后端 SiteConfigPayload::normalizeColor 口径一致——这个值会被写进
 * :root[theme-color='...'] 选择器，放行宽松格式等于放开 CSS 选择器注入。
 */
export function normalizeHexColor(value?: string): string {
  const raw = (value || '').trim();

  if (/^#([0-9a-fA-F]{3})$/.test(raw)) {
    const hex = raw.slice(1);
    return `#${hex[0]}${hex[0]}${hex[1]}${hex[1]}${hex[2]}${hex[2]}`.toLowerCase();
  }

  if (/^#([0-9a-fA-F]{6})$/.test(raw)) {
    return raw.toLowerCase();
  }

  return '';
}

/**
 * 把圆角基数写进 :root 的行内样式。
 *
 * 用行内样式而不是再插一个 <style>：圆角没有浅/深两套，也不参与色阶缓存，
 * 行内属性天然幂等，重复设置不会像 insertThemeStylesheet 那样不断堆 style 元素。
 * 优先级高于后端首屏注入的 <style>，因此后两者同时存在时以本函数为准。
 */
export function insertRadiusVariables(radius: number) {
  const small = Math.round(radius / 2);
  const large = Math.round(radius * 1.5);
  const root = document.documentElement;

  root.style.setProperty('--td-radius-small', `${small}px`);
  root.style.setProperty('--td-radius-default', `${small}px`);
  root.style.setProperty('--td-radius-medium', `${radius}px`);
  root.style.setProperty('--td-radius-large', `${large}px`);
}
