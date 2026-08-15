<template>
  <div class="content-list-page">
    <div class="content-list-layout">
      <section class="content-main">
        <section class="hero-panel">
          <div class="hero-panel__inner">
            <div class="hero-copy">
              <h1>{{ config.heroTitle }}</h1>
              <p>{{ config.heroDescription }}</p>

              <div class="hero-keywords">
                <span class="hero-keywords__label">热门搜索:</span>
                <button
                  v-for="item in heroKeywords"
                  :key="item"
                  type="button"
                  class="hero-keywords__item"
                  @click="applyKeyword(item)"
                >
                  {{ item }}
                </button>
              </div>
            </div>

            <div class="hero-search">
              <el-input
                v-model="keyword"
                :placeholder="config.searchPlaceholder"
                class="hero-search__input"
                @keyup.enter="submitSearch"
                @clear="submitSearch"
                clearable
              >
                <template #append>
                  <el-button type="primary" @click="submitSearch"
                    >搜索</el-button
                  >
                </template>
              </el-input>
            </div>
          </div>
        </section>

        <section class="category-panel">
          <button
            type="button"
            class="category-tab"
            :class="{ 'is-active': activeCategoryId === 0 }"
            @click="selectCategory(0)"
          >
            {{ config.allCategoryLabel }}
          </button>

          <button
            v-for="item in categories"
            :key="item.id"
            type="button"
            class="category-tab"
            :class="{ 'is-active': activeCategoryId === item.id }"
            @click="selectCategory(item.id)"
          >
            {{ item.name }}
          </button>
        </section>

        <section v-loading="loading" class="list-panel">
          <template v-if="articleList.length">
            <article
              v-for="item in articleList"
              :key="item.id"
              class="list-item"
            >
              <div class="list-item__head">
                <router-link
                  class="list-item__title"
                  :to="buildDetailRoute(item)"
                >
                  {{ item.title }}
                </router-link>

                <span
                  v-if="Number(item.is_pinned) === 1"
                  class="list-item__badge"
                  >置顶</span
                >
              </div>

              <p v-if="item.excerpt" class="list-item__summary">
                {{ item.excerpt }}
              </p>

              <div class="list-item__meta">
                <span>{{ item.category_name || currentCategoryLabel }}</span>
                <span>{{ item.publish_at || item.created_at || "--" }}</span>
                <span>浏览量: {{ item.view_count || 0 }}</span>
              </div>
            </article>
          </template>

          <el-empty v-else-if="!loading" :description="config.emptyText" />

          <div v-if="total > pageSize" class="list-panel__pager">
            <el-pagination
              v-model:current-page="page"
              :page-size="pageSize"
              :total="total"
              layout="total, prev, pager, next"
              @current-change="updatePage"
            />
          </div>
        </section>
      </section>

      <aside class="content-sidebar">
        <section class="sidebar-card">
          <div class="sidebar-card__title">
            {{ config.sidebarCategoryTitle }}
          </div>

          <div class="shortcut-grid">
            <button
              v-for="item in config.shortcuts"
              :key="item.key"
              type="button"
              class="shortcut-item"
              @click="goShortcut(item.route)"
            >
              <span class="shortcut-item__icon">
                <el-icon><component :is="item.icon" /></el-icon>
              </span>
              <span class="shortcut-item__label">{{ item.label }}</span>
            </button>
          </div>
        </section>

        <section class="sidebar-card">
          <div class="sidebar-card__title">{{ config.hotTitle }}</div>

          <div v-if="hotArticles.length" class="ranking-list">
            <router-link
              v-for="(item, index) in hotArticles"
              :key="item.id"
              class="ranking-item"
              :to="buildDetailRoute(item)"
            >
              <span class="ranking-item__index" :class="`top-${index + 1}`">{{
                index + 1
              }}</span>
              <span class="ranking-item__title">{{ item.title }}</span>
            </router-link>
          </div>
          <el-empty v-else description="暂无内容" />
        </section>

        <section class="sidebar-card">
          <div class="sidebar-card__title">{{ config.secondaryTitle }}</div>

          <div v-if="recentArticles.length" class="recent-list">
            <router-link
              v-for="item in recentArticles"
              :key="item.id"
              class="recent-item"
              :to="buildDetailRoute(item)"
            >
              <span class="recent-item__title">{{ item.title }}</span>
              <span class="recent-item__time">{{
                item.publish_at || item.created_at || "--"
              }}</span>
            </router-link>
          </div>
          <el-empty v-else description="暂无内容" />
        </section>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import siteApi from "@/api/site";
