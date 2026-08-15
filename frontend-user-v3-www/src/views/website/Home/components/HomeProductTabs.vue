<template>
  <section class="product-tabs-section" :aria-busy="loading ? 'true' : 'false'">
    <div class="container">
      <header class="product-tabs__head">
        <h2 class="product-tabs__title">安全、稳定、可信赖的产品与服务</h2>
        <p class="product-tabs__desc">
          {{ appStore.siteName }} 的技术积淀与交付实践，助力企业上云与产业互联网。
          <router-link to="/products" class="product-tabs__more">
            查看全部产品
            <el-icon><ArrowRight /></el-icon>
          </router-link>
        </p>
      </header>

      <div class="product-tabs__list" role="tablist" aria-label="产品分类">
        <button
          v-for="item in enterpriseServiceItems"
          :key="item.key"
          type="button"
          role="tab"
          class="product-tabs__tab"
          :class="{ 'is-active': item.key === activeEnterpriseServiceKey }"
          :aria-selected="item.key === activeEnterpriseServiceKey"
          :tabindex="item.key === activeEnterpriseServiceKey ? 0 : -1"
          @click="activateEnterpriseService(item.key)"
        >
          {{ item.label }}
        </button>
      </div>

      <div class="product-tabs__grid">
        <button
          v-for="card in activeEnterpriseService.cards"
          :key="`${activeEnterpriseService.key}-${card.key}`"
          type="button"
          class="product-card"
          @click="router.push(card.path)"
        >
          <strong class="product-card__title">{{ card.title }}</strong>
          <p class="product-card__desc">{{ card.desc }}</p>
          <span class="product-card__cta">
            立即选购
            <el-icon><ArrowRight /></el-icon>
          </span>
        </button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, shallowRef, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElIcon } from 'element-plus/es/components/icon/index.mjs'
import { ArrowRight } from '@element-plus/icons-vue'
import { useAppStore } from '@/stores/app'
import { buildWebsiteProductPath } from '@/utils/productRoute'
import { resolveProductDisplayName } from '@/utils/websiteProductConfig'

const EMPTY_LIST = Object.freeze([])

const props = defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
  productTypes: {
    type: Array,
    default: () => [],
  },
  rootGroups: {
    type: Array,
    default: () => [],
  },
  groupCatalogMap: {
    type: Object,
    default: () => ({}),
  },
})

const router = useRouter()
const appStore = useAppStore()
const activeEnterpriseServiceKey = shallowRef('')
const resolvedProductTypes = computed(() => {
  if (props.productTypes.length) {
    return props.productTypes
  }

  return deriveProductTypesFromGroups(props.rootGroups)
})

const activeEnterpriseType = computed(() => (
  resolvedProductTypes.value.find((item) => item.value === activeEnterpriseServiceKey.value)
    || resolvedProductTypes.value[0]
    || null
))

const activeEnterpriseGroups = computed(() => {
  const activeTypeValue = activeEnterpriseType.value?.value || ''
  return props.rootGroups.filter((group) => (
    activeTypeValue === '' || group.first_product_group_code === activeTypeValue
  ))
})

const activeEnterpriseCards = computed(() => (
  activeEnterpriseGroups.value.map((group, index) => {
    const groupId = Number(group?.id || 0)
    const summary = props.groupCatalogMap[groupId] || null
    const primaryProduct = resolveEnterprisePrimaryProduct(group, summary)

    return {
      key: String(groupId || index + 1),
      title: group?.name || `二级分类 ${index + 1}`,
      desc: resolveEnterpriseCategoryDesc(group, summary, primaryProduct),
      path: resolveEnterpriseCategoryPath(group, primaryProduct),
    }
  })
))

const activeEnterpriseService = computed(() => {
  const cards = activeEnterpriseCards.value.length
    ? activeEnterpriseCards.value
    : [
      {
        key: 'catalog',
        title: `${activeEnterpriseType.value?.label || '当前分类'}目录`,
        desc: '当前分类暂无可展示的二级分类，点击后可进入产品目录查看更多内容。',
        path: '/products',
      },
    ]

  return {
    key: activeEnterpriseType.value?.value || '',
    cards,
  }
})

