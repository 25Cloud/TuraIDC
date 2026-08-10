import { existsSync, readFileSync } from 'node:fs'
import path from 'node:path'

const ROOT = process.cwd()

function unquoteEnvValue(value) {
  const trimmed = String(value || '').trim()
  if (trimmed.length >= 2) {
    const first = trimmed[0]
    const last = trimmed[trimmed.length - 1]
    if ((first === '"' && last === '"') || (first === "'" && last === "'")) {
      return trimmed.slice(1, -1)
    }
  }
  return trimmed
}

function readEnvFile(filePath) {
  if (!existsSync(filePath)) {
    return {}
  }

  return readFileSync(filePath, 'utf8')
    .split(/\r?\n/)
    .reduce((env, line) => {
      const trimmed = line.trim()
      if (!trimmed || trimmed.startsWith('#')) {
        return env
      }

      const separatorIndex = trimmed.indexOf('=')
      if (separatorIndex <= 0) {
        return env
      }

      const key = trimmed.slice(0, separatorIndex).trim()
      const value = trimmed.slice(separatorIndex + 1)
      env[key] = unquoteEnvValue(value)
      return env
    }, {})
}

export function loadBuildEnv(mode = 'production') {
  const externalKeys = new Set(Object.keys(process.env))
  const envFiles = ['.env', '.env.local', `.env.${mode}`, `.env.${mode}.local`]

  for (const fileName of envFiles) {
    const env = readEnvFile(path.join(ROOT, fileName))
    for (const [key, value] of Object.entries(env)) {
      if (!externalKeys.has(key)) {
        process.env[key] = value
      }
    }
  }

  return process.env
}
