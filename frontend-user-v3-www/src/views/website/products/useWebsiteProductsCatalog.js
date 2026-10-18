import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import siteApi, {
  getCachedCatalog,
  invalidateCatalogCache,
  setCachedCatalog,
} from "@/api/site";
import { getPendingWebsiteCouponId } from "@/utils/websiteCoupon";
import {
  buildWebsiteProductPath,
  buildWebsiteProductRouteQuery,
  hasWebsiteProductRouteParams,
  readWebsiteProductRouteParams,
} from "@/utils/productRoute";

const MOBILE_BREAKPOINT = 900;

// 进行中的目录请求去重（site.js 的 catalogCache 负责结果缓存）
const catalogPendingMap = new Map();
// 已访问类型的根分组缓存：切换类型时本地过滤，避免重复请求 productGroups
const rootGroupsByType = new Map();

// 二级与三级分组的自增 ID 各自独立，会出现「二级 #57」与「三级 #57」并存的情况，
// 所以目录内的 key 必须带层级前缀，用裸 ID 做 key 会互相覆盖成别的分类的商品。
function catalogGroupKey(group) {
  const id = Number(group?.effective_product_group_id || group?.id || 0);
  const level = Number(group?.effective_product_group_level || 0);

  if (id <= 0) {
    return "";
  }

  return level > 0 ? `${level}:${id}` : `2:${id}`;
}

function groupMatchesType(group, typeValue) {
  if (!typeValue) {
    return true;
  }

  return [group?.first_product_group_code]
    .map((value) => String(value || "").trim())
    .includes(typeValue);
}

function filterGroupsByType(groups = [], typeValue = "") {
  if (!Array.isArray(groups)) {
    return [];
  }

  return groups.filter((group) => groupMatchesType(group, typeValue));
}

function catalogBelongsToGroup(catalog, groupId) {
  return (
    Number(catalog?.effective_product_group_id || 0) === Number(groupId || 0)
  );
}

