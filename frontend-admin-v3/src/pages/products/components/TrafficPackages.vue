<template>
  <t-card :bordered="false">
    <div class="traffic-head">
      <div>
        <h2>流量包分组</h2>
        <p>按旧端 `traffic_package_catalog` 设置项维护分组和档位，支持保存当前分组与从上游拉取后回填。</p>
      </div>
      <div class="traffic-actions">
        <t-button theme="primary" variant="outline" @click="openTrafficGroupDialog()">新增分组</t-button>
        <t-button
          variant="outline"
          :loading="trafficPulling"
          :disabled="!selectedTrafficGroup"
          @click="pullTrafficPackages"
        >
          上游拉取
        </t-button>
        <t-button
          theme="primary"
          :loading="trafficSaving"
          :disabled="!selectedTrafficGroup"
          @click="saveTrafficPackages"
        >
          保存当前分组
        </t-button>
      </div>
    </div>

    <div class="traffic-editor">
      <aside class="traffic-group-list">
        <article
          v-for="group in trafficGroupItems"
          :key="group.id"
          class="traffic-group-item"
          :class="{ active: String(selectedTrafficGroupId) === String(group.id) }"
        >
          <button type="button" @click="handleTrafficGroupChange(group.id)">
            <strong>{{ group.name || '未命名分组' }}</strong>
            <span>{{ group.product_group_label || `分类 #${group.effective_product_group_id || '--'}` }}</span>
            <small
              >已绑定 {{ group.product_ids.length }} 个配置 · {{ trafficGroupPackageCount(group.id) }} 个档位</small
            >
          </button>
          <t-space size="small">
            <t-button size="small" variant="text" theme="primary" @click.stop="openTrafficGroupDialog(group)"
              >编辑</t-button
            >
            <t-button size="small" variant="text" theme="danger" @click.stop="removeTrafficGroup(group)">删除</t-button>
          </t-space>
        </article>
        <t-empty v-if="trafficGroupItems.length === 0" description="暂无流量包分组" />
      </aside>

      <section class="traffic-package-editor">
        <template v-if="selectedTrafficGroup">
          <div class="traffic-package-head">
            <div>
              <strong>{{ selectedTrafficGroup.name }}</strong>
              <span>{{
                selectedTrafficGroup.product_group_label || `分类 #${selectedTrafficGroup.effective_product_group_id}`
              }}</span>
              <small>绑定配置：{{ trafficBoundProductSummary(selectedTrafficGroup) }}</small>
            </div>
            <t-space size="small">
              <t-button variant="outline" @click="openTrafficGroupDialog(selectedTrafficGroup)">调整绑定</t-button>
              <t-button theme="primary" variant="outline" @click="addTrafficRow">新增流量包</t-button>
            </t-space>
          </div>

          <div class="traffic-row-list">
            <div v-for="(row, index) in trafficRows" :key="row._rowKey" class="traffic-row">
              <t-input v-model="row.label" placeholder="档位名称" />
              <t-input-number v-model="row.target_value" :min="1" placeholder="目标 GB" />
              <t-input-number v-model="row.price" :min="0" :decimal-places="2" placeholder="售价" />
              <t-switch v-model="row.enabled" />
              <t-button variant="text" theme="danger" @click="removeTrafficRow(index)">删除</t-button>
            </div>
            <t-empty v-if="trafficRows.length === 0" description="暂无档位，请新增或从上游拉取" />
          </div>
        </template>
        <t-empty v-else description="请选择流量包分组" />
      </section>
    </div>
  </t-card>

  <t-dialog
    v-model:visible="trafficGroupDialogVisible"
    :header="trafficGroupEditingId ? '编辑流量包分组' : '新增流量包分组'"
    width="560px"
    :confirm-btn="{ content: '保存分组', loading: trafficGroupSubmitting }"
    @confirm="submitTrafficGroup"
  >
    <t-form :data="trafficGroupForm" label-width="110px">
      <t-form-item label="分组名称" name="name">
        <t-input v-model="trafficGroupForm.name" placeholder="例如 基础流量包" maxlength="30" />
      </t-form-item>
      <t-form-item label="绑定配置" name="product_ids">
        <product-binding-tree-select
          v-model="trafficGroupForm.product_ids"
          mode="batch"
          compact
          :popup-max-height="340"
          placeholder="按产品类型 / 地区 / 可用区选择流量包适用商品"
          @change="handleTrafficBindingsChange"
        />
      </t-form-item>
    </t-form>
  </t-dialog>
