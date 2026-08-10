import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import siteApi from '@/api/site'
import { getPendingWebsiteCouponId } from '@/utils/websiteCoupon'
import {
  buildWebsiteProductPath,
  hasWebsiteProductRouteParams,
  readWebsiteProductRouteParams,
} from '@/utils/productRoute'

const MOBILE_BREAKPOINT = 900
const CATALOG_CACHE_TTL = 3 * 60 * 1000

const catalogCache = new Map()
const catalogPendingMap = new Map()

function getCachedCatalog(groupId) {
  const entry = catalogCache.get(groupId)
  if (!entry) {
    return null
  }

  if (Date.now() - entry.timestamp > CATALOG_CACHE_TTL) {
    catalogCache.delete(groupId)
    return null
  }

  return entry.data
}

function setCachedCatalog(groupId, data) {
  catalogCache.set(groupId, { data, timestamp: Date.now() })
}

function invalidateCatalogCache(groupId) {
  catalogCache.delete(groupId)
}

function groupMatchesType(group, typeValue) {
  if (!typeValue) {
    return true
  }

  return [group?.first_product_group_code]
    .map((value) => String(value || '').trim())
    .includes(typeValue)
}

function filterGroupsByType(groups = [], typeValue = '') {
  if (!Array.isArray(groups)) {
    return []
  }

  return groups.filter((group) => groupMatchesType(group, typeValue))
}

function catalogBelongsToGroup(catalog, groupId) {
  return Number(catalog?.effective_product_group_id || 0) === Number(groupId || 0)
}

