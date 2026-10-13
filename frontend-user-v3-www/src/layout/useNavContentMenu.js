import { ref, computed } from "vue";
import siteApi from "@/api/site";

const CACHE_TTL = 5 * 60 * 1000;
const caches = {
  notice: { data: null, timestamp: 0 },
  help: { data: null, timestamp: 0 },
};
const pending = {
  notice: null,
  help: null,
};

function normalizeItem(item) {
  return {
    id: Number(item.id || 0),
    title: item.title || "",
    summary: item.summary || "",
    category: item.category || item.category_name || "",
    publish_at: item.publish_at || item.published_at || "",
  };
}

export function useNavContentMenu(contentType) {
  const items = ref([]);
  const loading = ref(false);
  // 失败要与「取完了确实是空」分开：原来失败被 catch 成空数组，
  // 再叠加 init() 已置位 initialized，本次会话内就永远停在「暂无公告」，
  // 一次抖动要刷新页面才恢复。
  const error = ref(false);
  const initialized = ref(false);

  const fetcher =
    contentType === "help" ? siteApi.helpArticles : siteApi.notices;

  async function fetchList() {
    const res = await fetcher({ page: 1, page_size: 6 });
    return (res.data?.list || res.data?.items || []).map(normalizeItem);
  }

  async function load(options = {}) {
    const cache = caches[contentType];
    if (
      !options.force &&
      cache.data &&
      Date.now() - cache.timestamp < CACHE_TTL
    ) {
      items.value = cache.data;
      error.value = false;
      return;
    }

    if (pending[contentType]) {
      await pending[contentType];
      items.value = caches[contentType].data || [];
      return;
    }

    loading.value = true;
    error.value = false;
    pending[contentType] = fetchList()
      .then((list) => {
        caches[contentType] = { data: list, timestamp: Date.now() };
        items.value = list;
      })
      .catch((e) => {
        // 失败不写缓存，否则这次抖动会被缓存键钉死到过期为止
        caches[contentType] = { data: null, timestamp: 0 };
        error.value = true;
        throw e;
      })
      .finally(() => {
        loading.value = false;
        pending[contentType] = null;
      });

    try {
      await pending[contentType];
    } catch {
      // 调用方不需要感知，面板按 error 态给「重新加载」
    }
  }

  async function init() {
    if (initialized.value) return;
    initialized.value = true;
    await load();
  }

  // 面板里的「重新加载」：强制绕过缓存再取一次
  function reload() {
    return load({ force: true });
  }

  const activeCategory = ref(null);

  const categories = computed(() => {
    const map = {};
    items.value.forEach((item) => {
      const cat = item.category || "未分类";
      if (!map[cat]) map[cat] = 0;
      map[cat]++;
    });
    return Object.entries(map).map(([label, count]) => ({ label, count }));
  });

  const filteredItems = computed(() => {
    if (!activeCategory.value) return items.value;
    return items.value.filter(
      (item) => (item.category || "未分类") === activeCategory.value,
    );
  });

  function activateCategory(cat) {
    activeCategory.value = activeCategory.value === cat ? null : cat;
  }

  return {
    items,
    loading,
    error,
    init,
    reload,
    categories,
    activeCategory,
    filteredItems,
    activateCategory,
  };
}