</template>
<script setup lang="ts">
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref } from 'vue';

import type { ProductBindingRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import { productApi } from '@/api/product';
import ProductBindingTreeSelect from '@/components/product-binding-tree-select/index.vue';

import { errorMessage, normalizeProductIds } from '../composables/useProductShared';

interface TrafficPackageGroup {
  id: string;
  name: string;
  product_type: string;
  product_group_key: string;
  first_product_group_id: number | null;
  second_product_group_id: number | null;
  third_product_group_id: number | null;
  effective_product_group_id: number;
  product_group_label: string;
  product_ids: number[];
  sort_order: number;
}

interface TrafficPackageItem {
  _rowKey: string;
  traffic_group_id: string;
  group_name: string;
  first_product_group_id: number | null;
  second_product_group_id: number | null;
  third_product_group_id: number | null;
  effective_product_group_id: number;
  product_group_label: string;
  product_type: string;
  product_ids: number[];
  label: string;
  target_value: number;
  price: number;
  enabled: boolean;
  sort_order: number;
}

// --- State ---
const trafficLoading = ref(false);
const trafficSaving = ref(false);
const trafficPulling = ref(false);
const trafficGroupDialogVisible = ref(false);
const trafficGroupSubmitting = ref(false);
const trafficGroupEditingId = ref('');
const trafficGroupForm = reactive({
  name: '',
  product_type: '',
  product_group_key: '' as string,
  product_ids: [] as number[],
  first_product_group_id: null as number | null,
  second_product_group_id: null as number | null,
  third_product_group_id: null as number | null,
  effective_product_group_id: 0,
  product_group_label: '' as string,
});

const trafficGroupItems = ref<TrafficPackageGroup[]>([]);
const trafficPackageItems = ref<TrafficPackageItem[]>([]);
const selectedTrafficGroupId = ref('');
const trafficRows = ref<TrafficPackageItem[]>([]);

// --- Computeds ---
const selectedTrafficGroup = computed(
  () => trafficGroupItems.value.find((group) => String(group.id) === String(selectedTrafficGroupId.value)) || null,
);

// --- Methods ---
function handleTrafficGroupChange(groupId: string) {
  selectedTrafficGroupId.value = groupId;
  syncTrafficRowsFromSelection();
}

function resetTrafficGroupForm() {
  Object.assign(trafficGroupForm, {
    name: '',
    product_type: '',
    product_group_key: '',
    product_ids: [],
    first_product_group_id: null,
    second_product_group_id: null,
    third_product_group_id: null,
    effective_product_group_id: 0,
    product_group_label: '',
  });
}

function openTrafficGroupDialog(group?: TrafficPackageGroup | null) {
  trafficGroupEditingId.value = group?.id || '';
  resetTrafficGroupForm();
  Object.assign(trafficGroupForm, {
    name: group?.name || '',
    product_type: group?.product_type || '',
    product_group_key: group?.product_group_key || '',
    product_ids: normalizeProductIds(group?.product_ids || []),
    first_product_group_id: group?.first_product_group_id ?? null,
    second_product_group_id: group?.second_product_group_id ?? null,
    third_product_group_id: group?.third_product_group_id ?? null,
    effective_product_group_id: group?.effective_product_group_id || 0,
    product_group_label: group?.product_group_label || '',
  });
  trafficGroupDialogVisible.value = true;
}

function handleTrafficBindingsChange(payload: { binding_ids: string[]; bindings: ProductBindingRecord[] }) {
  const bindings = Array.isArray(payload?.bindings) ? payload.bindings : [];
  if (!bindings.length) {
    Object.assign(trafficGroupForm, {
      product_type: '',
      product_group_key: '',
      first_product_group_id: null,
      second_product_group_id: null,
      third_product_group_id: null,
      effective_product_group_id: 0,
      product_group_label: '',
    });
    trafficGroupForm.product_ids = [];
    return;
  }

  const normalized = resolveTrafficGroupCategory(bindings);
  trafficGroupForm.product_type = String(normalized[0]?.product_type || '').trim();
  trafficGroupForm.first_product_group_id = Number(normalized[0]?.first_product_group_id || 0) || null;
  trafficGroupForm.second_product_group_id = Number(normalized[0]?.second_product_group_id || 0) || null;
  trafficGroupForm.third_product_group_id = Number(normalized[0]?.third_product_group_id || 0) || null;
  trafficGroupForm.effective_product_group_id = Number(normalized[0]?.effective_product_group_id || 0);
  trafficGroupForm.product_group_label = String(normalized[0]?.category_full_name || '').trim();
  trafficGroupForm.product_group_key = '';
  trafficGroupForm.product_ids = normalized
    .map((b) => Number(b.product_id || 0))
    .filter((id) => Number.isFinite(id) && id > 0);
}

function resolveTrafficGroupCategory(bindings: ProductBindingRecord[]): ProductBindingRecord[] {
  const firstEffectiveId = Number(bindings[0]?.effective_product_group_id || 0);
  if (firstEffectiveId > 0) {
    const sameCategory = bindings.filter((b) => Number(b.effective_product_group_id || 0) === firstEffectiveId);
    if (sameCategory.length < bindings.length) {
      MessagePlugin.warning('流量包分组仅支持同一分类下的配置，已自动仅保留首个分类的商品');
      return sameCategory;
    }
    return bindings;
  }

  // effective_product_group_id 为 0 时，无法确定分类归属，保留第一个配置并警告
  if (bindings.length > 1) {
    MessagePlugin.warning('部分配置无法确定分类归属，已自动仅保留第一个配置');
    return [bindings[0]];
  }
  return bindings;
}

async function submitTrafficGroup() {
  const name = trafficGroupForm.name.trim();
  const productType = String(trafficGroupForm.product_type || '').trim();
  const categoryId = Number(trafficGroupForm.effective_product_group_id || 0);
  const productIds = normalizeProductIds(trafficGroupForm.product_ids);
  if (!name) {
    MessagePlugin.warning('请填写分组名称');
    return;
  }
  if (!productType || categoryId <= 0) {
    MessagePlugin.warning('请选择关联分类与配置');
    return;
  }
  if (!productIds.length) {
    MessagePlugin.warning('请至少绑定一个配置');
    return;
  }

  const conflictGroup = trafficGroupItems.value.find((group) => {
    if (group.id === trafficGroupEditingId.value || group.product_type !== productType) return false;
    const boundIds = normalizeProductIds(group.product_ids);
    return boundIds.some((id) => productIds.includes(id));
  });
  if (conflictGroup) {
    MessagePlugin.warning(`所选配置已绑定在分组「${conflictGroup.name}」`);
    return;
  }

  trafficGroupSubmitting.value = true;
  try {
    const categoryLabel = String(trafficGroupForm.product_group_label || '').trim() || `分类 #${categoryId}`;
    const groupId = trafficGroupEditingId.value || generateTrafficGroupId(productType, categoryId);
    const previous = trafficGroupItems.value.find((group) => group.id === groupId);
    const nextGroup: TrafficPackageGroup = {
      id: groupId,
      name,
      product_type: productType,
      product_group_key: '',
      first_product_group_id: trafficGroupForm.first_product_group_id,
      second_product_group_id: trafficGroupForm.second_product_group_id,
      third_product_group_id: trafficGroupForm.third_product_group_id,
      effective_product_group_id: categoryId,
      product_group_label: categoryLabel,
      product_ids: productIds,
      sort_order: Number(previous?.sort_order || trafficGroupItems.value.length + 1),
    };
    const nextGroups = trafficGroupItems.value.some((group) => group.id === groupId)
      ? trafficGroupItems.value.map((group) => (group.id === groupId ? nextGroup : group))
      : trafficGroupItems.value.concat(nextGroup);
    const nextItems = trafficPackageItems.value.map((item) =>
      resolveTrafficGroupKey(item) === groupId
        ? createTrafficRow(
            {
              ...item,
              traffic_group_id: nextGroup.id,
              group_name: nextGroup.name,
              product_type: nextGroup.product_type,
              first_product_group_id: nextGroup.first_product_group_id,
              second_product_group_id: nextGroup.second_product_group_id,
              third_product_group_id: nextGroup.third_product_group_id,
              effective_product_group_id: nextGroup.effective_product_group_id,
              product_group_label: nextGroup.product_group_label,
              product_ids: nextGroup.product_ids,
            },
            nextGroup,
          )
        : item,
    );
    await saveTrafficPackageSettings(nextItems, nextGroups);
    trafficGroupItems.value = normalizeTrafficPackageGroups(nextGroups);
    trafficPackageItems.value = normalizeTrafficPackageItems(nextItems);
    selectedTrafficGroupId.value = groupId;
    syncTrafficRowsFromSelection();
    trafficGroupDialogVisible.value = false;
    MessagePlugin.success(trafficGroupEditingId.value ? '分组已更新' : '分组已创建');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存分组失败'));
  } finally {
    trafficGroupSubmitting.value = false;
  }
}

function removeTrafficGroup(group: TrafficPackageGroup) {
  const dialog = DialogPlugin.confirm({
    header: '删除流量包分组',
    body: `删除分组「${group.name}」后，该分组下的流量包配置也会一并移除，是否继续？`,
    confirmBtn: { content: '确认删除', theme: 'danger' },
    cancelBtn: '取消',
    async onConfirm() {
      try {
        const nextGroups = trafficGroupItems.value.filter((item) => item.id !== group.id);
        const nextItems = trafficPackageItems.value.filter((item) => resolveTrafficGroupKey(item) !== group.id);
        await saveTrafficPackageSettings(nextItems, nextGroups);
        trafficGroupItems.value = normalizeTrafficPackageGroups(nextGroups);
        trafficPackageItems.value = normalizeTrafficPackageItems(nextItems);
        if (selectedTrafficGroupId.value === group.id) {
          selectedTrafficGroupId.value = trafficGroupItems.value[0]?.id || '';
        }
        syncTrafficRowsFromSelection();
        MessagePlugin.success('分组已删除');
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除分组失败'));
      } finally {
        dialog.destroy();
      }
    },
  });
}

function addTrafficRow() {
  if (!selectedTrafficGroup.value) {
    MessagePlugin.warning('请先选择流量包分组');
    return;
  }
  trafficRows.value.push(createTrafficRow({}, selectedTrafficGroup.value));
}

function removeTrafficRow(index: number) {
  trafficRows.value.splice(index, 1);
}

async function saveTrafficPackages() {
  const group = selectedTrafficGroup.value;
  if (!group) {
    MessagePlugin.warning('请先选择流量包分组');
    return;
  }
  try {
    validateTrafficRows();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '流量包配置校验失败'));
    return;
  }

  trafficSaving.value = true;
  try {
    const preserved = trafficPackageItems.value.filter((item) => resolveTrafficGroupKey(item) !== group.id);
    const nextItems = preserved.concat(
      trafficRows.value.map((row, index) =>
        createTrafficRow(
          {
            ...row,
            traffic_group_id: group.id,
            group_name: group.name,
            first_product_group_id: group.first_product_group_id,
            second_product_group_id: group.second_product_group_id,
            third_product_group_id: group.third_product_group_id,
            effective_product_group_id: group.effective_product_group_id,
            product_group_label: group.product_group_label,
            product_type: group.product_type,
            product_ids: group.product_ids,
            sort_order: index + 1,
          },
          group,
        ),
      ),
    );
    await saveTrafficPackageSettings(nextItems, trafficGroupItems.value);
    trafficPackageItems.value = normalizeTrafficPackageItems(nextItems);
    syncTrafficRowsFromSelection();
    MessagePlugin.success('当前分组流量包已保存');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存流量包配置失败'));
  } finally {
    trafficSaving.value = false;
  }
}

