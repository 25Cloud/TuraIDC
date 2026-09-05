<template>
  <div class="agent-discounts-page">
    <t-tabs v-model="activeTab">
      <!-- 代理组 -->
      <t-tab-panel value="agent-groups" label="代理组">
        <t-card :bordered="false" :loading="loadingAgentGroups">
          <template #title>代理组</template>
          <template #subtitle>共 {{ agentGroups.length }} 个分组</template>
          <template #actions>
            <t-button v-if="canManage" theme="primary" @click="openAgentGroupDialog()">
              <template #icon><add-icon /></template>
              新增代理组
            </t-button>
          </template>

          <div class="table-scroll">
            <t-table
              row-key="id"
              :data="agentGroups"
              :columns="agentGroupColumns"
              hover
              table-layout="fixed"
              :pagination="null"
            >
              <template #group="{ row }">
                <div class="stack-cell">
                  <strong>{{ fieldValue(row.name) }}</strong>
                  <span>{{ fieldValue(row.code) }}</span>
                </div>
              </template>
              <template #status="{ row }">
                <t-tag :theme="Number(row.status) === 1 ? 'success' : 'default'" variant="light">
                  {{ Number(row.status) === 1 ? '启用' : '停用' }}
                </t-tag>
              </template>
              <template #remark="{ row }">{{ fieldValue(row.remark) }}</template>
              <template #updatedAt="{ row }">{{ formatDateTime(row.updated_at) }}</template>
              <template #actions="{ row }">
                <t-space v-if="canManage" size="small">
                  <t-button theme="primary" variant="text" @click="openAgentGroupDialog(row)">编辑</t-button>
                  <t-button theme="danger" variant="text" @click="removeAgentGroup(row)">删除</t-button>
                </t-space>
                <span v-else>-</span>
              </template>
            </t-table>
          </div>
        </t-card>
      </t-tab-panel>

      <!-- 商品折扣组 -->
      <t-tab-panel value="product-groups" label="商品折扣组">
        <t-card :bordered="false" :loading="loadingProductGroups">
          <template #title>商品折扣组</template>
          <template #subtitle>共 {{ productGroups.length }} 个分组</template>
          <template #actions>
            <t-button v-if="canManage" theme="primary" @click="openProductGroupDialog()">
              <template #icon><add-icon /></template>
              新增折扣组
            </t-button>
          </template>

          <div class="table-scroll">
            <t-table
              row-key="id"
              :data="productGroups"
              :columns="productGroupColumns"
              hover
              table-layout="fixed"
              :pagination="null"
            >
              <template #group="{ row }">
                <div class="stack-cell">
                  <strong>{{ fieldValue(row.name) }}</strong>
                  <span>{{ fieldValue(row.code) }}</span>
                </div>
              </template>
              <template #minRate="{ row }">
                <t-tag theme="primary" variant="light">{{ formatPercent(row.min_discount_rate) }}</t-tag>
              </template>
              <template #costRate="{ row }">
                <t-tag theme="warning" variant="light">{{ formatPercent(row.cost_rate) }}</t-tag>
              </template>
              <template #status="{ row }">
                <t-tag :theme="Number(row.status) === 1 ? 'success' : 'default'" variant="light">
                  {{ Number(row.status) === 1 ? '启用' : '停用' }}
                </t-tag>
              </template>
              <template #remark="{ row }">{{ fieldValue(row.remark) }}</template>
              <template #actions="{ row }">
                <t-space v-if="canManage" size="small">
                  <t-button theme="primary" variant="text" @click="openProductGroupDialog(row)">编辑</t-button>
                  <t-button theme="danger" variant="text" @click="removeProductGroup(row)">删除</t-button>
                </t-space>
                <span v-else>-</span>
              </template>
            </t-table>
          </div>
        </t-card>
      </t-tab-panel>

      <!-- 折扣矩阵 -->
      <t-tab-panel value="matrix" label="折扣矩阵">
        <t-card :bordered="false" :loading="loadingMatrix">
          <template #title>折扣矩阵</template>
          <template #subtitle>行为商品折扣组，列为代理组</template>
          <template #actions>
            <t-space size="small">
              <t-button variant="outline" @click="loadMatrix">重新加载</t-button>
              <t-button v-if="canManage" theme="primary" :loading="savingMatrix" @click="saveMatrix">保存矩阵</t-button>
            </t-space>
          </template>

          <p class="agent-matrix__hint">
            折扣率单位为百分比（100 表示原价、90 表示九折），不得低于所属商品折扣组的最低折扣率。
            留空的格子不会提交，已保存的折扣不受影响；要取消某组合的折扣请填 100（按原价计费）。
          </p>

          <div v-if="matrixRows.length && agentGroups.length" class="agent-matrix">
            <table>
              <thead>
                <tr>
                  <th>商品折扣组</th>
                  <th v-for="group in agentGroups" :key="group.id">{{ fieldValue(group.name) }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in matrixRows" :key="row.id">
                  <th>
                    <div class="stack-cell">
                      <strong>{{ fieldValue(row.name) }}</strong>
                      <span>最低 {{ formatPercent(row.min_discount_rate) }}</span>
                    </div>
                  </th>
                  <td v-for="group in agentGroups" :key="`${row.id}-${group.id}`">
                    <t-input-number
                      v-model="matrixModel[cellKey(row.id, group.id)]"
                      theme="normal"
                      :min="0"
                      :max="100"
                      :step="1"
                      :decimal-places="2"
                      :disabled="!canManage"
                      placeholder="不设折扣"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="agent-matrix__empty">请先创建代理组与商品折扣组，再来配置折扣矩阵。</div>
        </t-card>
      </t-tab-panel>
    </t-tabs>

    <!-- 代理组表单 -->
    <t-dialog
      v-model:visible="agentGroupDialogVisible"
      :header="agentGroupForm.id ? '编辑代理组' : '新增代理组'"
      :confirm-btn="{ content: '保存', loading: savingAgentGroup }"
      width="560px"
      @confirm="submitAgentGroup"
    >
      <t-form ref="agentGroupFormRef" :data="agentGroupForm" :rules="agentGroupRules" label-width="110px">
        <div class="agent-form-grid">
          <t-form-item label="名称" name="name">
            <t-input v-model="agentGroupForm.name" :maxlength="50" placeholder="如：金牌代理" />
          </t-form-item>
          <t-form-item label="标识" name="code">
            <t-input v-model="agentGroupForm.code" :maxlength="50" placeholder="唯一标识，如 gold" />
          </t-form-item>
          <t-form-item label="排序" name="sort_order">
            <t-input-number v-model="agentGroupForm.sort_order" theme="normal" :min="0" />
          </t-form-item>
          <t-form-item label="状态" name="status">
            <t-radio-group v-model="agentGroupForm.status">
              <t-radio :value="1">启用</t-radio>
              <t-radio :value="0">停用</t-radio>
            </t-radio-group>
          </t-form-item>
          <t-form-item class="agent-form-span" label="全局折扣率" name="default_discount_rate">
            <t-input-number
              v-model="agentGroupForm.default_discount_rate"
              theme="normal"
              :min="0"
              :max="100"
              :decimal-places="2"
              :step="1"
              placeholder="留空则仅按折扣矩阵生效"
            />
            <template #help>
              <span
                >组内用户购买/续费所有商品时默认生效的折扣率；折扣矩阵中已配置的组按矩阵优先。留空表示不启用全局折扣。</span
              >
            </template>
          </t-form-item>
          <t-form-item class="agent-form-span" label="备注" name="remark">
            <t-textarea v-model="agentGroupForm.remark" :autosize="{ minRows: 3, maxRows: 5 }" :maxlength="255" />
          </t-form-item>
        </div>
      </t-form>
    </t-dialog>

    <!-- 商品折扣组表单 -->
    <t-dialog
      v-model:visible="productGroupDialogVisible"
      :header="productGroupForm.id ? '编辑商品折扣组' : '新增商品折扣组'"
      :confirm-btn="{ content: '保存', loading: savingProductGroup }"
      width="560px"
      @confirm="submitProductGroup"
    >
      <t-form ref="productGroupFormRef" :data="productGroupForm" :rules="productGroupRules" label-width="130px">
        <div class="agent-form-grid">
          <t-form-item label="名称" name="name">
            <t-input v-model="productGroupForm.name" :maxlength="50" placeholder="如：云服务器" />
          </t-form-item>
          <t-form-item label="标识" name="code">
            <t-input v-model="productGroupForm.code" :maxlength="50" placeholder="唯一标识，如 cloud" />
          </t-form-item>
          <t-form-item label="最低折扣率(%)" name="min_discount_rate">
            <t-input-number
              v-model="productGroupForm.min_discount_rate"
              theme="normal"
              :min="0"
              :max="100"
              :decimal-places="2"
            />
          </t-form-item>
          <t-form-item label="成本占比(%)" name="cost_rate">
            <t-input-number
              v-model="productGroupForm.cost_rate"
              theme="normal"
              :min="0"
              :max="100"
              :decimal-places="2"
            />
          </t-form-item>
          <t-form-item label="排序" name="sort_order">
            <t-input-number v-model="productGroupForm.sort_order" theme="normal" :min="0" />
          </t-form-item>
          <t-form-item label="状态" name="status">
            <t-radio-group v-model="productGroupForm.status">
              <t-radio :value="1">启用</t-radio>
              <t-radio :value="0">停用</t-radio>
            </t-radio-group>
          </t-form-item>
          <t-form-item class="agent-form-span" label="备注" name="remark">
            <t-textarea v-model="productGroupForm.remark" :autosize="{ minRows: 3, maxRows: 5 }" :maxlength="255" />
          </t-form-item>
        </div>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { AddIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import type {
  AgentGroupDiscountMatrixRow,
  AgentGroupDiscountMatrixSavePayload,
  AgentGroupPayload,
  AgentGroupRecord,
  ProductDiscountGroupPayload,
  ProductDiscountGroupRecord,
} from '@/api/admin';
import { adminApi } from '@/api/admin';
import { AdminPermissions } from '@/constants/permissions';
import { fieldValue, formatDateTime } from '@/utils/format';
import { required } from '@/utils/formRules';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

defineOptions({
  name: 'AdminAgentDiscounts',
});

interface AgentGroupForm {
  id: number | string | null;
  name: string;
  code: string;
  status: number;
  default_discount_rate: number | null;
  sort_order: number;
  remark: string;
}

interface ProductGroupForm {
  id: number | string | null;
  name: string;
  code: string;
  min_discount_rate: number;
  cost_rate: number;
  status: number;
  sort_order: number;
  remark: string;
}

const activeTab = ref('agent-groups');

const loadingAgentGroups = ref(false);
const loadingProductGroups = ref(false);
const loadingMatrix = ref(false);
const savingAgentGroup = ref(false);
const savingProductGroup = ref(false);
const savingMatrix = ref(false);

const agentGroups = ref<AgentGroupRecord[]>([]);
const productGroups = ref<ProductDiscountGroupRecord[]>([]);
const matrixRows = ref<AgentGroupDiscountMatrixRow[]>([]);
const matrixModel = reactive<Record<string, number | undefined>>({});

const agentGroupDialogVisible = ref(false);
const productGroupDialogVisible = ref(false);
const agentGroupFormRef = ref<FormInstanceFunctions>();
const productGroupFormRef = ref<FormInstanceFunctions>();

const canManage = computed(() => hasAdminPermission(AdminPermissions.AGENT_DISCOUNT_MANAGE));

const agentGroupForm = reactive<AgentGroupForm>(createAgentGroupForm());
const productGroupForm = reactive<ProductGroupForm>(createProductGroupForm());

const agentGroupRules: Record<string, FormRule[]> = {
  name: [required('请输入代理组名称')],
  code: [required('请输入代理组标识')],
};

const productGroupRules: Record<string, FormRule[]> = {
  name: [required('请输入折扣组名称')],
  code: [required('请输入折扣组标识')],
  min_discount_rate: [required('请输入最低折扣率')],
  cost_rate: [required('请输入成本占比')],
};

const agentGroupColumns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'sort_order', title: '排序', width: 90 },
  { colKey: 'group', title: '代理组', minWidth: 220 },
  { colKey: 'status', title: '状态', width: 110 },
  { colKey: 'remark', title: '备注', minWidth: 200 },
  { colKey: 'updatedAt', title: '更新时间', width: 170 },
  { colKey: 'actions', title: '操作', fixed: 'right', width: 130 },
];

