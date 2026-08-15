import assert from 'node:assert/strict'

import { extractValidationErrors } from '../runtime/http/validationErrors.ts'

assert.deepEqual(
  extractValidationErrors({ data: { errors: { email: ['邮箱不能为空'], password: ['密码不能为空'] } } }),
  ['邮箱不能为空', '密码不能为空'],
  '应从标准 data.errors 提取字段错误'
)

assert.deepEqual(
  extractValidationErrors({ errors: { email: ['邮箱不能为空'] } }),
  ['邮箱不能为空'],
  '应兼容历史顶层 errors'
)

assert.deepEqual(extractValidationErrors({ data: null }), [], '无字段错误时返回空数组')

console.log('http validation tests passed')
