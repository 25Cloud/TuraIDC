<template>
  <t-card :bordered="false">
    <div class="traffic-head">
      <div>
        <h2>{{ $t('products.trafficPackages.title') }}</h2>
        <p>{{ $t('products.trafficPackages.description') }}</p>
      </div>
      <div class="traffic-actions">
        <t-button theme="primary" variant="outline" @click="openTrafficGroupDialog()">
          {{ $t('products.trafficPackages.addGroup') }}
        </t-button>
        <t-button
          variant="outline"
          :loading="trafficPulling"
          :disabled="!selectedTrafficGroup"
          @click="pullTrafficPackages"
        >
          {{ $t('products.trafficPackages.pullFromUpstream') }}
        </t-button>
        <t-button
          theme="primary"
          :loading="trafficSaving"
          :disabled="!selectedTrafficGroup"
          @click="saveTrafficPackages"
        >
          {{ $t('products.trafficPackages.saveCurrentGroup') }}
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
            <strong>{{ group.name || $t('products.trafficPackages.untitledGroup') }}</strong>
            <span>{{
              group.product_group_label ||
              $t('products.trafficPackages.categoryWithId', { id: group.effective_product_group_id || '--' })
            }}</span>
            <small>
              {{
                $t('products.trafficPackages.boundSummary', {
                  products: group.product_ids.length,
                  tiers: trafficGroupPackageCount(group.id),
                })
              }}
            </small>
          </button>
          <t-space size="small">
            <t-button size="small" variant="text" theme="primary" @click.stop="openTrafficGroupDialog(group)">
              {{ $t('products.trafficPackages.edit') }}
            </t-button>
            <t-button size="small" variant="text" theme="danger" @click.stop="removeTrafficGroup(group)">
              {{ $t('products.trafficPackages.delete') }}
            </t-button>
          </t-space>
        </article>
        <t-empty v-if="trafficGroupItems.length === 0" :description="$t('products.trafficPackages.emptyGroups')" />
      </aside>

      <section class="traffic-package-editor">
        <template v-if="selectedTrafficGroup">
          <div class="traffic-package-head">
            <div>
              <strong>{{ selectedTrafficGroup.name }}</strong>
              <span>{{
                selectedTrafficGroup.product_group_label ||
                $t('products.trafficPackages.categoryWithId', {
                  id: selectedTrafficGroup.effective_product_group_id,
                })
              }}</span>
              <small>
                {{ $t('products.trafficPackages.boundConfig') }}{{ trafficBoundProductSummary(selectedTrafficGroup) }}
              </small>
            </div>
            <t-space size="small">
              <t-button variant="outline" @click="openTrafficGroupDialog(selectedTrafficGroup)">
                {{ $t('products.trafficPackages.adjustBinding') }}
              </t-button>
              <t-button theme="primary" variant="outline" @click="addTrafficRow">
                {{ $t('products.trafficPackages.addPackage') }}
              </t-button>
            </t-space>
          </div>

          <div class="traffic-row-list">
            <div v-for="(row, index) in trafficRows" :key="row._rowKey" class="traffic-row">
              <t-input v-model="row.label" :placeholder="$t('products.trafficPackages.tierName')" />
              <t-input-number
                v-model="row.target_value"
                :min="1"
                :placeholder="$t('products.trafficPackages.targetGb')"
              />
              <t-input-number
                v-model="row.cost_price"
                :min="0"
                :decimal-places="2"
                :placeholder="$t('products.trafficPackages.costPrice')"
                @change="() => recalculateRowPrice(row)"
              />
              <t-input-number
                v-model="row.price"
                :min="0"
                :decimal-places="2"
                :disabled="row.markup_type !== 'none' && row.cost_price > 0"
                :placeholder="$t('products.trafficPackages.price')"
              />
              <t-switch v-model="row.enabled" />
              <t-button variant="text" theme="danger" @click="removeTrafficRow(index)">
                {{ $t('products.trafficPackages.delete') }}
              </t-button>
            </div>
            <t-empty v-if="trafficRows.length === 0" :description="$t('products.trafficPackages.emptyTiers')" />
          </div>
        </template>
        <t-empty v-else :description="$t('products.trafficPackages.selectGroupFirst')" />
      </section>
    </div>
  </t-card>

  <t-dialog
    v-model:visible="trafficGroupDialogVisible"
    :header="
      trafficGroupEditingId
        ? $t('products.trafficPackages.editGroupTitle')
        : $t('products.trafficPackages.addGroupTitle')
    "
    width="560px"
    :confirm-btn="{ content: $t('products.trafficPackages.saveGroup'), loading: trafficGroupSubmitting }"
    @confirm="submitTrafficGroup"
  >
    <t-form :data="trafficGroupForm" label-width="110px">
      <t-form-item :label="$t('products.trafficPackages.groupName')" name="name">
        <t-input
          v-model="trafficGroupForm.name"
          :placeholder="$t('products.trafficPackages.groupNamePlaceholder')"
          maxlength="30"
        />
      </t-form-item>
      <t-form-item :label="$t('products.trafficPackages.boundProducts')" name="product_ids">
        <product-binding-tree-select
          v-model="trafficGroupForm.product_ids"
          mode="batch"
          compact
          :popup-max-height="340"
          :placeholder="$t('products.trafficPackages.boundProductsPlaceholder')"
          @change="handleTrafficBindingsChange"
        />
      </t-form-item>
      <t-form-item :label="$t('products.trafficPackages.markupType')" name="markup_type">
        <t-select v-model="trafficGroupForm.markup_type" :placeholder="$t('products.trafficPackages.markupNone')">
          <t-option :label="$t('products.trafficPackages.markupNone')" value="none" />
          <t-option :label="$t('products.trafficPackages.markupPercent')" value="percent" />
          <t-option :label="$t('products.trafficPackages.markupFixed')" value="fixed" />
        </t-select>
      </t-form-item>
      <t-form-item
        v-if="trafficGroupForm.markup_type !== 'none'"
        :label="$t('products.trafficPackages.markupValue')"
        name="markup_value"
      >
        <t-input-number
          v-model="trafficGroupForm.markup_value"
          :min="0"
          :decimal-places="2"
          style="width: 100%"
          :placeholder="
            trafficGroupForm.markup_type === 'percent'
              ? $t('products.trafficPackages.markupPercentHint')
              : $t('products.trafficPackages.markupFixedHint')
          "
        />
      </t-form-item>
    </t-form>
    <p class="traffic-markup-hint">{{ $t('products.trafficPackages.markupHint') }}</p>
  </t-dialog>