export function useWebsiteProductsCatalog({
  onProductSelect,
  onResetSelection,
  autoSelectProduct = null,
}) {
  const route = useRoute();
  const router = useRouter();
  const pageLoading = ref(false);
  const sidebarCollapsed = ref(false);
  const isMobile = ref(false);
  const mobileTypeEntered = ref(false);
  const mobileCategoryDrawer = ref(false);
  const mobileProductDrawer = ref(false);
  const mobilePendingProductId = ref(0);

  const productTypes = ref([]);
  const rootGroups = ref([]);
  const childGroups = ref([]);
  const productsByGroup = ref({});

  const activeTypeValue = ref("");
  const activeGroupId = ref(0);
  const activeChildId = ref(0);
  const selectedProductId = ref(0);
  const mobileRegionDrawer = ref(false);
  const tempGroupId = ref(0);
  const tempChildGroups = ref([]);

  let routeSyncSuspendCount = 0;
  let groupToken = 0;
  let groupAbortController = null;

  const activeType = computed(
    () =>
      productTypes.value.find((type) => type.value === activeTypeValue.value) ||
      null,
  );
  const activeTypeLabel = computed(() => activeType.value?.label || "");
  const activeGroup = computed(
    () =>
      rootGroups.value.find((group) => group.id === activeGroupId.value) ||
      null,
  );
  const activeGroupName = computed(() => activeGroup.value?.name || "");
  const activeChildName = computed(() => {
    if (activeChildId.value <= 0) return "";
    return (
      childGroups.value.find((g) => g.id === activeChildId.value)?.name || ""
    );
  });
  const activeCatalogCategoryKey = computed(() => {
    if (activeChildId.value > 0) {
      return catalogGroupKey(
        childGroups.value.find((group) => group.id === activeChildId.value),
      );
    }

    return catalogGroupKey(activeGroup.value);
  });
  const showMobileTypePicker = computed(
    () =>
      isMobile.value &&
      !mobileTypeEntered.value &&
      productTypes.value.length > 1 &&
      !hasWebsiteProductRouteParams(readWebsiteProductRouteParams(route)),
  );
  // 卡片式下不自动选中第一个商品：没有选中态才能展示卡片网格，
  // 选中态留给 /products/:id 独立配置页去承载。
  const shouldAutoSelectProduct = computed(() => {
    if (autoSelectProduct && autoSelectProduct.value === false) {
      return false;
    }

    return getPendingWebsiteCouponId() <= 0;
  });
  const visibleProducts = computed(() => {
    const categoryKey = activeCatalogCategoryKey.value;
    return categoryKey ? productsByGroup.value[categoryKey] || [] : [];
  });

  function getChildCategoryKey(childId) {
    return catalogGroupKey(
      childGroups.value.find((group) => group.id === childId),
    );
  }

  function hasDisplayPrice(product) {
    const pricingEntries = Array.isArray(product?.pricing_entries)
      ? product.pricing_entries
      : [];
    if (pricingEntries.some((item) => Number(item?.amount || 0) > 0)) {
      return true;
    }

    return Number(product?.primary_price || 0) > 0;
  }

  function shouldSyncRoute(options = {}) {
    return options.syncRoute !== false;
  }

  function withSuspendedRouteSync(task) {
    routeSyncSuspendCount += 1;

    return Promise.resolve(task()).finally(() => {
      routeSyncSuspendCount = Math.max(routeSyncSuspendCount - 1, 0);
    });
  }

  async function syncSelectionRoute(options = {}) {
    if (routeSyncSuspendCount > 0) {
      return;
    }

    const targetPath = buildWebsiteProductPath({
      typeId: activeTypeValue.value,
      groupId: activeGroupId.value,
      childGroupId: activeChildId.value,
      productId: selectedProductId.value,
    });
    // 目录上下文（类型/分组/三级分组）全部写在路径里；查询里只保留优惠券等
    // 无关参数，旧的 ?type= / ?group= / ?child= 写法顺手清掉。
    const targetQuery = buildWebsiteProductRouteQuery(route.query);
    const legacyQueryKeys = ["type", "group", "child"].filter(
      (key) => route.query?.[key] !== undefined,
    );

    if (route.path === targetPath && legacyQueryKeys.length === 0) {
      return;
    }

    // 深链保护：当前 URL 是带参数的商品深链（刷新/直链进入），但本地状态尚未完整解析
    // （分组/目录/商品请求失败或仍为空）时，不要用空状态把 URL 回退到 /products，
    // 否则刷新后深链参数丢失、页面停摆在空目录。保持 URL，等待解析完成或用户手动切换。
    //
    // 用户主动退出选中态（切到卡片式）时必须跳过这条保护：否则地址上留着商品 ID，
    // 刷新后会被当成深链重新选中商品，卡片偏好形同虚设。
    if (
      !options.force &&
      targetPath === "/products" &&
      hasWebsiteProductRouteParams(readWebsiteProductRouteParams(route))
    ) {
      return;
    }

    await router.replace({
      path: targetPath,
      query: targetQuery,
    });
  }

  function resetSelectedProduct(options = {}) {
    selectedProductId.value = 0;
    onResetSelection?.();

    if (shouldSyncRoute(options)) {
      void syncSelectionRoute({ force: options.forceRoute === true });
    }
  }

  function selectProduct(id, options = {}) {
    if (selectedProductId.value === id) {
      onProductSelect?.(id, { refreshStockOnly: true });

      if (shouldSyncRoute(options)) {
        void syncSelectionRoute();
      }

      return;
    }

    selectedProductId.value = id;
    onProductSelect?.(id);

    if (shouldSyncRoute(options)) {
      void syncSelectionRoute();
    }
  }

  function syncDefaultProduct(products = [], options = {}) {
    const targetProductId = Number(options.targetProductId || 0);

    if (!products.length) {
      resetSelectedProduct({ syncRoute: shouldSyncRoute(options) });
      return;
    }

    if (targetProductId > 0) {
      const matchedProduct = products.find(
        (item) => item.id === targetProductId,
      );
      if (matchedProduct) {
        selectProduct(matchedProduct.id, {
          syncRoute: shouldSyncRoute(options),
        });
        return;
      }
    }

    if (!shouldAutoSelectProduct.value) {
      resetSelectedProduct({ syncRoute: shouldSyncRoute(options) });
      return;
    }

    selectProduct(products[0].id, { syncRoute: shouldSyncRoute(options) });
  }

  function enterMobileType(value) {
    mobileTypeEntered.value = true;
    mobileCategoryDrawer.value = false;
    return switchType(value, { syncRoute: true });
  }

  function returnToMobileTypePicker() {
    mobileTypeEntered.value = false;
    mobileCategoryDrawer.value = false;
    mobileProductDrawer.value = false;

    // 必须把地址上的选购参数一并清掉，否则这个按钮没有任何可见效果：
    // showMobileTypePicker 的条件里带着 !hasWebsiteProductRouteParams(route)，
    // 而进入时 enterMobileType() → switchType(..., { syncRoute: true }) 已经把参数
    // 写进地址。只翻 mobileTypeEntered 这个状态，一级分组界面永远不会回来，
    // 用户看到的就是「切换一级分组」只把抽屉关掉。
    //
    // 这里不复用 syncSelectionRoute()：它的「深链保护」正是为了阻止地址回退到
    // /products（防止深链刷新时状态未解析完就把参数抹掉）。这里是用户主动返回，
    // 本地分组与商品状态都已加载完毕，不该被那条保护拦住。
    const {
      type: _type,
      group: _group,
      child: _child,
      ...restQuery
    } = route.query || {};
    void router.replace({
      path: "/products",
      query: restQuery,
    });
  }

  function openMobileCategoryDrawer() {
    if (!rootGroups.value.length) {
      return;
    }

    mobileCategoryDrawer.value = true;
  }

  function openMobileRegionDrawer() {
    if (!rootGroups.value.length) {
      return;
    }

    tempGroupId.value = activeGroupId.value;
    tempChildGroups.value = [...childGroups.value];
    mobileRegionDrawer.value = true;
  }

  async function selectTempGroup(id) {
    if (tempGroupId.value === id) {
      return;
    }

    tempGroupId.value = id;
    tempChildGroups.value = [];

    const cached = getCachedCatalog(id);
    if (cached) {
      tempChildGroups.value = cached.children || [];
      return;
    }

    try {
      const data = await siteApi
        .productGroupCatalog(id)
        .then((res) => res.data);
      setCachedCatalog(id, data);
      tempChildGroups.value = data.children || [];
    } catch {
      // 静默处理
    }
  }

  function handleRegionChange(groupId) {
    return selectTempGroup(groupId);
  }

  async function confirmRegionSelection(groupId, childId) {
    mobileRegionDrawer.value = false;
    const targetGroupId = groupId || tempGroupId.value;
    await withSuspendedRouteSync(async () => {
      await switchGroup(targetGroupId, {
        syncRoute: false,
        targetChildId:
          childId ||
          tempChildGroups.value.find((g) => g.id === (childId || 0))?.id ||
          0,
        targetProductId: selectedProductId.value,
      });
    });
    await syncSelectionRoute();
  }

  function handleMobileGroupSelect(id) {
    mobileCategoryDrawer.value = false;
    return switchGroup(id, { syncRoute: true });
  }

  async function openMobileProductDrawer() {
    if (
      activeGroupId.value > 0 &&
      visibleProducts.value.length &&
      visibleProducts.value.every((product) => !hasDisplayPrice(product))
    ) {
      invalidateCatalogCache(activeGroupId.value);
      await loadGroupPayload(activeGroupId.value, {
        syncRoute: false,
        targetChildId: activeChildId.value,
        targetProductId: selectedProductId.value,
      });
    }

    mobilePendingProductId.value =
      selectedProductId.value || visibleProducts.value[0]?.id || 0;
    mobileProductDrawer.value = true;
  }

  function confirmMobileProductSelection() {
    if (mobilePendingProductId.value) {
      selectProduct(mobilePendingProductId.value, { syncRoute: true });
    }

    mobileProductDrawer.value = false;
  }

  async function switchGroup(id, options = {}) {
    activeGroupId.value = id;
    activeChildId.value = 0;
    resetSelectedProduct({ syncRoute: false });
    mobileProductDrawer.value = false;
    await loadGroupPayload(id, options);
  }

  function switchChild(id, options = {}) {
    activeChildId.value = id;
    resetSelectedProduct({ syncRoute: false });
    mobileProductDrawer.value = false;

    const childKey = getChildCategoryKey(id);
    const products = childKey ? productsByGroup.value[childKey] || [] : [];
    syncDefaultProduct(products, options);
  }

  async function switchType(value, options = {}) {
    activeTypeValue.value = value;
    activeGroupId.value = 0;
    activeChildId.value = 0;
    resetSelectedProduct({ syncRoute: false });
    productsByGroup.value = {};
    childGroups.value = [];
    mobileCategoryDrawer.value = false;
    mobileProductDrawer.value = false;
    if (options.enterMobile !== false && isMobile.value) {
      mobileTypeEntered.value = true;
    }

    await loadRootGroups(options);
  }

  async function loadRootGroups(options = {}) {
    pageLoading.value = true;

    try {
      const typeValue = activeTypeValue.value || "";
      // 优先复用已加载过的类型分组，切换回已访问类型时不重复请求 productGroups
      // 注意：空数组不缓存（如深链时 productsInit 返回不完整导致过滤后为空），
      // 否则后续 loadRootGroups 会命中空缓存永远拿不到真实分组。
      let groups = rootGroupsByType.get(typeValue);
      if (!groups || groups.length === 0) {
        const res = await siteApi.productGroups({
          first_product_group_code: typeValue || undefined,
        });
        groups = filterGroupsByType(res.data.list || [], typeValue);
        rootGroupsByType.set(typeValue, groups);
      }

      rootGroups.value = groups;

      if (rootGroups.value.length) {
        const targetGroupId = Number(options.targetGroupId || 0);
        const matchedGroup = rootGroups.value.find(
          (group) => group.id === targetGroupId,
        );
        const nextGroupId = matchedGroup?.id || rootGroups.value[0].id;
        activeGroupId.value = nextGroupId;
        await loadGroupPayload(nextGroupId, options);
      } else {
        activeGroupId.value = 0;
        activeChildId.value = 0;
        productsByGroup.value = {};
        childGroups.value = [];
        resetSelectedProduct({ syncRoute: shouldSyncRoute(options) });
      }
    } finally {
      pageLoading.value = false;
    }
  }

  async function loadGroupPayload(groupId, options = {}) {
    const token = ++groupToken;
    groupAbortController?.abort();
    groupAbortController = new AbortController();

    const cached = getCachedCatalog(groupId);
    if (cached) {
      applyGroupPayload(cached, options);
      return;
    }

    const pending = catalogPendingMap.get(groupId);
    if (pending) {
      try {
        const data = await pending;
        if (token !== groupToken) {
          return;
        }

        applyGroupPayload(data, options);
      } catch {
        // 由发起请求的调用方处理错误
      }

      return;
    }

    pageLoading.value = true;
    const controller = groupAbortController;

    const request = siteApi
      .productGroupCatalog(groupId, {
        signal: controller.signal,
      })
      .then((res) => res.data);

    catalogPendingMap.set(groupId, request);

    try {
      const data = await request;
      if (token !== groupToken) {
        return;
      }

      setCachedCatalog(groupId, data);
      applyGroupPayload(data, options);
    } catch (error) {
      if (error?.code === "ERR_CANCELED" || error?.name === "CanceledError") {
        return;
      }

      throw error;
    } finally {
      catalogPendingMap.delete(groupId);
      if (token === groupToken) {
        pageLoading.value = false;
      }
    }
  }

  function applyGroupPayload(data, options = {}) {
    const children = data.children || [];
    childGroups.value = children;

    const map = {};
    (data.items_by_group || []).forEach((item) => {
      const groupKey = catalogGroupKey(item);
      if (groupKey) {
        map[groupKey] = item.products || [];
      }
    });
    productsByGroup.value = map;

    // 三段路径的 thirdId 可能是三级分组 ID，也可能是旧深链的商品 ID。
    // 这里拿到真实 children / 商品后才好判定：
    //  - 命中 children → 当作三级分组；
    //  - 否则当商品 ID，再在各级分组商品里找它所属分组的归属。
    const ambiguousId = Number(options.ambiguousId || 0);
    let targetChildId = Number(options.targetChildId || 0);
    let targetProductId = Number(options.targetProductId || 0);

    if (ambiguousId > 0) {
      if (children.some((child) => Number(child.id) === ambiguousId)) {
        targetChildId = ambiguousId;
        targetProductId = 0;
      } else {
        targetProductId = ambiguousId;
      }
    }

    if (children.length) {
      let nextChild =
        children.find((child) => Number(child.id) === targetChildId) ||
        children[0];

      // 旧商品深链只带商品 ID：扫描各级分组的商品，找到它真正所属的三级分组，
      // 避免停在第一个分组、把深链商品丢掉。
      if (targetProductId > 0 && ambiguousId > 0) {
        const owner = children.find((child) => {
          const list = map[catalogGroupKey(child)] || [];
          return list.some((item) => Number(item.id) === targetProductId);
        });
        if (owner) {
          nextChild = owner;
        }
      }

      activeChildId.value = nextChild.id;
      const activeChildKey = catalogGroupKey(nextChild);
      const products = activeChildKey ? map[activeChildKey] || [] : [];
      syncDefaultProduct(products, {
        ...options,
        ambiguousId: 0,
        targetChildId: nextChild.id,
        targetProductId,
      });
    } else {
      activeChildId.value = 0;
      const rootKey = catalogGroupKey({
        effective_product_group_id: Number(
          data.effective_product_group_id || 0,
        ),
        effective_product_group_level: Number(
          data.effective_product_group_level || 2,
        ),
      });
      const products = rootKey ? map[rootKey] || [] : [];
      syncDefaultProduct(products, {
        ...options,
        ambiguousId: 0,
        targetProductId,
      });
    }
  }

  async function applyRouteSelection() {
    const routePayload = readWebsiteProductRouteParams(route);

    if (
      !hasWebsiteProductRouteParams(routePayload) ||
      !productTypes.value.length
    ) {
      return false;
    }

    const targetType = productTypes.value.find(
      (item) => String(item.value || "") === String(routePayload.typeId),
    );
    const nextTypeValue =
      targetType?.value ||
      activeTypeValue.value ||
      productTypes.value[0]?.value ||
      "";

    if (!nextTypeValue) {
      return false;
    }

    const ambiguousId = Number(routePayload.thirdId || 0);
    // 当前已加载的 children 能命中 thirdId 时按三级分组处理；
    // 否则交给 applyGroupPayload 在目标分组目录到达后再判定（可能是旧商品深链）。
    const knownChildId =
      ambiguousId > 0 &&
      childGroups.value.some((child) => Number(child.id) === ambiguousId)
        ? ambiguousId
        : 0;
    const targetChildId =
      Number(routePayload.childGroupId || 0) || knownChildId;
    const targetProductId = Number(routePayload.productId || 0);

    await withSuspendedRouteSync(async () => {
      if (isMobile.value) {
        mobileTypeEntered.value = true;
      }

      if (activeTypeValue.value !== nextTypeValue || !rootGroups.value.length) {
        activeTypeValue.value = nextTypeValue;
        await loadRootGroups({
          targetGroupId: routePayload.groupId,
          targetChildId,
          targetProductId,
          ambiguousId,
          syncRoute: false,
        });
        return;
      }

      if (
        routePayload.groupId > 0 &&
        activeGroupId.value !== routePayload.groupId
      ) {
        await switchGroup(routePayload.groupId, {
          targetChildId,
          targetProductId,
          ambiguousId,
          syncRoute: false,
        });
        return;
      }

      if (ambiguousId > 0 && knownChildId === 0) {
        // 同一分组内无法判定 thirdId 是三级分组还是商品：重新解析目录，
        // applyGroupPayload 会找到它所属的三级分组（或按商品选中）。
        await loadGroupPayload(activeGroupId.value, {
          targetChildId,
          targetProductId: 0,
          ambiguousId,
          syncRoute: false,
        });
        return;
      }

      if (
        routePayload.childGroupId > 0 &&
        activeChildId.value !== routePayload.childGroupId
      ) {
        switchChild(routePayload.childGroupId, {
          targetProductId,
          syncRoute: false,
        });
        return;
      }

      if (targetProductId > 0 && selectedProductId.value !== targetProductId) {
        const matchedProduct = visibleProducts.value.find(
          (item) => item.id === targetProductId,
        );

        if (matchedProduct) {
          selectProduct(matchedProduct.id, { syncRoute: false });
        } else if (activeGroupId.value > 0) {
          await loadGroupPayload(activeGroupId.value, {
            targetChildId,
            targetProductId,
            syncRoute: false,
          });
        }
      }
    });

    await syncSelectionRoute();

    return true;
  }

  async function init() {
    pageLoading.value = true;

    try {
      const linked = await initWithAggregatedApi();

      if (!linked && productTypes.value.length && !isMobile.value) {
        if (!rootGroups.value.length) {
          activeTypeValue.value = productTypes.value[0].value;
          await loadRootGroups({ syncRoute: true });
        } else {
          void syncSelectionRoute();
        }
      }
    } finally {
      pageLoading.value = false;
    }
  }

  async function initWithAggregatedApi() {
    const routePayload = readWebsiteProductRouteParams(route);
    const hasRouteTarget = hasWebsiteProductRouteParams(routePayload);

    try {
      // 深链（URL 带类型/分组/产品）时按目标类型请求初始化数据：
      // 后端 purchase-context 默认只返回第一个类型的根分组，若深链目标是其他类型
      // （如 isp），不传参会导致 root_groups 缺失该类型分组，前端过滤后为空并回退 /products。
      const res = await siteApi.productsInit(
        hasRouteTarget
          ? { first_product_group_code: routePayload.typeId || undefined }
          : undefined,
      );
      const data = res.data || {};
      productTypes.value = data.types || [];

      if (!productTypes.value.length) {
        return false;
      }

      if (hasRouteTarget) {
        // 先用 productsInit 响应的 root_groups 与 catalog 填充本地状态/缓存，
        // 让 applyRouteSelection 命中本地分组与目录，避免深链「purchase-context →
        // product-groups → 完整目录」重复拉取首组目录。
        const routePayload = readWebsiteProductRouteParams(route);
        const targetType = productTypes.value.find(
          (item) => String(item.value || "") === String(routePayload.typeId),
        );
        const nextTypeValue =
          targetType?.value || productTypes.value[0]?.value || "";
        if (nextTypeValue) {
          activeTypeValue.value = nextTypeValue;
          const groups = filterGroupsByType(
            data.root_groups || [],
            nextTypeValue,
          );
          rootGroupsByType.set(nextTypeValue, groups);
          rootGroups.value = groups;
        }
        if (
          data.catalog &&
          Number(data.catalog.effective_product_group_id || 0) > 0
        ) {
          setCachedCatalog(
            data.catalog.effective_product_group_id,
            data.catalog,
          );
        }

        const linked = await applyRouteSelection();
        return linked;
      }

      if (!hasRouteTarget && isMobile.value) {
        activeTypeValue.value = "";
        rootGroups.value = [];
        childGroups.value = [];
        productsByGroup.value = {};
        resetSelectedProduct({ syncRoute: false });
        mobileTypeEntered.value = false;
        return true;
      }

      const types = productTypes.value;
      const firstTypeValue = types[0]?.value || "";
      activeTypeValue.value = firstTypeValue;
      rootGroups.value = filterGroupsByType(
        data.root_groups || [],
        firstTypeValue,
      );
      rootGroupsByType.set(firstTypeValue, rootGroups.value);

      if (
        rootGroups.value.length &&
        data.catalog &&
        catalogBelongsToGroup(data.catalog, rootGroups.value[0].id)
      ) {
        const firstGroupId = rootGroups.value[0].id;
        activeGroupId.value = firstGroupId;
        setCachedCatalog(firstGroupId, data.catalog);
        applyGroupPayload(data.catalog, { syncRoute: true });
      } else if (rootGroups.value.length) {
        activeGroupId.value = rootGroups.value[0].id;
        await loadGroupPayload(rootGroups.value[0].id, { syncRoute: true });
      }

      return true;
    } catch {
      const res = await siteApi.productTypes();
      productTypes.value = res.data.list || [];
      return false;
    }
  }

  function updateMobileState() {
    if (typeof window === "undefined") {
      return;
    }

    // isMobile 未变化时直接返回，避免 resize 触发不必要的状态切换与重渲染
    const nextMobile = window.innerWidth <= MOBILE_BREAKPOINT;
    if (nextMobile === isMobile.value) return;
    isMobile.value = nextMobile;
  }

  watch(isMobile, async (mobile) => {
    if (mobile) {
      mobileTypeEntered.value = Boolean(activeTypeValue.value);
      return;
    }

    mobileCategoryDrawer.value = false;
    mobileProductDrawer.value = false;
    mobileTypeEntered.value = false;

    if (!activeTypeValue.value && productTypes.value.length) {
      activeTypeValue.value = productTypes.value[0].value;
      await loadRootGroups({ syncRoute: true });
    }
  });

  watch(
    () =>
      [
        route.params.typeId,
        route.params.groupId,
        route.params.childGroupId,
        route.params.productId,
        route.params.thirdId,
        route.query.type,
        route.query.group,
        route.query.child,
      ].join("|"),
    async () => {
      if (!productTypes.value.length) {
        return;
      }

      const routePayload = readWebsiteProductRouteParams(route);

      if (hasWebsiteProductRouteParams(routePayload)) {
        const currentTypeValue = activeTypeValue.value;
        const currentGroupId = activeGroupId.value;
        const currentChildId = activeChildId.value;
        const currentProductId = selectedProductId.value;
        const thirdId = Number(routePayload.thirdId || 0);
        const thirdAlreadyApplied =
          thirdId > 0 &&
          (thirdId === currentChildId || thirdId === currentProductId);

        const changed =
          String(routePayload.typeId) !== String(currentTypeValue) ||
          routePayload.groupId !== currentGroupId ||
          routePayload.childGroupId !== currentChildId ||
          (routePayload.productId > 0 &&
            routePayload.productId !== currentProductId) ||
          (thirdId > 0 && !thirdAlreadyApplied);

        if (changed) {
          await applyRouteSelection();
        }

        return;
      }

      if (route.path === "/products" && isMobile.value) {
        mobileTypeEntered.value = false;
      }
    },
  );

  onMounted(() => {
    updateMobileState();
    window.addEventListener("resize", updateMobileState);
    init();
  });

  onBeforeUnmount(() => {
    groupAbortController?.abort();
    window.removeEventListener("resize", updateMobileState);
  });

  return {
    pageLoading,
    sidebarCollapsed,
    isMobile,
    mobileTypeEntered,
    mobileCategoryDrawer,
    mobileProductDrawer,
    mobileRegionDrawer,
    tempGroupId,
    tempChildGroups,
    mobilePendingProductId,
    productTypes,
    rootGroups,
    childGroups,
    activeTypeValue,
    activeGroupId,
    activeChildId,
    selectedProductId,
    activeTypeLabel,
    activeGroupName,
    activeChildName,
    showMobileTypePicker,
    visibleProducts,
    enterMobileType,
    returnToMobileTypePicker,
    openMobileCategoryDrawer,
    openMobileRegionDrawer,
    selectTempGroup,
    handleRegionChange,
    confirmRegionSelection,
    handleMobileGroupSelect,
    openMobileProductDrawer,
    confirmMobileProductSelection,
    switchType,
    switchGroup,
    switchChild,
    selectProduct,
    resetSelectedProduct,
    syncDefaultProduct,
  };
}
