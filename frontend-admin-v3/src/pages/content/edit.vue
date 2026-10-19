<template>
  <div class="content-edit-page">
    <t-card :bordered="false">
      <div class="content-edit-head">
        <t-button variant="text" @click="goBack">
          <template #icon><arrow-left-icon /></template>
          {{ $t('content.edit.backToList') }}
        </t-button>
        <span class="content-edit-head__title">{{
          isEditing
            ? $t('content.edit.editTitle', { label: articleLabel })
            : $t('content.edit.addTitle', { label: articleLabel })
        }}</span>
      </div>
    </t-card>

    <t-card :bordered="false" :loading="pageLoading">
      <t-form ref="formRef" :data="form" :rules="rules" label-align="top" class="content-edit-form">
        <section class="edit-section">
          <h3 class="edit-section__title">{{ $t('content.edit.basicInfo') }}</h3>
          <div class="edit-section__body">
            <t-form-item class="edit-span-full" :label="$t('content.edit.titleLabel')" name="title">
              <t-input v-model="form.title" size="large" :placeholder="$t('content.edit.titlePlaceholder')" />
            </t-form-item>
            <t-form-item :label="$t('content.edit.categoryLabel')" name="category_id">
              <t-select v-model="form.category_id" filterable :placeholder="$t('content.edit.selectCategory')">
                <t-option v-for="item in categories" :key="item.id" :label="item.name || '-'" :value="item.id" />
              </t-select>
            </t-form-item>
            <t-form-item :label="$t('content.edit.statusLabel')" name="status">
              <t-select v-model="form.status" :placeholder="$t('content.edit.selectStatus')">
                <t-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
              </t-select>
            </t-form-item>
            <t-form-item :label="$t('content.edit.slugLabel')" name="slug">
              <t-input v-model="form.slug" :placeholder="$t('content.edit.slugPlaceholder')" />
            </t-form-item>
          </div>
        </section>

        <section class="edit-section">
          <h3 class="edit-section__title">{{ $t('content.edit.publishSettings') }}</h3>
          <div class="edit-section__body">
            <t-form-item :label="$t('content.edit.publishAt')" name="publish_at">
              <t-date-picker
                v-model="form.publish_at"
                clearable
                enable-time-picker
                mode="date"
                format="YYYY-MM-DD HH:mm:ss"
                value-type="YYYY-MM-DD HH:mm:ss"
                :placeholder="$t('content.edit.selectPublishAt')"
                style="width: 100%"
              />
            </t-form-item>
            <t-form-item :label="$t('content.edit.sortOrder')" name="sort_order">
              <t-input-number v-model="form.sort_order" :min="0" :max="999999" theme="normal" style="width: 100%" />
            </t-form-item>
            <t-form-item :label="$t('content.edit.operator')" name="operator">
              <t-input v-model="form.operator" :placeholder="$t('content.edit.operatorPlaceholder')" />
            </t-form-item>
          </div>
          <div class="edit-flag-row">
            <t-form-item :label="$t('content.edit.pinned')" name="is_pinned">
              <t-switch
                v-model="form.is_pinned"
                :custom-value="[1, 0]"
                :label="[$t('content.edit.pinned'), $t('content.edit.normal')]"
                @change="handlePinnedChange"
              />
            </t-form-item>
            <t-form-item :label="$t('content.edit.recommended')" name="is_recommended">
              <t-switch
                v-model="form.is_recommended"
                :custom-value="[1, 0]"
                :label="[$t('content.edit.recommended'), $t('content.edit.notRecommended')]"
                @change="handleRecommendedChange"
              />
            </t-form-item>
            <t-form-item
              v-if="isEditing && contentType === 'notice'"
              :label="$t('content.edit.requireReread')"
              name="require_reread"
            >
              <t-switch
                v-model="form.require_reread"
                :custom-value="[true, false]"
                :label="[$t('content.edit.yes'), $t('content.edit.no')]"
              />
              <template #help>{{ $t('content.edit.requireRereadResetHint') }}</template>
            </t-form-item>
          </div>
        </section>

        <section class="edit-section">
          <h3 class="edit-section__title">{{ $t('content.edit.coverAndSummary') }}</h3>
          <div class="edit-section__body edit-section__body--single">
            <t-form-item :label="$t('content.edit.cover')" name="cover_image">
              <div class="cover-image-selector" @click="openCoverImageDrawer">
                <image-icon v-if="!isCoverVideo" />
                <video-icon v-else />
                <span v-if="form.cover_image" class="cover-image-selector__name">{{
                  form.cover_image.split('/').pop()
                }}</span>
                <span v-else class="cover-image-selector__placeholder">{{ $t('content.edit.coverPlaceholder') }}</span>
                <chevron-right-icon />
              </div>
            </t-form-item>
            <t-form-item :label="$t('content.edit.summary')" name="summary">
              <t-textarea
                v-model="form.summary"
                :autosize="{ minRows: 2, maxRows: 4 }"
                :maxlength="500"
                :placeholder="$t('content.edit.summaryPlaceholder')"
              />
            </t-form-item>
            <t-form-item :label="$t('content.edit.keywords')" name="keywords">
              <t-input v-model="form.keywords" :placeholder="$t('content.edit.keywordsPlaceholder')" />
            </t-form-item>
          </div>
        </section>

        <section class="edit-section">
          <h3 class="edit-section__title">{{ $t('content.edit.body') }}</h3>
          <div class="edit-section__body edit-section__body--single">
            <t-form-item name="content">
              <t-textarea
                v-model="form.content"
                :autosize="{ minRows: 14, maxRows: 24 }"
                :maxlength="30000"
                :placeholder="$t('content.edit.bodyPlaceholder')"
              />
            </t-form-item>
          </div>
        </section>

        <section class="edit-section">
          <h3 class="edit-section__title">{{ $t('content.edit.remark') }}</h3>
          <div class="edit-section__body edit-section__body--single">
            <t-form-item name="remark">
              <t-textarea
                v-model="form.remark"
                :autosize="{ minRows: 2, maxRows: 4 }"
                :maxlength="255"
                :placeholder="$t('content.edit.remarkPlaceholder')"
              />
            </t-form-item>
          </div>
        </section>

        <div class="content-edit-actions">
          <t-button variant="outline" @click="goBack">{{ $t('content.edit.cancel') }}</t-button>
          <t-button theme="primary" :loading="saving" @click="submit">{{
            $t('content.edit.save', { label: articleLabel })
          }}</t-button>
        </div>
      </t-form>
    </t-card>

    <t-drawer
      :visible="coverImageDrawerVisible"
      :header="$t('content.edit.selectCoverMedia')"
      size="520px"
      placement="right"
      :footer="null"
      @close="closeCoverImageDrawer"
    >
      <div class="cover-drawer-toolbar">
        <t-select
          v-model="coverMediaType"
          :placeholder="$t('content.edit.mediaTypePlaceholder')"
          @change="loadCoverMediaList"
        >
          <t-option v-for="item in coverMediaTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-button variant="outline" @click="openCoverImagePicker">
          <template #icon><upload-icon /></template>
          {{ $t('content.edit.uploadNewFile') }}
        </t-button>
      </div>
      <div class="cover-drawer-grid">
        <div
          v-for="item in coverImageList"
          :key="item.url"
          class="cover-drawer-card"
          :class="{ 'is-selected': form.cover_image === item.url }"
          @click="selectCoverImage(item.url)"
        >
          <video
            v-if="item.isVideo"
            class="cover-drawer-card__img"
            :src="item.url"
            muted
            preload="metadata"
            playsinline
          ></video>
          <img v-else class="cover-drawer-card__img" :src="item.url" :alt="item.filename" loading="lazy" />
          <div class="cover-drawer-card__label">
            <check-circle-filled-icon v-if="form.cover_image === item.url" class="cover-drawer-card__check" />
            <span>{{ item.filename }}</span>
          </div>
        </div>
        <div v-if="!coverImageList.length && !coverImageLoading" class="cover-drawer-empty">
          {{ $t('content.edit.emptyMedia') }}
        </div>
      </div>
    </t-drawer>

    <input
      ref="coverImageInputRef"
      type="file"
      accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/ogg,video/quicktime,video/x-m4v"
      style="display: none"
      @change="handleCoverImageUpload"
    />
  </div>
