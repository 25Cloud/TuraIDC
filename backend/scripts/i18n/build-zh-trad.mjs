/**
 * 由 zh_CN 词条生成两种繁体：zh_TW（台湾用语）与 zh_HK（香港用语）。
 *
 * 用法：node backend/scripts/i18n/build-zh-trad.mjs
 *
 * 为什么必须分两版：繁体的「字」大多相同，差异在「词」——
 *   台湾：伺服器 / 記憶體 / 資料 / 網路 / 軟體 / 快取 / 使用者
 *   香港：伺服器 / 記憶體 / 數據 / 網絡 / 軟件 / 快取 / 用户
 * 用一份繁体词条覆盖两地，会有一半用词是错的。
 *
 * 字符与词汇层面交给 OpenCC（cn->twp / cn->hkp），两者都覆盖不到的地方再人工补
 * （见 EXTRA_TERMS）。详见 docs/develop/i18n.md 第五节。
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import OpenCC from 'opencc-js'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')

const TARGETS = [
  'frontend-admin-v3/src/locales/lang',
  'frontend-user-v4-console/src/locales/lang',
  'frontend-user-v3-www/src/locales/lang',
]

// OpenCC 覆盖不到的地区用词。必须在 opencc 之前替换。
const TW_TERMS = [
  ['退出登录', '登出'],
  ['代登录', '代理登入'],
  ['注册', '註冊'],
  ['注销', '註銷'],
  ['邮箱', '電子郵件'],
  ['信箱', '電子郵件'],
]

// opencc 之前：这些词需要连字带词一起替换
const HK_PRE_TERMS = [
  ['退出登录', '登出'],
  ['代登录', '代理登入'],
  ['注册', '註冊'],
  ['注销', '註銷'],
  ['邮箱', '電郵'],
  ['信箱', '電郵'],
  // hkp 把「内存」留成「內存」，香港通行「記憶體」
  ['内存', '記憶體'],
  ['缓存', '快取'],
  ['软件', '軟件'],
  ['网络', '網絡'],
  ['数据', '數據'],
  ['默认', '默認'],
  ['视频', '視頻'],
]

// opencc 之后：hkp 的港式字典会把部分字反向还原成简体形态，必须再改回来
const HK_POST_TERMS = [
  ['户', '戶'],
  ['税', '稅'],
  ['温', '溫'],
  ['脱', '脫'],
  // 港式异体字，统一回标准繁体
  ['啓', '啟'],
  ['衆', '眾'],
  ['裏', '裡'],
]

const VARIANTS = [
  { locale: 'zh_TW', to: 'twp', display: '繁體中文（台灣）', pre: TW_TERMS, post: [] },
  { locale: 'zh_HK', to: 'hkp', display: '繁體中文（香港）', pre: HK_PRE_TERMS, post: HK_POST_TERMS },
]

function makeConvert(to, pre, post) {
  const opencc = OpenCC.Converter({ from: 'cn', to })
  return (input) => {
    let text = String(input)
    for (const [from, target] of pre) text = text.split(from).join(target)
    text = opencc(text)
    for (const [from, target] of post) text = text.split(from).join(target)
    return text
  }
}

function transform(node, convert) {
  if (Array.isArray(node)) return node.map((item) => transform(item, convert))
  if (node && typeof node === 'object') {
    const out = {}
    for (const [k, v] of Object.entries(node)) out[k] = transform(v, convert)
    return out
  }
  return typeof node === 'string' ? convert(node) : node
}

let written = 0
for (const dir of TARGETS) {
  const data = JSON.parse(readFileSync(resolve(ROOT, dir, 'zh_CN.json'), 'utf8'))

  for (const variant of VARIANTS) {
    const converted = transform(data, makeConvert(variant.to, variant.pre, variant.post))
    // lang 置顶，与 zh_CN 的结构保持一致
    const ordered = { lang: variant.display }
    for (const [k, v] of Object.entries(converted)) if (k !== 'lang') ordered[k] = v

    writeFileSync(resolve(ROOT, dir, `${variant.locale}.json`), `${JSON.stringify(ordered, null, 2)}\n`, 'utf8')
    written += 1
  }
  console.log(`${dir}: zh_TW / zh_HK 已生成`)
}
console.log(`\n${written} 个繁体词条文件完成（台湾用语 / 香港用语）`)
// ---------------------------------------------------------------------------
// BACKEND_VALIDATION：后端校验消息包
// validation.php 是 PHP 数组，不能按 JSON 处理；逐个单引号字面量转换后原样写回，
// 这样结构、注释、占位符都不会被破坏。
// ---------------------------------------------------------------------------

const BACKEND_SRC = resolve(ROOT, 'backend/lang/zh_CN/validation.php')

function convertPhpLiterals(source, convert) {
  let out = ''
  let i = 0
  while (i < source.length) {
    if (source[i] !== "'") {
      out += source[i]
      i += 1
      continue
    }
    let j = i + 1
    let raw = ''
    while (j < source.length) {
      if (source[j] === '\\') { raw += source[j] + source[j + 1]; j += 2; continue }
      if (source[j] === "'") break
      raw += source[j]
      j += 1
    }
    const inner = raw.replace(/\\'/g, "'").replace(/\\\\/g, '\\')
    if (/[\u4e00-\u9fff]/.test(inner)) {
      out += "'" + convert(inner).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"
    } else {
      out += "'" + raw + "'"
    }
    i = j + 1
  }
  return out
}

if (existsSync(BACKEND_SRC)) {
  const php = readFileSync(BACKEND_SRC, 'utf8')
  for (const variant of VARIANTS) {
    const convert = makeConvert(variant.to, variant.pre, variant.post)
    const converted = convertPhpLiterals(php, convert)
    const header =
      '<?php\n\ndeclare(strict_types=1);\n\n' +
      '// 本文件由 zh_CN/validation.php 自动生成，请勿手工编辑。\n' +
      '// 修改文案请改 zh_CN 后重跑：node backend/scripts/i18n/build-zh-trad.mjs\n\n'
    const body = converted.replace(/^<\?php\s*\n?\s*declare\(strict_types=1\);\s*\n*/m, '')
    const dir = resolve(ROOT, 'backend/lang', variant.locale)
    mkdirSync(dir, { recursive: true })
    writeFileSync(resolve(dir, 'validation.php'), header + body, 'utf8')
    written += 1
    console.log('written', resolve(dir, 'validation.php'))
  }
}