import { getContentConfig } from "./contentConfig";

const props = defineProps({
  contentType: {
    type: String,
    required: true,
  },
  scope: {
    type: String,
    default: "client",
  },
});

const route = useRoute();
const router = useRouter();
const api = computed(() => siteApi);
const config = computed(() => getContentConfig(props.contentType, props.scope));

const loading = ref(false);
const categories = ref([]);
const articleList = ref([]);
const hotArticles = ref([]);
const recentArticles = ref([]);
const keyword = ref("");
const page = ref(1);
const pageSize = 10;
const total = ref(0);
const activeCategoryId = ref(0);

const heroKeywords = computed(() => {
  const categoryNames = categories.value.slice(0, 5).map((item) => item.name);
  return categoryNames.length ? categoryNames : config.value.keywordSuggestions;
});

const currentCategoryLabel = computed(() => {
  const matched = categories.value.find(
    (item) => item.id === activeCategoryId.value,
  );
  return matched?.name || config.value.allCategoryLabel;
});

function parseQueryNumber(value, fallback = 0) {
  const normalized = Array.isArray(value) ? value[0] : value;
  const parsed = Number.parseInt(normalized || "", 10);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
}

function parseQueryString(value) {
  const normalized = Array.isArray(value) ? value[0] : value;
  return String(normalized || "").trim();
}

function normalizeQuery(query) {
  return Object.fromEntries(
    Object.entries(query).filter(
      ([, value]) =>
        value !== undefined && value !== null && value !== "" && value !== 0,
    ),
  );
}

async function loadOverview(token) {
  const res = await api.value.contentOverview();
  if (token !== syncToken) return;
  categories.value = res.data?.[config.value.overviewCategoryKey] || [];
}

async function loadList(token) {
  loading.value = true;

  try {
    const params = {
      page: page.value,
      page_size: pageSize,
    };

    if (activeCategoryId.value > 0) {
      params.category_id = activeCategoryId.value;
    }

    if (keyword.value) {
      params.keyword = keyword.value;
    }

    const res = await api.value[config.value.apiListMethod](params);
    if (token !== syncToken) return;
    articleList.value = res.data?.list || [];
    total.value = Number(res.data?.total || 0);

    if (!categories.value.length) {
      categories.value = res.data?.categories || [];
    }
  } finally {
    if (token === syncToken) {
      loading.value = false;
    }
  }
}

async function loadSidebarContent(token) {
  const res = await api.value[config.value.apiListMethod]({
    page: 1,
    page_size: 20,
  });

  if (token !== syncToken) return;
  const list = res.data?.list || [];
  hotArticles.value = [...list]
    .sort((a, b) => Number(b.view_count || 0) - Number(a.view_count || 0))
    .slice(0, 5);

  recentArticles.value = [...list]
    .sort((a, b) => {
      const timeA = new Date(a.publish_at || a.created_at || 0).getTime();
      const timeB = new Date(b.publish_at || b.created_at || 0).getTime();
      return timeB - timeA;
    })
    .slice(0, 5);
}

let syncToken = 0;
let syncDebounceTimer = null;
let didInitialSync = false;

async function syncPage() {
  const token = ++syncToken;
  keyword.value = parseQueryString(route.query.keyword);
  page.value = parseQueryNumber(route.query.page, 1);
  activeCategoryId.value = parseQueryNumber(route.query.category, 0);

  await Promise.all([
    loadOverview(token),
    loadList(token),
    loadSidebarContent(token),
  ]);
}

function replaceListQuery(nextQuery) {
  router.replace({
    path: config.value.routeBasePath,
    query: normalizeQuery(nextQuery),
  });
}