</template>
<script setup lang="ts">
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref } from 'vue';

import type { ProductBindingRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import { productApi } from '@/api/product';
import ProductBindingTreeSelect from '@/components/product-binding-tree-select/index.vue';
import { t } from '@/locales';

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
  markup_type: TrafficMarkupType;
  markup_value: number;
  sort_order: number;
}

/** 流量包加价方式：流量包不走上游代理折扣，售价 = 成本 + 加价 */
type TrafficMarkupType = 'none' | 'percent' | 'fixed';

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
  cost_price: number;
  markup_type: TrafficMarkupType;
  markup_value: number;
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
  markup_type: 'none' as TrafficMarkupType,
  markup_value: 0,
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
    markup_type: 'none' as TrafficMarkupType,
    markup_value: 0,
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
    markup_type: group?.markup_type || 'none',
    markup_value: group?.markup_value || 0,
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
      MessagePlugin.warning(t('products.trafficPackages.singleGroupOnly'));
      return sameCategory;
    }
    return bindings;
  }

  // effective_product_group_id 为 0 时，无法确定分类归属，保留第一个配置并警告
  if (bindings.length > 1) {
    MessagePlugin.warning(t('products.trafficPackages.categoryUnresolved'));
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
    MessagePlugin.warning(t('products.trafficPackages.groupNameRequired'));
    return;
  }
  if (!productType || categoryId <= 0) {
    MessagePlugin.warning(t('products.trafficPackages.selectCategoryAndConfig'));
    return;
  }
  if (!productIds.length) {
    MessagePlugin.warning(t('products.trafficPackages.bindConfigRequired'));
    return;
  }

  const conflictGroup = trafficGroupItems.value.find((group) => {
    if (group.id === trafficGroupEditingId.value || group.product_type !== productType) return false;
    const boundIds = normalizeProductIds(group.product_ids);
    return boundIds.some((id) => productIds.includes(id));
  });
  if (conflictGroup) {
    MessagePlugin.warning(t('products.trafficPackages.configBoundInGroup', { name: conflictGroup.name }));
    return;
  }

  trafficGroupSubmitting.value = true;
  try {
    const categoryLabel =
      String(trafficGroupForm.product_group_label || '').trim() ||
      t('products.trafficPackages.categoryWithId', { id: categoryId });
    const groupId = trafficGroupEditingId.value || generateTrafficGroupId(productType, categoryId);
    const previous = trafficGroupItems.value.find((group) => group.id === groupId);
    const markupType = normalizeTrafficMarkupType(trafficGroupForm.markup_type);
    const markupValue = markupType === 'none' ? 0 : Math.max(Number(trafficGroupForm.markup_value) || 0, 0);
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
      markup_type: markupType,
      markup_value: markupValue,
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
    MessagePlugin.success(
      trafficGroupEditingId.value
        ? t('products.trafficPackages.groupUpdated')
        : t('products.trafficPackages.groupCreated'),
    );
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.trafficPackages.saveGroupFailed')));
  } finally {
    trafficGroupSubmitting.value = false;
  }
}

