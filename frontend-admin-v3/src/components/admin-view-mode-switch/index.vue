<template>
  <t-radio-group
    :value="mode"
    variant="default-filled"
    size="small"
    class="admin-view-mode-switch"
    @change="onChange"
  >
    <t-radio-button value="list">
      {{ t('settings.index.productDisplayModeList') }}
    </t-radio-button>
    <t-radio-button value="card">
      {{ t('settings.index.productDisplayModeCard') }}
    </t-radio-button>
  </t-radio-group>
</template>

<script setup lang="ts">
/**
 * 列表 / 卡片 展示方式开关。
 *
 * 放在各列表页的筛选栏里，与 useAdminViewMode 配套：同一页面的偏好会被记住，
 * 默认值在设置面板里配置。文案复用设置页已有的「列表式 / 卡片式」词条，
 * 不再新增一份同义翻译。
 */
import { t } from '@/locales';
import type { AdminViewMode } from '@/composables/useAdminViewMode';

defineProps<{ mode: AdminViewMode }>();

const emit = defineEmits<{ change: [mode: AdminViewMode] }>();

function onChange(value: unknown): void {
  emit('change', value === 'card' ? 'card' : 'list');
}
</script>

<style lang="less" scoped>
.admin-view-mode-switch {
  flex-shrink: 0;
}
</style>
