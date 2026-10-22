<template>
  <t-dialog
    v-model:visible="visibleProxy"
    :header="$t('products.description.syncSettingsTitle')"
    width="520px"
    :confirm-btn="{ content: $t('products.description.syncSettingsConfirm'), loading: submitting }"
    @confirm="submit"
  >
    <t-form :data="form" label-width="140px">
      <t-form-item :label="$t('products.description.autoSyncEnabled')" name="enabled">
        <t-switch v-model="form.enabled" :custom-value="[1, 0]" />
      </t-form-item>
    </t-form>
    <p class="description-sync-hint">{{ $t('products.description.autoSyncHint') }}</p>
    <p v-if="targetCount > 0" class="description-sync-hint">
      {{ $t('products.description.autoSyncTargetCount', { count: targetCount }) }}
    </p>
    <p v-if="scheduleHint" class="description-sync-hint">{{ scheduleHint }}</p>
  </t-dialog>
</template>
<script setup lang="ts">
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref, watch } from 'vue';

import { productApi } from '@/api/product';
import { t } from '@/locales';

import { errorMessage } from '../composables/useProductShared';

/**
 * 描述同步设置弹窗。
 *
 * 两种作用域共用一个组件：
 *   - scope=products：针对商品列表里勾选的商品，批量开关「描述自动同步」；
 *   - scope=group：针对某个二级 / 三级分组下的商品，批量开关同一开关。
 *
 * 开关只表达「是否参与定时同步上游描述」，全局节拍在系统设置 → 自动化里配置，
 * 弹窗里只做只读回显，避免同一份节拍出现两个改入口。
 */
const props = defineProps<{
  visible: boolean;
  scope: 'products' | 'group';
  productIds?: number[];
  groupId?: number;
  groupLevel?: number;
  targetCount?: number;
}>();

const emit = defineEmits<{
  (event: 'update:visible', value: boolean): void;
  (event: 'success'): void;
}>();

const visibleProxy = computed({
  get: () => props.visible,
  set: (value: boolean) => emit('update:visible', value),
});

const form = reactive({ enabled: 0 });
const submitting = ref(false);
const scheduleHint = ref('');

const targetCount = computed(() => {
  if (props.scope === 'group') return props.targetCount ?? 0;
  return props.productIds?.length ?? 0;
});

watch(
  () => props.visible,
  async (visible) => {
    if (!visible) return;
    submitting.value = false;
    form.enabled = 0;
    await loadScheduleHint();
  },
);

/** 回显全局节拍：只读，让操作者知道「开启后什么时候会跑」 */
async function loadScheduleHint() {
  try {
    const settings = await productApi.descriptionSyncSettings();
    const mode = String(settings.schedule_mode || '');
    const time = String(settings.schedule_time || '');
    if (!mode) {
      scheduleHint.value = '';
      return;
    }
    scheduleHint.value = t('products.description.scheduleHint', {
      mode: t(`settings.index.${scheduleModeLabel(mode)}`),
      time,
    });
  } catch {
    scheduleHint.value = '';
  }
}

function scheduleModeLabel(mode: string) {
  const labels: Record<string, string> = {
    every_fifteen_minutes: 'everyFifteenMinutes',
    every_thirty_minutes: 'everyThirtyMinutes',
    hourly: 'hourly',
    daily: 'daily',
  };
  return labels[mode] || 'daily';
}

async function submit() {
  submitting.value = true;
  try {
    if (props.scope === 'group') {
      await productApi.updateGroupDescriptionAutoSync(props.groupId as number, {
        level: props.groupLevel as number,
        enabled: form.enabled,
      });
    } else {
      await productApi.updateDescriptionAutoSync({
        product_ids: props.productIds || [],
        enabled: form.enabled,
      });
    }
    MessagePlugin.success(
      form.enabled ? t('products.description.autoSyncOpened') : t('products.description.autoSyncClosed'),
    );
    emit('update:visible', false);
    emit('success');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.description.autoSyncFailed')));
  } finally {
    submitting.value = false;
  }
}
</script>
<style scoped>
.description-sync-hint {
  margin: 4px 0 0;
  color: var(--td-text-color-placeholder);
  font-size: 12px;
  line-height: 20px;
}
</style>
