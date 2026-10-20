import assert from 'node:assert/strict'

import {
  abbreviateGroupName,
  charHalfWidth,
  GROUP_ICON_ABBR_MAX_UNITS,
  isFullWidthCodePoint,
  resolveProductGroupIcon,
  truncateByHalfWidth,
} from '../text/abbr.ts'

assert.equal(GROUP_ICON_ABBR_MAX_UNITS, 4)

// 1. 字符宽度：中文 / 全角符号算 2，英文 / 数字 / 半角符号算 1
assert.equal(charHalfWidth('轻'), 2)
assert.equal(charHalfWidth('G'), 1)
assert.equal(charHalfWidth('7'), 1)
assert.equal(charHalfWidth('，'), 2)
assert.equal(charHalfWidth(','), 1)
assert.equal(isFullWidthCodePoint(0x4e00), true)
assert.equal(isFullWidthCodePoint(0x41), false)

// 2. 需求里的三个例子
assert.equal(abbreviateGroupName('轻量云'), '轻量')
assert.equal(abbreviateGroupName('GameCloud'), 'Game')
// 「轻」2 + 「量」2 刚好用满 4，后面的英文字符不再截取
assert.equal(abbreviateGroupName('轻量Game'), '轻量')

// 3. 正好用满不丢字符，超出上限不截半个字符
assert.equal(abbreviateGroupName('Game'), 'Game')
assert.equal(abbreviateGroupName('GameC'), 'Game')
assert.equal(abbreviateGroupName('云AB'), '云AB') // 2 + 1 + 1 = 4
assert.equal(abbreviateGroupName('云ABC'), '云AB') // 2 + 1 + 1 = 4，C 放不下
assert.equal(abbreviateGroupName('云服务器'), '云服') // 2 + 2 = 4
assert.equal(abbreviateGroupName('A'), 'A')
assert.equal(abbreviateGroupName(''), '')
assert.equal(abbreviateGroupName('  轻量云  '), '轻量')

// 4. 自定义上限与非法上限
assert.equal(truncateByHalfWidth('轻量云', 6), '轻量云')
assert.equal(truncateByHalfWidth('GameCloud', 0), '')
assert.equal(truncateByHalfWidth('GameCloud', -2), '')
assert.equal(truncateByHalfWidth(null, 4), '')
assert.equal(truncateByHalfWidth(12345, 2), '12')

// 5. 全角数字 / 全角标点按 2 计
assert.equal(abbreviateGroupName('１２３'), '１２') // 两个全角数字刚好 4
assert.equal(abbreviateGroupName('云，A'), '云，') // 2 + 2 = 4

// 6. 图标展示回退规则：icon_image → icon_text → icon（内置图标名）→ 名称缩写
assert.deepEqual(resolveProductGroupIcon(null), {
  mode: 'abbr',
  image: '',
  text: '',
  icon_name: '',
  abbr: '',
})
// 全部为空 → 名称缩写
assert.deepEqual(resolveProductGroupIcon({ label: '云服务器' }), {
  mode: 'abbr',
  image: '',
  text: '',
  icon_name: '',
  abbr: '云服',
})
// 图片优先级最高
assert.deepEqual(resolveProductGroupIcon({ icon: 'Server', icon_text: '轻量', icon_image: '/media/a.png', label: '轻量云' }), {
  mode: 'image',
  image: '/media/a.png',
  text: '轻量',
  icon_name: 'Server',
  abbr: '轻量',
})
assert.deepEqual(resolveProductGroupIcon({ icon_text: '轻量', label: '轻量云' }), {
  mode: 'text',
  image: '',
  text: '轻量',
  icon_name: '',
  abbr: '轻量',
})
assert.deepEqual(resolveProductGroupIcon({ icon: 'Gamepad', label: '游戏云' }), {
  mode: 'icon',
  image: '',
  text: '',
  icon_name: 'Gamepad',
  abbr: '游戏',
})
// 字符串两端空白不参与判定
assert.deepEqual(resolveProductGroupIcon({ icon: '  ', icon_text: ' ', label: 'GameCloud' }), {
  mode: 'abbr',
  image: '',
  text: '',
  icon_name: '',
  abbr: 'Game',
})

console.log('shared text abbr tests passed')