const enterpriseServiceItems = computed(() => (
  resolvedProductTypes.value.map((type, index) => ({
    key: type.value || String(Number(type.id || index + 1)),
    id: Number(type.id || index + 1),
    label: type.label || `产品分类 ${index + 1}`,
  }))
))

function deriveProductTypesFromGroups(groups) {
  if (!groups.length) {
    return EMPTY_LIST
  }

  const map = new Map()

  groups.forEach((group, index) => {
    const value = String(group?.first_product_group_code || '')

    if (!value) {
      return
    }

    const current = map.get(value) || {
      id: Number(group?.product_type_id || index + 1),
      value,
      label: group?.first_product_group_name || `产品分类 ${index + 1}`,
      group_count: 0,
      product_count: 0,
    }

    current.group_count += 1
    current.product_count += Number(group?.product_count || 0)
    map.set(value, current)
  })

  return Array.from(map.values())
}

function activateEnterpriseService(key) {
  activeEnterpriseServiceKey.value = String(key)
}

function resolveEnterpriseCategoryDesc(group, summary, primaryProduct) {
  const featuredName = resolveProductDisplayName(summary?.featured_product)
  if (featuredName) {
    return `主推 ${featuredName}，可在线查看配置并快速完成选型。`
  }

  const previewName = resolveProductDisplayName(primaryProduct?.product)
  if (previewName) {
    return `主推 ${previewName}，可在线查看配置并快速完成选型。`
  }

  if (group?.slogan) {
    return group.slogan
  }

  const totalProducts = Number(group?.product_count || 0)
  return `${group?.product_type_label || '当前分类'} 下已上架 ${totalProducts} 款在售产品，可按业务需求快速选型。`
}

function resolveEnterpriseCategoryPath(group, primaryProduct) {
  if (!primaryProduct?.product) {
    const typeCode = String(group?.first_product_group_code || '')
    const groupId = Number(group?.id || 0)

    if (typeCode && groupId > 0) {
      return {
        path: '/products',
        query: {
          type: typeCode,
          group: String(groupId),
        },
      }
    }

    return '/products'
  }

  return buildWebsiteProductPath({
    typeId: String(group?.first_product_group_code || ''),
    groupId: Number(group?.id || 0),
    childGroupId: Number(primaryProduct.childGroupId || 0),
    productId: Number(primaryProduct.product?.id || 0),
  })
}

function resolveEnterprisePrimaryProduct(group, summary) {
  const featuredProduct = summary?.featured_product && typeof summary.featured_product === 'object'
    ? summary.featured_product
    : null

  if (featuredProduct) {
    const productGroupId = Number(featuredProduct?.effective_product_group_id || 0)
    return {
      product: featuredProduct,
      childGroupId: productGroupId > 0 && productGroupId !== Number(group?.id || 0) ? productGroupId : 0,
    }
  }

  const previewProduct = Array.isArray(summary?.preview_products) ? summary.preview_products[0] : null

  if (previewProduct && typeof previewProduct === 'object') {
    const productGroupId = Number(previewProduct?.effective_product_group_id || 0)
    return {
      product: previewProduct,
      childGroupId: productGroupId > 0 && productGroupId !== Number(group?.id || 0) ? productGroupId : 0,
    }
  }

  return null
}

watch(
  () => enterpriseServiceItems.value.map((item) => item.key).join('|'),
  () => {
    if (!enterpriseServiceItems.value.length) {
      activeEnterpriseServiceKey.value = ''
      return
    }

    const currentExists = enterpriseServiceItems.value.some((item) => item.key === activeEnterpriseServiceKey.value)

    if (!currentExists) {
      activeEnterpriseServiceKey.value = enterpriseServiceItems.value[0].key
    }
  },
  { immediate: true },
)

