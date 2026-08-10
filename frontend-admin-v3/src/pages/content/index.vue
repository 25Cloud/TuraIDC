<template>
  <div class="content-page">
    <t-card :bordered="false">
      <div class="content-filter">
        <t-input
          v-model="filters.keyword"
          clearable
          :placeholder="`搜索${articleLabel}标题 / 摘要 / 正文 / 别名`"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select v-model="filters.category_id" clearable filterable placeholder="全部分类" @change="handleSearch">
          <t-option v-for="item in categories" :key="item.id" :label="fieldValue(item.name)" :value="item.id" />
        </t-select>
        <t-select v-model="filters.status" clearable placeholder="全部状态" @change="handleSearch">
          <t-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-select v-model="filters.is_pinned" clearable placeholder="置顶状态" @change="handleSearch">
          <t-option label="仅看置顶" :value="1" />
          <t-option label="仅看普通" :value="0" />
        </t-select>
        <t-button variant="outline" @click="openCategoryDialog">
          <template #icon><folder-icon /></template>
          分类管理
        </t-button>
        <t-button theme="primary" @click="goCreateArticle">
          <template #icon><add-icon /></template>
          新增{{ articleLabel }}
        </t-button>
      </div>

      <div class="category-strip">
        <button
          type="button"
          class="category-chip"
          :class="{ active: filters.category_id === '' }"
          @click="applyCategoryFilter('')"
        >
          全部分类
        </button>
        <button
          v-for="item in categories"
          :key="item.id"
          type="button"
          class="category-chip"
          :class="{ active: String(filters.category_id) === String(item.id) }"
          @click="applyCategoryFilter(item.id)"
        >
          <span>{{ item.name }}</span>
          <small>{{ item.articles_count || 0 }}</small>
        </button>
      </div>
    </t-card>

    <t-card :bordered="false" :loading="loading">
      <template #title>{{ pageTitle }}列表</template>
      <template #subtitle>共 {{ total }} 条</template>

      <div v-if="!isMobile" class="table-scroll">
        <t-table row-key="id" :data="articles" :columns="columns" hover table-layout="fixed">
          <template #title="{ row }">
            <button class="title-button" type="button" @click="goEditArticle(row.id)">
              {{ fieldValue(row.title) }}
            </button>
            <p>{{ fieldValue(row.summary || row.excerpt) }}</p>
          </template>
          <template #category="{ row }">
            <div class="stack-cell">
              <strong>{{ fieldValue(row.category_name || row.content_category?.name) }}</strong>
              <span>{{ fieldValue(row.slug) }}</span>
            </div>
          </template>
          <template #status="{ row }">
            <t-tag :theme="contentStatusTheme(row.status)" variant="light">{{
              row.status_label || contentStatusLabel(row.status)
            }}</t-tag>
          </template>
          <template #flags="{ row }">
            <t-space size="small">
              <t-tag v-if="Number(row.is_pinned) === 1" theme="danger" variant="light">置顶</t-tag>
              <t-tag v-if="Number(row.is_recommended) === 1" theme="success" variant="light">推荐</t-tag>
              <span v-if="Number(row.is_pinned) !== 1 && Number(row.is_recommended) !== 1" class="muted-text"
                >普通</span
              >
            </t-space>
          </template>
          <template #publish="{ row }">
            <div class="stack-cell">
              <strong>{{ formatDateTime(row.publish_at || row.created_at) }}</strong>
              <span>浏览 {{ row.view_count || 0 }}</span>
            </div>
          </template>
          <template #updated="{ row }">
            <div class="stack-cell">
              <strong>{{ formatDateTime(row.updated_at) }}</strong>
              <span>{{ fieldValue(row.operator) }}</span>
            </div>
          </template>
          <template #actions="{ row }">
            <t-space size="small">
              <t-button theme="primary" variant="text" @click="goEditArticle(row.id)">编辑</t-button>
              <t-button theme="danger" variant="text" @click="handleDeleteArticle(row)">删除</t-button>
            </t-space>
          </template>
        </t-table>
      </div>

      <div v-else class="mobile-list">
        <article v-for="row in articles" :key="row.id" class="content-mobile-card">
          <div class="content-mobile-card__head">
            <button class="content-mobile-card__title" type="button" @click="goEditArticle(row.id)">
              {{ fieldValue(row.title) }}
            </button>
            <div class="content-mobile-card__tools">
              <t-tag :theme="contentStatusTheme(row.status)" variant="light">{{
                row.status_label || contentStatusLabel(row.status)
              }}</t-tag>
              <t-dropdown
                trigger="click"
                placement="bottom-right"
                :options="mobileActionOptions(row)"
                @click="(data: { value: unknown }) => handleMobileAction(data.value, row)"
              >
                <t-button class="content-mobile-card__more" variant="text" shape="square">...</t-button>
              </t-dropdown>
            </div>
          </div>
          <dl class="content-mobile-card__meta">
            <div>
              <dt>分类</dt>
              <dd>{{ fieldValue(row.category_name || row.content_category?.name) }}</dd>
            </div>
            <div>
              <dt>属性</dt>
              <dd>{{ Number(row.is_pinned) === 1 ? '置顶' : Number(row.is_recommended) === 1 ? '推荐' : '普通' }}</dd>
            </div>
            <div>
              <dt>发布</dt>
              <dd>{{ formatDateTime(row.publish_at || row.created_at) }}</dd>
            </div>
            <div>
              <dt>浏览</dt>
              <dd>{{ row.view_count || 0 }}</dd>
            </div>
          </dl>
        </article>
      </div>

      <div v-if="total > 0" class="pagination-row">
        <t-pagination
          :current="pagination.page"
          :page-size="pagination.page_size"
          :total="total"
          :page-size-options="[20, 50, 100]"
          show-jumper
          @change="handlePageChange"
        />
      </div>
    </t-card>

    <t-dialog v-model:visible="categoryDialogVisible" header="分类管理" width="880px" :footer="false">
      <div class="category-dialog">
        <t-card :bordered="false">
          <template #title>{{ categoryForm.id ? '编辑分类' : '新增分类' }}</template>
          <t-form ref="categoryFormRef" :data="categoryForm" :rules="categoryRules" label-align="top">
            <div class="category-form-grid">
              <t-form-item label="分类名称" name="name">
                <t-input v-model="categoryForm.name" placeholder="请输入分类名称" />
              </t-form-item>
              <t-form-item label="别名" name="slug">
                <t-input v-model="categoryForm.slug" placeholder="留空自动生成" />
              </t-form-item>
              <t-form-item label="排序值" name="sort_order">
                <t-input-number v-model="categoryForm.sort_order" :min="0" :max="999999" />
              </t-form-item>
              <t-form-item label="状态" name="status">
                <t-switch v-model="categoryForm.status" :custom-value="[1, 0]" :label="['启用', '停用']" />
              </t-form-item>
              <t-form-item class="category-form-span" label="分类说明" name="description">
                <t-textarea
                  v-model="categoryForm.description"
                  :autosize="{ minRows: 3, maxRows: 5 }"
                  :maxlength="255"
                />
              </t-form-item>
            </div>
          </t-form>
          <div class="category-actions">
            <t-button v-if="categoryForm.id" variant="outline" @click="resetCategoryForm">取消编辑</t-button>
            <t-button theme="primary" :loading="categorySaving" @click="submitCategory">
              {{ categoryForm.id ? '保存分类' : '新增分类' }}
            </t-button>
            <t-button variant="outline" @click="categoryDialogVisible = false">关闭</t-button>
          </div>
        </t-card>

        <t-card :bordered="false" :loading="categoryLoading">
          <template #title>分类列表</template>
          <t-table row-key="id" :data="categories" :columns="categoryColumns" hover table-layout="fixed">
            <template #status="{ row }">
              <t-tag :theme="Number(row.status) === 1 ? 'success' : 'default'" variant="light">
                {{ Number(row.status) === 1 ? '启用' : '停用' }}
              </t-tag>
            </template>
            <template #actions="{ row }">
              <t-space size="small">
                <t-button theme="primary" variant="text" @click="fillCategoryForm(row)">编辑</t-button>
                <t-button theme="danger" variant="text" @click="handleDeleteCategory(row)">删除</t-button>
              </t-space>
            </template>
          </t-table>
        </t-card>
      </div>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { AddIcon, FolderIcon, SearchIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, PrimaryTableCol } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type { ContentArticleRecord, ContentCategoryPayload, ContentCategoryRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { fieldValue, formatDateTime } from '@/utils/format';
import { required } from '@/utils/formRules';
import { errorMessage } from '@/utils/userMessage';

type ContentType = 'notice' | 'help';

const route = useRoute();
const router = useRouter();
const loading = ref(false);
const categoryLoading = ref(false);
const categorySaving = ref(false);
const categoryDialogVisible = ref(false);
const categoryFormRef = ref<FormInstanceFunctions>();
const articles = ref<ContentArticleRecord[]>([]);
const categories = ref<ContentCategoryRecord[]>([]);
const total = ref(0);

const filters = reactive({
  keyword: '',
  category_id: '' as string | number,
  status: '' as string | number,
  is_pinned: '' as string | number,
});
const pagination = reactive({
  page: 1,
  page_size: 20,
});
const categoryForm = reactive({
  id: null as number | string | null,
  name: '',
  slug: '',
  description: '',
  status: 1,
  sort_order: 0,
});

const statusOptions = [
  { label: '草稿', value: 0 },
  { label: '已发布', value: 1 },
  { label: '已下线', value: 2 },
];
const categoryRules: Record<string, FormRule[]> = {
  name: [required('请输入分类名称')],
};

const contentType = computed<ContentType>(() => (route.meta.contentType === 'help' ? 'help' : 'notice'));
const pageTitle = computed(() => (contentType.value === 'help' ? '帮助中心' : '系统公告'));
const articleLabel = computed(() => (contentType.value === 'help' ? '帮助文章' : '公告'));
const isMobile = useMediaQuery('(max-width: 768px)');

const columns: PrimaryTableCol<ContentArticleRecord>[] = [
  { colKey: 'id', title: 'ID', width: 80 },
  { colKey: 'title', title: '标题', minWidth: 300 },
  { colKey: 'category', title: '分类', minWidth: 160 },
  { colKey: 'status', title: '状态', width: 110 },
  { colKey: 'flags', title: '属性', width: 150 },
  { colKey: 'publish', title: '发布 / 浏览', minWidth: 180 },
  { colKey: 'updated', title: '更新信息', minWidth: 180 },
  { colKey: 'actions', title: '操作', fixed: 'right', width: 130 },
];
const categoryColumns: PrimaryTableCol<ContentCategoryRecord>[] = [
  { colKey: 'name', title: '分类名称', minWidth: 150 },
  { colKey: 'slug', title: '别名', minWidth: 130 },
  { colKey: 'status', title: '状态', width: 100 },
  { colKey: 'sort_order', title: '排序', width: 90 },
  { colKey: 'articles_count', title: '文章数', width: 90 },
  { colKey: 'actions', title: '操作', fixed: 'right', width: 130 },
];

function getBasePath() {
  return contentType.value === 'help' ? '/admin/content/help' : '/admin/content/notices';
}

function goCreateArticle() {
  if (!categories.value.length) {
    MessagePlugin.warning(`请先创建${articleLabel.value}分类`);
    openCategoryDialog();
    return;
  }
  router.push(`${getBasePath()}/create`);
}

function goEditArticle(id: number | string) {
  router.push(`${getBasePath()}/${id}`);
}

function resetCategoryForm() {
  categoryForm.id = null;
  categoryForm.name = '';
  categoryForm.slug = '';
  categoryForm.description = '';
  categoryForm.status = 1;
  categoryForm.sort_order = 0;
  categoryFormRef.value?.clearValidate?.();
}

function handleSearch() {
  pagination.page = 1;
  loadArticles();
}

function applyCategoryFilter(value: string | number) {
  filters.category_id = value;
  pagination.page = 1;
  loadArticles();
}

function handlePageChange(data: { current: number; pageSize: number }) {
  pagination.page = data.current;
  pagination.page_size = data.pageSize;
  loadArticles();
}

function mobileActionOptions(_row: ContentArticleRecord) {
  return [
    { content: '编辑', value: 'edit' },
    { content: '删除', value: 'delete', theme: 'error' },
  ];
}

function handleMobileAction(value: unknown, row: ContentArticleRecord) {
  if (value === 'edit') goEditArticle(row.id);
  if (value === 'delete') handleDeleteArticle(row);
}

function buildArticleParams() {
  const params: Record<string, unknown> = {
    content_type: contentType.value,
    page: pagination.page,
    page_size: pagination.page_size,
  };
  if (filters.keyword.trim()) params.keyword = filters.keyword.trim();
  if (filters.category_id !== '') params.category_id = filters.category_id;
  if (filters.status !== '') params.status = filters.status;
  if (filters.is_pinned !== '') params.is_pinned = filters.is_pinned;
  return params;
}

async function loadCategories() {
  categoryLoading.value = true;
  try {
    categories.value = await adminApi.content.categories.list({ content_type: contentType.value });
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载分类失败'));
  } finally {
    categoryLoading.value = false;
  }
}

async function loadArticles() {
  loading.value = true;
  try {
    const response = await adminApi.content.articles.list(buildArticleParams());
    articles.value = response.list || [];
    total.value = Number(response.total || 0);
    pagination.page = Number(response.page || pagination.page);
    pagination.page_size = Number(response.page_size || pagination.page_size);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载内容列表失败'));
  } finally {
    loading.value = false;
  }
}

async function loadAll() {
  await Promise.allSettled([loadCategories(), loadArticles()]);
}

function openCategoryDialog() {
  resetCategoryForm();
  categoryDialogVisible.value = true;
}

function fillCategoryForm(row: ContentCategoryRecord) {
  categoryForm.id = row.id;
  categoryForm.name = String(row.name || '');
  categoryForm.slug = String(row.slug || '');
  categoryForm.description = String(row.description || '');
  categoryForm.status = Number(row.status ?? 1);
  categoryForm.sort_order = Number(row.sort_order || 0);
}

async function submitCategory() {
  const result = await categoryFormRef.value?.validate?.();
  if (result !== true) return;

  const payload: ContentCategoryPayload = {
    content_type: contentType.value,
    name: categoryForm.name.trim(),
    slug: categoryForm.slug.trim() || null,
    description: categoryForm.description.trim() || null,
    status: Number(categoryForm.status),
    sort_order: Number(categoryForm.sort_order || 0),
  };

  categorySaving.value = true;
  try {
    if (categoryForm.id) {
      await adminApi.content.categories.update(categoryForm.id, payload);
      MessagePlugin.success('分类已更新');
    } else {
      await adminApi.content.categories.create(payload);
      MessagePlugin.success('分类已创建');
    }
    resetCategoryForm();
    await Promise.allSettled([loadCategories(), loadArticles()]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存分类失败'));
  } finally {
    categorySaving.value = false;
  }
}

function handleDeleteCategory(row: ContentCategoryRecord) {
  const dialog = DialogPlugin.confirm({
    header: '删除分类',
    body: `确认删除分类"${fieldValue(row.name)}"吗？`,
    confirmBtn: { content: '确认删除', theme: 'danger' },
    async onConfirm() {
      try {
        await adminApi.content.categories.delete(row.id);
        MessagePlugin.success('分类已删除');
        dialog.destroy();
        if (String(filters.category_id) === String(row.id)) filters.category_id = '';
        await Promise.allSettled([loadCategories(), loadArticles()]);
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除分类失败'));
      }
    },
  });
}

function handleDeleteArticle(row: ContentArticleRecord) {
  const dialog = DialogPlugin.confirm({
    header: '删除内容',
    body: `确认删除"${fieldValue(row.title)}"吗？`,
    confirmBtn: { content: '确认删除', theme: 'danger' },
    async onConfirm() {
      try {
        await adminApi.content.articles.delete(row.id);
        MessagePlugin.success(`${articleLabel.value}已删除`);
        dialog.destroy();
        await loadArticles();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除内容失败'));
      }
    },
  });
}

function contentStatusLabel(status: unknown) {
  const labels: Record<string, string> = { 0: '草稿', 1: '已发布', 2: '已下线' };
  return labels[String(status ?? '')] || fieldValue(status);
}

function contentStatusTheme(status: unknown) {
  const themes: Record<string, 'default' | 'success' | 'warning'> = { 0: 'default', 1: 'success', 2: 'warning' };
  return themes[String(status ?? '')] || 'default';
}

onMounted(() => {
  loadAll();
});
</script>