async function pullTrafficPackages() {
  const group = selectedTrafficGroup.value;
  if (!group) {
    MessagePlugin.warning('请先选择流量包分组');
    return;
  }
  const categoryPayload = trafficPullCategoryPayload(group);
  if (!categoryPayload.second_product_group_id && !categoryPayload.third_product_group_id) {
    MessagePlugin.warning('请先调整流量包分组的关联分类');
    return;
  }

  trafficPulling.value = true;
  try {
    const response = (await productApi.pullTrafficPackages({
      ...categoryPayload,
      product_type: group.product_type,
      source_product_id: group.product_ids[0] || undefined,
    })) as Record<string, unknown>;
    const packages = Array.isArray(response.packages) ? response.packages : [];
    trafficRows.value = packages.map((item, index) =>
      createTrafficRow({ ...(item as Record<string, unknown>), sort_order: index + 1 }, group),
    );
    MessagePlugin.success('流量包配置已从上游拉取');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '拉取流量包配置失败'));
  } finally {
    trafficPulling.value = false;
  }
}

async function loadTrafficPackages() {
  trafficLoading.value = true;
  try {
    const response = await adminApi.settings.list({ group: 'traffic_package_catalog' });
    const rawItems = getSettingValue(response, 'items') ?? getSettingValue(response, 'traffic_package_catalog') ?? [];
    const rawGroups = getSettingValue(response, 'groups') ?? [];
    const parsedItems = normalizeTrafficPackageItems(parseJsonArray(rawItems));
    const parsedGroups = normalizeTrafficPackageGroups(parseJsonArray(rawGroups));
    trafficPackageItems.value = parsedItems;
    trafficGroupItems.value = parsedGroups.length ? parsedGroups : createTrafficGroupsFromItems(parsedItems);
    if (!trafficGroupItems.value.some((group) => String(group.id) === String(selectedTrafficGroupId.value))) {
      selectedTrafficGroupId.value = trafficGroupItems.value[0]?.id || '';
    }
    syncTrafficRowsFromSelection();
  } catch (error) {
    trafficGroupItems.value = [];
    trafficPackageItems.value = [];
    trafficRows.value = [];
    MessagePlugin.error(errorMessage(error, '加载流量包配置失败'));
  } finally {
    trafficLoading.value = false;
  }
}