</script>

<style scoped lang="scss">
.container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.product-tabs-section {
  position: relative;
  padding: 72px 0 80px;
  background: #ffffff;
}

.product-tabs__head {
  text-align: center;
}

.product-tabs__title {
  margin: 0;
  color: #0f172a;
  font-size: clamp(26px, 2.4vw, 30px);
  font-weight: 700;
  line-height: 1.28;
  letter-spacing: -0.01em;
}

.product-tabs__desc {
  max-width: 760px;
  margin: 12px auto 0;
  color: #5b6b82;
  font-size: 14px;
  line-height: 1.8;
}

.product-tabs__more {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-left: 8px;
  color: #2f5ef3;
  font-weight: 500;
  text-decoration: none;
  transition: color 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}

.product-tabs__more:hover {
  color: #2754e3;
}

.product-tabs__more .el-icon {
  font-size: 12px;
}

.product-tabs__list {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0 32px;
  margin: 34px auto 28px;
  border-bottom: 1px solid rgba(15, 23, 42, 0.08);
}

.product-tabs__tab {
  position: relative;
  padding: 8px 2px 16px;
  border: none;
  background: transparent;
  color: #556274;
  font-size: 15px;
  font-weight: 500;
  line-height: 1.5;
  cursor: pointer;
  transition: color 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}

.product-tabs__tab:hover {
  color: #2f5ef3;
}

.product-tabs__tab::after {
  content: "";
  position: absolute;
  left: 50%;
  bottom: -1px;
  width: 0;
  height: 2px;
  background: #2f5ef3;
  transform: translateX(-50%);
  transition: width 0.28s cubic-bezier(0.22, 1, 0.36, 1);
}

.product-tabs__tab.is-active {
  color: #2f5ef3;
  font-weight: 600;
}

.product-tabs__tab.is-active::after {
  width: 100%;
}

.product-tabs__grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
}

.product-card {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 8px;
  min-height: 100px;
  padding: 14px 20px 12px;
  border: 1px solid #e5eaf3;
  border-radius: 8px;
  background: #ffffff;
  text-align: left;
  cursor: pointer;
  appearance: none;
  overflow: hidden;
  transition:
    border-color 0.24s cubic-bezier(0.22, 1, 0.36, 1),
    box-shadow 0.24s cubic-bezier(0.22, 1, 0.36, 1),
    transform 0.24s cubic-bezier(0.22, 1, 0.36, 1);
}

.product-card:hover {
  border-color: rgba(22, 93, 255, 0.4);
  box-shadow: 0 18px 40px rgba(22, 93, 255, 0.12);
  transform: translateY(-3px);
}

.product-card__title {
  flex: 1;
  min-width: 0;
  color: #111827;
  font-size: 16px;
  font-weight: 600;
  line-height: 1.4;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.product-card__desc {
  margin: 0;
  color: #5b6b82;
  font-size: 13px;
  line-height: 1.78;
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
}

.product-card__cta {
  display: inline-flex;
  align-items: center;
  align-self: flex-end;
  gap: 4px;
  margin-top: auto;
  color: #2f5ef3;
  font-size: 13px;
  font-weight: 600;
  opacity: 0;
  transform: translateX(-4px);
  transition:
    opacity 0.24s cubic-bezier(0.22, 1, 0.36, 1),
    transform 0.24s cubic-bezier(0.22, 1, 0.36, 1);
}

.product-card:hover .product-card__cta {
  opacity: 1;
  transform: translateX(0);
}

@media (max-width: 1180px) {
  .product-tabs__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 960px) {
  .product-tabs__desc {
    font-size: 13px;
  }

  .product-tabs__tab {
    font-size: 14px;
  }
}

@media (max-width: 640px) {
  .product-tabs-section {
    padding: 48px 0 56px;
  }

  .product-tabs__title {
    font-size: 20px;
  }

  .product-tabs__grid {
    grid-template-columns: 1fr;
  }
}
</style>