const productGroupColumns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'sort_order', title: '排序', width: 90 },
  { colKey: 'group', title: '商品折扣组', minWidth: 220 },
  { colKey: 'minRate', title: '最低折扣率', width: 130 },
  { colKey: 'costRate', title: '成本占比', width: 130 },
  { colKey: 'status', title: '状态', width: 110 },
  { colKey: 'remark', title: '备注', minWidth: 180 },
  { colKey: 'actions', title: '操作', fixed: 'right', width: 130 },
];

function createAgentGroupForm(): AgentGroupForm {
  return { id: null, name: '', code: '', status: 1, default_discount_rate: null, sort_order: 0, remark: '' };
}

function createProductGroupForm(): ProductGroupForm {
  return { id: null, name: '', code: '', min_discount_rate: 100, cost_rate: 0, status: 1, sort_order: 0, remark: '' };
}

function formatPercent(value: unknown) {
  return `${Number(value || 0).toFixed(2)}%`;
}

function cellKey(productGroupId: number | string, agentGroupId: number | string) {
  return `${productGroupId}:${agentGroupId}`;
}

function assertManage() {
  if (canManage.value) return true;
  MessagePlugin.warning('当前账号无代理折扣管理权限');
  return false;
}

async function loadAgentGroups() {
  loadingAgentGroups.value = true;
  try {
    agentGroups.value = (await adminApi.agentDiscount.agentGroups.list()) || [];
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载代理组失败'));
  } finally {
    loadingAgentGroups.value = false;
  }
}

