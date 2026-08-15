// 最小 en locale stub：
// element-plus 的 use-locale 会静态 import en 语言包作为默认 fallback，
// 而站点 bootstrap 始终 provideGlobalConfig({ locale: zhCn })，该 fallback 永不使用。
// 用空结构替代完整 en 语言包，避免约 4.7KB raw 死代码进入入口 chunk。
export default {
  name: 'en',
  el: {},
}
