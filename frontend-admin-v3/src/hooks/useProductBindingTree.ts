import { computed, ref } from 'vue';

import type { ProductBindingRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';

function toPlainRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

// ---- types ----

export interface BindingOption extends ProductBindingRecord {
  value: string;
  label: string;
  first_product_group_code?: string | null;
}

export interface BindingTreeNode {
  value: string;
  label: string;
  text: string;
  selectable: boolean;
  activable?: boolean;
  product_id?: number;
  binding_value?: string;
  raw_value?: string;
  children?: BindingTreeNode[];
  [key: string]: unknown;
}

const PRODUCT_TYPE_LABELS: Record<string, string> = {
  cloud_server: '云服务器',
  game_cloud: '游戏云',
  cloud_desktop: '云电脑',
  bare_metal: '裸金属',
  cdn: 'CDN',
  other: '其他',
  physical_machine: '物理机',
  web_hosting: '虚拟主机',
};

// ---- price normalization ----

function normalizeBindingPrice(price: unknown) {
  const record = toPlainRecord(price);
  return {
    cycle: String(record.cycle || ''),
    amount: String(record.amount || '0.00'),
  };
}

// ---- hierarchical context preserved when walking the product tree ----

export interface BindingParentContext {
  product_type?: string;
  first_product_group_id?: number | null;
  first_product_group_code?: string | null;
  first_product_group_name?: string | null;
  second_product_group_id?: number | null;
  second_product_group_name?: string | null;
  third_product_group_id?: number | null;
  third_product_group_name?: string | null;
  effective_product_group_id?: number;
  effective_product_group_level?: number;
  effective_product_group_full_name?: string;
}

function mergeParentContext(
  source: Record<string, unknown>,
  parent?: BindingParentContext | null,
): BindingParentContext {
  if (!parent) return {};
  const sourceEffectiveId = Number(source.effective_product_group_id || 0) || 0;
  return {
    product_type: String(source.product_type || parent.product_type || '').trim() || undefined,
    first_product_group_id:
      Number(source.first_product_group_id || parent.first_product_group_id || 0) ||
      parent.first_product_group_id ||
      null,
    first_product_group_code:
      String(source.first_product_group_code || parent.first_product_group_code || '').trim() ||
      parent.first_product_group_code ||
      null,
    first_product_group_name:
      String(source.first_product_group_name || parent.first_product_group_name || '').trim() ||
      parent.first_product_group_name ||
      null,
    second_product_group_id:
      Number(source.second_product_group_id || parent.second_product_group_id || 0) ||
      parent.second_product_group_id ||
      null,
    second_product_group_name:
      String(source.second_product_group_name || parent.second_product_group_name || '').trim() ||
      parent.second_product_group_name ||
      null,
    third_product_group_id:
      Number(source.third_product_group_id || parent.third_product_group_id || 0) ||
      parent.third_product_group_id ||
      null,
    third_product_group_name:
      String(source.third_product_group_name || parent.third_product_group_name || '').trim() ||
      parent.third_product_group_name ||
      null,
    effective_product_group_id: sourceEffectiveId || Number(parent.effective_product_group_id || 0) || undefined,
    effective_product_group_level:
      Number(source.effective_product_group_level || parent.effective_product_group_level || 0) || undefined,
    effective_product_group_full_name:
      String(
        source.effective_product_group_full_name ||
          source.category_full_name ||
          parent.effective_product_group_full_name ||
          '',
      ).trim() || undefined,
  };
}

// ---- binding record ----

function createBindingRecord(sourceValue: unknown, parent?: BindingParentContext | null): BindingOption | null {
  const source = toPlainRecord(sourceValue);
  const productId = Number(source.product_id || source.id || 0);
  if (productId <= 0) return null;
  const customDisplayName = String(source.custom_display_name || '').trim();
  const cpuMemorySlugDisplay = String(source.cpu_memory_slug_display || '').trim();
  const productSpecDisplay = String(source.product_spec_display || '').trim();
  const cpuMemoryDisplay = String(source.cpu_memory_display || '').trim();
  const productDisplayName = String(source.product_display_name || '').trim();
  const combinedDisplayName = String(source.combined_display_name || '').trim();
  const displayName = String(source.display_name || source.label || '').trim();
  const bindingLabel =
    customDisplayName ||
    cpuMemorySlugDisplay ||
    productSpecDisplay ||
    cpuMemoryDisplay ||
    productDisplayName ||
    combinedDisplayName ||
    displayName ||
    `商品 #${productId}`;
  const categoryName = String(
    source.category_full_name ||
      source.group_full_name ||
      source.effective_product_group_full_name ||
      parent?.effective_product_group_full_name ||
      '',
  ).trim();
  const context = mergeParentContext(source, parent);
  return {
    value: String(productId),
    product_id: productId,
    label: bindingLabel,
    display_name: bindingLabel,
    product_display_name: productDisplayName || bindingLabel,
    custom_display_name: customDisplayName,
    cpu_memory_display: cpuMemoryDisplay,
    cpu_memory_slug_display: cpuMemorySlugDisplay,
    product_spec_display: productSpecDisplay,
    combined_display_name: combinedDisplayName,
    category_full_name: categoryName,
    primary_price: normalizeBindingPrice(source.primary_price),
    status: Number(source.status || 0) === 1 ? 1 : 0,
    product_type: context.product_type,
    first_product_group_id: context.first_product_group_id ?? null,
    first_product_group_code: context.first_product_group_code ?? null,
    first_product_group_name: context.first_product_group_name ?? null,
    second_product_group_id: context.second_product_group_id ?? null,
    second_product_group_name: context.second_product_group_name ?? null,
    third_product_group_id: context.third_product_group_id ?? null,
    third_product_group_name: context.third_product_group_name ?? null,
    effective_product_group_id: context.effective_product_group_id,
    effective_product_group_level: context.effective_product_group_level,
  };
}

export function normalizeBindingPriceRecord(price: unknown) {
  return normalizeBindingPrice(price);
}

export function buildBindingRecord(source: unknown): BindingOption | null {
  return createBindingRecord(source);
}

// ---- bindings normalization ----

export function normalizeProductBindings(bindings: unknown): ProductBindingRecord[] {
  if (!Array.isArray(bindings)) return [];
  const seen = new Set<string>();
  return bindings.reduce<ProductBindingRecord[]>((result, item) => {
    const record = createBindingRecord(item);
    if (!record || seen.has(record.value)) return result;
    seen.add(record.value);
    result.push({
      product_id: record.product_id,
      display_name: record.display_name,
      product_display_name: record.product_display_name,
      custom_display_name: record.custom_display_name,
      cpu_memory_display: record.cpu_memory_display,
      cpu_memory_slug_display: record.cpu_memory_slug_display,
      product_spec_display: record.product_spec_display,
      combined_display_name: record.combined_display_name,
      category_full_name: record.category_full_name,
      primary_price: record.primary_price,
      status: record.status,
      product_type: record.product_type,
      first_product_group_id: record.first_product_group_id ?? null,
      first_product_group_name: record.first_product_group_name ?? null,
      second_product_group_id: record.second_product_group_id ?? null,
      second_product_group_name: record.second_product_group_name ?? null,
      third_product_group_id: record.third_product_group_id ?? null,
      third_product_group_name: record.third_product_group_name ?? null,
      effective_product_group_id: record.effective_product_group_id,
      effective_product_group_level: record.effective_product_group_level,
    });
    return result;
  }, []);
}

// ---- tree flattening ----

export function flattenProductTree(
  nodes: unknown[],
  result: BindingOption[] = [],
  parent?: BindingParentContext | null,
) {
  if (!Array.isArray(nodes)) return result;
  nodes.forEach((nodeValue) => {
    const node = toPlainRecord(nodeValue);
    const isProductNode = String(node.node_type || '') === 'product' || node.leaf === true;
    const currentContext = isProductNode ? parent : mergeParentContext(node, parent);
    const record = isProductNode ? createBindingRecord(node, currentContext) : null;
    if (record) result.push(record);
    if (Array.isArray(node.children)) {
      flattenProductTree(node.children, result, currentContext);
    }
  });
  return result;
}

export type BindingTreeMode = 'multiple' | 'batch' | 'single';

// ---- tree building ----

function isBindingTreeNodeCheckDisabled(node: { data?: Record<string, unknown> }) {
  // 只有明确标记 disabled 的节点才禁用，分类/类型节点均可展开/点击
  return toPlainRecord(node?.data).disabled === true;
}

function ensureBindingTreeTypeNode(
  nodes: Map<string, BindingTreeNode>,
  productType: string,
  label: string,
  mode: BindingTreeMode = 'multiple',
) {
  const key = `type:${productType}`;
  const existing = nodes.get(key);
  if (existing) return existing;

  const node: BindingTreeNode = {
    value: key,
    label,
    text: label,
    selectable: true,
    // 添加实例使用单选树：类型仅用于分组，不能作为商品写入表单。
    activable: mode === 'single' ? false : undefined,
    children: [],
  };
  nodes.set(key, node);
  return node;
}

function normalizedTreeLabel(value: unknown): string {
  return String(value || '').trim();
}

function normalizeTreeValue(value: unknown): string {
  return String(value || '').trim();
}

function uniqueValues(values: string[]): string[] {
  return Array.from(new Set(values.filter(Boolean)));
}

function productTreeValue(nodeKey: string, productId: string): string {
  return `product:${nodeKey}:${productId}`;
}

function categoryTreeValue(nodeKey: string, rawValue: string): string {
  return `category:${nodeKey}:${rawValue}`;
}

function shouldHoistDuplicateTypeNode(categoryNode: BindingTreeNode, typeLabel: string) {
  return (
    normalizedTreeLabel(categoryNode.label) === normalizedTreeLabel(typeLabel) &&
    Array.isArray(categoryNode.children) &&
    categoryNode.children.length > 0
  );
}

function buildBindingCategoryTreeNode(
  node: Record<string, unknown>,
  nodeKey: string,
  mode: BindingTreeMode = 'multiple',
  parent?: BindingParentContext | null,
): BindingTreeNode | null {
  const result: BindingTreeNode[] = [];
  const sourceChildren = Array.isArray(node.children) ? node.children : [];
  const currentContext = mergeParentContext(node, parent);
  sourceChildren.forEach((childValue, index) => {
    const child = toPlainRecord(childValue);
    const isProductNode = String(child.node_type || '') === 'product' || child.leaf === true;
    const record = isProductNode ? createBindingRecord(child, currentContext) : null;

    if (record) {
      result.push({
        ...record,
        value: productTreeValue(`${nodeKey}-${index}`, record.value),
        binding_value: record.value,
        label: record.label,
        text: record.label,
        selectable: true,
      });
      return;
    }

    const categoryNode = buildBindingCategoryTreeNode(child, `${nodeKey}-${index}`, mode, currentContext);
    if (categoryNode) result.push(categoryNode);
  });

  const label = String(node.label || node.name || node.title || node.text || '未命名分类').trim();
  if (!label && result.length === 0) return null;

  // 添加实例使用单选树：分类仅用于导航，不能作为商品写入表单。
  // 批量模式保留按分类勾选并展开为商品的语义。
  const selectable = true;
  const rawValue = String(node.value || node.id || nodeKey).trim() || nodeKey;

  return {
    value: categoryTreeValue(nodeKey, rawValue),
    label: label || '未命名分类',
    text: label || '未命名分类',
    selectable,
    activable: mode === 'single' ? false : undefined,
    raw_value: rawValue,
    children: result,
    ...currentContext,
  };
}

export function buildBindingTreeOptions(
  nodes: unknown[],
  _parentKey = 'root',
  mode: BindingTreeMode = 'multiple',
  hideTypeGroup = false,
): BindingTreeNode[] {
  if (!Array.isArray(nodes)) return [];

  const typeNodes = new Map<string, BindingTreeNode>();
  nodes.forEach((nodeValue, index) => {
    const node = toPlainRecord(nodeValue);
    const productType = String(node.product_type || 'other').trim() || 'other';
    const typeLabel = String(
      node.product_type_label || node.service_type_label || PRODUCT_TYPE_LABELS[productType] || productType,
    ).trim();
    const typeNode = ensureBindingTreeTypeNode(typeNodes, productType, typeLabel, mode);
    const categoryNode = buildBindingCategoryTreeNode(node, `${_parentKey}-${productType}-${index}`, mode, {
      product_type: productType,
      first_product_group_id: Number(node.first_product_group_id || 0) || null,
      first_product_group_code: String(node.first_product_group_code || '').trim() || null,
      first_product_group_name: String(node.first_product_group_name || '').trim() || null,
      effective_product_group_id: Number(node.first_product_group_id || 0) || undefined,
      effective_product_group_level: 1,
      effective_product_group_full_name: String(node.label || node.name || '').trim() || undefined,
    });
    if (categoryNode) {
      const nextNodes = shouldHoistDuplicateTypeNode(categoryNode, typeLabel)
        ? categoryNode.children || []
        : [categoryNode];
      typeNode.children = [...(typeNode.children || []), ...nextNodes];
    }
  });

  const filtered = Array.from(typeNodes.values()).filter((node) => (node.children || []).length > 0);

  if (!hideTypeGroup) return filtered;

  // 打平产品类型节点：直接展示分类作为根节点
  return filtered.flatMap((typeNode) => typeNode.children || []);
}

// ---- leaf collection (for batch mode) ----

export function collectLeafProductIds(node: BindingTreeNode): string[] {
  if (!node.children || node.children.length === 0) {
    const productId = Number(node.product_id || node.binding_value || 0);
    return node.selectable && productId > 0 ? [String(productId)] : [];
  }
  return node.children.flatMap((child) => collectLeafProductIds(child));
}

export function expandToLeafIds(selectedIds: string[], treeNodes: BindingTreeNode[]): string[] {
  const idSet = new Set(selectedIds);
  const result = new Set<string>();

  function walk(nodes: BindingTreeNode[]) {
    for (const node of nodes) {
      if (idSet.has(node.value)) {
        // 选中了分类节点 → 收集其下所有叶子产品ID
        collectLeafProductIds(node).forEach((id) => result.add(id));
      } else if (node.children && node.children.length > 0) {
        walk(node.children);
      } else if (idSet.has(node.value)) {
        result.add(node.value);
      }
    }
  }

  walk(treeNodes);
  selectedIds.filter((id) => /^\d+$/.test(id) && Number(id) > 0).forEach((id) => result.add(id));

  return Array.from(result);
}

// ---- selection helpers ----

export function normalizeBindingSelectionValue(value: unknown): string[] {
  return (Array.isArray(value) ? value : [value])
    .map((item) => String(item || '').trim())
    .filter((item) => /^\d+$/.test(item) && Number(item) > 0);
}

// ---- composable ----

export function useProductBindingTree(
  mode: BindingTreeMode = 'multiple',
  options?: {
    hideTypeGroup?: boolean | (() => boolean);
    expandAll?: boolean | (() => boolean);
  },
) {
  const treeLoading = ref(false);
  const treeData = ref<Record<string, unknown>[]>([]);
  const hideTypeGroupRef = computed(() => {
    const val = options?.hideTypeGroup;
    return typeof val === 'function' ? val() : !!val;
  });
  const expandAllRef = computed(() => {
    const val = options?.expandAll;
    return hideTypeGroupRef.value || (typeof val === 'function' ? val() : !!val);
  });

  const flatOptions = computed(() => flattenProductTree(treeData.value));
  const optionMap = computed(() =>
    flatOptions.value.reduce((map, item) => {
      if (!map.has(item.value)) map.set(item.value, item);
      return map;
    }, new Map<string, BindingOption>()),
  );
  const treeOptions = computed(() => buildBindingTreeOptions(treeData.value, 'root', mode, hideTypeGroupRef.value));

  const treeValueProductIdMap = computed(() => {
    const map = new Map<string, string>();

    function walk(nodes: BindingTreeNode[]) {
      nodes.forEach((node) => {
        const productId = Number(node.product_id || node.binding_value || 0);
        if (productId > 0) {
          map.set(node.value, String(productId));
        }
        if (node.children?.length) walk(node.children);
      });
    }

    walk(treeOptions.value);
    return map;
  });

  const productTreeValueMap = computed(() => {
    const map = new Map<string, string>();

    treeValueProductIdMap.value.forEach((productId, treeValue) => {
      if (!map.has(productId)) map.set(productId, treeValue);
    });

    return map;
  });

  const treeProps = computed(() => ({
    checkStrictly: true,
    expandAll: expandAllRef.value,
    expandLevel: expandAllRef.value ? undefined : 0,
    expandParent: false,
    expandOnClickNode: true,
    disableCheck: isBindingTreeNodeCheckDisabled,
  }));

  async function loadTree() {
    treeLoading.value = true;
    try {
      const response = await adminApi.coupons.productTree();
      treeData.value = Array.isArray(response.tree) ? response.tree : [];
    } finally {
      treeLoading.value = false;
    }
  }

  function resolveBindingsFromIds(
    bindingIds: string[],
    existingBindings?: ProductBindingRecord[],
  ): ProductBindingRecord[] {
    const existingMap = new Map(
      normalizeProductBindings(existingBindings).map((item) => [String(item.product_id || '').trim(), item]),
    );
    return bindingIds
      .map((id) => optionMap.value.get(id) || existingMap.get(id))
      .filter((item): item is ProductBindingRecord => Boolean(item))
      .map((item) => ({
        product_id: Number(item.product_id || 0),
        display_name: String(item.display_name || '').trim(),
        product_display_name: String(item.product_display_name || '').trim(),
        custom_display_name: String(item.custom_display_name || '').trim(),
        cpu_memory_display: String(item.cpu_memory_display || '').trim(),
        cpu_memory_slug_display: String(item.cpu_memory_slug_display || '').trim(),
        product_spec_display: String(item.product_spec_display || '').trim(),
        combined_display_name: String(item.combined_display_name || '').trim(),
        category_full_name: String(item.category_full_name || '').trim(),
        primary_price: normalizeBindingPrice(item.primary_price),
        status: Number(item.status || 0) === 1 ? 1 : 0,
        product_type: String(item.product_type || '').trim() || undefined,
        first_product_group_id: Number(item.first_product_group_id || 0) || null,
        first_product_group_name: String(item.first_product_group_name || '').trim() || null,
        second_product_group_id: Number(item.second_product_group_id || 0) || null,
        second_product_group_name: String(item.second_product_group_name || '').trim() || null,
        third_product_group_id: Number(item.third_product_group_id || 0) || null,
        third_product_group_name: String(item.third_product_group_name || '').trim() || null,
        effective_product_group_id: Number(item.effective_product_group_id || 0) || undefined,
        effective_product_group_level: Number(item.effective_product_group_level || 0) || undefined,
      }));
  }

  function resolveProductIdFromTreeValue(value: unknown): string {
    const normalizedValue = normalizeTreeValue(value);
    if (!normalizedValue) return '';

    const mappedProductId = treeValueProductIdMap.value.get(normalizedValue);
    if (mappedProductId) return mappedProductId;

    return /^\d+$/.test(normalizedValue) && Number(normalizedValue) > 0 ? normalizedValue : '';
  }

  function normalizeSelectionForTree(value: unknown): string[] {
    const values = (Array.isArray(value) ? value : [value]).map(normalizeTreeValue).filter(Boolean);

    return uniqueValues(
      values.map((item) => {
        const productId = resolveProductIdFromTreeValue(item);
        return productId ? productTreeValueMap.value.get(productId) || item : item;
      }),
    );
  }

  function firstSelectionForTree(value: unknown): string {
    return normalizeSelectionForTree(value)[0] || '';
  }

  function selectionToBindings(
    value: unknown,
    existingBindings?: ProductBindingRecord[],
  ): { binding_ids: string[]; bindings: ProductBindingRecord[] } {
    // 统一为数组：single 模式下 TDesign 传回的是 string
    const rawValues = (Array.isArray(value) ? value : [value]).map((item) => String(item || '').trim()).filter(Boolean);

    let selectedIds: string[];

    if (mode === 'batch') {
      // 批量模式：将分类节点 ID 展开为所有叶子产品 ID
      selectedIds = expandToLeafIds(rawValues, treeOptions.value);
    } else {
      // 默认模式：仅保留纯数字产品 ID
      selectedIds = uniqueValues(rawValues.map(resolveProductIdFromTreeValue));
    }

    const bindings = resolveBindingsFromIds(selectedIds, existingBindings);
    return { binding_ids: selectedIds, bindings };
  }

  return {
    treeLoading,
    treeData,
    treeOptions,
    treeProps,
    flatOptions,
    optionMap,
    loadTree,
    resolveBindingsFromIds,
    normalizeSelectionForTree,
    firstSelectionForTree,
    selectionToBindings,
  };
}