// --- Helper functions ---
function parseJsonArray(rawValue: unknown) {
  try {
    const parsed = typeof rawValue === 'string' ? JSON.parse(rawValue || '[]') : rawValue;
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function getSettingValue(response: unknown, key: string) {
  if (Array.isArray(response)) {
    return response.find((item) => String((item as Record<string, unknown>).key || '') === key)?.value;
  }
  if (response && typeof response === 'object') {
    const record = response as Record<string, unknown>;
    if (Array.isArray(record.list)) {
      return record.list.find((item) => String((item as Record<string, unknown>).key || '') === key)?.value;
    }
    return record[key];
  }
  return undefined;
}

function createTrafficRow(item: Record<string, unknown> = {}, group = selectedTrafficGroup.value): TrafficPackageItem {
  const effectiveProductGroupId = numberFrom(
    item.effective_product_group_id,
    item.category_id,
    group?.effective_product_group_id,
  );
  return {
    _rowKey: String(item._rowKey || `traffic-package-${Date.now()}-${Math.random().toString(36).slice(2)}`),
    traffic_group_id: stringFrom(item.traffic_group_id, item.group_id, group?.id),
    group_name: stringFrom(item.group_name, item.name, group?.name),
    first_product_group_id: nullableNumberFrom(item.first_product_group_id, group?.first_product_group_id),
    second_product_group_id: nullableNumberFrom(item.second_product_group_id, group?.second_product_group_id),
    third_product_group_id: nullableNumberFrom(item.third_product_group_id, group?.third_product_group_id),
    effective_product_group_id: effectiveProductGroupId,
    product_group_label: stringFrom(item.product_group_label, item.category_label, group?.product_group_label),
    product_type: String(item.product_type || group?.product_type || ''),
    product_ids: normalizeProductIds(item.product_ids || group?.product_ids || []),
    label: String(item.label || ''),
    target_value: Number(item.target_value || 0),
    price: Number(item.price || 0),
    enabled: !(item.enabled === false || item.enabled === 0 || item.enabled === '0'),
    sort_order: Number(item.sort_order || 0),
  };
}

function normalizeTrafficPackageItems(items: unknown[]): TrafficPackageItem[] {
  return items
    .filter((item): item is Record<string, unknown> => !!item && typeof item === 'object')
    .map((item) => createTrafficRow(item))
    .filter((item) => item.effective_product_group_id > 0 && item.target_value > 0)
    .sort((a, b) => a.sort_order - b.sort_order || a.target_value - b.target_value);
}

function normalizeTrafficPackageGroups(groups: unknown[]): TrafficPackageGroup[] {
  return groups
    .filter((item): item is Record<string, unknown> => !!item && typeof item === 'object')
    .map((item) => {
      const effectiveProductGroupId = numberFrom(item.effective_product_group_id, item.category_id);
      return {
        id: stringFrom(item.id, item.group_id),
        name: String(item.name || '').trim(),
        product_type: String(item.product_type || '').trim(),
        product_group_key: String(item.product_group_key || '').trim(),
        first_product_group_id: nullableNumberFrom(item.first_product_group_id),
        second_product_group_id: nullableNumberFrom(item.second_product_group_id),
        third_product_group_id: nullableNumberFrom(item.third_product_group_id),
        effective_product_group_id: effectiveProductGroupId,
        product_group_label: stringFrom(item.product_group_label, item.category_label),
        product_ids: normalizeProductIds(item.product_ids || []),
        sort_order: Number(item.sort_order || 0),
      };
    })
    .filter((item) => item.id && item.name && item.effective_product_group_id > 0)
    .sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name));
}

