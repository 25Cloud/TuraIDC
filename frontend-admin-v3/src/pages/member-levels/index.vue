<template>
  <div class="member-levels-page">
    <t-card :bordered="false" :loading="loading">
      <template #title>{{ $t('admin.memberLevels.listTitle') }}</template>
      <template #subtitle>{{ $t('admin.memberLevels.tierCount', { count: levels.length }) }}</template>
      <template #actions>
        <t-space>
          <t-button v-if="canManage" theme="primary" @click="openCreateDialog">
            <template #icon><add-icon /></template>
            {{ $t('admin.memberLevels.addLevel') }}
          </t-button>
        </t-space>
      </template>

      <div v-if="!isMobile" class="table-scroll">
        <t-table row-key="id" :data="levels" :columns="columns" hover table-layout="fixed">
          <template #level="{ row }">
            <div class="stack-cell">
              <strong>{{ fieldValue(row.name) }}</strong>
              <span>{{ fieldValue(row.code) }}</span>
            </div>
          </template>
          <template #range="{ row }">
            <div class="stack-cell">
              <strong>{{ formatMoney(row.sales_amount_min) }}</strong>
              <span
                >{{ $t('admin.memberLevels.upTo') }}
                {{ row.sales_amount_max ? formatMoney(row.sales_amount_max) : $t('admin.memberLevels.noCap') }}</span
              >
            </div>
          </template>
          <template #rate="{ row }">
            <t-tag theme="success" variant="light">{{ formatPercent(row.reward_rate) }}</t-tag>
          </template>
          <template #status="{ row }">
            <t-tag :theme="Number(row.status) === 1 ? 'success' : 'default'" variant="light">
              {{ Number(row.status) === 1 ? $t('admin.memberLevels.enabled') : $t('admin.memberLevels.disabled') }}
            </t-tag>
          </template>
          <template #updatedAt="{ row }">{{ formatDateTime(row.updated_at) }}</template>
          <template #remark="{ row }">{{ fieldValue(row.remark) }}</template>
          <template #actions="{ row }">
            <t-space v-if="canManage" size="small">
              <t-button theme="primary" variant="text" @click="openEditDialog(row)">{{
                $t('admin.memberLevels.actionEdit')
              }}</t-button>
              <t-button theme="danger" variant="text" @click="handleDelete(row)">{{
                $t('admin.memberLevels.actionDelete')
              }}</t-button>
            </t-space>
            <span v-else>-</span>
          </template>
        </t-table>
      </div>

      <div v-else class="mobile-list">
        <article v-for="row in levels" :key="row.id" class="member-mobile-card">
          <div class="member-mobile-card__head">
            <div>
              <strong>{{ fieldValue(row.name) }}</strong>
              <t-tag theme="success" variant="light">{{ formatPercent(row.reward_rate) }}</t-tag>
            </div>
            <t-dropdown
              v-if="canManage"
              trigger="click"
              placement="bottom-right"
              :options="mobileActionOptions"
              @click="handleMobileAction(row, $event as DropdownOption)"
            >
              <t-button class="member-mobile-card__more" variant="text" shape="square">...</t-button>
            </t-dropdown>
          </div>
          <dl>
            <div>
              <dt>{{ $t('admin.memberLevels.threshold') }}</dt>
              <dd>
                {{ formatMoney(row.sales_amount_min) }} ~
                {{ row.sales_amount_max ? formatMoney(row.sales_amount_max) : $t('admin.memberLevels.noCap') }}
              </dd>
            </div>
            <div>
              <dt>{{ $t('admin.memberLevels.status') }}</dt>
              <dd>
                <t-tag :theme="Number(row.status) === 1 ? 'success' : 'default'" variant="light">
                  {{ Number(row.status) === 1 ? $t('admin.memberLevels.enabled') : $t('admin.memberLevels.disabled') }}
                </t-tag>
              </dd>
            </div>
          </dl>
        </article>
      </div>
    </t-card>

    <t-dialog
      v-model:visible="dialogVisible"
      :header="form.id ? $t('admin.memberLevels.editTitle') : $t('admin.memberLevels.addTitle')"
      width="720px"
      :confirm-btn="canManage ? { content: $t('admin.memberLevels.save'), theme: 'primary' } : null"
      :confirm-loading="saving"
      @confirm="submitForm"
    >
      <t-form ref="formRef" :data="form" :rules="rules" label-align="top">
        <div class="member-form-grid">
          <t-form-item :label="$t('admin.memberLevels.nameLabel')" name="name">
            <t-input v-model="form.name" :placeholder="$t('admin.memberLevels.namePlaceholder')" />
          </t-form-item>
          <t-form-item :label="$t('admin.memberLevels.codeLabel')" name="code">
            <t-input v-model="form.code" :placeholder="$t('admin.memberLevels.codePlaceholder')" />
          </t-form-item>
          <t-form-item :label="$t('admin.memberLevels.salesMin')" name="sales_amount_min">
            <t-input-number v-model="form.sales_amount_min" :min="0" :decimal-places="2" />
          </t-form-item>
          <t-form-item :label="$t('admin.memberLevels.salesMax')" name="sales_amount_max">
            <t-input v-model="form.sales_amount_max" :placeholder="$t('admin.memberLevels.salesMaxPlaceholder')" />
          </t-form-item>
          <t-form-item :label="$t('admin.memberLevels.rewardRateLabel')" name="reward_rate">
            <t-input-number v-model="form.reward_rate" :min="0" :max="100" :decimal-places="2" />
          </t-form-item>
          <t-form-item :label="$t('admin.memberLevels.sortOrder')" name="sort_order">
            <t-input-number v-model="form.sort_order" :min="0" :max="999999" />
          </t-form-item>
          <t-form-item :label="$t('admin.memberLevels.status')" name="status">
            <t-switch
              v-model="form.status"
              :custom-value="[1, 0]"
              :label="[$t('admin.memberLevels.enabled'), $t('admin.memberLevels.disabled')]"
            />
          </t-form-item>
          <t-form-item class="member-form-span" :label="$t('admin.memberLevels.remark')" name="remark">
            <t-textarea v-model="form.remark" :autosize="{ minRows: 3, maxRows: 5 }" :maxlength="255" />
          </t-form-item>
        </div>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { AddIcon } from 'tdesign-icons-vue-next';
