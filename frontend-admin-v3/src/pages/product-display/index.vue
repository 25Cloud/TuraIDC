<template>
  <div class="product-display-page">
    <t-card :bordered="false" class="product-display-card">
      <div class="product-display-head">
        <div class="product-display-head-text">
          <h2 class="product-display-title">{{ t('productDisplay.title') }}</h2>
          <p class="product-display-desc">{{ t('productDisplay.description') }}</p>
        </div>
      </div>

      <t-loading :loading="loading" size="small" class="product-display-body">
        <!-- 每个字段都是「标签一行 → 说明一行 → 控件一行」，说明不再和控件挤同一行 -->
        <!-- 展示形态 -->
        <section class="admin-field-section">
          <div class="admin-field-section__head">
            <h3 class="admin-field-section__title">{{ t('productDisplay.sectionDisplay') }}</h3>
          </div>
          <div class="admin-field-grid">
            <!-- 分节里只有一项时整行铺满，右半行不留空格子 -->
            <div class="admin-field admin-field--full">
              <span class="admin-field__label">{{ t('productDisplay.displayMode') }}</span>
              <p class="admin-field__desc">{{ t('productDisplay.displayModeHint') }}</p>
              <div class="admin-field__control admin-field__control--medium">
                <t-radio-group v-model="form.product_display_mode" variant="default-filled">
                  <t-radio-button value="list">{{ t('productDisplay.modeList') }}</t-radio-button>
                  <t-radio-button value="card">{{ t('productDisplay.modeCard') }}</t-radio-button>
                </t-radio-group>
              </div>
            </div>
          </div>
        </section>

        <!-- 卡片式样式 -->
        <section class="admin-field-section">
          <div class="admin-field-section__head">
            <h3 class="admin-field-section__title">{{ t('productDisplay.sectionCard') }}</h3>
          </div>
          <div class="admin-field-grid">
            <div class="admin-field admin-field--switch admin-field--full">
              <span class="admin-field__label">{{ t('productDisplay.cardShowStock') }}</span>
              <div class="admin-field__control">
                <t-switch v-model="form.product_card_show_stock" />
              </div>
              <p class="admin-field__desc">{{ t('productDisplay.cardShowStockHint') }}</p>
            </div>
          </div>
        </section>

        <!-- 独立配置页 -->
        <section class="admin-field-section">
          <div class="admin-field-section__head">
            <h3 class="admin-field-section__title">{{ t('productDisplay.sectionDetail') }}</h3>
          </div>
          <div class="admin-field-grid">
            <div class="admin-field admin-field--switch admin-field--full">
              <span class="admin-field__label">{{ t('productDisplay.detailSpecCompare') }}</span>
              <div class="admin-field__control">
                <t-switch v-model="form.product_detail_spec_compare" />
              </div>
              <p class="admin-field__desc">{{ t('productDisplay.detailSpecCompareHint') }}</p>
            </div>
          </div>
        </section>

        <!-- 页面版心 -->
        <section class="admin-field-section">
          <div class="admin-field-section__head">
            <h3 class="admin-field-section__title">{{ t('productDisplay.sectionLayout') }}</h3>
          </div>
          <div class="admin-field-grid">
            <div class="admin-field">
              <span class="admin-field__label">{{ t('productDisplay.pageLayout') }}</span>
              <p class="admin-field__desc">{{ t('productDisplay.pageLayoutHint') }}</p>
              <div class="admin-field__control admin-field__control--medium">
                <t-radio-group v-model="form.product_page_layout" variant="default-filled">
                  <t-radio-button value="contained">{{ t('productDisplay.layoutContained') }}</t-radio-button>
                  <t-radio-button value="full">{{ t('productDisplay.layoutFull') }}</t-radio-button>
                </t-radio-group>
              </div>
            </div>
            <div class="admin-field">
              <span class="admin-field__label">{{ t('productDisplay.maxWidth') }}</span>
              <p class="admin-field__desc">{{ t('productDisplay.maxWidthHint') }}</p>
              <div class="admin-field__control admin-field__control--narrow">
                <t-input-number
                  v-model="form.product_page_max_width"
                  :min="960"
                  :max="1920"
                  :step="20"
                  :disabled="form.product_page_layout !== 'contained'"
                />
              </div>
            </div>
          </div>
        </section>
      </t-loading>
    </t-card>

    <!-- 保存操作条与设置页一致：吸在视口底部右对齐 -->
    <div v-if="canManage" class="product-display-actions">
      <t-button theme="primary" :loading="saving" @click="save">{{ t('productDisplay.save') }}</t-button>
    </div>
  </div>
</template>
<script setup lang="ts">
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import { adminApi } from '@/api/admin';
import { AdminPermissions } from '@/constants/permissions';
import { t } from '@/locales';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