async function loadProductGroups() {
  loadingProductGroups.value = true;
  try {
    productGroups.value = (await adminApi.agentDiscount.productDiscountGroups.list()) || [];
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载商品折扣组失败'));
  } finally {
    loadingProductGroups.value = false;
  }
}

// 保存后会立刻重新加载，而「重新加载」按钮可随时点击，两次 GET 可能交叠返回。
// 用自增序号丢弃过期响应，避免旧折扣值覆盖刚保存的结果、下次保存又把旧值提交回去。
let matrixLoadSeq = 0;

async function loadMatrix() {
  const seq = ++matrixLoadSeq;
  loadingMatrix.value = true;
  try {
    const rows = (await adminApi.agentDiscount.matrix.get()) || [];
    if (seq !== matrixLoadSeq) return;

    matrixRows.value = rows;
    Object.keys(matrixModel).forEach((key) => delete matrixModel[key]);
    rows.forEach((row) => {
      (row.discounts || []).forEach((item) => {
        const key = cellKey(row.id, item.agent_group_id);
        matrixModel[key] = item.discount_rate === null ? undefined : Number(item.discount_rate);
      });
    });
  } catch (error) {
    if (seq !== matrixLoadSeq) return;
    MessagePlugin.error(errorMessage(error, '加载折扣矩阵失败'));
  } finally {
    if (seq === matrixLoadSeq) {
      loadingMatrix.value = false;
    }
  }
}

