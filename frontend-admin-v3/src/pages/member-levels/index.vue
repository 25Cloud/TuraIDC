<template>
  <div class="member-levels-page">
    <t-card :bordered="false" :loading="loading">
      <template #title>等级列表</template>
      <template #subtitle>共 {{ levels.length }} 个档位</template>
      <template #actions>
        <t-space>
          <t-button v-if="canManage" theme="primary" @click="openCreateDialog">
            <template #icon><add-icon /></template>
            新增等级
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
              <span>至 {{ row.sales_amount_max ? formatMoney(row.sales_amount_max) : '不封顶' }}</span>
            </div>
          </template>
          <template #rate="{ row }">
            <t-tag theme="success" variant="light">{{ formatPercent(row.reward_rate) }}</t-tag>
          </template>
          <template #status="{ row }">
            <t-tag :theme="Number(row.status) === 1 ? 'success' : 'default'" variant="light">
              {{ Number(row.status) === 1 ? '启用' : '停用' }}
            </t-tag>
          </template>
          <template #updatedAt="{ row }">{{ formatDateTime(row.updated_at) }}</template>
          <template #remark="{ row }">{{ fieldValue(row.remark) }}</template>
          <template #actions="{ row }">
            <t-space v-if="canManage" size="small">
              <t-button theme="primary" variant="text" @click="openEditDialog(row)">编辑</t-button>
              <t-button theme="danger" variant="text" @click="handleDelete(row)">删除</t-button>
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
              @click="handleMobileAction(row, $event)"
            >
              <t-button class="member-mobile-card__more" variant="text" shape="square">...</t-button>
            </t-dropdown>
          </div>
          <dl>
            <div>
              <dt>门槛</dt>
              <dd>
                {{ formatMoney(row.sales_amount_min) }} ~
                {{ row.sales_amount_max ? formatMoney(row.sales_amount_max) : '不封顶' }}
              </dd>
            </div>
            <div>
              <dt>状态</dt>
              <dd>
                <t-tag :theme="Number(row.status) === 1 ? 'success' : 'default'" variant="light">
                  {{ Number(row.status) === 1 ? '启用' : '停用' }}
                </t-tag>
              </dd>
            </div>
          </dl>
        </article>
      </div>
    </t-card>

    <t-dialog
      v-model:visible="dialogVisible"
      :header="form.id ? '编辑会员等级' : '新增会员等级'"
      width="720px"
      :confirm-btn="canManage ? { content: '保存', theme: 'primary' } : null"
      :confirm-loading="saving"
      @confirm="submitForm"
    >
      <t-form ref="formRef" :data="form" :rules="rules" label-align="top">
        <div class="member-form-grid">
          <t-form-item label="等级名称" name="name">
            <t-input v-model="form.name" placeholder="例如：黄金会员" />
          </t-form-item>
          <t-form-item label="等级编码" name="code">
            <t-input v-model="form.code" placeholder="例如：gold" />
          </t-form-item>
          <t-form-item label="累计销售额下限" name="sales_amount_min">
            <t-input-number v-model="form.sales_amount_min" :min="0" :decimal-places="2" />
          </t-form-item>
          <t-form-item label="累计销售额上限" name="sales_amount_max">
            <t-input v-model="form.sales_amount_max" placeholder="留空表示不封顶" />
          </t-form-item>
          <t-form-item label="返利比例（%）" name="reward_rate">
            <t-input-number v-model="form.reward_rate" :min="0" :max="100" :decimal-places="2" />
          </t-form-item>
          <t-form-item label="排序值" name="sort_order">
            <t-input-number v-model="form.sort_order" :min="0" :max="999999" />
          </t-form-item>
          <t-form-item label="状态" name="status">
            <t-switch v-model="form.status" :custom-value="[1, 0]" :label="['启用', '停用']" />
          </t-form-item>
          <t-form-item class="member-form-span" label="备注" name="remark">
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
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { required } from '@/utils/formRules';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

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
  name: [required('请输入等级名称')],
  sales_amount_min: [required('请输入累计销售额下限')],
  reward_rate: [required('请输入返利比例')],
};

const columns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'sort_order', title: '排序', width: 90 },
  { colKey: 'level', title: '等级信息', minWidth: 220 },
  { colKey: 'range', title: '累计销售额门槛', minWidth: 220 },
  { colKey: 'rate', title: '返利比例', width: 130 },
  { colKey: 'status', title: '状态', width: 110 },
  { colKey: 'remark', title: '备注', minWidth: 180 },
  { colKey: 'updatedAt', title: '更新时间', width: 170 },
  { colKey: 'actions', title: '操作', fixed: 'right', width: 130 },
];
const mobileActionOptions: DropdownOption[] = [
  { content: '编辑', value: 'edit' },
  { content: '删除', value: 'delete', theme: 'error' },
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
    MessagePlugin.error(errorMessage(error, '加载会员等级失败'));
  } finally {
    loading.value = false;
  }
}

function openCreateDialog() {
  if (!canManage.value) {
    MessagePlugin.warning('当前账号无会员等级管理权限');
    return;
  }

  Object.assign(form, createDefaultForm());
  dialogVisible.value = true;
}

function openEditDialog(row: MemberLevelRecord) {
  if (!canManage.value) {
    MessagePlugin.warning('当前账号无会员等级管理权限');
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
    MessagePlugin.warning('当前账号无会员等级管理权限');
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
      MessagePlugin.success('会员等级已更新');
    } else {
      await adminApi.memberLevels.create(payload);
      MessagePlugin.success('会员等级已创建');
    }
    dialogVisible.value = false;
    await loadLevels();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存会员等级失败'));
  } finally {
    saving.value = false;
  }
}

function buildPayload(): MemberLevelPayload | null {
  const maxValue = form.sales_amount_max.trim();
  if (maxValue !== '' && Number.isNaN(Number(maxValue))) {
    MessagePlugin.warning('累计销售额上限必须是有效数字');
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
    MessagePlugin.warning('当前账号无会员等级管理权限');
    return;
  }

  const dialog = DialogPlugin.confirm({
    header: '删除会员等级',
    body: `确认删除等级“${fieldValue(row.name)}”吗？`,
    confirmBtn: { content: '确认删除', theme: 'danger' },
    async onConfirm() {
      try {
        await adminApi.memberLevels.delete(row.id);
        MessagePlugin.success('会员等级已删除');
        dialog.destroy();
        await loadLevels();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除会员等级失败'));
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
