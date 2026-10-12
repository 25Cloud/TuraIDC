import assert from 'node:assert/strict'
import { existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

import {
  MIN_MARQUEE_COPIES,
  partnerStrips,
  resolveMarqueeCopies,
} from '../src/data/homePartners.js'

// 合作伙伴条幅是首页唯一的合作方展示位。素材若指回外部 CDN，对方站点
// 不可用时本区块会直接空白，因此这里同时校验路径是本地的、且文件真实存在。

const DIRECTIONS = new Set(['forward', 'reverse'])
const PUBLIC_DIR = fileURLToPath(new URL('../public/', import.meta.url))

assert.ok(Array.isArray(partnerStrips) && partnerStrips.length > 0, '合作伙伴条幅不能为空')

const ids = new Set()
for (const strip of partnerStrips) {
  assert.ok(strip.id && !ids.has(strip.id), `条幅 id 缺失或重复: ${strip.id}`)
  ids.add(strip.id)

  assert.ok(strip.src.startsWith('/img/'), `条幅 ${strip.id} 必须使用本地图资源: ${strip.src}`)
  assert.ok(existsSync(`${PUBLIC_DIR}${strip.src.slice(1)}`), `条幅 ${strip.id} 的素材文件不存在: ${strip.src}`)

  // alt 缺失会让读屏用户只听到「条幅 1」这类无意义文案。
  assert.ok(strip.alt && strip.alt.trim().length > 0, `条幅 ${strip.id} 缺少 alt 描述`)

  assert.ok(DIRECTIONS.has(strip.direction), `条幅 ${strip.id} 的 direction 非法: ${strip.direction}`)

  // 轨道整体左移一个条幅宽度，低于 24s 时 Logo 一闪而过、无法辨认。
  const speed = Number.parseFloat(strip.speed)
  assert.ok(Number.isFinite(speed), `条幅 ${strip.id} 的 speed 不是数字: ${strip.speed}`)
  assert.ok(speed >= 24, `条幅 ${strip.id} 的滚动过快（${speed}s），Logo 无法阅读`)
}

assert.ok(
  partnerStrips.some((strip) => strip.direction === 'forward') &&
    partnerStrips.some((strip) => strip.direction === 'reverse'),
  '滚动方向应同时包含正向与反向，避免视觉单调',
)

// 条幅按固定高度渲染，宽度由素材原始比例决定。逐档校验轨道不变量：
//   1) 位移量（轨道宽度的一半）≥ 可视宽度  —— 否则右侧露出空白；
//   2) 位移量是单份宽度的整数倍        —— 否则循环接缝可见。
// 此前固定渲染两份，视口超过单份宽度时右侧就会缺一块（线上反馈即此问题）。
const VIEWPORTS = [375, 768, 1280, 1440, 1600, 1920, 2560, 3440, 3840]
for (const height of [76, 56]) {
  // row1 素材 3976×212，约 18.75:1；这是宽度最窄的一行，属于最不利情况。
  const stripWidth = height * (3976 / 212)

  for (const viewport of VIEWPORTS) {
    const copies = resolveMarqueeCopies(viewport, stripWidth)
    const travel = (copies * stripWidth) / 2

    assert.ok(copies % 2 === 0, `份数必须为偶数才能无缝循环，viewport=${viewport} 得到 ${copies}`)
    assert.ok(
      travel >= viewport,
      `位移量 ${travel.toFixed(0)}px 小于视口 ${viewport}px，右侧会露出空白`,
    )
  }
}

assert.equal(resolveMarqueeCopies(0, 100), MIN_MARQUEE_COPIES, '尺寸未就绪时应回退到最少份数')
assert.equal(resolveMarqueeCopies(1000, 0), MIN_MARQUEE_COPIES, '条幅未加载时应回退到最少份数')

console.log(`Partner strip tests passed (${partnerStrips.length} strips)`)