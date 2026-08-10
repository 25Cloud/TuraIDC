import { resolveApiAssetUrl } from '@/utils/apiAssetUrl'

const API_BASE_URL = String(import.meta.env?.VITE_API_BASE_URL || '')

function pickFirst(...values) {
  return values.find((value) => value !== undefined && value !== null && value !== '')
}

function toNumber(value, fallback = 0) {
  const normalized = Number(value)
  return Number.isFinite(normalized) ? normalized : fallback
}

function normalizeSiteConfig(config = {}) {
  return {
    ...config,
    site_name: String(pickFirst(config.site_name, config.siteName) || ''),
    browser_title: String(pickFirst(config.browser_title, config.browserTitle, config.site_name, config.siteName) || ''),
    site_logo: resolveApiAssetUrl(pickFirst(config.site_logo, config.siteLogo), API_BASE_URL),
    site_favicon: resolveApiAssetUrl(pickFirst(config.site_favicon, config.siteFavicon), API_BASE_URL),
    service_qq_group: String(pickFirst(config.service_qq_group, config.serviceQqGroup, config.service_phone, config.servicePhone) || ''),
    service_phone: String(pickFirst(config.service_phone, config.servicePhone, config.service_qq_group, config.serviceQqGroup) || ''),
    service_email: String(pickFirst(config.service_email, config.serviceEmail) || ''),
    service_hours: String(pickFirst(config.service_hours, config.serviceHours) || ''),
    support_group_title: String(pickFirst(config.support_group_title, config.supportGroupTitle) || ''),
    support_group_text: String(pickFirst(config.support_group_text, config.supportGroupText) || ''),
    support_group_qr: resolveApiAssetUrl(pickFirst(config.support_group_qr, config.supportGroupQr), API_BASE_URL),
  }
}

export function normalizeContentCategory(item = {}) {
  const id = toNumber(pickFirst(item.id, item.category_id, item.categoryId), 0)
  const name = String(pickFirst(item.name, item.title, item.category_name, item.category) || '')

  return {
    ...item,
    id,
    name,
    slug: String(pickFirst(item.slug, item.category_slug, item.categorySlug, item.alias) || ''),
    description: String(pickFirst(item.description, item.summary, item.remark) || ''),
    status: toNumber(pickFirst(item.status, item.state, item.enabled), 1),
    sort_order: toNumber(pickFirst(item.sort_order, item.sortOrder, item.sort), 0),
    articles_count: toNumber(pickFirst(item.articles_count, item.article_count, item.articleCount, item.total), 0),
  }
}

