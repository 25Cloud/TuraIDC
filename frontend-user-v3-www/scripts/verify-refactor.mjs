import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { build } from 'vite'

// 独立进程避免健康检查的 SFC 解析缓存影响后续生产编译。
execFileSync(process.execPath, [fileURLToPath(new URL('./check-source-health.mjs', import.meta.url))], {
  stdio: 'inherit',
  windowsHide: true,
})
await build({ mode: 'production' })
