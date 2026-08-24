<template>
  <div class="media-library-page">
    <t-card :bordered="false">
      <div class="media-toolbar">
        <t-input
          v-model="filters.keyword"
          clearable
          placeholder="按文件名搜索"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select v-model="filters.type" clearable placeholder="全部类型" @change="handleFilterChange">
          <t-option v-for="item in typeOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-button variant="outline" :loading="reindexing" @click="handleReindex">
          <template #icon><refresh-icon /></template>
          重新获取
        </t-button>
        <t-button theme="primary" :loading="uploading" @click="triggerUpload">
          <template #icon><upload-icon /></template>
          上传媒体
        </t-button>
      </div>
    </t-card>

    <t-card :bordered="false" class="media-library-card">
      <t-table
        row-key="id"
        :loading="loading"
        :data="items"
        :columns="columns"
        :pagination="pagination"
        :hover="true"
        size="medium"
        table-layout="auto"
        @page-change="handlePageChange"
      >
        <template #preview="{ row }">
          <div class="media-preview">
            <video
              v-if="isVideo(row)"
              class="media-preview__asset"
              :src="String(row.url || '')"
              muted
              preload="metadata"
              playsinline
            ></video>
            <img
              v-else
              class="media-preview__asset"
              :src="String(row.url || '')"
              :alt="String(row.filename || 'media')"
              loading="lazy"
            />
          </div>
        </template>

        <template #filename="{ row }">
          <div class="media-cell">
            <strong>{{ row.filename || '-' }}</strong>
            <span class="media-cell__sub">{{ row.path || '-' }}</span>
          </div>
        </template>

        <template #type="{ row }">
          <t-tag :theme="isVideo(row) ? 'warning' : 'primary'" variant="light">
            {{ isVideo(row) ? '视频' : '图片' }}
          </t-tag>
        </template>

        <template #meta="{ row }">
          <div class="media-cell">
            <span>{{ formatFileSize(row.size) }}</span>
            <span class="media-cell__sub">{{ formatDimensions(row) }}</span>
          </div>
        </template>

        <template #actions="{ row }">
          <t-space size="8">
            <t-button variant="text" @click="copyText(String(row.url || ''))">复制 URL</t-button>
            <t-button variant="text" @click="copyText(String(row.path || ''))">复制路径</t-button>
            <t-popconfirm
              content="删除后将无法恢复，确认继续？"
              :confirm-btn="{ theme: 'danger', content: '删除' }"
              @confirm="removeMedia(row)"
            >
              <t-button
                variant="text"
                theme="danger"
                :disabled="!canDelete(row)"
                :loading="deletingId === String(row.id || '')"
                >删除</t-button
              >
            </t-popconfirm>
          </t-space>
        </template>
      </t-table>

      <div v-if="!loading && !items.length" class="media-empty">暂无媒体文件，先上传一批图片或视频。</div>
    </t-card>

    <input
      ref="fileInputRef"
      class="media-upload-input"
      type="file"
      accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/ogg,video/quicktime,video/x-m4v"
      @change="handleFileChange"
    />
  </div>
</template>
<script setup lang="ts">
import './media-library.less';

