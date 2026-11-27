import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const checker = fileURLToPath(new URL('../check-source-health.mjs', import.meta.url))

async function fixture(t, source) {
  const directory = await mkdtemp(path.join(tmpdir(), 'tura-source-health-'))
  assert.equal(path.dirname(directory), path.resolve(tmpdir()))
  t.after(() => rm(directory, { recursive: true, force: true }))
  await mkdir(path.join(directory, 'src'))
  await writeFile(path.join(directory, 'src', 'page.vue'), source)
  await writeFile(path.join(directory, 'package.json'), '{}')
  await writeFile(path.join(directory, 'vite.config.js'), 'export default {}')
  return directory
}

test('直接执行源码检查时必须真正扫描并输出成功信息', async (t) => {
  const cwd = await fixture(t, '<template><p>正常页面</p></template>')
  const result = spawnSync(process.execPath, [checker], { cwd, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stderr)
  assert.match(result.stdout, /Source health check passed/)
})

test('检测到损坏内容时命令必须失败，防止回归校验假通过', async (t) => {
  const cwd = await fixture(t, '<template><p>' + String.fromCharCode(0xfffd) + '</p></template>')
  const result = spawnSync(process.execPath, [checker], { cwd, encoding: 'utf8' })
  assert.equal(result.status, 1)
  assert.match(result.stderr, /Source health check failed/)
  assert.match(result.stderr, /page.vue/)
})

test('合法的多行 Vue 属性不应误报为缺少引号', async (t) => {
  const source = [
    '<template><button :aria-label="',
    "  active ? '展开' : '收起'",
    '">菜单</button></template>',
  ].join(String.fromCharCode(10))
  const cwd = await fixture(t, source)
  const result = spawnSync(process.execPath, [checker], { cwd, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stderr)
  assert.match(result.stdout, /Source health check passed/)
})

test('Vue 属性确实未闭合时必须检查失败', async (t) => {
  const cwd = await fixture(t, '<template><input placeholder="未闭合></template>')
  const result = spawnSync(process.execPath, [checker], { cwd, encoding: 'utf8' })
  assert.equal(result.status, 1)
  assert.match(result.stderr, /page.vue/)
})
