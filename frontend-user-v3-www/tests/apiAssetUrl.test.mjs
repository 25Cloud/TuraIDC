import assert from 'node:assert/strict'

import {
  resolveApiAssetUrl,
  resolveApiOrigin,
  rewriteApiAssetUrlsInHtml,
} from '../src/utils/apiAssetUrl.js'

const apiBaseUrl = 'https://api.coyjs.cn/api'

assert.equal(resolveApiOrigin(apiBaseUrl), 'https://api.coyjs.cn')
assert.equal(resolveApiAssetUrl('/uploads/content/cover.png', apiBaseUrl), 'https://api.coyjs.cn/uploads/content/cover.png')
assert.equal(resolveApiAssetUrl('/media/home/video.mp4', apiBaseUrl), 'https://api.coyjs.cn/media/home/video.mp4')
assert.equal(resolveApiAssetUrl('uploads/content/cover.png', apiBaseUrl), 'https://api.coyjs.cn/uploads/content/cover.png')
assert.equal(resolveApiAssetUrl('/branding/logo.svg', apiBaseUrl), '/branding/logo.svg')
assert.equal(resolveApiAssetUrl('https://cdn.example.com/image.png', apiBaseUrl), 'https://cdn.example.com/image.png')
assert.equal(resolveApiAssetUrl(' `https://api.25y.cn/media/logo.png` ', apiBaseUrl), 'https://api.25y.cn/media/logo.png')
assert.equal(
  rewriteApiAssetUrlsInHtml('<img src="/uploads/a.png"><video poster="/media/poster.jpg"></video><a href="uploads/file.pdf">附件</a>', apiBaseUrl),
  '<img src="https://api.coyjs.cn/uploads/a.png"><video poster="https://api.coyjs.cn/media/poster.jpg"></video><a href="https://api.coyjs.cn/uploads/file.pdf">附件</a>',
)

console.log('API asset URL tests passed')