function createTrafficGroupsFromItems(items: TrafficPackageItem[]): TrafficPackageGroup[] {
  const map = new Map<string, TrafficPackageGroup>();
  items.forEach((item) => {
    const id = resolveTrafficGroupKey(item);
    if (!id || map.has(id)) return;
    map.set(id, {
      id,
      name: item.group_name || item.product_group_label || `分类 #${item.effective_product_group_id}`,
      product_type: item.product_type,
      product_group_key: '',
      first_product_group_id: item.first_product_group_id,
      second_product_group_id: item.second_product_group_id,
      third_product_group_id: item.third_product_group_id,
      effective_product_group_id: item.effective_product_group_id,
      product_group_label: item.product_group_label,
      product_ids: item.product_ids,
      sort_order: map.size + 1,
    });
  });
  return Array.from(map.values());
}

function resolveTrafficGroupKey(
  item: Pick<TrafficPackageItem, 'traffic_group_id' | 'product_type' | 'effective_product_group_id'>,
) {
  return String(item.traffic_group_id || `${item.product_type || 'default'}:${item.effective_product_group_id || 0}`);
}

function generateTrafficGroupId(productType: string, categoryId: number) {
  return `traffic:${productType}:${categoryId}:${Date.now()}`;
}

function trafficBoundProductSummary(group: TrafficPackageGroup) {
  const ids = normalizeProductIds(group.product_ids);
  return ids.length ? `${ids.length} 个配置` : '未绑定配置';
}