import type { DropdownOption, FormInstanceFunctions, FormRule, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import type { MemberLevelPayload, MemberLevelRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import { AdminPermissions } from '@/constants/permissions';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { required } from '@/utils/formRules';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminMemberLevels' });

interface MemberLevelForm {
  id: number | string | null;
  name: string;
  code: string;
  sales_amount_min: number;
  sales_amount_max: string;
  reward_rate: number;
  status: number;
  sort_order: number;
  remark: string;
}

const loading = ref(false);
const saving = ref(false);
const dialogVisible = ref(false);
const formRef = ref<FormInstanceFunctions>();
const levels = ref<MemberLevelRecord[]>([]);
const isMobile = useMediaQuery('(max-width: 768px)');
const canManage = computed(() => hasAdminPermission(AdminPermissions.MEMBER_LEVEL_MANAGE));

const form = reactive<MemberLevelForm>(createDefaultForm());

const rules: Record<string, FormRule[]> = {
  name: [required(t('admin.memberLevels.nameRequired'))],
  sales_amount_min: [required(t('admin.memberLevels.salesMinRequired'))],
  reward_rate: [required(t('admin.memberLevels.rewardRateRequired'))],
};

const columns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'sort_order', title: t('common.sort'), width: 90 },
  { colKey: 'level', title: t('admin.memberLevels.levelInfo'), minWidth: 220 },
  { colKey: 'range', title: t('admin.memberLevels.salesThreshold'), minWidth: 220 },
  { colKey: 'rate', title: t('admin.memberLevels.rewardRate'), width: 130 },
  { colKey: 'status', title: t('common.status'), width: 110 },
  { colKey: 'remark', title: t('admin.memberLevels.remark'), minWidth: 180 },
  { colKey: 'updatedAt', title: t('admin.memberLevels.updatedAt'), width: 170 },
  { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 130 },
];
const mobileActionOptions: DropdownOption[] = [
  { content: t('admin.memberLevels.edit'), value: 'edit' },
  { content: t('admin.memberLevels.delete'), value: 'delete', theme: 'error' },
];

