/**
 * 扫描前端 <script> 段里「高置信度 UI 文案」，用于 i18n 接入进度自查。
 *
 * 用法：node backend/scripts/i18n/probe-ui-text.mjs [--json <输出路径>]
 *
 * 只收录明确给用户看的两类位置：
 *   1. 一手提示调用：MessagePlugin.* / DialogPlugin.* / ElMessage / Toast / Notify
 *   2. 显示类对象的键：title / label / placeholder / description / emptyText ...
 *
 * 明确排除：
 *   - 日志与调试输出（Log:: / logger. / console.）—— 运维排障入口，不该随界面语言变
 *   - 注释行
 *   - 含代码插值（{xxx} / ${xxx} / $var）的文案—— 需要人工确认，先不计入
 *
 * 故意「宁可漏，不可误伤」：关键词常量、枚举值一旦被误翻译会让业务逻辑静默失效，
 * 那比留中文严重得多。这份清单是人工审阅的起点，不是自动替换的依据。
 */
import { existsSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs'
import { dirname, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')
const APPS = ['frontend-admin-v3', 'frontend-user-v4-console', 'frontend-user-v3-www']

const DIRECT = [
  /(?:MessagePlugin|DialogPlugin|Notification|Notify|ElMessage|ElNotification|Toast)\s*\.\s*\w+\s*\(\s*(['"])((?:\\.|(?!\1)[^\\])*?)\1/g,
  /\b(?:MessagePlugin|ElMessage|Toast)\s*\(\s*(['"])((?:\\.|(?!\1)[^\\])*?)\1/g,
]

const UI_KEYS = [
  'title', 'label', 'placeholder', 'description', 'emptyText', 'empty-text',
  'confirmBtn', 'cancelBtn', 'okBtn', 'tip', 'hint', 'message', 'text',
  'confirmText', 'cancelText', 'subTitle', 'subTitleText', 'help', 'name',
]
const KEY_RE = new RegExp(
  `\\b(${UI_KEYS.join('|')})\\s*:\\s*(['"])((?:\\\\.|(?!\\2)[^\\\\])*?)\\2`,
  'g',
)

function walk(dir, out = []) {
  if (!existsSync(dir)) return out
  for (const name of readdirSync(dir)) {
    const p = resolve(dir, name)
    if (statSync(p).isDirectory()) walk(p, out)
    else if (/\.(vue|ts|js)$/.test(name)) out.push(p)
  }
  return out
}

const found = new Map()
let total = 0

for (const app of APPS) {
  for (const f of walk(resolve(ROOT, app, 'src'))) {
    const src = readFileSync(f, 'utf8')
    const isVue = f.endsWith('.vue')
    const si = isVue ? src.indexOf('<script') : 0
    const se = isVue ? src.indexOf('</script>') : src.length
    if (si < 0 || se < si) continue

    src.slice(si, se).split(/\r?\n/).forEach((raw, idx) => {
      const line = raw.replace(/\/\/.*$/, '')
      const t = line.trimStart()
      if (t.startsWith('//') || t.startsWith('*') || t.startsWith('/*')) return
      if (/\bLog\s*::|logger\s*\.|console\s*\./.test(line)) return

      const push = (m, i) => {
        const text = m[i]
        if (!text || !/[\u4e00-\u9fff]/.test(text)) return
        if (text.length < 2 || text.length > 120) return
        if (/\{|\$\{|\$\w/.test(text)) return
        if (!found.has(text)) found.set(text, new Set())
        found.get(text).add(`${relative(ROOT, f).replace(/\\/g, '/')}:${idx + 1}`)
        total += 1
      }

      for (const re of DIRECT) for (const m of line.matchAll(re)) push(m, 2)
      for (const m of line.matchAll(KEY_RE)) push(m, 3)
    })
  }
}

const list = [...found.entries()]
  .map(([text, at]) => ({ text, n: at.size, at: [...at] }))
  .sort((a, b) => b.n - a.n || a.text.localeCompare(b.text, 'zh-Hans-CN'))

const jsonArg = process.argv.indexOf('--json')
if (jsonArg > 0) writeFileSync(process.argv[jsonArg + 1], JSON.stringify(list, null, 2), 'utf8')

const files = new Set(list.flatMap((x) => x.at.map((a) => a.split(':')[0])))
console.log(`唯一 UI 文案: ${list.length} 条`)
console.log(`出现总次数:   ${total}`)
console.log(`分布文件:     ${files.size} 个`)