function selectCategory(categoryId) {
  activeCategoryId.value = Number(categoryId || 0);
  page.value = 1;

  replaceListQuery({
    ...route.query,
    category: activeCategoryId.value || undefined,
    page: undefined,
  });
}

function updatePage(nextPage) {
  page.value = nextPage;

  replaceListQuery({
    ...route.query,
    page: nextPage > 1 ? nextPage : undefined,
  });
}

function submitSearch() {
  page.value = 1;

  replaceListQuery({
    ...route.query,
    keyword: keyword.value.trim() || undefined,
    page: undefined,
  });
}

function applyKeyword(value) {
  keyword.value = value;
  submitSearch();
}

function goShortcut(path) {
  if (path === route.path) {
    return;
  }

  router.push(path);
}

function buildDetailRoute(item) {
  return {
    name: config.value.detailRouteName,
    params: { id: item.id },
    query: normalizeQuery({
      category: activeCategoryId.value || undefined,
      keyword: keyword.value || undefined,
      page: page.value > 1 ? page.value : undefined,
    }),
  };
}

watch(
  () => [route.query.category, route.query.keyword, route.query.page].join("|"),
  () => {
    // 首次同步立即执行；后续分类/翻页连续变化防抖合并，避免连发多组请求
    if (!didInitialSync) {
      didInitialSync = true;
      syncPage();
      return;
    }
    clearTimeout(syncDebounceTimer);
    syncDebounceTimer = setTimeout(syncPage, 250);
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  clearTimeout(syncDebounceTimer);
  // 使仍在途的列表请求响应失效，避免写已卸载组件状态
  syncToken += 1;
});
</script>

<style scoped lang="scss">
.content-list-page {
  display: flex;
  flex-direction: column;
}

.content-list-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 330px;
  gap: 18px;
  align-items: start;
}

.content-main {
  min-width: 0;
}

.hero-panel {
  position: relative;
  overflow: hidden;
  min-height: 150px;
  margin-bottom: 18px;
  border: 1px solid $border-color;
  background:
    radial-gradient(
      circle at top right,
      rgba(221, 122, 31, 0.12),
      transparent 32%
    ),
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.98),
      rgba(245, 247, 251, 0.96)
    );
  box-shadow: $shadow-md;

  &::after {
    content: "";
    position: absolute;
    inset: 0;
    background-image:
      linear-gradient(rgba(15, 23, 42, 0.05) 1px, transparent 1px),
      linear-gradient(90deg, rgba(15, 23, 42, 0.05) 1px, transparent 1px);
    background-size: 24px 24px;
    opacity: 0.12;
    pointer-events: none;
  }
}

.hero-panel__inner {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: 28px;
  padding: 26px 30px;
}

.hero-copy h1 {
  color: $text-color-primary;
  font-size: 20px;
  font-weight: 700;
}

.hero-copy p {
  max-width: 560px;
  margin-top: 10px;
  color: $text-color-secondary;
  font-size: 13px;
  line-height: 1.8;
}

.hero-keywords {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 18px;
  color: $text-color-secondary;
  font-size: 13px;
}

.hero-keywords__label {
  font-weight: 600;
}

.hero-keywords__item {
  border: none;
  background: transparent;
  color: inherit;
  cursor: pointer;
  transition: color $motion-fast ease;

  &:hover {
    color: $color-primary;
  }
}

.hero-search {
  display: flex;
  align-items: center;
  justify-content: flex-end;
}

.hero-search__input {
  width: 100%;
}

.category-panel {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 8px;
  padding: 0 20px;
  border: 1px solid $border-color;
  background: $bg-color-card;
  box-shadow: $shadow-sm;
}

.category-tab {
  position: relative;
  min-height: 50px;
  padding: 0 10px;
  border: none;
  background: transparent;
  color: $text-color-primary;
  font-size: 14px;
  cursor: pointer;
  transition: color $motion-fast ease;

  &:hover,
  &.is-active {
    color: $color-primary;
  }

  &.is-active::after {
    content: "";
    position: absolute;
    right: 0;
    bottom: 0;
    left: 0;
    height: 3px;
    background: $color-primary;
  }
}

