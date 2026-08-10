<template>
  <div class="home-page">
    <HomeHeroCarousel v-if="homeHeroReady" :hero="homeHero || {}" />
    <HomeSectionSkeleton v-else type="hero" />

    <HomeProductTabs
      v-if="productContentReady"
      :loading="loading"
      :product-types="productTypes"
      :root-groups="rootGroups"
      :group-catalog-map="groupCatalogMap"
    />
    <HomeSectionSkeleton v-else type="products" />

    <template v-if="homeContentReady && mountDeferredSections">
      <HomeSolutionSection />
      <HomePartnerSection />
      <HomeNewsSection :notices="notices" />
      <HomeRegisterBar />
    </template>
    <template v-else>
      <HomeSectionSkeleton type="solutions" />
      <HomeSectionSkeleton type="partner" />
      <HomeSectionSkeleton type="news" />
      <HomeSectionSkeleton type="register" />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, ref } from 'vue'
import siteApi from '@/api/site'
import { useAppStore } from '@/stores/app'
import HomeHeroCarousel from '@/views/website/Home/components/HomeHeroCarousel.vue'
import HomeProductTabs from '@/views/website/Home/components/HomeProductTabs.vue'
import HomeSectionSkeleton from '@/views/website/Home/components/HomeSectionSkeleton.vue'

const HomeSolutionSection = defineAsyncComponent(() => import('@/views/website/Home/components/HomeSolutionSection.vue'))
const HomePartnerSection = defineAsyncComponent(() => import('@/views/website/Home/components/HomePartnerSection.vue'))
const HomeNewsSection = defineAsyncComponent(() => import('@/views/website/Home/components/HomeNewsSection.vue'))
const HomeRegisterBar = defineAsyncComponent(() => import('@/views/website/Home/components/HomeRegisterBar.vue'))

interface ProductTypeItem {
  id?: number
  value?: string
  label?: string
  product_count?: number
}

const appStore = useAppStore()
const loading = ref(true)
const homeLoaded = ref(false)
const homeLoadSucceeded = ref(false)
const mountDeferredSections = ref(false)
const homeHero = ref<Record<string, unknown> | null>(null)
const notices = ref<any[]>([])
const rootGroups = ref<any[]>([])
const groupCatalogMap = ref<Record<string, unknown>>({})
const productTypes = ref<ProductTypeItem[]>([])
const homeContentReady = computed(() => homeLoaded.value && homeLoadSucceeded.value)
const homeHeroReady = computed(() => {
  const hero = homeHero.value
  const slides = hero && typeof hero === 'object' ? hero.slides : null

  return homeContentReady.value && Array.isArray(slides) && slides.length > 0
})
const productContentReady = computed(() => homeContentReady.value && rootGroups.value.length > 0)

function deriveProductTypesFromGroups(groups: any[]) {
  const map = new Map<string, ProductTypeItem>()

  groups.forEach((group, index) => {
    const value = String(group?.first_product_group_code || '')
    if (!value) {
      return
    }

    const current = map.get(value) || {
      id: Number(group?.product_type_id || index + 1),
      value,
      label: String(group?.first_product_group_name || `产品分类 ${index + 1}`),
      product_count: 0,
    }

    current.product_count = Number(current.product_count || 0) + Number(group?.product_count || 0)
    map.set(value, current)
  })

  return Array.from(map.values())
}

async function loadHomePage() {
  loading.value = true
  homeLoaded.value = false
  homeLoadSucceeded.value = false

  try {
    // 优化：移除 home-hero 请求，hero 数据已包含在 home 响应中
    const [homeRes, typeRes] = await Promise.allSettled([
      siteApi.home(),
      siteApi.productTypes(),
    ])

    if (homeRes.status === 'fulfilled') {
      homeLoadSucceeded.value = true
      const data = homeRes.value.data || {}
      notices.value = Array.isArray(data.notices) ? data.notices : []
      rootGroups.value = Array.isArray(data.root_groups) ? data.root_groups : []
      groupCatalogMap.value = data.group_catalog_map && typeof data.group_catalog_map === 'object'
        ? data.group_catalog_map
        : {}

      // 从 home 响应中提取 hero 数据
      homeHero.value = data.hero || {}

      if (data.site_config) {
        appStore.hydrateSiteConfig(data.site_config)
      }
    }

    if (typeRes.status === 'fulfilled') {
      productTypes.value = Array.isArray(typeRes.value.data?.list) ? typeRes.value.data.list : []
    }

    if (!productTypes.value.length) {
      productTypes.value = deriveProductTypesFromGroups(rootGroups.value)
    }
  } finally {
    homeLoaded.value = true
    loading.value = false
  }
}

onMounted(async () => {
  await loadHomePage()

  if (!homeLoadSucceeded.value) {
    return
  }

  if (typeof window !== 'undefined') {
    window.requestAnimationFrame(() => {
      mountDeferredSections.value = true
    })
    return
  }

  mountDeferredSections.value = true
})
</script>
