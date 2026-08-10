import { computed, ref } from 'vue'

const pendingRouteLoads = ref(0)

export const isRouteLoading = computed(() => pendingRouteLoads.value > 0)

export function beginRouteLoading() {
  pendingRouteLoads.value += 1

  let settled = false

  return () => {
    if (settled) {
      return
    }

    settled = true
    pendingRouteLoads.value = Math.max(0, pendingRouteLoads.value - 1)
  }
}
