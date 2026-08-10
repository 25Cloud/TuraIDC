/**
 * Vite 插件：在构建时注入前端版本号到 import.meta.env.VITE_APP_VERSION。
 * 版本号格式：YYYYMMDD-HHMMSS，确保每次构建唯一。
 */
import type { Plugin } from 'vite'

function generateVersion(): string {
  const now = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  const date = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}`
  const time = `${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`
  return `${date}-${time}`
}

export function versionDefinePlugin(): Plugin {
  const version = generateVersion()

  return {
    name: 'inject-app-version',
    config() {
      return {
        define: {
          'import.meta.env.VITE_APP_VERSION': JSON.stringify(version),
        },
      }
    },
  }
}
