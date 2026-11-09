<template>
  <t-dialog
    :visible="visible"
    :header="$t('services.hostConfig.title')"
    width="720px"
    :confirm-btn="{
      content: $t('services.hostConfig.confirm'),
      loading: submitting,
    }"
    :cancel-btn="$t('common.cancel')"
    @update:visible="(value: boolean) => emit('update:visible', value)"
    @cancel="close"
    @confirm="submit"
  >
    <div class="host-config-dialog">
      <!-- 两句口径必须在表单最上面：留空≠清零，以及这是不经订单计费的手工操作 -->
      <t-alert theme="info" :message="$t('services.hostConfig.blankHint')" />
      <t-alert theme="warning" :message="$t('services.hostConfig.billingNotice')" />

      <t-form :data="form" label-align="top" class="host-config-form">
        <section class="host-config-section">
          <h4>{{ $t('services.hostConfig.sectionCompute') }}</h4>
          <div class="host-config-grid">
            <t-form-item :label="$t('services.hostConfig.cpu')">
              <t-input-number
                v-model="form.cpu"
                theme="normal"
                :decimal-places="0"
                :placeholder="$t('services.hostConfig.blankPlaceholder')"
                style="width: 100%"
              />
              <p class="host-config-range">{{ rangeHint('cpu') }}</p>
            </t-form-item>
            <t-form-item :label="$t('services.hostConfig.memory')">
              <t-input-number
                v-model="form.memory"
                theme="normal"
                :decimal-places="0"
                :placeholder="$t('services.hostConfig.blankPlaceholder')"
                style="width: 100%"
              />
              <p class="host-config-range">{{ rangeHint('memory') }}</p>
            </t-form-item>
          </div>
        </section>

        <section class="host-config-section">
          <h4>{{ $t('services.hostConfig.sectionNetwork') }}</h4>
          <div class="host-config-grid">
            <t-form-item :label="$t('services.hostConfig.inBw')">
              <t-input-number
                v-model="form.in_bw"
                theme="normal"
                :decimal-places="0"
                :placeholder="$t('services.hostConfig.blankPlaceholder')"
                style="width: 100%"
              />
              <p class="host-config-range">{{ rangeHint('in_bw') }}</p>
            </t-form-item>
            <t-form-item :label="$t('services.hostConfig.outBw')">
              <t-input-number
                v-model="form.out_bw"
                theme="normal"
                :decimal-places="0"
                :placeholder="$t('services.hostConfig.blankPlaceholder')"
                style="width: 100%"
              />
              <p class="host-config-range">{{ rangeHint('out_bw') }}</p>
            </t-form-item>
            <t-form-item :label="$t('services.hostConfig.flow')">
              <t-input-number
                v-model="form.flow"
                theme="normal"
                :decimal-places="0"
                :placeholder="$t('services.hostConfig.blankPlaceholder')"
                style="width: 100%"
              />
              <p class="host-config-range">{{ rangeHint('flow') }}</p>
            </t-form-item>
            <t-form-item :label="$t('services.hostConfig.ipNum')">
              <t-input-number
                v-model="form.ip_num"
                theme="normal"
                :decimal-places="0"
                :placeholder="$t('services.hostConfig.blankPlaceholder')"
                style="width: 100%"
              />
              <p class="host-config-range">{{ rangeHint('ip_num') }}</p>
            </t-form-item>
            <t-form-item :label="$t('services.hostConfig.ipGroup')">
              <t-input
                v-model="form.ip_group"
                clearable
                :maxlength="64"
                :placeholder="$t('services.hostConfig.ipGroupPlaceholder')"
              />
            </t-form-item>
            <t-form-item :label="$t('services.hostConfig.ipv6Num')">
              <t-input-number
                v-model="form.ipv6_num"
                theme="normal"
                :decimal-places="0"
                :placeholder="$t('services.hostConfig.blankPlaceholder')"
                style="width: 100%"
              />
              <p class="host-config-range">{{ rangeHint('ipv6_num') }}</p>
            </t-form-item>
          </div>
        </section>

        <section class="host-config-section">
          <h4>{{ $t('services.hostConfig.sectionStorage') }}</h4>
          <div class="host-config-grid">
            <t-form-item :label="$t('services.hostConfig.diskSize')">
              <t-input-number
                v-model="form.disk_size"
                theme="normal"
                :decimal-places="0"
                :placeholder="$t('services.hostConfig.blankPlaceholder')"
                style="width: 100%"
              />
              <p class="host-config-range">{{ rangeHint('disk_size') }}</p>
            </t-form-item>
            <t-form-item :label="$t('services.hostConfig.diskId')">
              <t-input-number
                v-model="form.disk_id"
                theme="normal"
                :decimal-places="0"
                :placeholder="$t('services.hostConfig.blankPlaceholder')"
                style="width: 100%"
              />
              <p class="host-config-range">{{ rangeHint('disk_id') }}</p>
            </t-form-item>
          </div>
          <!-- 只填容量不填盘 ID 时后端自行挑盘：这里提示一句，避免运营以为必须两项一起填 -->
          <t-alert
            v-if="diskIdMissing"
            theme="info"
            class="host-config-note"
            :message="$t('services.hostConfig.diskIdMissingHint')"
          />
        </section>

        <section class="host-config-section">
          <h4>{{ $t('services.hostConfig.sectionQuota') }}</h4>
          <div class="host-config-grid">
            <t-form-item :label="$t('services.hostConfig.snapNum')">
              <t-input-number
                v-model="form.snap_num"
                theme="normal"
                :decimal-places="0"
                :placeholder="$t('services.hostConfig.blankPlaceholder')"
                style="width: 100%"
              />
              <p class="host-config-range">{{ rangeHint('snap_num') }}</p>
            </t-form-item>
            <t-form-item :label="$t('services.hostConfig.backupNum')">
              <t-input-number
                v-model="form.backup_num"
                theme="normal"
                :decimal-places="0"
                :placeholder="$t('services.hostConfig.blankPlaceholder')"
                style="width: 100%"
              />
              <p class="host-config-range">{{ rangeHint('backup_num') }}</p>
            </t-form-item>
          </div>
        </section>
      </t-form>
    </div>
  </t-dialog>
