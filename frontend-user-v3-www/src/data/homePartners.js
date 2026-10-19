/**
 * 首页「优质合作伙伴」展示数据。
 *
 * 素材说明：每条是一张横向长图（宽约 3.5~4 千像素、高 212 像素），
 * 图内已排版好多个圆角 Logo 卡片，因此前端只能整体滚动，
 * 无法拆成单个 Logo 渲染。
 *
 * 维护约定：
 * - 素材已本地化到 public/img/website/partners/，不依赖外部 CDN：
 *   对方站点不可用时本区块会直接空白，且第三方长图会拖慢首屏。
 * - altKey 指向词条里该行代表合作方的描述（读屏用户据此获知内容）；
 *   替换素材后请同步更新词条。
 * - speed 是整轮滚完一遍的秒数，只用于制造轻微错位感：
 *   低于 24s 会让整块区域持续晃动，Logo 一闪而过无法阅读。
 *
 * 本模块刻意不 import i18n：tests/homePartners.test.mjs 直接用 node 跑它，
 * 而 `@/` 是 Vite 别名，node 解析不了。文案在组件里用 $t(altKey) 取，
 * 切换语言时会跟着重渲染。
 */
export const partnerStrips = Object.freeze([
  {
    id: "row1",
    src: "/img/website/partners/row1.png",
    altKey: "home.partners.mediaAndReading",
    direction: "forward",
    speed: "62s",
  },
  {
    id: "row2",
    src: "/img/website/partners/row2.png",
    altKey: "home.partners.internetTech",
    direction: "reverse",
    speed: "70s",
  },
  {
    id: "row3",
    src: "/img/website/partners/row3.png",
    altKey: "home.partners.telecomAndTravel",
    direction: "forward",
    speed: "78s",
  },
  {
    id: "row4",
    src: "/img/website/partners/row4.png",
    altKey: "home.partners.ecommerceAndMobility",
    direction: "reverse",
    speed: "86s",
  },
  {
    id: "row5",
    src: "/img/website/partners/row5.png",
    altKey: "home.partners.electronicsAndEv",
    direction: "forward",
    speed: "94s",
  },
]);

/** 轨道至少要渲染两份才能形成循环。 */
export const MIN_MARQUEE_COPIES = 2;

/**
 * 计算一条轨道需要渲染多少份条幅，才既无缝循环又不会在右侧露出空白。
 *
 * 轨道用 `transform: translateX(-50%)` 位移，位移量恰好是轨道宽度的一半，
 * 即「份数 × 单份宽度 ÷ 2」。要盖满可视宽度，位移量必须 ≥ 可视宽度；
 * 同时位移量必须是单份宽度的整数倍，循环回到起点时才停在同一张内容上，
 * 接缝不可见——因此份数必须取偶数。
 *
 * @param viewportWidth 行容器可视宽度
 * @param stripWidth 单份条幅实际渲染宽度
 */
export function resolveMarqueeCopies(viewportWidth, stripWidth) {
  if (!(viewportWidth > 0) || !(stripWidth > 0)) {
    return MIN_MARQUEE_COPIES;
  }

  const needed = Math.ceil((2 * viewportWidth) / stripWidth);

  return Math.max(MIN_MARQUEE_COPIES, needed + (needed % 2));
}