.list-panel,
.sidebar-card {
  border: 1px solid $border-color;
  background: $bg-color-card;
  box-shadow: $shadow-sm;
}

.list-panel {
  overflow: hidden;
}

.list-item {
  padding: 26px 30px 22px;
  border-bottom: 1px solid $divider-color;
}

.list-item:last-child {
  border-bottom: none;
}

.list-item__head {
  display: flex;
  align-items: center;
  gap: 12px;
}

.list-item__title {
  color: $text-color-primary;
  font-size: 16px;
  font-weight: 700;
  line-height: 1.5;
  transition: color $motion-fast ease;

  &:hover {
    color: $color-primary;
  }
}

.list-item__badge {
  display: inline-flex;
  align-items: center;
  height: 24px;
  padding: 0 10px;
  background: $color-danger;
  color: #fff;
  font-size: 12px;
  font-weight: 600;
}

.list-item__summary {
  margin-top: 12px;
  color: $text-color-secondary;
  font-size: 14px;
  line-height: 1.8;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
  overflow: hidden;
}

.list-item__meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 14px;
  margin-top: 14px;
  color: $text-color-placeholder;
  font-size: 12px;
}

.list-panel__pager {
  display: flex;
  justify-content: flex-end;
  padding: 20px 24px;
}

.content-sidebar {
  display: grid;
  gap: 18px;
}

.sidebar-card {
  padding: 20px;
}

.sidebar-card__title {
  padding-bottom: 14px;
  border-bottom: 1px solid $divider-color;
  color: $text-color-primary;
  font-size: 16px;
  font-weight: 600;
}

.shortcut-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  margin-top: 16px;
}

.shortcut-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  border: none;
  background: transparent;
  cursor: pointer;
  color: $text-color-primary;
}

.shortcut-item__icon {
  display: grid;
  place-items: center;
  width: 48px;
  height: 48px;
  background: $color-primary-soft;
  color: $color-primary;
  font-size: 22px;
}

.shortcut-item__label {
  font-size: 13px;
}

.ranking-list,
.recent-list {
  display: grid;
  gap: 2px;
  margin-top: 14px;
}

.ranking-item,
.recent-item {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
  padding: 11px 0;
  border-bottom: 1px solid $divider-color;
  color: $text-color-primary;
  transition: color $motion-fast ease;

  &:last-child {
    border-bottom: none;
  }

  &:hover {
    color: $color-primary;
  }
}

.ranking-item__index {
  display: inline-grid;
  place-items: center;
  width: 24px;
  height: 24px;
  background: $bg-color-soft;
  color: $text-color-secondary;
  font-size: 12px;
  font-weight: 700;
  flex-shrink: 0;

  &.top-1 {
    background: #fff0d9;
    color: #d97706;
  }

  &.top-2 {
    background: #e9f1fb;
    color: #3b82f6;
  }

  &.top-3 {
    background: #f7e8de;
    color: #b45309;
  }
}

.ranking-item__title,
.recent-item__title {
  min-width: 0;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 14px;
}

.recent-item {
  justify-content: space-between;
  gap: 16px;
}

.recent-item__time {
  color: $text-color-placeholder;
  font-size: 12px;
  flex-shrink: 0;
}

@media (max-width: 1260px) {
  .content-list-layout {
    grid-template-columns: 1fr;
  }

  .content-sidebar {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    align-items: start;
  }
}

@media (max-width: 900px) {
  .hero-panel__inner,
  .content-sidebar {
    grid-template-columns: 1fr;
  }

  .hero-search {
    justify-content: flex-start;
  }

  .shortcut-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .hero-panel__inner,
  .list-item,
  .sidebar-card {
    padding-right: 16px;
    padding-left: 16px;
  }

  .category-panel {
    padding: 0 12px;
  }

  .shortcut-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
  }

  .shortcut-item {
    gap: 6px;
  }

  .shortcut-item__icon {
    width: 40px;
    height: 40px;
    font-size: 18px;
  }

  .shortcut-item__label {
    font-size: 12px;
  }

  .list-item__head {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
