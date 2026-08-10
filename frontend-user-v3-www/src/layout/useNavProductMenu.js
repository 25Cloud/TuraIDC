import { computed, ref, shallowRef } from 'vue'
import siteApi from '@/api/site'

const CACHE_TTL = 5 * 60 * 1000
let cachedProductTypes = null
let cachedTimestamp = 0
let pendingRequest = null

export function useNavProductMenu() {
  const productTypes = ref([])
  const activeTypeValue = ref('')
  const groupsByType = shallowRef({})
  const loading = ref(false)
  const initialized = ref(false)

  const activeType = computed(() =>
    productTypes.value.find((t) => t.value === activeTypeValue.value) || null,
  )

  const activeGroups = computed(() => {
    const key = activeTypeValue.value
    return groupsByType.value[key] || []
  })

  function getGroupsForType(typeValue) {
    return groupsByType.value[typeValue] || []
  }

  async function loadProductTypes() {
    if (cachedProductTypes && Date.now() - cachedTimestamp < CACHE_TTL) {
      productTypes.value = cachedProductTypes
      if (cachedProductTypes.length && !activeTypeValue.value) {
        activeTypeValue.value = cachedProductTypes[0].value
      }
      return
    }

    if (pendingRequest) {
      await pendingRequest
      return
    }

    loading.value = true
    pendingRequest = siteApi.productTypes()
      .then((res) => {
        const list = res.data.list || []
        const normalized = list.map((item, index) => ({
          id: Number(item.id || index + 1),
          value: String(item.first_product_group_code || item.value || ''),
          label: item.label || item.product_type_label || `分类 ${index + 1}`,
          product_type: String(item.product_type || ''),
          product_count: Number(item.product_count || 0),
          group_count: Number(item.group_count || 0),
        })).filter((item) => item.value !== '')

        cachedProductTypes = normalized
        cachedTimestamp = Date.now()
        productTypes.value = normalized

        if (normalized.length && !activeTypeValue.value) {
          activeTypeValue.value = normalized[0].value
        }
      })
      .finally(() => {
        loading.value = false
        pendingRequest = null
      })

    await pendingRequest
  }

  async function loadGroupsForType(typeValue) {
    if (!typeValue || groupsByType.value[typeValue]) {
      return
    }

    try {
      const res = await siteApi.productGroups({ first_product_group_code: typeValue })
      const groups = (res.data.list || []).map((g) => ({
        id: Number(g.id || 0),
        name: g.name || '',
        slogan: g.slogan || '',
        product_count: Number(g.product_count || 0),
        product_type: g.product_type || '',
        first_product_group_code: g.first_product_group_code || '',
        product_type_id: Number(g.product_type_id || 0),
        children_count: Number(g.children_count || 0),
      }))

      groupsByType.value = { ...groupsByType.value, [typeValue]: groups }
    } catch {
      // silent
    }
  }

  async function activateType(typeValue) {
    if (activeTypeValue.value === typeValue) {
      return
    }
    activeTypeValue.value = typeValue
    await loadGroupsForType(typeValue)
  }

  async function init() {
    if (initialized.value) {
      return
    }
    initialized.value = true
    await loadProductTypes()
    if (activeTypeValue.value) {
      await loadGroupsForType(activeTypeValue.value)
    }
  }

  function reset() {
    initialized.value = false
  }

  return {
    productTypes,
    activeTypeValue,
    activeType,
    activeGroups,
    getGroupsForType,
    loading,
    activateType,
    loadGroupsForType,
    init,
    reset,
  }
}