function openAgentGroupDialog(row?: AgentGroupRecord) {
  if (!assertManage()) return;
  Object.assign(agentGroupForm, createAgentGroupForm());
  if (row) {
    Object.assign(agentGroupForm, {
      id: row.id,
      name: String(row.name || ''),
      code: String(row.code || ''),
      status: Number(row.status ?? 1),
      default_discount_rate:
        row.default_discount_rate === null ||
        row.default_discount_rate === undefined ||
        row.default_discount_rate === ''
          ? null
          : Number(row.default_discount_rate),
      sort_order: Number(row.sort_order || 0),
      remark: String(row.remark || ''),
    });
  }
  agentGroupDialogVisible.value = true;
}

async function submitAgentGroup() {
  if (!assertManage()) return;
  const valid = await agentGroupFormRef.value?.validate?.();
  if (valid !== true) return;

  const payload: AgentGroupPayload = {
    name: agentGroupForm.name.trim(),
    code: agentGroupForm.code.trim(),
    status: Number(agentGroupForm.status ?? 1),
    default_discount_rate:
      agentGroupForm.default_discount_rate === null || agentGroupForm.default_discount_rate === undefined
        ? null
        : Number(agentGroupForm.default_discount_rate),
    sort_order: Number(agentGroupForm.sort_order || 0),
    remark: agentGroupForm.remark.trim() || null,
  };

  savingAgentGroup.value = true;
  try {
    if (agentGroupForm.id) {
      await adminApi.agentDiscount.agentGroups.update(agentGroupForm.id, payload);
      MessagePlugin.success('代理组已更新');
    } else {
      await adminApi.agentDiscount.agentGroups.create(payload);
      MessagePlugin.success('代理组已创建');
    }
    agentGroupDialogVisible.value = false;
    await loadAgentGroups();
    await loadMatrix();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存代理组失败'));
  } finally {
    savingAgentGroup.value = false;
  }
}

function removeAgentGroup(row: AgentGroupRecord) {
  if (!assertManage()) return;
  const dialog = DialogPlugin.confirm({
    header: '删除代理组',
    body: `确认删除代理组“${fieldValue(row.name)}”吗？组内仍有用户时将无法删除。`,
    confirmBtn: { content: '确认删除', theme: 'danger' },
    async onConfirm() {
      try {
        await adminApi.agentDiscount.agentGroups.delete(row.id);
        MessagePlugin.success('代理组已删除');
        dialog.destroy();
        await loadAgentGroups();
        await loadMatrix();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除代理组失败'));
      }
    },
  });
}

