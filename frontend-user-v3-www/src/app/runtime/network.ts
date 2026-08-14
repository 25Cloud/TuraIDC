import { initRuntimeConnectionHints, primeConnectionHints } from '@turaidc/shared/runtime'

export function initClientRuntimeConnectionHints(options = {}) {
  initRuntimeConnectionHints(options)
}

export function primeClientConnectionHints(options = {}) {
  primeConnectionHints(options)
}