</template>
<script setup lang="ts">
import './index.less';

import {
  ArrowLeftIcon,
  CheckCircleFilledIcon,
  ChevronRightIcon,
  ImageIcon,
  UploadIcon,
  VideoIcon,
} from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type { ContentArticlePayload, ContentArticleRecord, ContentCategoryRecord, MediaFileRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import { t } from '@/locales';

interface CoverMediaItem {
  url: string;
  filename: string;
  isVideo: boolean;
}

function isMediaVideo(row: MediaFileRecord): boolean {
  return String(row.type || '').toLowerCase() === 'video' || String(row.mime_type || '').startsWith('video/');
}

function isUrlVideo(url: string): boolean {
  const lower = url.toLowerCase();
  return (
    lower.endsWith('.mp4') ||
    lower.endsWith('.webm') ||
    lower.endsWith('.ogg') ||
    lower.endsWith('.mov') ||
    lower.endsWith('.m4v')
  );
}

interface ArticleForm {
  id: number | string | null;
  content_type: string;
  title: string;
  category_id: number | string | null;
  slug: string;
  summary: string;
  content: string;
  keywords: string;
  status: number;
  is_pinned: number;
  is_recommended: number;
  cover_image: string;
  sort_order: number;
  publish_at: string;
  operator: string;
  remark: string;
  require_reread: boolean;
}

const route = useRoute();
const router = useRouter();

const pageLoading = ref(false);
const saving = ref(false);
const formRef = ref<FormInstanceFunctions>();
const coverImageInputRef = ref<HTMLInputElement>();
const coverImageDrawerVisible = ref(false);
const coverImageLoading = ref(false);
const coverImageList = ref<CoverMediaItem[]>([]);
const coverMediaType = ref('');
const coverMediaTypeOptions = [
  { label: t('content.mediaLibrary.allTypes'), value: '' },
  { label: t('content.mediaLibrary.image'), value: 'image' },
  { label: t('content.mediaLibrary.video'), value: 'video' },
];
const categories = ref<ContentCategoryRecord[]>([]);

const contentType = computed<string>(() => String(route.meta.contentType || route.query.type || 'notice'));
const articleLabel = computed(() =>
  contentType.value === 'help' ? t('content.edit.articleLabelHelp') : t('content.edit.articleLabelNotice'),
);
const isCoverVideo = computed(() => isUrlVideo(form.cover_image));

const statusOptions = [
  { label: t('content.edit.statusDraft'), value: 0 },
  { label: t('content.edit.statusPublished'), value: 1 },
  { label: t('content.edit.statusOffline'), value: 2 },
];

const rules: Record<string, FormRule[]> = {
  title: [{ required: true, message: t('content.edit.titleRequired'), type: 'error' }],
  category_id: [{ required: true, message: t('content.edit.categoryRequired'), type: 'error' }],
  status: [{ required: true, message: t('content.edit.statusRequired'), type: 'error' }],
  content: [{ required: true, message: t('content.edit.bodyRequired'), type: 'error' }],
};

function createDefault(): ArticleForm {
  return {
    id: null,
    content_type: '',
    title: '',
    category_id: null,
    slug: '',
    summary: '',
    content: '',
    keywords: '',
    status: 1,
    is_pinned: 0,
    is_recommended: 0,
    cover_image: '',
    sort_order: 0,
    publish_at: '',
    operator: '',
    remark: '',
    require_reread: false,
  };
}

const form = reactive<ArticleForm>(createDefault());
const isEditing = computed(() => !!form.id);
const articleId = computed(() => route.params.id || route.query.id || null);

function goBack() {
  const basePath = contentType.value === 'help' ? '/admin/content/help' : '/admin/content/notices';
  router.push(basePath);
}

function handlePinnedChange(value: unknown) {
  if (Number(value) === 1 && Number(form.is_recommended) === 1) form.is_recommended = 0;
}

function handleRecommendedChange(value: unknown) {
  if (Number(value) === 1 && Number(form.is_pinned) === 1) form.is_pinned = 0;
}

function fillForm(row: ContentArticleRecord) {
  Object.assign(form, {
    id: row.id,
    content_type: String(row.content_type || row.type || ''),
    title: String(row.title || ''),
    category_id: row.category_id ?? row.content_category?.id ?? null,
    slug: String(row.slug || ''),
    summary: String(row.summary || ''),
    content: String(row.content || ''),
    keywords: String(row.keywords || ''),
    status: Number(row.status ?? 1),
    is_pinned: Number(row.is_pinned || 0),
    is_recommended: Number(row.is_recommended || 0),
    cover_image: String(row.cover_image || ''),
    sort_order: Number(row.sort_order || 0),
    publish_at: String(row.publish_at || ''),
    operator: String(row.operator || ''),
    remark: String(row.remark || ''),
    require_reread: false,
  });
}

function buildPayload(): ContentArticlePayload | null {
  if (!form.category_id) {
    MessagePlugin.warning(t('content.edit.categoryRequired'));
    return null;
  }
  return {
    content_type: contentType.value,
    category_id: Number(form.category_id),
    title: form.title.trim(),
    slug: form.slug.trim() || null,
    summary: form.summary.trim() || null,
    content: form.content,
    keywords: form.keywords.trim() || null,
    status: Number(form.status),
    is_pinned: Number(form.is_pinned),
    is_recommended: Number(form.is_recommended),
    cover_image: form.cover_image.trim() || null,
    sort_order: Number(form.sort_order || 0),
    publish_at: form.publish_at.trim() || null,
    operator: form.operator.trim() || null,
    remark: form.remark.trim() || null,
    require_reread: form.require_reread || false,
  };
}

async function submit() {
  const result = await formRef.value?.validate?.();
  if (result !== true) return;
  const payload = buildPayload();
  if (!payload) return;

  saving.value = true;
  try {
    if (form.id) {
      await adminApi.content.articles.update(form.id, payload);
      MessagePlugin.success(t('content.edit.updated', { label: articleLabel.value }));
    } else {
      await adminApi.content.articles.create(payload);
      MessagePlugin.success(t('content.edit.created', { label: articleLabel.value }));
    }
    goBack();
  } catch (error) {
    const record = error as Record<string, unknown>;
    const resp = record.response as Record<string, unknown> | undefined;
    const data = resp?.data as Record<string, unknown> | undefined;
    MessagePlugin.error(String(data?.message || record.message || t('content.edit.saveFailed')));
  } finally {
    saving.value = false;
  }
}

// Cover media drawer
async function openCoverImageDrawer() {
  coverImageDrawerVisible.value = true;
  await loadCoverMediaList();
}

async function loadCoverMediaList() {
  coverImageLoading.value = true;
  try {
    const res = await adminApi.media.list({
      type: coverMediaType.value || undefined,
      page_size: 100,
    });
    coverImageList.value = (res.list || [])
      .map((item) => ({
        url: String(item.url || ''),
        filename:
          String(item.filename || '')
            .split('/')
            .pop() || '',
        isVideo: isMediaVideo(item),
      }))
      .filter((item) => item.url);
  } catch {
    coverImageList.value = [];
  } finally {
    coverImageLoading.value = false;
  }
}

function closeCoverImageDrawer() {
  coverImageDrawerVisible.value = false;
}

function selectCoverImage(url: string) {
  form.cover_image = form.cover_image === url ? '' : url;
  closeCoverImageDrawer();
}

function openCoverImagePicker() {
  if (coverImageInputRef.value) {
    coverImageInputRef.value.value = '';
    coverImageInputRef.value.click();
  }
}

async function handleCoverImageUpload(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  const data = new FormData();
  data.append('file', file);
  data.append('group', 'content');
  try {
    const response = await adminApi.media.upload(data);
    const url = String(response.url || '');
    form.cover_image = url;
    if (url) {
      coverImageList.value.unshift({
        url,
        filename:
          String(response.filename || '')
            .split('/')
            .pop() || file.name,
        isVideo: isMediaVideo(response),
      });
    }
    MessagePlugin.success(t('content.edit.coverUploaded'));
  } catch (error) {
    const record = error as Record<string, unknown>;
    const resp = record.response as Record<string, unknown> | undefined;
    const d = resp?.data as Record<string, unknown> | undefined;
    MessagePlugin.error(String(d?.message || record.message || t('content.edit.coverUploadFailed')));
  }
}

onMounted(async () => {
  pageLoading.value = true;
  try {
    const id = articleId.value;
    const [catRes] = await Promise.allSettled([adminApi.content.categories.list({ content_type: contentType.value })]);
    if (catRes.status === 'fulfilled') categories.value = catRes.value;

    if (id) {
      const detail = await adminApi.content.articles.detail(Array.isArray(id) ? id[0] : id);
      fillForm(detail);
    } else if (categories.value.length) {
      form.category_id = categories.value[0]?.id ?? null;
    }
  } catch {
    // ignore
  } finally {
    pageLoading.value = false;
  }
});
</script>