function trafficPullCategoryPayload(group: TrafficPackageGroup) {
  const thirdProductGroupId = Number(group.third_product_group_id || 0) || 0;
  const secondProductGroupId =
    thirdProductGroupId > 0
      ? Number(group.second_product_group_id || 0) || 0
      : Number(group.second_product_group_id || group.effective_product_group_id || 0) || 0;

  return {
    second_product_group_id: secondProductGroupId || undefined,
    third_product_group_id: thirdProductGroupId || undefined,
  };
}

function syncTrafficRowsFromSelection() {
  const group = selectedTrafficGroup.value;
  trafficRows.value = group
    ? trafficPackageItems.value
        .filter((item) => resolveTrafficGroupKey(item) === group.id)
        .map((item) => createTrafficRow(item, group))
    : [];
}

function validateTrafficRows() {
  const seenTargets = new Set<number>();
  trafficRows.value.forEach((row) => {
    const label = String(row.label || '').trim();
    const target = Number(row.target_value || 0);
    const price = Number(row.price || 0);
    if (!label) throw new Error('请填写流量包名称');
    if (!Number.isFinite(target) || target <= 0) throw new Error('目标流量必须大于 0');
    if (seenTargets.has(target)) throw new Error('同一分组下的目标流量不能重复');
    if (!Number.isFinite(price) || price < 0) throw new Error('售价不能小于 0');
    seenTargets.add(target);
  });
}