function createDefaultForm(): MemberLevelForm {
  return {
    id: null,
    name: '',
    code: '',
    sales_amount_min: 0,
    sales_amount_max: '',
    reward_rate: 0,
    status: 1,
    sort_order: 0,
    remark: '',
  };
}

async function loadLevels() {
  loading.value = true;
  try {
    const response = await adminApi.memberLevels.list();
    levels.value = response || [];
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('admin.memberLevels.loadFailed')));
  } finally {
    loading.value = false;
  }
}

function openCreateDialog() {
  if (!canManage.value) {
    MessagePlugin.warning(t('admin.memberLevels.noPermission'));
    return;
  }

  Object.assign(form, createDefaultForm());
  dialogVisible.value = true;
}

function openEditDialog(row: MemberLevelRecord) {
  if (!canManage.value) {
    MessagePlugin.warning(t('admin.memberLevels.noPermission'));
    return;
  }

  Object.assign(form, {
    id: row.id,
    name: String(row.name || ''),
    code: String(row.code || ''),
    sales_amount_min: Number(row.sales_amount_min || 0),
    sales_amount_max:
      row.sales_amount_max === null || row.sales_amount_max === undefined ? '' : String(row.sales_amount_max),
    reward_rate: Number(row.reward_rate || 0),
    status: Number(row.status ?? 1),
    sort_order: Number(row.sort_order || 0),
    remark: String(row.remark || ''),
  });
  dialogVisible.value = true;
}

async function submitForm() {
  if (!canManage.value) {
    MessagePlugin.warning(t('admin.memberLevels.noPermission'));
    return;
  }

  const result = await formRef.value?.validate?.();
  if (result !== true) return;

  const payload = buildPayload();
  if (!payload) return;

  saving.value = true;
  try {
    if (form.id) {
      await adminApi.memberLevels.update(form.id, payload);
      MessagePlugin.success(t('admin.memberLevels.updateSuccess'));
    } else {
      await adminApi.memberLevels.create(payload);
      MessagePlugin.success(t('admin.memberLevels.createSuccess'));
    }
    dialogVisible.value = false;
    await loadLevels();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('admin.memberLevels.saveFailed')));
  } finally {
    saving.value = false;
  }
}

function buildPayload(): MemberLevelPayload | null {
  const maxValue = form.sales_amount_max.trim();
  if (maxValue !== '' && Number.isNaN(Number(maxValue))) {
    MessagePlugin.warning(t('admin.memberLevels.salesMaxInvalid'));
    return null;
  }
  return {
    name: form.name.trim(),
    code: form.code.trim() || null,
    sales_amount_min: Number(form.sales_amount_min || 0),
    sales_amount_max: maxValue === '' ? null : Number(maxValue),
    reward_rate: Number(form.reward_rate || 0),
    status: Number(form.status ?? 1),
    sort_order: Number(form.sort_order || 0),
    remark: form.remark.trim() || null,
  };
}

function handleDelete(row: MemberLevelRecord) {
  if (!canManage.value) {
    MessagePlugin.warning(t('admin.memberLevels.noPermission'));
    return;
  }

  const dialog = DialogPlugin.confirm({
    header: t('admin.memberLevels.deleteTitle'),
    body: t('admin.memberLevels.deleteConfirm', { name: fieldValue(row.name) }),
    confirmBtn: { content: t('admin.memberLevels.confirmDelete'), theme: 'danger' },
    async onConfirm() {
      try {
        await adminApi.memberLevels.delete(row.id);
        MessagePlugin.success(t('admin.memberLevels.deleteSuccess'));
        dialog.destroy();
        await loadLevels();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('admin.memberLevels.deleteFailed')));
      }
    },
  });
}

function handleMobileAction(row: MemberLevelRecord, option: DropdownOption) {
  const action = String(option.value || '');
  if (action === 'edit') {
    openEditDialog(row);
    return;
  }
  if (action === 'delete') handleDelete(row);
}

function formatPercent(value: unknown) {
  return `${Number(value || 0).toFixed(2)}%`;
}

onMounted(loadLevels);
</script>
