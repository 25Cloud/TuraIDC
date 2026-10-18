<template>
  <div class="product-display-page">
    <t-card :bordered="false" class="product-display-card">
      <div class="product-display-head">
        <div class="product-display-head-text">
          <h2 class="product-display-title">{{ t('productDisplay.title') }}</h2>
          <p class="product-display-desc">{{ t('productDisplay.description') }}</p>
        </div>
        <t-button
          theme="primary"
          :loading="saving"
          :disabled="!canManage"
          @click="save"
        >
          {{ t('productDisplay.save') }}
        </t-button>
      </div>

      <t-loading :loading="loading" size="small" class="product-display-body">
        <!-- 展示形态 -->
        <section class="product-display-section">
          <h3 class="product-display-section-title">
            {{ t('productDisplay.sectionDisplay') }}
          </h3>
          <t-form :data="form" :label-align="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '180px'">
            <t-form-item :label="t('productDisplay.displayMode')" name="product_display_mode">
              <div class="product-display-field">
                <t-radio-group v-model="form.product_display_mode" variant="default-filled">
                  <t-radio-button value="list">{{ t('productDisplay.modeList') }}</t-radio-button>
                  <t-radio-button value="card">{{ t('productDisplay.modeCard') }}</t-radio-button>
                </t-radio-group>
                <p class="product-display-hint">{{ t('productDisplay.displayModeHint') }}</p>
              </div>
            </t-form-item>
          </t-form>
        </section>

        <!-- 卡片样式 -->
        <section class="product-display-section">
          <h3 class="product-display-section-title">
            {{ t('productDisplay.sectionCard') }}
          </h3>
          <t-form :data="form" :label-align="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '180px'">
            <t-form-item :label="t('productDisplay.cardShowStock')" name="product_card_show_stock">
              <div class="product-display-field">
                <t-switch v-model="form.product_card_show_stock" />
                <p class="product-display-hint">{{ t('productDisplay.cardShowStockHint') }}</p>
              </div>
            </t-form-item>
          </t-form>
        </section>

        <!-- 独立配置页 -->
        <section class="product-display-section">
          <h3 class="product-display-section-title">
            {{ t('productDisplay.sectionDetail') }}
          </h3>
          <t-form :data="form" :label-align="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '180px'">
            <t-form-item :label="t('productDisplay.detailSpecCompare')" name="product_detail_spec_compare">
              <div class="product-display-field">
                <t-switch v-model="form.product_detail_spec_compare" />
                <p class="product-display-hint">{{ t('productDisplay.detailSpecCompareHint') }}</p>
              </div>
            </t-form-item>
          </t-form>
        </section>

        <!-- 页面版心 -->
        <section class="product-display-section">
          <h3 class="product-display-section-title">
            {{ t('productDisplay.sectionLayout') }}
          </h3>
          <t-form :data="form" :label-align="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '180px'">
            <t-form-item :label="t('productDisplay.pageLayout')" name="product_page_layout">
              <div class="product-display-field">
                <t-radio-group v-model="form.product_page_layout" variant="default-filled">
                  <t-radio-button value="contained">{{ t('productDisplay.layoutContained') }}</t-radio-button>
                  <t-radio-button value="full">{{ t('productDisplay.layoutFull') }}</t-radio-button>
                </t-radio-group>
                <p class="product-display-hint">{{ t('productDisplay.pageLayoutHint') }}</p>
              </div>
            </t-form-item>
            <t-form-item :label="t('productDisplay.maxWidth')" name="product_page_max_width">
              <div class="product-display-field">
                <t-input-number
                  v-model="form.product_page_max_width"
                  :min="960"
                  :max="1920"
                  :step="20"
                  :disabled="form.product_page_layout !== 'contained'"
                  style="width: 180px"
                />
                <p class="product-display-hint">{{ t('productDisplay.maxWidthHint') }}</p>
              </div>
            </t-form-item>
          </t-form>
        </section>
      </t-loading>
    </t-card>
  </div>
</template>

<script setup lang="ts">
import { useWindowSize } from '@vueuse/core';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import { adminApi } from '@/api/admin';
import { AdminPermissions } from '@/constants/permissions';
import { t } from '@/locales';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

const { width } = useWindowSize();
const isMobile = computed(() => width.value < 768);
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
  form.product_page_layout =
    String(settings.product_page_layout || '').trim() === 'full' ? 'full' : 'contained';

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
  padding: 16px;
}

.product-display-card {
  max-width: 960px;
}

.product-display-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
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

/* 每类配置独立成段，避免和别的输入框挤在一张表单里 */
.product-display-section {
  padding: 16px 0;
  border-top: 1px solid var(--td-component-stroke);

  &:first-of-type {
    border-top: none;
    padding-top: 4px;
  }
}

.product-display-section-title {
  margin: 0 0 12px;
  font-size: 14px;
  font-weight: 600;
  color: var(--td-text-color-primary);
}

.product-display-hint {
  margin: 6px 0 0;
  font-size: 12px;
  line-height: 1.7;
  color: var(--td-text-color-placeholder);
}

/* 控件与说明纵向排列：说明文字不能横向挤在控件右边 */
.product-display-field {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
  min-width: 0;
}

.product-display-field .product-display-hint {
  margin: 0;
}
</style>
