import { computed, ref, shallowRef } from "vue";
import siteApi from "@/api/site";

const CACHE_TTL = 5 * 60 * 1000;
let cachedProductTypes = null;
let cachedTimestamp = 0;
let pendingRequest = null;

// 地区分组按目录单独缓存：下拉里反复划过同一个目录不再重复打接口，
// 同一目录的并发请求也合并成一个，避免快速划过时把同一个请求打出去多次。
const groupCache = new Map();
const groupPending = new Map();

function normalizeGroup(group) {
  return {
    id: Number(group.id || 0),
    name: group.name || "",
    slogan: group.slogan || "",
    product_count: Number(group.product_count || 0),
    product_type: group.product_type || "",
    first_product_group_code: group.first_product_group_code || "",
    product_type_id: Number(group.product_type_id || 0),
    children_count: Number(group.children_count || 0),
  };
}

export function useNavProductMenu() {
  const productTypes = ref([]);
  const activeTypeValue = ref("");
  const groupsByType = shallowRef({});
  // 产品目录列表的加载态
  const loading = ref(false);
  // 当前目录下地区分组的加载态，与上面的 loading 分开，
  // 否则目录先回来、分组还在飞的那一段会被当成「已加载完且为空」，
  // 下拉面板就会先闪一句「暂无产品分类」再被内容顶掉。
  const groupsLoading = ref(false);
  const groupsError = ref(false);
  const initialized = ref(false);
  let groupsInflight = 0;

  const activeType = computed(
    () =>
      productTypes.value.find((t) => t.value === activeTypeValue.value) || null,
  );

  const activeTypeLabel = computed(() => activeType.value?.label || "");

  const activeGroups = computed(() => {
    const key = activeTypeValue.value;
    return groupsByType.value[key] || [];
  });

  // 下拉面板「还在取数」的统一口径：目录列表或地区分组任一在飞都算 pending。
  const groupsPending = computed(() => loading.value || groupsLoading.value);

  function getGroupsForType(typeValue) {
    return groupsByType.value[typeValue] || [];
  }

  async function loadProductTypes() {
    if (cachedProductTypes && Date.now() - cachedTimestamp < CACHE_TTL) {
      productTypes.value = cachedProductTypes;
      if (cachedProductTypes.length && !activeTypeValue.value) {
        activeTypeValue.value = cachedProductTypes[0].value;
      }
      return;
    }

    if (pendingRequest) {
      await pendingRequest;
      return;
    }

    loading.value = true;
    pendingRequest = siteApi
      .productTypes()
      .then((res) => {
        const list = res.data.list || [];
        const normalized = list
          .map((item, index) => ({
            id: Number(item.id || index + 1),
            value: String(item.first_product_group_code || item.value || ""),
            label: item.label || item.product_type_label || `分类 ${index + 1}`,
            product_type: String(item.product_type || ""),
            product_count: Number(item.product_count || 0),
            group_count: Number(item.group_count || 0),
          }))
          .filter((item) => item.value !== "");

        cachedProductTypes = normalized;
        cachedTimestamp = Date.now();
        productTypes.value = normalized;

        if (normalized.length && !activeTypeValue.value) {
          activeTypeValue.value = normalized[0].value;
        }
      })
      .finally(() => {
        loading.value = false;
        pendingRequest = null;
      });

    await pendingRequest;
  }

  async function loadGroupsForType(typeValue, options = {}) {
    if (!typeValue) {
      return;
    }

    // 命中缓存：直接回填，不发请求
    if (!options.force && groupCache.has(typeValue)) {
      if (!groupsByType.value[typeValue]) {
        groupsByType.value = {
          ...groupsByType.value,
          [typeValue]: groupCache.get(typeValue),
        };
      }
      return;
    }

    // 同一个目录已在飞：复用那个 promise，只把自己的 loading 计数对齐
    let request = groupPending.get(typeValue);
    if (!request) {
      request = siteApi
        .productGroups({ first_product_group_code: typeValue })
        .then((res) => {
          const groups = (res.data.list || []).map(normalizeGroup);
          groupCache.set(typeValue, groups);
          groupsByType.value = { ...groupsByType.value, [typeValue]: groups };
          return groups;
        })
        .catch((error) => {
          // 失败不写缓存，否则这次抖动会把目录永久钉死在空态上，只能刷页面才恢复
          groupCache.delete(typeValue);
          throw error;
        })
        .finally(() => {
          groupPending.delete(typeValue);
        });
      groupPending.set(typeValue, request);
    }

    groupsInflight += 1;
    groupsLoading.value = true;
    groupsError.value = false;
    try {
      await request;
    } catch {
      // 只在失败的是当前选中目录时提示，避免划过目录时误报
      if (typeValue === activeTypeValue.value) {
        groupsError.value = true;
      }
    } finally {
      groupsInflight -= 1;
      groupsLoading.value = groupsInflight > 0;
    }
  }

  async function activateType(typeValue) {
    if (!typeValue || activeTypeValue.value === typeValue) {
      return;
    }
    activeTypeValue.value = typeValue;
    await loadGroupsForType(typeValue);
  }

  async function init() {
    if (initialized.value) {
      return;
    }
    initialized.value = true;
    await loadProductTypes();
    if (activeTypeValue.value) {
      await loadGroupsForType(activeTypeValue.value);
    }
  }

  // 下拉里的「重新加载」：强制绕过缓存再取一次
  function reloadGroups() {
    return loadGroupsForType(activeTypeValue.value, { force: true });
  }

  function reset() {
    initialized.value = false;
  }

  return {
    productTypes,
    activeTypeValue,
    activeType,
    activeTypeLabel,
    activeGroups,
    getGroupsForType,
    loading,
    groupsLoading,
    groupsPending,
    groupsError,
    activateType,
    loadGroupsForType,
    reloadGroups,
    init,
    reset,
  };
}
