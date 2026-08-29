<template>
  <div class="content-reader-page">
    <div class="reader-breadcrumb">
      <router-link class="reader-breadcrumb__link" :to="backToListRoute">
        {{ config.pageTitle }}
      </router-link>
      <el-icon><ArrowRight /></el-icon>
      <router-link
        v-if="currentCategoryId"
        class="reader-breadcrumb__link"
        :to="categoryListRoute"
      >
        {{ currentCategoryName }}
      </router-link>
      <span v-else class="reader-breadcrumb__text">{{ currentCategoryName }}</span>
      <el-icon><ArrowRight /></el-icon>
      <span class="reader-breadcrumb__current">
        {{ currentArticle?.title || config.detailTitle }}
      </span>
    </div>

    <div v-loading="loading" class="reader-layout">
      <section class="reader-main">
        <article id="article-top" class="reader-article">
          <header class="reader-article__header">
            <h1>{{ currentArticle?.title || config.detailTitle }}</h1>
            <div v-if="currentArticle" class="reader-meta">
              <span>发布人：{{ currentPublisher }}</span>
              <span>{{ timeLabel }}：{{ currentPublishTime }}</span>
              <span>阅读量：{{ currentArticle.view_count || 0 }}</span>
            </div>
          </header>

          <el-alert
            v-if="errorText && !loading"
            :title="errorText"
            type="error"
            show-icon
            :closable="false"
            class="reader-error"
          />

          <template v-else-if="currentArticle">
            <el-divider />

            <!-- articleContentHtml 由 renderMarkdown() 产出，最后一步是 sanitizeRenderedHtml()
                 （shared/htmlSanitizer.js 的 DOM 白名单：标签、属性、URL 协议三层），
                 且渲染器重写了 validateLink 拦掉 javascript:/vbscript:/data: 协议。 -->
            <!-- eslint-disable-next-line vue/no-v-html -- 内容已过 sanitizeRenderedHtml 白名单净化 -->
            <div ref="contentRef" class="reader-content" v-html="articleContentHtml" />
          </template>

          <el-empty v-else-if="!loading" :description="config.emptyText" class="reader-empty" />
        </article>
      </section>

      <aside class="reader-sidebar">
        <section class="sidebar-card">
          <div class="sidebar-card__title">{{ config.categoryTitle }}</div>
          <div class="category-list">
            <button
              v-for="item in categories"
              :key="item.id"
              type="button"
              class="category-item"
              :class="{ 'is-active': currentCategoryId === item.id }"
              :aria-current="currentCategoryId === item.id ? 'page' : undefined"
              :aria-label="`${item.name}，共 ${item.articles_count || 0} 篇`"
              @click="goCategoryList(item.id)"
            >
              <span class="category-item__name">{{ item.name }}</span>
              <span class="category-item__count">{{ item.articles_count || 0 }}</span>
            </button>
          </div>
        </section>

        <section v-if="tocItems.length > 1" class="sidebar-card">
          <div class="sidebar-card__title">目录结构</div>
          <div class="toc-list">
            <button
              v-for="item in tocItems"
              :key="item.id"
              type="button"
              class="toc-item"
              :class="[`level-${item.level}`]"
              :aria-label="`跳转到 ${item.label}`"
              @click="scrollToAnchor(item.id)"
            >
              {{ item.label }}
            </button>
          </div>
        </section>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight } from '@element-plus/icons-vue'
import siteApi from '@/api/site'
import { renderMarkdown } from '@/utils/markdown'
import { updatePageMeta } from '@/utils/pageMeta'
import { rewriteApiAssetUrlsInHtml } from '@/utils/apiAssetUrl'
import { getContentConfig } from './contentConfig'

// ---- 模块级分类缓存 ----
const overviewCache = new Map()
const OVERVIEW_CACHE_TTL = 5 * 60 * 1000 // 5 分钟

function getCachedOverview(cacheKey) {
  const entry = overviewCache.get(cacheKey)
  if (entry && Date.now() - entry.ts < OVERVIEW_CACHE_TTL) {
    return entry.data
  }
  overviewCache.delete(cacheKey)
  return null
}

function setCachedOverview(cacheKey, data) {
  overviewCache.set(cacheKey, { data, ts: Date.now() })
}

// ---- 工具函数 ----

function sanitizeMarkdownSource(content) {
  if (!content) return ''
  return String(content)
    .replace(/\*\*\s*(<[^>]+>)/g, '$1')
    .replace(/(<\/[^>]+>)\s*\*\*/g, '$1')
}

const props = defineProps({
  contentType: {
    type: String,
    required: true,
  },
  scope: {
    type: String,
    default: 'client',
  },
})

const route = useRoute()
const router = useRouter()
const apiBaseUrl = String(import.meta.env.VITE_API_BASE_URL || '')
const api = computed(() => siteApi)
const config = computed(() => getContentConfig(props.contentType, props.scope))

const loading = ref(false)
const errorText = ref('')
const categories = ref([])
const currentArticle = ref(null)
const currentCategoryId = ref(null)
const tocItems = ref([{ id: 'article-top', label: '全文', level: 1 }])
const contentRef = ref(null)

