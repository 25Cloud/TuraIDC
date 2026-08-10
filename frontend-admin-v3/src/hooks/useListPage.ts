import type { Ref } from 'vue';
import { onMounted, reactive, ref } from 'vue';

export interface ListPageResponse<T> {
  list?: T[];
  total?: number;
  /** 后端回传的当前页码（可选，用于同步分页器） */
  page?: number;
  /** 后端回传的每页条数（可选，用于同步分页器） */
  page_size?: number;
}

export interface UseListPageOptions<F, T> {
  /** 列表请求函数，接收 { ...filters, page, page_size }，返回列表分页响应 */
  fetch: (params: F & { page: number; page_size: number }) => Promise<ListPageResponse<T>>;
  /** 默认筛选值 */
  defaultFilters: F;
  /** 默认每页条数 */
  defaultPageSize?: number;
  /** 是否 onMounted 自动加载 */
  immediate?: boolean;
  /** 自定义错误处理，默认吞掉错误 */
  onError?: (error: unknown) => void;
}

export function useListPage<F extends Record<string, any>, T>(options: UseListPageOptions<F, T>) {
  const { fetch, defaultFilters, defaultPageSize = 20, immediate = true, onError } = options;

  const filters = reactive({ ...defaultFilters }) as F;
  const list: Ref<T[]> = ref([]);
  const total = ref(0);
  const loading = ref(false);
  const pagination = reactive({
    page: 1,
    page_size: defaultPageSize,
    pageSizeOptions: [20, 50, 100],
  });

  async function loadList() {
    loading.value = true;
    try {
      const res = await fetch({
        ...filters,
        page: pagination.page,
        page_size: pagination.page_size,
      });
      list.value = res.list || [];
      total.value = Number(res.total || 0);
      if (res.page) pagination.page = Number(res.page);
      if (res.page_size) pagination.page_size = Number(res.page_size);
    } catch (error) {
      if (onError) onError(error);
    } finally {
      loading.value = false;
    }
  }

  function handleSearch() {
    pagination.page = 1;
    return loadList();
  }

  function resetFilters() {
    Object.assign(filters, defaultFilters);
    pagination.page = 1;
    return loadList();
  }

  function handlePageChange(page: number) {
    pagination.page = page;
    return loadList();
  }

  function handlePageSizeChange(size: number) {
    pagination.page_size = size;
    pagination.page = 1;
    return loadList();
  }

  /** 兼容 t-pagination 的 change 事件回调，入参为 { current, pageSize } */
  function handlePaginationChange(data: { current: number; pageSize: number }) {
    pagination.page = data.current;
    pagination.page_size = data.pageSize;
    return loadList();
  }

  if (immediate) onMounted(loadList);

  return {
    filters,
    list,
    total,
    loading,
    pagination,
    loadList,
    handleSearch,
    resetFilters,
    handlePageChange,
    handlePageSizeChange,
    handlePaginationChange,
  };
}
