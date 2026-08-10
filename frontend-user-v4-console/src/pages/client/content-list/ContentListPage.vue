<template>
  <section class="content-list-page">
    <div class="content-list-layout">
      <main class="content-main">
        <t-card class="content-card hero-card" :bordered="false">
          <div class="hero-copy">
            <h1>{{ config.pageTitle }}</h1>
            <p>{{ config.heroDescription }}</p>
            <div class="hero-keywords">
              <span>热门搜索:</span>
              <t-button
                v-for="item in heroKeywords"
                :key="item"
                theme="primary"
                variant="text"
                @click="applyKeyword(item)"
              >
                {{ item }}
              </t-button>
            </div>
          </div>
          <t-input
            v-model="keyword"
            clearable
            :placeholder="config.searchPlaceholder"
            @enter="submitSearch"
            @clear="submitSearch"
          >
            <template #suffixIcon><search-icon /></template>
          </t-input>
        </t-card>

        <t-card class="content-card category-card" :bordered="false">
          <t-space break-line>
            <t-button
              :theme="activeCategoryId === 0 ? 'primary' : 'default'"
              variant="outline"
              @click="selectCategory(0)"
            >
              {{ config.allCategoryLabel }}
            </t-button>
            <t-button
              v-for="item in categories"
              :key="item.id"
              :theme="activeCategoryId === Number(item.id) ? 'primary' : 'default'"
              variant="outline"
              @click="selectCategory(item.id)"
            >
              {{ item.name }}
            </t-button>
          </t-space>
        </t-card>

        <t-card class="content-card list-card" :bordered="false">
          <template v-if="isNotice && unreadCount > 0" #actions>
            <t-button theme="primary" variant="text" @click="handleMarkAllRead"
              >全部标记已读 ({{ unreadCount }})</t-button
            >
          </template>
          <data-state :loading="loading" :empty="!articleList.length" :description="config.emptyText">
            <article v-for="item in articleList" :key="item.id" class="article-row">
              <div class="article-row__head">
                <router-link class="article-row__title" :to="buildDetailRoute(item)">{{ item.title }}</router-link>
                <t-tag v-if="Number(item.is_pinned) === 1" theme="danger" variant="light">置顶</t-tag>
              </div>
              <p v-if="item.excerpt">{{ item.excerpt }}</p>
              <div class="article-row__meta">
                <span>{{ item.category_name || currentCategoryLabel }}</span>
                <span>{{ item.publish_at || item.created_at || '--' }}</span>
                <span>浏览量 {{ item.view_count || 0 }}</span>
              </div>
            </article>
          </data-state>
        </t-card>

        <div v-if="total > pageSize" class="content-pagination">
          <t-pagination v-model="page" :page-size="pageSize" :total="total" show-total @change="updatePage" />
        </div>
      </main>

      <aside class="content-sidebar">
        <t-card class="content-card" :bordered="false">
          <template #title>{{ config.hotTitle }}</template>
          <div v-if="hotArticles.length" class="rank-list">
            <router-link
              v-for="(item, index) in hotArticles"
              :key="item.id"
              :to="buildDetailRoute(item)"
              class="rank-row"
            >
              <span>{{ index + 1 }}</span>
              <strong>{{ item.title }}</strong>
            </router-link>
          </div>
          <t-empty v-else description="暂无内容" />
        </t-card>

        <t-card class="content-card" :bordered="false">
          <template #title>{{ config.secondaryTitle }}</template>
          <div v-if="recentArticles.length" class="recent-list">
            <router-link v-for="item in recentArticles" :key="item.id" :to="buildDetailRoute(item)" class="recent-row">
              <strong>{{ item.title }}</strong>
              <span>{{ item.publish_at || item.created_at || '--' }}</span>
            </router-link>
          </div>
          <t-empty v-else description="暂无内容" />
        </t-card>
      </aside>
    </div>
  </section>
</template>
<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import { SearchIcon } from 'tdesign-icons-vue-next';

import { useContentList } from '@/domains/content/useContent';
import { useNoticeReadStatus } from '@/domains/content/useNoticeReadStatus';

const props = defineProps<{
  contentType: 'notice' | 'help';
}>();

const {
  config,
  loading,
  categories,
  articleList,
  hotArticles,
  recentArticles,
  keyword,
  page,
  pageSize,
  total,
  activeCategoryId,
  heroKeywords,
  currentCategoryLabel,
  selectCategory,
  updatePage,
  submitSearch,
  applyKeyword,
  buildDetailRoute,
} = useContentList(props.contentType);

const { unreadCount, markAllRead, fetchUnreadCount } = useNoticeReadStatus();
const isNotice = props.contentType === 'notice';

async function handleMarkAllRead() {
  await markAllRead();
  await fetchUnreadCount(true);
}
</script>
<style scoped lang="less">
.content-list-page {
  // padding 由 Starter 布局层统一提供
}

.content-list-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(18rem, 24%);
  gap: var(--td-comp-margin-m);
  align-items: start;
}

.content-main,
.content-sidebar {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  min-width: 0;
}

.content-card {
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.hero-card {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
}

.hero-copy {
  h1 {
    margin: 0;
    color: var(--td-text-color-primary);
    font: var(--td-font-title-large);
  }

  p {
    margin: var(--td-comp-margin-xs) 0 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-medium);
    line-height: var(--td-line-height-body-medium);
  }
}

.hero-keywords {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-xs);
  align-items: center;
  margin-top: var(--td-comp-margin-m);
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.article-row {
  padding: var(--td-comp-paddingTB-l) 0;
  border-bottom: thin solid var(--td-border-color);

  &:first-child {
    padding-top: 0;
  }

  &:last-child {
    border-bottom: 0;
  }

  p {
    margin: var(--td-comp-margin-s) 0 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-medium);
    line-height: var(--td-line-height-body-medium);
  }
}

.article-row__head {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  align-items: center;
}

.article-row__title {
  color: var(--td-text-color-primary);
  font: var(--td-font-title-medium);
  font-weight: 600;
  text-decoration: none;

  &:hover {
    color: var(--td-brand-color);
  }
}

.article-row__meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-m);
  margin-top: var(--td-comp-margin-s);
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.rank-list,
.recent-list {
  display: flex;
  flex-direction: column;
}

.rank-row,
.recent-row {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  min-height: var(--td-comp-size-xxl);
  color: var(--td-text-color-primary);
  text-decoration: none;
  border-bottom: thin solid var(--td-border-color);

  &:last-child {
    border-bottom: 0;
  }

  &:hover {
    color: var(--td-brand-color);
  }
}

.rank-row {
  span {
    width: 1.5rem;
    color: var(--td-brand-color);
    font: var(--td-font-title-small);
    text-align: center;
  }

  strong {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.recent-row {
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  padding: var(--td-comp-paddingTB-s) 0;

  strong,
  span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
  }

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

.content-pagination {
  display: flex;
  justify-content: flex-end;
}

@media (max-width: 72rem) {
  .content-list-layout {
    grid-template-columns: 1fr;
  }

  .content-sidebar {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 48rem) {
  .hero-card,
  .content-sidebar {
    grid-template-columns: 1fr;
  }
}
</style>
