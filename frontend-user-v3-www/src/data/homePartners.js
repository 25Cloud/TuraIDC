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
 * - alt 写明该行出现的代表合作方，读屏用户才能获知这部分内容；
 *   替换素材后请同步更新。
 * - speed 是整轮滚完一遍的秒数，只用于制造轻微错位感：
 *   低于 24s 会让整块区域持续晃动，Logo 一闪而过无法阅读。
 */
export const partnerStrips = Object.freeze([
  {
    id: "row1",
    src: "/img/website/partners/row1.png",
    alt: "爱奇艺、酷狗音乐、迅雷、掌阅 iReader 等合作方",
    direction: "forward",
    speed: "62s",
  },
  {
    id: "row2",
    src: "/img/website/partners/row2.png",
    alt: "阿里巴巴、腾讯、字节跳动、美团 等合作方",
    direction: "reverse",
    speed: "70s",
  },
  {
    id: "row3",
    src: "/img/website/partners/row3.png",
    alt: "中国移动、中国联通、58 同城、携程 等合作方",
    direction: "forward",
    speed: "78s",
  },
  {
    id: "row4",
    src: "/img/website/partners/row4.png",
    alt: "格力、京东、Segway-Ninebot、淘宝 等合作方",
    direction: "reverse",
    speed: "86s",
  },
  {
    id: "row5",
    src: "/img/website/partners/row5.png",
    alt: "格力、京东方、小鹏、蔚来 等合作方",
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
