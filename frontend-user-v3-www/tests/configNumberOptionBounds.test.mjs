import assert from 'node:assert/strict'
import { resolveNumberOptionBounds } from '../src/domains/products/configNumberOptionBounds.js'

assert.deepEqual(
  resolveNumberOptionBounds({ qty_minimum: 0, qty_maximum: 0 }),
  {
    min: 0,
    max: 0,
    hasExplicitMin: true,
    hasExplicitMax: true,
  },
)

assert.deepEqual(
  resolveNumberOptionBounds({ qty_minimum: '', qty_maximum: '' }),
  {
    min: 1,
    max: 9999,
    hasExplicitMin: false,
    hasExplicitMax: false,
  },
)

assert.deepEqual(
  resolveNumberOptionBounds({ qty_minimum: 3, qty_maximum: 1 }),
  {
    min: 3,
    max: 3,
    hasExplicitMin: true,
    hasExplicitMax: true,
  },
)

console.log('configNumberOptionBounds tests passed')