export function useWebsiteProductsCatalog({ onProductSelect, onResetSelection }) {
  const route = useRoute()
  const router = useRouter()
  const pageLoading = ref(false)
  const sidebarCollapsed = ref(false)
  const isMobile = ref(false)
  const mobileTypeEntered = ref(false)
  const mobileCategoryDrawer = ref(false)
  const mobileProductDrawer = ref(false)
  const mobilePendingProductId = ref(0)

  const productTypes = ref([])
  const rootGroups = ref([])
  const childGroups = ref([])
  const productsByGroup = ref({})

  const activeTypeValue = ref('')
  const activeGroupId = ref(0)
  const activeChildId = ref(0)
  const selectedProductId = ref(0)
  const mobileRegionDrawer = ref(false)
  const tempGroupId = ref(0)
  const tempChildGroups = ref([])

  let routeSyncSuspendCount = 0
  let groupToken = 0
  let groupAbortController = null

  const activeType = computed(() => productTypes.value.find((type) => type.value === activeTypeValue.value) || null)
  const activeTypeLabel = computed(() => activeType.value?.label || '')
  const activeTypeId = computed(() => Number(activeType.value?.id || 0))
  const activeGroup = computed(() => rootGroups.value.find((group) => group.id === activeGroupId.value) || null)
  const activeGroupName = computed(() => activeGroup.value?.name || '')
  const activeChildName = computed(() => {
    if (activeChildId.value <= 0) return ''
    return childGroups.value.find((g) => g.id === activeChildId.value)?.name || ''
  })
  const activeCatalogCategoryId = computed(() => {
    if (activeChildId.value > 0) {
      return Number(childGroups.value.find((group) => group.id === activeChildId.value)?.effective_product_group_id || 0)
    }

    return Number(activeGroup.value?.effective_product_group_id || 0)
  })
  const showMobileTypePicker = computed(() => false)
  const shouldAutoSelectProduct = computed(() => getPendingWebsiteCouponId() <= 0)
  const visibleProducts = computed(() => {
    const categoryId = activeCatalogCategoryId.value
    return productsByGroup.value[categoryId] || []
  })

  function getChildCategoryId(childId) {
    return Number(childGroups.value.find((group) => group.id === childId)?.effective_product_group_id || 0)
  }

  function hasDisplayPrice(product) {
    const pricingEntries = Array.isArray(product?.pricing_entries) ? product.pricing_entries : []
    if (pricingEntries.some((item) => Number(item?.amount || 0) > 0)) {
      return true
    }

    return Number(product?.primary_price || 0) > 0
  }

  function shouldSyncRoute(options = {}) {
    return options.syncRoute !== false
  }

  function withSuspendedRouteSync(task) {
    routeSyncSuspendCount += 1

    return Promise.resolve(task()).finally(() => {
      routeSyncSuspendCount = Math.max(routeSyncSuspendCount - 1, 0)
    })
  }

  async function syncSelectionRoute() {
    if (routeSyncSuspendCount > 0) {
      return
    }

    const targetPath = buildWebsiteProductPath({
      typeId: activeTypeId.value,
      groupId: activeGroupId.value,
      childGroupId: activeChildId.value,
      productId: selectedProductId.value,
    })

    if (route.path === targetPath) {
      return
    }

    await router.replace({
      path: targetPath,
      query: route.query,
    })
  }

  function resetSelectedProduct(options = {}) {
    selectedProductId.value = 0
    onResetSelection?.()

    if (shouldSyncRoute(options)) {
      void syncSelectionRoute()
    }
  }

  function selectProduct(id, options = {}) {
    if (selectedProductId.value === id) {
      onProductSelect?.(id, { refreshStockOnly: true })

      if (shouldSyncRoute(options)) {
        void syncSelectionRoute()
      }

      return
    }

    selectedProductId.value = id
    onProductSelect?.(id)

    if (shouldSyncRoute(options)) {
      void syncSelectionRoute()
    }
  }

  function syncDefaultProduct(products = [], options = {}) {
    const targetProductId = Number(options.targetProductId || 0)

    if (!products.length) {
      resetSelectedProduct({ syncRoute: shouldSyncRoute(options) })
      return
    }

    if (targetProductId > 0) {
      const matchedProduct = products.find((item) => item.id === targetProductId)
      if (matchedProduct) {
        selectProduct(matchedProduct.id, { syncRoute: shouldSyncRoute(options) })
        return
      }
    }

    if (!shouldAutoSelectProduct.value) {
      resetSelectedProduct({ syncRoute: shouldSyncRoute(options) })
      return
    }

    selectProduct(products[0].id, { syncRoute: shouldSyncRoute(options) })
  }

  function enterMobileType(value) {
    mobileTypeEntered.value = true
    mobileCategoryDrawer.value = false
    return switchType(value, { syncRoute: true })
  }

  function returnToMobileTypePicker() {
    mobileTypeEntered.value = false
    mobileCategoryDrawer.value = false
    mobileProductDrawer.value = false
  }

  function openMobileCategoryDrawer() {
    if (!rootGroups.value.length) {
      return
    }

    mobileCategoryDrawer.value = true
  }

  function openMobileRegionDrawer() {
    if (!rootGroups.value.length) {
      return
    }

    tempGroupId.value = activeGroupId.value
    tempChildGroups.value = [...childGroups.value]
    mobileRegionDrawer.value = true
  }

  async function selectTempGroup(id) {
    if (tempGroupId.value === id) {
      return
    }

    tempGroupId.value = id
    tempChildGroups.value = []

    const cached = getCachedCatalog(id)
    if (cached) {
      tempChildGroups.value = cached.children || []
      return
    }

    try {
      const data = await siteApi.productGroupCatalog(id).then((res) => res.data)
      setCachedCatalog(id, data)
      tempChildGroups.value = data.children || []
    } catch {
      // 静默处理
    }
  }

  function handleRegionChange(groupId) {
    return selectTempGroup(groupId)
  }

  async function confirmRegionSelection(groupId, childId) {
    mobileRegionDrawer.value = false
    const targetGroupId = groupId || tempGroupId.value
    await withSuspendedRouteSync(async () => {
      await switchGroup(targetGroupId, {
        syncRoute: false,
        targetChildId: childId || tempChildGroups.value.find((g) => g.id === (childId || 0))?.id || 0,
        targetProductId: selectedProductId.value,
      })
    })
    await syncSelectionRoute()
  }

  function handleMobileGroupSelect(id) {
    mobileCategoryDrawer.value = false
    return switchGroup(id, { syncRoute: true })
  }

  async function openMobileProductDrawer() {
    if (
      activeGroupId.value > 0
      && visibleProducts.value.length
      && visibleProducts.value.every((product) => !hasDisplayPrice(product))
    ) {
      invalidateCatalogCache(activeGroupId.value)
      await loadGroupPayload(activeGroupId.value, {
        syncRoute: false,
        targetChildId: activeChildId.value,
        targetProductId: selectedProductId.value,
      })
    }

    mobilePendingProductId.value = selectedProductId.value || visibleProducts.value[0]?.id || 0
    mobileProductDrawer.value = true
  }

  function confirmMobileProductSelection() {
    if (mobilePendingProductId.value) {
      selectProduct(mobilePendingProductId.value, { syncRoute: true })
    }

    mobileProductDrawer.value = false
  }

  async function switchGroup(id, options = {}) {
    activeGroupId.value = id
    activeChildId.value = 0
    resetSelectedProduct({ syncRoute: false })
    mobileProductDrawer.value = false
    await loadGroupPayload(id, options)
  }

  function switchChild(id, options = {}) {
    activeChildId.value = id
    resetSelectedProduct({ syncRoute: false })
    mobileProductDrawer.value = false

    const products = productsByGroup.value[getChildCategoryId(id)] || []
    syncDefaultProduct(products, options)
  }

  async function switchType(value, options = {}) {
    activeTypeValue.value = value
    activeGroupId.value = 0
    activeChildId.value = 0
    resetSelectedProduct({ syncRoute: false })
    productsByGroup.value = {}
    childGroups.value = []
    mobileCategoryDrawer.value = false
    mobileProductDrawer.value = false
    if (options.enterMobile !== false && isMobile.value) {
      mobileTypeEntered.value = true
    }

    await loadRootGroups(options)
  }

  async function loadRootGroups(options = {}) {
    pageLoading.value = true

    try {
      const res = await siteApi.productGroups({ first_product_group_code: activeTypeValue.value || undefined })
      rootGroups.value = filterGroupsByType(res.data.list || [], activeTypeValue.value)

      if (rootGroups.value.length) {
        const targetGroupId = Number(options.targetGroupId || 0)
        const matchedGroup = rootGroups.value.find((group) => group.id === targetGroupId)
        const nextGroupId = matchedGroup?.id || rootGroups.value[0].id
        activeGroupId.value = nextGroupId
        await loadGroupPayload(nextGroupId, options)
      } else {
        activeGroupId.value = 0
        activeChildId.value = 0
        productsByGroup.value = {}
        childGroups.value = []
        resetSelectedProduct({ syncRoute: shouldSyncRoute(options) })
      }
    } finally {
      pageLoading.value = false
    }
  }

  async function loadGroupPayload(groupId, options = {}) {
    const token = ++groupToken
    groupAbortController?.abort()
    groupAbortController = new AbortController()

    const cached = getCachedCatalog(groupId)
    if (cached) {
      applyGroupPayload(cached, options)
      return
    }

    const pending = catalogPendingMap.get(groupId)
    if (pending) {
      try {
        const data = await pending
        if (token !== groupToken) {
          return
        }

        applyGroupPayload(data, options)
      } catch {
        // 由发起请求的调用方处理错误
      }

      return
    }

    pageLoading.value = true
    const controller = groupAbortController

    const request = siteApi.productGroupCatalog(groupId, {
      signal: controller.signal,
    }).then((res) => res.data)

    catalogPendingMap.set(groupId, request)

    try {
      const data = await request
      if (token !== groupToken) {
        return
      }

      setCachedCatalog(groupId, data)
      applyGroupPayload(data, options)
    } catch (error) {
      if (error?.code === 'ERR_CANCELED' || error?.name === 'CanceledError') {
        return
      }

      throw error
    } finally {
      catalogPendingMap.delete(groupId)
      if (token === groupToken) {
        pageLoading.value = false
      }
    }
  }

  function applyGroupPayload(data, options = {}) {
    const children = data.children || []
    childGroups.value = children

    const map = {}
    ;(data.items_by_group || []).forEach((item) => {
      const groupId = Number(item.effective_product_group_id || 0)
      if (groupId > 0) {
        map[groupId] = item.products || []
      }
    })
    productsByGroup.value = map

    if (children.length) {
      const targetChildId = Number(options.targetChildId || 0)
      const matchedChild = children.find((item) => item.id === targetChildId)
      const nextChildId = matchedChild?.id || children[0].id
      activeChildId.value = nextChildId
      const activeChildCategoryId = Number(
        (matchedChild?.effective_product_group_id || children[0]?.effective_product_group_id || 0),
      )
      const products = map[activeChildCategoryId] || []
      syncDefaultProduct(products, options)
    } else {
      activeChildId.value = 0
      const rootCategoryId = Number(data.effective_product_group_id || activeGroup.value?.effective_product_group_id || 0)
      const products = map[rootCategoryId] || []
      syncDefaultProduct(products, options)
    }
  }

  async function applyRouteSelection() {
    const routePayload = readWebsiteProductRouteParams(route)

    if (!hasWebsiteProductRouteParams(routePayload) || !productTypes.value.length) {
      return false
    }

    const targetType = productTypes.value.find((item) => Number(item.id) === routePayload.typeId)
    const nextTypeValue = targetType?.value || activeTypeValue.value || productTypes.value[0]?.value || ''

    if (!nextTypeValue) {
      return false
    }

    await withSuspendedRouteSync(async () => {
      if (isMobile.value) {
        mobileTypeEntered.value = true
      }

      if (activeTypeValue.value !== nextTypeValue || !rootGroups.value.length) {
        activeTypeValue.value = nextTypeValue
        await loadRootGroups({
          targetGroupId: routePayload.groupId,
          targetChildId: routePayload.childGroupId,
          targetProductId: routePayload.productId,
          syncRoute: false,
        })
        return
      }

      if (routePayload.groupId > 0 && activeGroupId.value !== routePayload.groupId) {
        await switchGroup(routePayload.groupId, {
          targetChildId: routePayload.childGroupId,
          targetProductId: routePayload.productId,
          syncRoute: false,
        })
        return
      }

      if (routePayload.childGroupId > 0 && activeChildId.value !== routePayload.childGroupId) {
        switchChild(routePayload.childGroupId, {
          targetProductId: routePayload.productId,
          syncRoute: false,
        })
        return
      }

      if (routePayload.productId > 0 && selectedProductId.value !== routePayload.productId) {
        const matchedProduct = visibleProducts.value.find((item) => item.id === routePayload.productId)

        if (matchedProduct) {
          selectProduct(matchedProduct.id, { syncRoute: false })
        } else if (activeGroupId.value > 0) {
          await loadGroupPayload(activeGroupId.value, {
            targetChildId: routePayload.childGroupId,
            targetProductId: routePayload.productId,
            syncRoute: false,
          })
        }
      }
    })

    await syncSelectionRoute()

    return true
  }

  async function init() {
    pageLoading.value = true

    try {
      const linked = await initWithAggregatedApi()

      if (!linked && productTypes.value.length && !isMobile.value) {
        if (!rootGroups.value.length) {
          activeTypeValue.value = productTypes.value[0].value
          await loadRootGroups({ syncRoute: true })
        } else {
          void syncSelectionRoute()
        }
      }
    } finally {
      pageLoading.value = false
    }
  }

  async function initWithAggregatedApi() {
    const routePayload = readWebsiteProductRouteParams(route)
    const hasRouteTarget = hasWebsiteProductRouteParams(routePayload)

    try {
      const res = await siteApi.productsInit()
      const data = res.data || {}
      productTypes.value = data.types || []

      if (!productTypes.value.length) {
        return false
      }

      if (hasRouteTarget) {
        const linked = await applyRouteSelection()
        return linked
      }

      const types = productTypes.value
      const firstTypeValue = types[0]?.value || ''
      activeTypeValue.value = firstTypeValue
      rootGroups.value = filterGroupsByType(data.root_groups || [], firstTypeValue)

      if (rootGroups.value.length && data.catalog && catalogBelongsToGroup(data.catalog, rootGroups.value[0].id)) {
        const firstGroupId = rootGroups.value[0].id
        activeGroupId.value = firstGroupId
        setCachedCatalog(firstGroupId, data.catalog)
        applyGroupPayload(data.catalog, { syncRoute: true })
      } else if (rootGroups.value.length) {
        activeGroupId.value = rootGroups.value[0].id
        await loadGroupPayload(rootGroups.value[0].id, { syncRoute: true })
      }

      return true
    } catch {
      const res = await siteApi.productTypes()
      productTypes.value = res.data.list || []
      return false
    }
  }

  function updateMobileState() {
    if (typeof window === 'undefined') {
      return
    }

    isMobile.value = window.innerWidth <= MOBILE_BREAKPOINT
  }

  watch(isMobile, async (mobile) => {
    if (mobile) {
      mobileTypeEntered.value = Boolean(activeTypeValue.value)
      return
    }

    mobileCategoryDrawer.value = false
    mobileProductDrawer.value = false
    mobileTypeEntered.value = false

    if (!activeTypeValue.value && productTypes.value.length) {
      activeTypeValue.value = productTypes.value[0].value
      await loadRootGroups({ syncRoute: true })
    }
  })

  watch(
    () => [
      route.params.typeId,
      route.params.groupId,
      route.params.childGroupId,
      route.params.productId,
      route.query.type,
      route.query.group,
    ],
    async () => {
      if (!productTypes.value.length) {
        return
      }

      const routePayload = readWebsiteProductRouteParams(route)

      if (hasWebsiteProductRouteParams(routePayload)) {
        const currentTypeId = activeTypeId.value
        const currentGroupId = activeGroupId.value
        const currentChildId = activeChildId.value
        const currentProductId = selectedProductId.value

        const changed = routePayload.typeId !== currentTypeId
          || routePayload.groupId !== currentGroupId
          || routePayload.childGroupId !== currentChildId
          || (routePayload.productId > 0 && routePayload.productId !== currentProductId)

        if (changed) {
          await applyRouteSelection()
        }

        return
      }

      if (route.path === '/products' && isMobile.value) {
        mobileTypeEntered.value = false
      }
    }
  )

  onMounted(() => {
    updateMobileState()
    window.addEventListener('resize', updateMobileState)
    init()
  })

  onBeforeUnmount(() => {
    groupAbortController?.abort()
    window.removeEventListener('resize', updateMobileState)
  })

  return {
    pageLoading,
    sidebarCollapsed,
    isMobile,
    mobileTypeEntered,
    mobileCategoryDrawer,
    mobileProductDrawer,
    mobileRegionDrawer,
    tempGroupId,
    tempChildGroups,
    mobilePendingProductId,
    productTypes,
    rootGroups,
    childGroups,
    activeTypeValue,
    activeGroupId,
    activeChildId,
    selectedProductId,
    activeTypeLabel,
    activeGroupName,
    activeChildName,
    showMobileTypePicker,
    visibleProducts,
    enterMobileType,
    returnToMobileTypePicker,
    openMobileCategoryDrawer,
    openMobileRegionDrawer,
    selectTempGroup,
    handleRegionChange,
    confirmRegionSelection,
    handleMobileGroupSelect,
    openMobileProductDrawer,
    confirmMobileProductSelection,
    switchType,
    switchGroup,
    switchChild,
    selectProduct,
  }
}