const backToListRoute = computed(() => {
  const query = {}
  if (route.query.category) query.category = route.query.category
  if (route.query.keyword) query.keyword = route.query.keyword
  if (route.query.page) query.page = route.query.page
  return {
    path: config.value.routeBasePath,
    query: Object.keys(query).length ? query : undefined,
  }
})

const categoryListRoute = computed(() => {
  if (!currentCategoryId.value) return backToListRoute.value
  return {
    path: config.value.routeBasePath,
    query: { category: currentCategoryId.value },
  }
})

const timeLabel = computed(() => (
  props.contentType === 'help' ? '更新时间' : '发布时间'
))

const currentCategoryName = computed(() => {
  const matched = categories.value.find((item) => item.id === currentCategoryId.value)
  return matched?.name || currentArticle.value?.category_name || '未分类'
})

const currentPublisher = computed(() => (
  currentArticle.value?.creator?.nickname
  || currentArticle.value?.creator?.username
  || currentArticle.value?.operator
  || '官方客服'
))

const currentPublishTime = computed(() => (
  currentArticle.value?.updated_at
  || currentArticle.value?.last_published_at
  || currentArticle.value?.publish_at
  || currentArticle.value?.created_at
  || '--'
))

const articleContentHtml = computed(() => {
  const source = sanitizeMarkdownSource(currentArticle.value?.content || '')
  const rendered = renderMarkdown(source, {
    imageAltFallback: currentArticle.value?.title || config.value.detailTitle || '相关配图',
  })
  return rewriteApiAssetUrlsInHtml(rendered, apiBaseUrl)
})