</template>
<script setup lang="ts">
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref, watch } from 'vue';

import type { ServiceHostConfigPayload, ServiceHostConfigResult } from '@/api/user';
import { userApi } from '@/api/user';
import { t } from '@/locales';
import { errorMessage } from '@/utils/userMessage';

/**
 * 管理端「实例改配」对话框。
 *
 * 为什么只在管理端：改规格会改变上游资源占用，但本平台没有「按配置项补差价」的订单模型，
 * 所以入口不放进用户控制台，文案里也明确写清这是运营手工操作、不经订单与计费。
 *
 * 交互口径：每个输入框留空 = 不改该项（后端把不传/空值当「不动」，传 0 也不是清零），
 * 因此这里只提交真正填了值的字段；范围校验在前端先做一遍，给中文提示，
 * 不把明显的非法值丢给后端与上游。
 */

type NumericKey =
  | 'cpu'
  | 'memory'
  | 'in_bw'
  | 'out_bw'
  | 'flow'
  | 'ip_num'
  | 'ipv6_num'
  | 'disk_size'
  | 'disk_id'
  | 'snap_num'
  | 'backup_num';

interface FieldRule {
  key: NumericKey;
  labelKey: string;
  min: number;
  max: number;
  /** 范围说明的展示文案；不填就按 min–max 渲染 */
  rangeLabel?: string;
}

const props = withDefaults(defineProps<{ visible?: boolean; userId: string; serviceId: number }>(), {
  visible: false,
});

const emit = defineEmits<{
  (event: 'update:visible', value: boolean): void;
  (event: 'success', payload: ServiceHostConfigResult): void;
}>();

/**
 * 与后端 `ServiceHostConfigService::FIELD_RULES` 逐项对齐（同一套 min/max）。
 * disk_id 后端只校验「>=1」，这里同样不编造上限。
 */
const FIELD_RULES: FieldRule[] = [
  { key: 'cpu', labelKey: 'services.hostConfig.cpu', min: 1, max: 256 },
  { key: 'memory', labelKey: 'services.hostConfig.memory', min: 128, max: 1048576 },
  { key: 'in_bw', labelKey: 'services.hostConfig.inBw', min: 1, max: 100000 },
  { key: 'out_bw', labelKey: 'services.hostConfig.outBw', min: 1, max: 100000 },
  { key: 'flow', labelKey: 'services.hostConfig.flow', min: 1, max: 10485760 },
  { key: 'ip_num', labelKey: 'services.hostConfig.ipNum', min: 1, max: 253 },
  { key: 'ipv6_num', labelKey: 'services.hostConfig.ipv6Num', min: 1, max: 253 },
  { key: 'disk_size', labelKey: 'services.hostConfig.diskSize', min: 1, max: 65536 },
  { key: 'disk_id', labelKey: 'services.hostConfig.diskId', min: 1, max: Number.MAX_SAFE_INTEGER, rangeLabel: '' },
  { key: 'snap_num', labelKey: 'services.hostConfig.snapNum', min: 0, max: 1024 },
  { key: 'backup_num', labelKey: 'services.hostConfig.backupNum', min: 0, max: 1024 },
];