function numberFrom(...values: unknown[]): number {
  for (const value of values) {
    const numeric = Number(value || 0);
    if (Number.isFinite(numeric) && numeric > 0) return numeric;
  }
  return 0;
}

function nullableNumberFrom(...values: unknown[]): number | null {
  const numeric = numberFrom(...values);
  return numeric > 0 ? numeric : null;
}

function stringFrom(...values: unknown[]): string {
  for (const value of values) {
    const normalized = String(value || '').trim();
    if (normalized) return normalized;
  }
  return '';
}

function serializeTrafficItems(items: TrafficPackageItem[]) {
  return items.map((item) => ({
    group_id: item.traffic_group_id,
    group_name: item.group_name,
    category_id: Number(item.effective_product_group_id || 0),
    category_label: item.product_group_label,
    traffic_group_id: item.traffic_group_id,
    first_product_group_id: item.first_product_group_id,
    second_product_group_id: item.second_product_group_id,
    third_product_group_id: item.third_product_group_id,
    effective_product_group_id: Number(item.effective_product_group_id || 0),
    product_group_label: item.product_group_label,
    product_type: item.product_type,
    product_ids: normalizeProductIds(item.product_ids),
    label: String(item.label || '').trim(),
    target_value: Number(item.target_value || 0),
    price: Number(item.price || 0).toFixed(2),
    enabled: item.enabled ? 1 : 0,
    sort_order: Number(item.sort_order || 0),
  }));
}

function serializeTrafficGroups(groups: TrafficPackageGroup[]) {
  return groups.map((group) => ({
    id: group.id,
    name: group.name,
    product_type: group.product_type,
    category_id: Number(group.effective_product_group_id || 0),
    category_label: group.product_group_label,
    product_group_key: group.product_group_key,
    first_product_group_id: group.first_product_group_id,
    second_product_group_id: group.second_product_group_id,
    third_product_group_id: group.third_product_group_id,
    effective_product_group_id: Number(group.effective_product_group_id || 0),
    product_group_label: group.product_group_label,
    product_ids: normalizeProductIds(group.product_ids),
    sort_order: Number(group.sort_order || 0),
  }));
}

async function saveTrafficPackageSettings(items: TrafficPackageItem[], groups: TrafficPackageGroup[]) {
  await adminApi.settings.save({
    group: 'traffic_package_catalog',
    settings: {
      items: JSON.stringify(serializeTrafficItems(items)),
      groups: JSON.stringify(serializeTrafficGroups(groups)),
    },
  });
}

function trafficGroupPackageCount(groupId: string) {
  return trafficPackageItems.value.filter((item) => resolveTrafficGroupKey(item) === groupId).length;
}

// --- Init ---
function init() {
  loadTrafficPackages();
}

init();
</script>
