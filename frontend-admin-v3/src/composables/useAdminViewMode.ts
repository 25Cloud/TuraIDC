import { computed, ref, watch } from 'vue';

/**
 * 管理端列表页的「列表 / 卡片」展示方式。
 *
 * 后台此前各页风格分裂——商品、用户是表格，工单、上游是卡片，管理员没有任何
 * 统一入口切换，跨页操作手感不一致。这里给所有列表页一个共用开关。
 *
 * 语义（踩过一次坑后按管理员预期收敛）：
 *  - 持久化的只有「默认展示方式」这一个值，存在 localStorage；
 *    换浏览器回到默认值是预期行为，也省掉一条后端写入链路。
 *  - 每个列表页初始形态 = 默认值；从没设置过默认值时才用页面自带兜底
 *    （工单原本是卡片，其它页是列表）。
 *  - 页面内临时切换只影响当前会话里的这一个页面，**不写 localStorage**：
 *    此前每页各存一份偏好，导致管理员改了默认值后旧页面仍按自己的历史偏好走，
 *    出现「明明设成列表，工单还是卡片」。
 */
export type AdminViewMode = 'list' | 'card';

/**
 * 桌面卡片视图的网格样式。
 *
 * 各列表页已经有一套信息完整的移动端卡片，桌面卡片视图直接复用它们，
 * 只把容器从「单列堆叠」换成自适应网格。用行内样式而不是全局类：
 * 移动端容器的样式是按页面定义的，加类会被页面 scoped 样式按优先级压回去。
 */
export const ADMIN_CARD_GRID_STYLE = {
  display: 'grid',
  gridTemplateColumns: 'repeat(auto-fill, minmax(min(100%, 22rem), 1fr))',
  gap: '16px',
} as const;

const DEFAULT_STORAGE_KEY = 'turaidc:admin-view-mode-default';

/** 旧版本按页面存的偏好键前缀：只用于清理，不再读取 */
const LEGACY_PAGE_STORAGE_PREFIX = 'turaidc:admin-view-mode:';

function isMode(value: unknown): value is AdminViewMode {
  return value === 'list' || value === 'card';
}

function readStorage(key: string): string {
  try {
    return window.localStorage.getItem(key) ?? '';
  } catch {
    // 隐私模式 / 禁用存储时不要抛错：展示偏好不值得让整个页面挂掉
    return '';
  }
}

function writeStorage(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    /* 同上：写不进去就只影响下次是否记得 */
  }
}

/** 读取管理员设置过的默认值；没有设置过返回 null（区别于「设置为 list」） */
function readConfiguredDefault(): AdminViewMode | null {
  const stored = readStorage(DEFAULT_STORAGE_KEY);

  return isMode(stored) ? stored : null;
}

/**
 * 清掉旧版本遗留的页面级偏好。
 *
 * 不清掉的话，浏览器里还存着的历史值会继续压住新的默认值——表现为
 * 「默认值改了没反应」，直到用户手动清 localStorage。
 */
function cleanupLegacyPageOverrides(): void {
  try {
    const keys: string[] = [];

    for (let index = 0; index < window.localStorage.length; index += 1) {
      const key = window.localStorage.key(index);
      if (key && key.startsWith(LEGACY_PAGE_STORAGE_PREFIX)) {
        keys.push(key);
      }
    }

    keys.forEach((key) => window.localStorage.removeItem(key));
  } catch {
    /* 存储不可用时无事可做 */
  }
}

const defaultMode = ref<AdminViewMode>(readConfiguredDefault() ?? 'list');

/** 已创建的页面开关，默认值变化时全部跟随。 */
const pageModes = new Map<string, ReturnType<typeof ref<AdminViewMode>>>();

export function getDefaultViewMode(): AdminViewMode {
  return defaultMode.value;
}

export function setDefaultViewMode(mode: AdminViewMode): void {
  defaultMode.value = mode;
  writeStorage(DEFAULT_STORAGE_KEY, mode);
  cleanupLegacyPageOverrides();
}

/**
 * 页面级开关。
 *
 * @param pageKey 页面的稳定标识，用于同一会话内记住本页的临时切换
 * @param fallback 从没设置过默认值时该页的兜底形态（工单原本就是卡片）
 */
export function useAdminViewMode(
  pageKey: string,
  fallback: AdminViewMode = 'list',
) {
  if (!pageModes.has(pageKey)) {
    // 旧版本可能已把页面偏好写进 localStorage，初始化时顺手清一次，
    // 避免升级后的第一次打开仍被历史值带偏。
    cleanupLegacyPageOverrides();
    pageModes.set(pageKey, ref(readConfiguredDefault() ?? fallback));
  }

  const state = pageModes.get(pageKey)!;

  // 默认值一变，所有页面立即跟随（页面内临时切换不落库，也不该挡住默认值）
  watch(defaultMode, (next) => {
    state.value = next;
  });

  function setMode(mode: AdminViewMode): void {
    // 只改当前会话的页面状态，不写 localStorage
    state.value = mode;
  }

  return {
    mode: computed(() => state.value),
    isCard: computed(() => state.value === 'card'),
    setMode,
    toggle: () => setMode(state.value === 'card' ? 'list' : 'card'),
  };
}
