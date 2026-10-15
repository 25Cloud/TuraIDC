/**
 * 扫描后端 BusinessException 的中文消息，生成繁简之外的 messages 词条。
 *
 * 用法：node backend/scripts/i18n/build-biz-messages.mjs
 *
 * 约定：消息表以**中文原文为 key**。这样
 *   - 查表命中即拿到译文；
 *   - 未命中的语言天然回落中文，不会出现空消息；
 *   - 新增业务消息不必同步维护四份词表，未翻译项会列在结尾提示里。
 *
 * en_US 不由本脚本覆盖——它是人工翻译的产物，脚本只负责 zh_CN / zh_TW / zh_HK。
 */
import { existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import OpenCC from 'opencc-js'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')
const APP_DIR = resolve(ROOT, 'backend/app')

const TW_TERMS = [
  ['退出登录', '登出'], ['代登录', '代理登入'], ['注册', '註冊'],
  ['注销', '註銷'], ['邮箱', '電子郵件'], ['信箱', '電子郵件'],
]

// opencc 之前：连字带词一起替换（hkp 不会为「内存」做词汇映射）
const HK_PRE_TERMS = [
  ['退出登录', '登出'], ['代登录', '代理登入'], ['注册', '註冊'],
  ['注销', '註銷'], ['邮箱', '電郵'], ['信箱', '電郵'],
  ['内存', '記憶體'], ['缓存', '快取'], ['软件', '軟件'],
  ['网络', '網絡'], ['数据', '數據'], ['默认', '默認'], ['视频', '視頻'],
]

// opencc 之后：hkp 的港式字典会把部分字反向还原成简体形态，必须改回来
const HK_POST_TERMS = [
  ['户', '戶'], ['税', '稅'], ['温', '溫'], ['启', '啟'], ['啓', '啟'],
  ['众', '眾'], ['衆', '眾'], ['里', '裡'], ['裏', '裡'],
]

function walk(dir, out = []) {
  if (!existsSync(dir)) return out
  for (const name of readdirSync(dir)) {
    const p = resolve(dir, name)
    if (statSync(p).isDirectory()) walk(p, out)
    else if (/\.php$/.test(name)) out.push(p)
  }
  return out
}


function applyTerms(text, terms) {
  let out = String(text)
  for (const [from, to] of terms) out = out.split(from).join(to)
  return out
}

const twp = OpenCC.Converter({ from: 'cn', to: 'twp' })
const hkp = OpenCC.Converter({ from: 'cn', to: 'hkp' })
const toTW = (s) => twp(applyTerms(s, TW_TERMS))
const toHK = (s) => applyTerms(hkp(applyTerms(s, HK_PRE_TERMS)), HK_POST_TERMS)

// 提取唯一中文消息
const messages = new Set()
for (const file of walk(APP_DIR)) {
  const src = readFileSync(file, 'utf8')
  for (const m of src.matchAll(/BusinessException\(\s*(['"])((?:\\.|(?!\1)[^\\])*?)\1/g)) {
    if (/[\u4e00-\u9fff]/.test(m[2])) messages.add(m[2])
  }
}

const list = [...messages].sort((a, b) => a.localeCompare(b, 'zh-Hans-CN'))
if (list.length === 0) {
  console.log('未扫描到任何中文业务消息')
  process.exit(0)
}

const q = (s) => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`

const packs = {
  zh_CN: (s) => s,
  zh_TW: toTW,
  zh_HK: toHK,
}

for (const [locale, convert] of Object.entries(packs)) {
  const rows = list.map((m) => `    ${q(m)} => ${q(convert(m))},`)
  const header =
    '// 本文件由 backend/scripts/i18n/build-biz-messages.mjs 从 app/ 扫描生成，请勿手工编辑。\n' +
    '// 新增业务消息后重跑即可补齐该语言的繁体版本。\n'
  const body = `<?php\n\ndeclare(strict_types=1);\n\n${header}\nreturn [\n${rows.join('\n')}\n];\n`
  const dir = resolve(ROOT, 'backend/lang', locale)
  mkdirSync(dir, { recursive: true })
  writeFileSync(resolve(dir, 'messages.php'), body, 'utf8')
  console.log(`written backend/lang/${locale}/messages.php (${list.length} 条)`)
}

console.log(`\n共 ${list.length} 条业务消息。en_US 为人工翻译，未被本脚本覆盖。`)