function removeTrafficGroup(group: TrafficPackageGroup) {
  const dialog = DialogPlugin.confirm({
    header: t('products.trafficPackages.deleteGroupTitle'),
    body: t('products.trafficPackages.deleteGroupConfirm', { name: group.name }),
    confirmBtn: { content: t('products.trafficPackages.confirmDelete'), theme: 'danger' },
    cancelBtn: t('common.cancel'),
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
        MessagePlugin.success(t('products.trafficPackages.groupDeleted'));
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('products.trafficPackages.deleteGroupFailed')));
      } finally {
        dialog.destroy();
      }
    },
  });
}

function addTrafficRow() {
  if (!selectedTrafficGroup.value) {
    MessagePlugin.warning(t('products.trafficPackages.selectGroupFirstHint'));
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
    MessagePlugin.warning(t('products.trafficPackages.selectGroupFirstHint'));
    return;
  }
  try {
    validateTrafficRows();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.trafficPackages.validateFailed')));
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
    MessagePlugin.success(t('products.trafficPackages.currentGroupSaved'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.trafficPackages.saveFailed')));
  } finally {
    trafficSaving.value = false;
  }
}

async function pullTrafficPackages() {
  const group = selectedTrafficGroup.value;
  if (!group) {
    MessagePlugin.warning(t('products.trafficPackages.selectGroupFirstHint'));
    return;
  }
  const categoryPayload = trafficPullCategoryPayload(group);
  if (!categoryPayload.second_product_group_id && !categoryPayload.third_product_group_id) {
    MessagePlugin.warning(t('products.trafficPackages.adjustCategoryFirst'));
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
    // 上游给的价格是成本价：一键对接时按当前分组的加价规则直接算出售价
    trafficRows.value = packages.map((item, index) => {
      const record = item as Record<string, unknown>;
      return createTrafficRow(
        {
          ...record,
          cost_price: Number(record.cost_price ?? record.price ?? 0) || 0,
          markup_type: group.markup_type,
          markup_value: group.markup_value,
          sort_order: index + 1,
        },
        group,
      );
    });
    MessagePlugin.success(t('products.trafficPackages.pulledFromUpstream'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.trafficPackages.pullFailed')));
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
    MessagePlugin.error(errorMessage(error, t('products.trafficPackages.loadFailed')));
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
  const markupType = normalizeTrafficMarkupType(item.markup_type ?? group?.markup_type);
  const markupValue = Number(item.markup_value ?? group?.markup_value ?? 0) || 0;
  const costPrice = Number(item.cost_price || 0) || 0;
  // 设了加价规则且填了成本价时，售价一律由规则算出，避免手填价与规则打架
  const price =
    markupType !== 'none' && costPrice > 0
      ? applyTrafficMarkup(costPrice, markupType, markupValue)
      : Number(item.price || 0);

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
    cost_price: costPrice,
    markup_type: markupType,
    markup_value: markupValue,
    price,
    enabled: !(item.enabled === false || item.enabled === 0 || item.enabled === '0'),
    sort_order: Number(item.sort_order || 0),
  };
}

function normalizeTrafficMarkupType(value: unknown): TrafficMarkupType {
  const normalized = String(value || '').trim();
  return normalized === 'percent' || normalized === 'fixed' ? normalized : 'none';
}

/** 与后端 SettingService::applyTrafficPackageMarkup 保持同一套算法 */
function applyTrafficMarkup(cost: number, markupType: TrafficMarkupType, markupValue: number): number {
  const base = Math.max(Number(cost) || 0, 0);
  if (base <= 0) return 0;
  const delta = Math.max(Number(markupValue) || 0, 0);
  const value = markupType === 'percent' ? base * (1 + delta / 100) : markupType === 'fixed' ? base + delta : base;
  return Math.round(value * 100) / 100;
}

function recalculateRowPrice(row: TrafficPackageItem) {
  if (row.markup_type === 'none' || row.cost_price <= 0) return;
  row.price = applyTrafficMarkup(row.cost_price, row.markup_type, row.markup_value);
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
        markup_type: normalizeTrafficMarkupType(item.markup_type),
        markup_value: Number(item.markup_value || 0) || 0,
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
      name:
        item.group_name ||
        item.product_group_label ||
        t('products.trafficPackages.categoryWithId', { id: item.effective_product_group_id }),
      product_type: item.product_type,
      product_group_key: '',
      first_product_group_id: item.first_product_group_id,
      second_product_group_id: item.second_product_group_id,
      third_product_group_id: item.third_product_group_id,
      effective_product_group_id: item.effective_product_group_id,
      product_group_label: item.product_group_label,
      product_ids: item.product_ids,
      markup_type: item.markup_type,
      markup_value: item.markup_value,
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
  return ids.length
    ? t('products.trafficPackages.boundConfigCount', { count: ids.length })
    : t('products.trafficPackages.unboundConfig');
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
    if (!label) throw new Error(t('products.trafficPackages.nameRequired'));
    if (!Number.isFinite(target) || target <= 0) throw new Error(t('products.trafficPackages.targetRequired'));
    if (seenTargets.has(target)) throw new Error(t('products.trafficPackages.targetDuplicated'));
    if (row.markup_type !== 'none' && Number(row.cost_price || 0) <= 0) {
      throw new Error(t('products.trafficPackages.costPriceRequired'));
    }
    if (!Number.isFinite(price) || price < 0) throw new Error(t('products.trafficPackages.priceInvalid'));
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
    cost_price: Number(item.cost_price || 0).toFixed(2),
    markup_type: normalizeTrafficMarkupType(item.markup_type),
    markup_value: Number(item.markup_value || 0) || 0,
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
    markup_type: normalizeTrafficMarkupType(group.markup_type),
    markup_value: Number(group.markup_value || 0) || 0,
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
