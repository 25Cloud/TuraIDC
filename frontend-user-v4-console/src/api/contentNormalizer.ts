import type {
  ApiEnvelope,
  ContentArticleRecord,
  ContentCategoryRecord,
  ContentDetailPayload,
  ContentListPayload,
  ContentOverviewPayload,
} from '@/types/client';

type GenericRecord = Record<string, unknown>;

function pickFirst(...values: unknown[]) {
  return values.find((value) => value !== undefined && value !== null && value !== '');
}

function toNumber(value: unknown, fallback = 0) {
  const normalized = Number(value);
  return Number.isFinite(normalized) ? normalized : fallback;
}

export function normalizeContentCategory(item: GenericRecord = {}): ContentCategoryRecord {
  const id = toNumber(pickFirst(item.id, item.category_id, item.categoryId), 0);
  const name = String(pickFirst(item.name, item.title, item.category_name, item.category) || '');

  return {
    ...item,
    id,
    name,
    slug: String(pickFirst(item.slug, item.category_slug, item.categorySlug, item.alias) || ''),
    description: String(pickFirst(item.description, item.summary, item.remark) || ''),
    status: toNumber(pickFirst(item.status, item.state, item.enabled), 1),
    sort_order: toNumber(pickFirst(item.sort_order, item.sortOrder, item.sort), 0),
    articles_count: toNumber(pickFirst(item.articles_count, item.article_count, item.articleCount, item.total), 0),
  };
}

export function normalizeContentArticle(item: GenericRecord = {}): ContentArticleRecord {
  const categoryId = toNumber(
    pickFirst(item.category_id, item.content_category_id, item.categoryId, item.contentCategoryId),
    0,
  );
  const categoryName = String(
    pickFirst(
      item.category_name,
      item.category,
      item.content_category_name,
      item.contentCategoryName,
      item.category_title,
      item.categoryTitle,
    ) || '',
  );
  const summary = String(pickFirst(item.summary, item.excerpt, item.description, item.intro) || '');
  const excerpt = String(pickFirst(item.excerpt, item.summary, item.description, item.intro) || '');
  const publishAt = String(
    pickFirst(item.publish_at, item.published_at, item.publishedAt, item.last_published_at, item.created_at) || '',
  );

  const keywords = pickFirst(item.keywords, item.keyword_list, item.keywordList);

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
    category_slug: String(
      pickFirst(item.category_slug, item.categorySlug, item.category_alias, item.categoryAlias) || '',
    ),
    keywords: Array.isArray(keywords) ? keywords.map((entry) => String(entry)) : String(keywords || ''),
    status: toNumber(pickFirst(item.status, item.state), 0),
    is_pinned: toNumber(pickFirst(item.is_pinned, item.is_top, item.isTop), 0),
    is_recommended: toNumber(pickFirst(item.is_recommended, item.is_hot, item.isHot, item.recommended), 0),
    cover_image: String(pickFirst(item.cover_image, item.coverImage) || ''),
    sort_order: toNumber(pickFirst(item.sort_order, item.sortOrder, item.sort), 0),
    view_count: toNumber(pickFirst(item.view_count, item.views, item.read_count, item.readCount), 0),
    publish_at: publishAt,
    last_published_at: String(pickFirst(item.last_published_at, item.lastPublishedAt, publishAt) || ''),
    operator: String(pickFirst(item.operator, item.author, item.publisher) || ''),
    created_at: String(pickFirst(item.created_at, item.createdAt) || ''),
    updated_at: String(pickFirst(item.updated_at, item.updatedAt, item.publish_at, item.published_at) || ''),
    category_detail:
      item.category_detail && typeof item.category_detail === 'object'
        ? normalizeContentCategory(item.category_detail as GenericRecord)
        : null,
  };
}

export function normalizeContentCategoryList(list: unknown): ContentCategoryRecord[] {
  return Array.isArray(list) ? list.map((item) => normalizeContentCategory(item)) : [];
}

export function normalizeContentArticleList(list: unknown): ContentArticleRecord[] {
  return Array.isArray(list) ? list.map((item) => normalizeContentArticle(item)) : [];
}

export function normalizeContentOverviewPayload(data: GenericRecord = {}): ContentOverviewPayload {
  return {
    ...data,
    notices: normalizeContentArticleList(pickFirst(data.notices, data.notice_list, data.noticeList)),
    help_articles: normalizeContentArticleList(
      pickFirst(data.help_articles, data.helpArticles, data.help_list, data.helpList),
    ),
    notice_categories: normalizeContentCategoryList(
      pickFirst(data.notice_categories, data.noticeCategories, data.notice_category_list, data.noticeCategoryList),
    ),
    help_categories: normalizeContentCategoryList(
      pickFirst(data.help_categories, data.helpCategories, data.help_category_list, data.helpCategoryList),
    ),
  };
}

export function normalizeContentListPayload(data: GenericRecord = {}): ContentListPayload {
  return {
    ...data,
    list: normalizeContentArticleList(pickFirst(data.list, data.items, data.rows)),
    categories: normalizeContentCategoryList(pickFirst(data.categories, data.category_list, data.categoryList)),
    page: toNumber(data.page, 1),
    page_size: toNumber(data.page_size, 10),
    total: toNumber(pickFirst(data.total, data.count), 0),
  };
}

export function normalizeContentDetailPayload(data: GenericRecord = {}): ContentDetailPayload {
  return normalizeContentArticle((pickFirst(data.article, data.item, data.record, data) || {}) as GenericRecord);
}

export function withNormalizedData<T>(
  response: ApiEnvelope<Record<string, unknown>>,
  normalizer: (payload: GenericRecord) => T,
): ApiEnvelope<T> {
  const payload = response?.data && typeof response.data === 'object' ? (response.data as GenericRecord) : {};
  return {
    ...response,
    data: normalizer(payload),
  };
}