export function normalizeContentArticle(item = {}) {
  const categoryId = toNumber(
    pickFirst(item.category_id, item.content_category_id, item.categoryId, item.contentCategoryId),
    0,
  )
  const categoryName = String(
    pickFirst(
      item.category_name,
      item.category,
      item.content_category_name,
      item.contentCategoryName,
      item.category_title,
      item.categoryTitle,
    ) || '',
  )
  const summary = String(pickFirst(item.summary, item.excerpt, item.description, item.intro) || '')
  const excerpt = String(pickFirst(item.excerpt, item.summary, item.description, item.intro) || '')
  const publishAt = String(
    pickFirst(item.publish_at, item.published_at, item.publishedAt, item.last_published_at, item.created_at) || '',
  )

  return {
    ...item,
    id: toNumber(pickFirst(item.id, item.article_id, item.articleId), 0),
    content_type: String(pickFirst(item.content_type, item.type, item.article_type, item.articleType) || ''),
    type: String(pickFirst(item.type, item.content_type, item.article_type, item.articleType) || ''),
    category_id: categoryId,
    content_category_id: categoryId,
    title: String(pickFirst(item.title, item.name) || ''),
    slug: String(pickFirst(item.slug, item.alias) || ''),
    summary,
    excerpt,
    content: String(pickFirst(item.content, item.body, item.details, item.html) || ''),
    category_name: categoryName,
    category: categoryName,
    category_slug: String(pickFirst(item.category_slug, item.categorySlug, item.category_alias, item.categoryAlias) || ''),
    keywords: pickFirst(item.keywords, item.keyword_list, item.keywordList) || '',
    status: toNumber(pickFirst(item.status, item.state), 0),
    is_pinned: toNumber(pickFirst(item.is_pinned, item.is_top, item.isTop), 0),
    is_recommended: toNumber(pickFirst(item.is_recommended, item.is_hot, item.isHot, item.recommended), 0),
    cover_image: resolveApiAssetUrl(pickFirst(item.cover_image, item.coverImage), API_BASE_URL),
    sort_order: toNumber(pickFirst(item.sort_order, item.sortOrder, item.sort), 0),
    view_count: toNumber(pickFirst(item.view_count, item.views, item.read_count, item.readCount), 0),
    publish_at: publishAt,
    last_published_at: String(pickFirst(item.last_published_at, item.lastPublishedAt, publishAt) || ''),
    operator: String(pickFirst(item.operator, item.author, item.publisher) || ''),
    created_at: String(pickFirst(item.created_at, item.createdAt) || ''),
    updated_at: String(pickFirst(item.updated_at, item.updatedAt, item.publish_at, item.published_at) || ''),
    category_detail: item.category_detail && typeof item.category_detail === 'object'
      ? normalizeContentCategory(item.category_detail)
      : null,
  }
}

export function normalizeContentCategoryList(list) {
  return Array.isArray(list) ? list.map((item) => normalizeContentCategory(item)) : []
}

export function normalizeContentArticleList(list) {
  return Array.isArray(list) ? list.map((item) => normalizeContentArticle(item)) : []
}

export function normalizeContentOverviewPayload(data = {}) {
  return {
    ...data,
    notices: normalizeContentArticleList(pickFirst(data.notices, data.notice_list, data.noticeList)),
    help_articles: normalizeContentArticleList(pickFirst(data.help_articles, data.helpArticles, data.help_list, data.helpList)),
    notice_categories: normalizeContentCategoryList(
      pickFirst(data.notice_categories, data.noticeCategories, data.notice_category_list, data.noticeCategoryList),
    ),
    help_categories: normalizeContentCategoryList(
      pickFirst(data.help_categories, data.helpCategories, data.help_category_list, data.helpCategoryList),
    ),
  }
}

export function normalizeContentListPayload(data = {}) {
  return {
    ...data,
    list: normalizeContentArticleList(pickFirst(data.list, data.items, data.rows)),
    categories: normalizeContentCategoryList(pickFirst(data.categories, data.category_list, data.categoryList)),
    page: toNumber(data.page, 1),
    page_size: toNumber(data.page_size, 10),
    total: toNumber(pickFirst(data.total, data.count), 0),
  }
}

export function normalizeContentDetailPayload(data = {}) {
  return normalizeContentArticle(pickFirst(data.article, data.item, data.record, data))
}

export function normalizeSiteHomePayload(data = {}) {
  return {
    ...data,
    site_config: normalizeSiteConfig(pickFirst(data.site_config, data.siteConfig, {})),
    notices: normalizeContentArticleList(pickFirst(data.notices, data.notice_list, data.noticeList)),
    help_articles: normalizeContentArticleList(pickFirst(data.help_articles, data.helpArticles, data.help_list, data.helpList)),
    root_groups: Array.isArray(pickFirst(data.root_groups, data.rootGroups))
      ? pickFirst(data.root_groups, data.rootGroups)
      : [],
    group_catalog_map: pickFirst(data.group_catalog_map, data.groupCatalogMap, {}),
  }
}

export function withNormalizedData(response, normalizer) {
  return {
    ...response,
    data: normalizer(response?.data && typeof response.data === 'object' ? response.data : {}),
  }
}
