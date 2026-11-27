import fs from 'node:fs/promises'
import path from 'node:path'
import { pathToFileURL } from 'node:url'
import { parse } from 'vue/compiler-sfc'

const ROOT = process.cwd()
const TARGETS = [
  'src',
  'package.json',
  'vite.config.js',
]

const TEXT_FILE_EXTENSIONS = new Set([
  '.vue',
  '.js',
  '.mjs',
  '.json',
  '.scss',
  '.css',
  '.md',
  '.html',
])

function createIssueCollector() {
  return []
}

function inspectFile(relativePath, content, issues) {
  if (content.includes('\uFFFD')) {
    issues.push({
      file: relativePath,
      problem: '包含 Unicode replacement character（疑似编码损坏）',
    })
  }

  // Vue 属性允许跨行；交给编译器检查，避免把合法绑定误报成缺少引号。
  if (path.extname(relativePath) === '.vue') {
    const { errors } = parse(content, { filename: relativePath })
    for (const error of errors) {
      issues.push({
        file: relativePath,
        problem: error.message || String(error),
      })
    }
  }

  const lines = content.split(/\r?\n/)
  lines.forEach((line, index) => {
    const trimmed = line.trim()

    if (
      trimmed.startsWith('router-link>') ||
      trimmed.startsWith('/router-link>') ||
      trimmed.startsWith('p>') ||
      trimmed.startsWith('/p>')
    ) {
      issues.push({
        file: relativePath,
        problem: `第 ${index + 1} 行疑似缺失起始尖括号`,
      })
    }
  })
}

export async function runSourceHealthCheck() {
  const issues = createIssueCollector()

  for (const target of TARGETS) {
    await walk(target, issues)
  }

  return issues
}

async function walk(targetPath, issues) {
  const absolutePath = path.join(ROOT, targetPath)
  const stats = await fs.stat(absolutePath)

  if (stats.isDirectory()) {
    const entries = await fs.readdir(absolutePath, { withFileTypes: true })
    for (const entry of entries) {
      if (entry.name === 'node_modules' || entry.name === 'dist') {
        continue
      }
      await walk(path.join(targetPath, entry.name), issues)
    }
    return
  }

  const extension = path.extname(absolutePath)
  if (!TEXT_FILE_EXTENSIONS.has(extension) && path.basename(absolutePath) !== 'package.json') {
    return
  }

  const content = await fs.readFile(absolutePath, 'utf8')
  inspectFile(targetPath, content, issues)
}

function reportIssues(issues) {
  if (issues.length) {
    console.error('Source health check failed:')
    for (const issue of issues) {
      console.error(`- ${issue.file}: ${issue.problem}`)
    }
    return false
  }

  console.log('Source health check passed.')
  return true
}

async function main() {
  const issues = await runSourceHealthCheck()

  if (!reportIssues(issues)) {
    process.exit(1)
  }
}

const isDirectRun = process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href

if (isDirectRun) {
  main().catch((error) => {
    console.error(error?.stack || String(error))
    process.exit(1)
  })
}
