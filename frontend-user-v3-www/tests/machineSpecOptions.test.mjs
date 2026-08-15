import assert from 'node:assert/strict'
import { buildMachineSpecOptions } from '../src/views/website/products/machineSpecOptions.js'

const configs = [
  {
    key: 'cpu',
    isNumber: true,
    defaultNum: 2,
    subOptions: [
      { id: 'cpu-2', label: '2核' },
      { id: 'cpu-4', label: '4核' },
    ],
  },
  {
    key: 'memory',
    isNumber: true,
    defaultNum: 2,
    subOptions: [
      { id: 'mem-2', label: '2G' },
      { id: 'mem-4', label: '4G' },
    ],
  },
]

const cpuOptions = buildMachineSpecOptions([configs[0]], {}, 'cpu')
const memoryOptions = buildMachineSpecOptions([configs[1]], {}, 'memory')

assert.deepEqual(
  cpuOptions.map((item) => item.label),
  ['2核', '4核'],
)
assert.deepEqual(
  memoryOptions.map((item) => item.label),
  ['2G', '4G'],
)
assert.deepEqual(
  cpuOptions.map((item) => item.configKey),
  ['cpu_num', 'cpu_num'],
)
assert.deepEqual(
  cpuOptions.map((item) => item.id),
  ['2核', '4核'],
)

console.log('machineSpecOptions tests passed')