function createForm() {
  return {
    cpu: undefined as number | undefined,
    memory: undefined as number | undefined,
    in_bw: undefined as number | undefined,
    out_bw: undefined as number | undefined,
    flow: undefined as number | undefined,
    ip_num: undefined as number | undefined,
    ipv6_num: undefined as number | undefined,
    disk_size: undefined as number | undefined,
    disk_id: undefined as number | undefined,
    snap_num: undefined as number | undefined,
    backup_num: undefined as number | undefined,
    ip_group: '',
  };
}

const form = reactive(createForm());
const submitting = ref(false);

const ruleMap = new Map(FIELD_RULES.map((rule) => [rule.key, rule] as const));

/** 输入框下面的范围说明：让运营在填之前就知道边界，而不是提交后被弹窗纠正 */
function rangeHint(key: NumericKey) {
  const rule = ruleMap.get(key);
  if (!rule) return '';

  return rule.rangeLabel === ''
    ? t('services.hostConfig.positiveInteger')
    : t('services.hostConfig.rangeHint', { min: rule.min, max: rule.max });
}

const diskIdMissing = computed(
  () =>
    form.disk_size !== undefined && form.disk_size !== null && (form.disk_id === undefined || form.disk_id === null),
);

watch(
  () => props.visible,
  (visible) => {
    if (visible) Object.assign(form, createForm());
  },
);

function close() {
  emit('update:visible', false);
}

/** 只收填了值的字段；null 与 undefined 都按「不改」处理 */
function buildPayload(): ServiceHostConfigPayload {
  const payload: ServiceHostConfigPayload = {};

  for (const rule of FIELD_RULES) {
    const value = form[rule.key];
    if (value === undefined || value === null) continue;
    payload[rule.key] = Number(value);
  }

  const ipGroup = form.ip_group.trim();
  if (ipGroup !== '') payload.ip_group = ipGroup;

  return payload;
}

/** 前端先按后端同一套 min/max 校验，返回第一条中文提示；通过返回空串 */
function validatePayload(): string {
  for (const rule of FIELD_RULES) {
    const value = form[rule.key];
    if (value === undefined || value === null) continue;

    const label = t(rule.labelKey);
    // t-input-number 在清空时可能给 null 或空串，这里统一按数字再判一次
    const numeric = Number(value);
    if (!Number.isInteger(numeric)) {
      return t('services.hostConfig.integerRequired', { field: label });
    }
    if (numeric < rule.min || numeric > rule.max) {
      return t('services.hostConfig.rangeInvalid', { field: label, min: rule.min, max: rule.max });
    }
  }

  return '';
}

async function submit() {
  const invalid = validatePayload();
  if (invalid) {
    MessagePlugin.warning(invalid);
    return;
  }

  const payload = buildPayload();
  if (Object.keys(payload).length === 0) {
    MessagePlugin.warning(t('services.hostConfig.nothingChanged'));
    return;
  }

  submitting.value = true;
  try {
    const response = await userApi.changeServiceHostConfig(props.userId, props.serviceId, payload);
    // 成功提示用后端/上游原文（多为「已提交变更」这类上游措辞），没有才回落到本地文案
    MessagePlugin.success(response?.message || t('services.hostConfig.success'));
    emit('success', response || {});
    close();
  } catch (error) {
    // 上游原因必须原样透出（「当前上游不支持在线变更规格」「CPU 不能低于当前值」等），
    // errorMessage 只做「是否可信中文」过滤，不会替换成通用文案
    MessagePlugin.error(errorMessage(error, t('services.hostConfig.failed')));
  } finally {
    submitting.value = false;
  }
}
</script>
<style lang="less" scoped>
.host-config-dialog {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
}

.host-config-form {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
}

.host-config-section {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.host-config-section h4 {
  position: relative;
  margin: 0;
  padding-left: 10px;
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-3, 14px);
  font-weight: 650;
  line-height: 22px;
}

.host-config-section h4::before {
  position: absolute;
  inset: 4px auto 4px 0;
  width: 3px;
  border-radius: var(--td-radius-small, 2px);
  background: var(--td-brand-color);
  content: '';
}

.host-config-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 var(--td-comp-margin-m);
}

/* 输入框与下面的范围说明竖排：t-form 默认把控件内容当行内序列，副标题会挤到输入框右侧 */
.host-config-section :deep(.t-form__item) {
  margin-bottom: var(--td-comp-margin-s);
}

.host-config-section :deep(.t-form__controls-content) {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 2px;
}

.host-config-range {
  margin: 0;
  color: var(--td-text-color-placeholder);
  font-size: var(--td-font-size-size-1, 12px);
  line-height: 1.4;
  white-space: nowrap;
}

.host-config-note {
  margin-top: var(--td-comp-margin-xs);
}

@media (width <= 640px) {
  .host-config-grid {
    grid-template-columns: 1fr;
  }
}
</style>
