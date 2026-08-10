import { beginRouteLoading } from './routeLoading'

export function lazyRouteView<T>(loader: () => Promise<T>) {
  return async () => {
    const stopLoading = beginRouteLoading()

    try {
      return await loader()
    } finally {
      stopLoading()
    }
  }
}
