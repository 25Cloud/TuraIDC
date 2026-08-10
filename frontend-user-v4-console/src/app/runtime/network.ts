import { initRuntimeConnectionHints, primeConnectionHints } from '@ewyfinance/shared/runtime';

export function initClientRuntimeConnectionHints(options = {}) {
  initRuntimeConnectionHints(options);
}

export function primeClientConnectionHints(options = {}) {
  primeConnectionHints(options);
}
