import assert from 'node:assert/strict'

import { normalizeExternalLink } from '../src/utils/externalLink.js'

assert.equal(normalizeExternalLink(' `https://qm.qq.com/q/FDk7U0YtyO` '), 'https://qm.qq.com/q/FDk7U0YtyO')
assert.equal(normalizeExternalLink('javascript:alert(1)'), '')

console.log('External link tests passed')