import { RefreshIcon, SearchIcon, UploadIcon } from 'tdesign-icons-vue-next';
import type { PageInfo, PrimaryTableCol } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import type { MediaFileRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';

defineOptions({ name: 'AdminMediaLibrary' });

const PAGE_SIZE = 24;

const typeOptions = [
  { label: '全部类型', value: '' },
  { label: '图片', value: 'image' },
  { label: '视频', value: 'video' },
];

const loading = ref(false);
const uploading = ref(false);
const reindexing = ref(false);
const deletingId = ref('');
const fileInputRef = ref<HTMLInputElement | null>(null);
const items = ref<MediaFileRecord[]>([]);
const total = ref(0);
const filters = reactive({
  keyword: '',
  type: '',
  page: 1,
  page_size: PAGE_SIZE,
});

const columns = computed<PrimaryTableCol<MediaFileRecord>[]>(() => [
  { colKey: 'preview', title: '预览', width: 112 },
  { colKey: 'filename', title: '文件', minWidth: 360 },
  { colKey: 'type', title: '类型', width: 96 },
  { colKey: 'meta', title: '体积 / 尺寸', width: 160 },
  { colKey: 'created_at', title: '上传时间', width: 180 },
  { colKey: 'actions', title: '操作', width: 220, fixed: 'right' },
]);

const pagination = computed(() => ({
  current: filters.page,
  pageSize: filters.page_size,
  total: total.value,
}));

function isVideo(row: MediaFileRecord) {
  return String(row.type || '').toLowerCase() === 'video' || String(row.mime_type || '').startsWith('video/');
}

function canDelete(row: MediaFileRecord) {
  const id = String(row.id || '');
  return /^\d+$/.test(id);
}

function formatFileSize(size: unknown) {
  const value = Number(size || 0);
  if (!Number.isFinite(value) || value <= 0) return '0 B';
  if (value >= 1024 * 1024) return `${(value / 1024 / 1024).toFixed(2)} MB`;
  if (value >= 1024) return `${(value / 1024).toFixed(1)} KB`;
  return `${value} B`;
}

function formatDimensions(row: MediaFileRecord) {
  if (isVideo(row)) return '视频文件';
  const width = Number(row.width || 0);
  const height = Number(row.height || 0);
  if (width > 0 && height > 0) return `${width} × ${height}`;
  return '未记录尺寸';
}

async function loadMediaList() {
  loading.value = true;
  try {
    const response = await adminApi.media.list({
      keyword: filters.keyword.trim() || undefined,
      type: filters.type || undefined,
      page: filters.page,
      page_size: filters.page_size,
    });

    items.value = response.list || [];
    total.value = Number(response.total || 0);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载媒体库失败'));
    items.value = [];
    total.value = 0;
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  filters.page = 1;
  loadMediaList();
}

function handleFilterChange() {
  filters.page = 1;
  loadMediaList();
}

function handlePageChange(pageInfo: PageInfo) {
  filters.page = pageInfo.current;
  filters.page_size = pageInfo.pageSize;
  loadMediaList();
}

function triggerUpload() {
  if (fileInputRef.value) {
    fileInputRef.value.value = '';
    fileInputRef.value.click();
  }
}

async function handleFileChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;

  const data = new FormData();
  data.append('file', file);
  data.append('group', 'content');

  uploading.value = true;
  try {
    await adminApi.media.upload(data);
    MessagePlugin.success('媒体上传成功');
    filters.page = 1;
    await loadMediaList();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '媒体上传失败'));
  } finally {
    uploading.value = false;
  }
}

async function handleReindex() {
  reindexing.value = true;
  try {
    const response = await adminApi.media.reindex();
    const created = Number(response.created || 0);
    const unrecognized = response.unrecognized || [];

    let msg = `重新获取完成，新增 ${created} 个媒体文件`;
    if (unrecognized.length > 0) {
      msg += `，${unrecognized.length} 个文件因类型不支持被跳过`;
    }
    MessagePlugin.success(msg);

    await loadMediaList();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '重新获取媒体文件失败'));
  } finally {
    reindexing.value = false;
  }
}

async function removeMedia(row: MediaFileRecord) {
  if (!canDelete(row)) return;

  const id = String(row.id || '');

  // 先检查引用
  let refs: string[] = [];
  try {
    const result = await adminApi.media.references(id);
    refs = result.references || [];
  } catch {
    // 引用检查失败时允许继续删除
  }

  if (refs.length > 0) {
    const refList = refs.join('；');
    const confirmed = await new Promise<boolean>((resolve) => {
      const dialog = DialogPlugin.confirm({
        header: '确认删除',
        body: `该媒体文件被以下内容引用：${refList}。删除后将导致这些内容出现死链，确认继续？`,
        confirmBtn: { theme: 'danger', content: '仍然删除' },
        cancelBtn: '取消',
        onConfirm: () => {
          dialog.destroy();
          resolve(true);
        },
        onCancel: () => {
          dialog.destroy();
          resolve(false);
        },
        onClose: () => {
          dialog.destroy();
          resolve(false);
        },
      });
    });

    if (!confirmed) return;
  }

  deletingId.value = id;
  try {
    await adminApi.media.remove(id);
    MessagePlugin.success('媒体已删除');

    if (items.value.length === 1 && filters.page > 1) {
      filters.page -= 1;
    }
    await loadMediaList();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '删除媒体失败'));
  } finally {
    deletingId.value = '';
  }
}

async function copyText(value: string) {
  if (!value) {
    MessagePlugin.warning('没有可复制的内容');
    return;
  }

  try {
    await navigator.clipboard.writeText(value);
    MessagePlugin.success('已复制到剪贴板');
  } catch {
    MessagePlugin.error('复制失败，请手动复制');
  }
}

function errorMessage(error: unknown, fallback: string) {
  const record = error as Record<string, unknown>;
  const response = record.response as Record<string, unknown> | undefined;
  const data = response?.data as Record<string, unknown> | undefined;
  return String(data?.message || record.message || fallback);
}

onMounted(() => {
  loadMediaList();
});
</script>