const canManage = computed(() => hasAdminPermission(AdminPermissions.SETTINGS_MANAGE));

const loading = ref(false);
const saving = ref(false);

// 与 SiteConfigPayload::SETTING_GROUP_BASIC 一致：这几个键都落在 basic 组
const SETTING_GROUP = 'basic';

const form = reactive({
  product_display_mode: 'list' as 'list' | 'card',
  product_card_show_stock: true,
  product_detail_spec_compare: true,
  product_page_layout: 'contained' as 'contained' | 'full',
  product_page_max_width: 1280,
});

function normalizeFlag(value: unknown, fallback: boolean): boolean {
  if (value === true || value === 1) return true;
  if (value === false || value === 0) return false;

  const text = String(value ?? '')
    .trim()
    .toLowerCase();
  if (['1', 'true', 'on', 'yes'].includes(text)) return true;
  if (['0', 'false', 'off', 'no'].includes(text)) return false;

  return fallback;
}

function applySettings(settings: Record<string, unknown>) {
  form.product_display_mode = String(settings.product_display_mode || '').trim() === 'card' ? 'card' : 'list';
  form.product_card_show_stock = normalizeFlag(settings.product_card_show_stock, true);
  form.product_detail_spec_compare = normalizeFlag(settings.product_detail_spec_compare, true);
  form.product_page_layout = String(settings.product_page_layout || '').trim() === 'full' ? 'full' : 'contained';

  const width = Number(settings.product_page_max_width);
  form.product_page_max_width = Number.isFinite(width) && width > 0 ? Math.min(Math.max(width, 960), 1920) : 1280;
}

async function loadSettings() {
  loading.value = true;
  try {
    const response = await adminApi.settings.list({ group: SETTING_GROUP });
    const settings: Record<string, unknown> = {};
    (response.list || []).forEach((item) => {
      settings[item.key] = item.value;
    });
    applySettings(settings);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('productDisplay.loadFailed')));
  } finally {
    loading.value = false;
  }
}

async function save() {
  if (!canManage.value) {
    MessagePlugin.warning(t('productDisplay.noPermission'));
    return;
  }

  saving.value = true;
  try {
    await adminApi.settings.save({
      group: SETTING_GROUP,
      settings: {
        product_display_mode: form.product_display_mode,
        // 与设置页一致：开关按 1 / 0 存，后端统一按字符串收敛
        product_card_show_stock: form.product_card_show_stock ? 1 : 0,
        product_detail_spec_compare: form.product_detail_spec_compare ? 1 : 0,
        product_page_layout: form.product_page_layout,
        product_page_max_width: form.product_page_max_width,
      },
    });
    MessagePlugin.success(t('productDisplay.saved'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('productDisplay.saveFailed')));
  } finally {
    saving.value = false;
  }
}

onMounted(loadSettings);
</script>
<style lang="less" scoped>
.product-display-page {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 16px;
}

/* 整页铺满可用宽度：两列栅格才用得上右侧空间，不再挤在左侧 940px */
.product-display-head {
  display: flex;
  align-items: flex-start;
  gap: 16px;
}

.product-display-title {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  color: var(--td-text-color-primary);
}

.product-display-desc {
  margin: 6px 0 0;
  font-size: 13px;
  line-height: 1.7;
  color: var(--td-text-color-secondary);
}

.product-display-body {
  display: block;
  margin-top: 16px;
}

/* 段标题、字段、间距全部走全局 admin-field 体系，页面内不另立一套 */
.product-display-body :deep(.admin-field-section:first-child) {
  margin-top: 0;
  padding-top: 0;
  border-top: none;
}

.product-display-body :deep(.admin-field__control > .t-input-number) {
  width: 100%;
}

/* 保存操作条：与设置页同一形态，吸在视口底部右对齐 */
.product-display-actions {
  position: sticky;
  bottom: 0;
  z-index: 10;
  display: flex;
  justify-content: flex-end;
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-l);
  border-top: 1px solid var(--td-component-stroke);
  border-radius: var(--td-radius-medium);
  background: var(--td-bg-color-container);
  box-shadow: var(--td-shadow-1);
}

/* 窄屏控件铺满整行，鼠标点不准的加减号也不会被挤成小点 */
@media (width <= 768px) {
  .product-display-page {
    padding: 12px;
  }

  .product-display-actions {
    flex-wrap: wrap;
    padding-right: 12px;
    padding-left: 12px;
  }

  /* 窄屏保存按钮铺满整行 */
  .product-display-actions .t-button {
    width: 100%;
  }

  .product-display-body :deep(.admin-field__control--narrow),
  .product-display-body :deep(.admin-field__control--medium) {
    max-width: none;
  }
}
</style>