function openProductGroupDialog(row?: ProductDiscountGroupRecord) {
  if (!assertManage()) return;
  Object.assign(productGroupForm, createProductGroupForm());
  if (row) {
    Object.assign(productGroupForm, {
      id: row.id,
      name: String(row.name || ''),
      code: String(row.code || ''),
      min_discount_rate: Number(row.min_discount_rate ?? 100),
      cost_rate: Number(row.cost_rate ?? 0),
      status: Number(row.status ?? 1),
      sort_order: Number(row.sort_order || 0),
      remark: String(row.remark || ''),
    });
  }
  productGroupDialogVisible.value = true;
}

async function submitProductGroup() {
  if (!assertManage()) return;
  const valid = await productGroupFormRef.value?.validate?.();
  if (valid !== true) return;

  const payload: ProductDiscountGroupPayload = {
    name: productGroupForm.name.trim(),
    code: productGroupForm.code.trim(),
    min_discount_rate: Number(productGroupForm.min_discount_rate ?? 0),
    cost_rate: Number(productGroupForm.cost_rate ?? 0),
    status: Number(productGroupForm.status ?? 1),
    sort_order: Number(productGroupForm.sort_order || 0),
    remark: productGroupForm.remark.trim() || null,
  };

  savingProductGroup.value = true;
  try {
    if (productGroupForm.id) {
      await adminApi.agentDiscount.productDiscountGroups.update(productGroupForm.id, payload);
      MessagePlugin.success('商品折扣组已更新');
    } else {
      await adminApi.agentDiscount.productDiscountGroups.create(payload);
      MessagePlugin.success('商品折扣组已创建');
    }
    productGroupDialogVisible.value = false;
    await loadProductGroups();
    await loadMatrix();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存商品折扣组失败'));
  } finally {
    savingProductGroup.value = false;
  }
}

function removeProductGroup(row: ProductDiscountGroupRecord) {
  if (!assertManage()) return;
  const dialog = DialogPlugin.confirm({
    header: '删除商品折扣组',
    body: `确认删除折扣组“${fieldValue(row.name)}”吗？组内仍有商品时将无法删除。`,
    confirmBtn: { content: '确认删除', theme: 'danger' },
    async onConfirm() {
      try {
        await adminApi.agentDiscount.productDiscountGroups.delete(row.id);
        MessagePlugin.success('商品折扣组已删除');
        dialog.destroy();
        await loadProductGroups();
        await loadMatrix();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除商品折扣组失败'));
      }
    },
  });
}

async function saveMatrix() {
  if (!assertManage()) return;

  // 后端 items.*.discount_rate 是 required|numeric，空格子不能带 null 提交，
  // 否则整批 422、连填好的格子一起保存不进去；空格子直接不提交。
  const items: AgentGroupDiscountMatrixSavePayload['items'] = [];
  const belowMinimum: string[] = [];

  matrixRows.value.forEach((row) => {
    const minimum = Number(row.min_discount_rate ?? 0);
    agentGroups.value.forEach((group) => {
      const value = matrixModel[cellKey(row.id, group.id)];
      if (value === undefined || value === null || Number.isNaN(Number(value))) return;

      const rate = Number(value);
      // 后端保存时同样按库里的最低折扣率拦截，这里先拦一道是为了能指出具体是哪个格子——
      // 后端返回的报错只说“折扣率不能低于最低折扣率”，不带行列信息。
      if (rate < minimum) {
        belowMinimum.push(`${fieldValue(row.name)} × ${fieldValue(group.name)}`);
      }
      items.push({ agent_group_id: group.id, product_discount_group_id: row.id, discount_rate: rate });
    });
  });

  if (belowMinimum.length) {
    MessagePlugin.error(`以下组合的折扣率低于所属折扣组的最低折扣率：${belowMinimum.join('、')}`);
    return;
  }

  if (!items.length) {
    MessagePlugin.warning('没有需要保存的折扣，请先填写折扣率');
    return;
  }

  savingMatrix.value = true;
  try {
    await adminApi.agentDiscount.matrix.save({ items });
    MessagePlugin.success('折扣矩阵已保存');
    await loadMatrix();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存折扣矩阵失败'));
  } finally {
    savingMatrix.value = false;
  }
}

onMounted(async () => {
  await Promise.all([loadAgentGroups(), loadProductGroups()]);
  await loadMatrix();
});
</script>