function plainTextSummary(value, maxLength = 160) {
  return String(value || '')
    .replace(/```[\s\S]*?```/g, ' ')
    .replace(/`[^`]*`/g, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/[>#*_\-[\]()!]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, maxLength)
}

function currentCanonicalUrl() {
  if (typeof window === 'undefined') return ''
  return new URL(route.path, window.location.origin).toString()
}

function syncArticleMeta(article) {
  if (!article) return
  const title = String(article.title || config.value.detailTitle || '').trim()
  const description = plainTextSummary(article.summary || article.excerpt || article.content || config.value.description)
  updatePageMeta({
    title: `${title} - ${config.value.pageTitle}`,
    description,
    keywords: [article.category_name, title, config.value.pageTitle].filter(Boolean).join(','),
    canonical: currentCanonicalUrl(),
    structuredData: {
      '@context': 'https://schema.org',
      '@type': 'Article',
      headline: title,
      description,
      datePublished: article.publish_at || article.created_at || undefined,
      dateModified: article.updated_at || undefined,
    },
  })
}

async function loadOverview() {
  const cacheKey = config.value.overviewCategoryKey
  const cached = getCachedOverview(cacheKey)
  if (cached) {
    categories.value = cached
    return
  }
  const res = await api.value.contentOverview()
  const data = res.data?.[cacheKey] || []
  categories.value = data
  setCachedOverview(cacheKey, data)
}

async function loadArticleDetail(articleId) {
  const res = await api.value[config.value.apiDetailMethod](articleId)
  currentArticle.value = res.data || null
  currentCategoryId.value = Number(res.data?.category_id || 0) || null
  syncArticleMeta(currentArticle.value)
}

async function syncPage() {
  loading.value = true
  errorText.value = ''
  currentArticle.value = null
  currentCategoryId.value = null
  tocItems.value = [{ id: 'article-top', label: '全文', level: 1 }]

  try {
    await Promise.all([
      loadOverview(),
      loadArticleDetail(route.params.id),
    ])
  } catch (error) {
    currentArticle.value = null
    currentCategoryId.value = null
    errorText.value = error?.response?.data?.message || error?.message || `${config.value.detailTitle}加载失败，请稍后重试`
  } finally {
    loading.value = false
  }
}

function goCategoryList(categoryId) {
  router.push({
    path: config.value.routeBasePath,
    query: {
      category: categoryId,
    },
  })
}

function scrollToAnchor(anchorId) {
  if (typeof document === 'undefined') {
    return
  }

  document.getElementById(anchorId)?.scrollIntoView({
    behavior: 'smooth',
    block: 'start',
  })
}

function buildToc() {
  const container = contentRef.value

  if (!container) {
    tocItems.value = [{ id: 'article-top', label: '全文', level: 1 }]
    return
  }

  const headings = [...container.querySelectorAll('h1, h2, h3, h4')]
  const items = [{ id: 'article-top', label: '全文', level: 1 }]

  headings.forEach((heading, index) => {
    const text = heading.textContent?.trim()

    if (!text) {
      return
    }

    const id = `${props.contentType}-heading-${currentArticle.value?.id || 'current'}-${index + 1}`
    heading.id = id
    items.push({
      id,
      label: text,
      level: Number.parseInt(heading.tagName.slice(1), 10) || 2,
    })
  })

  tocItems.value = items
}

watch(
  () => route.params.id,
  () => {
    syncPage()
  },
  { immediate: true },
)

watch(
  () => articleContentHtml.value,
  async () => {
    await nextTick()
    buildToc()
  },
)

</script>

<style scoped lang="scss">
.content-reader-page {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding-top: 24px;
}

.reader-breadcrumb {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  color: $text-color-placeholder;
  font-size: 13px;
  line-height: 1.5;

  .el-icon {
    color: $text-color-disabled;
    font-size: 12px;
  }
}

.reader-breadcrumb__link {
  color: $text-color-secondary;
  text-decoration: none;

  &:hover {
    color: $color-primary;
  }
}

.reader-breadcrumb__text {
  color: $text-color-secondary;
}

.reader-breadcrumb__current {
  color: $text-color-primary;
}

.reader-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 20px;
  align-items: start;
}

.reader-error {
  margin-top: 18px;
}

.reader-main {
  min-width: 0;
}

.reader-article,
.sidebar-card {
  border: 1px solid $border-color;
  background: $bg-color-card;
  box-shadow: $shadow-sm;
}

.reader-article {
  padding: 22px 20px 28px;
}

.reader-article__header h1 {
  color: $text-color-primary;
  font-size: 24px;
  font-weight: 700;
  line-height: 1.35;
}

.reader-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 24px;
  margin-top: 18px;
  padding: 12px 14px;
  border: 1px solid $color-primary-border;
  background: $color-primary-soft;
  color: $text-color-secondary;
  font-size: 13px;

  span {
    color: $text-color-primary;
    font-weight: 500;
  }
}

.reader-content {
  color: $text-color-primary;
  font-size: 15px;
  line-height: 2;
  word-break: break-word;

  :deep(h1),
  :deep(h2),
  :deep(h3),
  :deep(h4) {
    margin: 26px 0 12px;
    color: $text-color-primary;
    font-weight: 700;
    line-height: 1.45;
  }

  :deep(p) {
    margin-bottom: 16px;
  }

  :deep(ul),
  :deep(ol) {
    margin: 0 0 16px;
    padding-left: 22px;
  }

  :deep(li) {
    margin-bottom: 8px;
  }

  :deep(a) {
    color: $color-primary;
  }

  :deep(pre) {
    margin-bottom: 16px;
    padding: 16px;
    overflow-x: auto;
    background: $bg-color-soft;
    color: $text-color-primary;
  }

  :deep(code) {
    padding: 2px 6px;
    background: $bg-color-soft;
    color: $color-primary;
  }

  :deep(blockquote) {
    margin: 0 0 16px;
    padding: 12px 16px;
    border-left: 4px solid $color-primary-border;
    background: $bg-color-soft;
    color: $text-color-secondary;
  }

  :deep(table) {
    width: 100%;
    margin-bottom: 16px;
    border-collapse: collapse;
  }

  :deep(th),
  :deep(td) {
    padding: 10px 12px;
    border: 1px solid $divider-color;
    text-align: left;
  }

  :deep(th) {
    background: $bg-color-soft;
  }

  :deep(img) {
    max-width: 100%;
    height: auto;
  }
}

.reader-empty {
  border: 1px solid $border-color;
  background: $bg-color-card;
  padding: 48px 20px;
}

.reader-sidebar {
  display: grid;
  gap: 16px;
}

.sidebar-card {
  padding: 18px;
}

.sidebar-card__title {
  padding-bottom: 12px;
  border-bottom: 1px solid $divider-color;
  color: $text-color-primary;
  font-size: 16px;
  font-weight: 600;
}

.category-list,
.toc-list {
  display: grid;
  gap: 8px;
  margin-top: 16px;
}

.category-item,
.toc-item {
  width: 100%;
  border: 1px solid transparent;
  background: transparent;
  text-align: left;
  cursor: pointer;
  transition:
    color $motion-fast ease,
    border-color $motion-fast ease,
    background-color $motion-fast ease;
}

.category-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px;
  color: $text-color-secondary;

  &:hover,
  &.is-active {
    border-color: $color-primary-border;
    background: $color-primary-soft;
    color: $color-primary;
  }
}

.category-item__name {
  min-width: 0;
  flex: 1;
}

.category-item__count {
  padding: 1px 8px;
  background: $bg-color-card;
  color: $text-color-placeholder;
  font-size: 12px;
}

.toc-item {
  padding: 8px 0;
  color: $text-color-secondary;
  font-size: 13px;
  line-height: 1.6;

  &:hover {
    color: $color-primary;
  }

  &.level-3 {
    padding-left: 12px;
  }

  &.level-4 {
    padding-left: 24px;
  }
}

@media (max-width: 1200px) {
  .reader-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .reader-article {
    padding: 18px 16px 22px;
  }

  .reader-article__header h1 {
    font-size: 22px;
  }
}

// ---- 打印样式 ----
@media print {
  .reader-breadcrumb,
  .reader-sidebar {
    display: none !important;
  }

  .reader-layout {
    display: block;
  }

  .reader-article {
    border: none;
    box-shadow: none;
    padding: 0;
  }

  .reader-content {
    font-size: 12pt;
    line-height: 1.8;
    color: #000;

    :deep(a) {
      color: #000;
      text-decoration: underline;
    }
  }
}
</style>
