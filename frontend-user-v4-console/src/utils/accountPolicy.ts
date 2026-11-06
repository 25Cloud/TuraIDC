import { ref } from 'vue';

import type { ClientAccountPolicy, ClientAccountPolicyItem } from '@/types/client';

/**
 * 账户策略的「当前快照」。
 *
 * 为什么要单独放一份，而不是各处直接读 `useUserStore().info.account_policy`：
 * 请求拦截器（`src/utils/request.ts`）要按缺失项决定 40301 对话框给哪个直达按钮，
 * 但拦截器不能 import store —— store → api/auth → utils/request 会形成循环依赖。
 * 因此让 store 在归一化用户信息时把这份快照写进来，拦截器只读这里，不反向依赖 store。
 *
 * 判断权始终在后端（`App\Services\Auth\AccountPolicyService`）：
 * 这里只做「枚举码 → 前端落点」的映射与未知码过滤，不推导「谁需要实名」。
 */

/** 缺失项 → 补齐页。手机/邮箱都落在个人资料页的绑定弹窗，用 query 指明打开哪一个。 */
const ITEM_ROUTES: Record<ClientAccountPolicyItem, { path: string; query?: Record<string, string> }> = {
  verification: { path: '/client/verification' },
  phone: { path: '/client/profile', query: { bind: 'phone' } },
  email: { path: '/client/profile', query: { bind: 'email' } },
};

const KNOWN_ITEMS: readonly ClientAccountPolicyItem[] = ['verification', 'phone', 'email'];

const accountPolicy = ref<ClientAccountPolicy | null>(null);

export function isAccountPolicyItem(value: unknown): value is ClientAccountPolicyItem {
  return typeof value === 'string' && (KNOWN_ITEMS as readonly string[]).includes(value);
}

/** 由用户信息归一化写入；后端没给该字段时显式清空，避免上一个账号的策略残留 */
export function setAccountPolicy(next: ClientAccountPolicy | null | undefined): void {
  accountPolicy.value = next && typeof next === 'object' ? next : null;
}

export function currentAccountPolicy(): ClientAccountPolicy | null {
  return accountPolicy.value;
}

/** 后端下发的缺失项（未知码已过滤），顺序沿用后端：实名 → 手机 → 邮箱 */
export function currentMissingItems(): ClientAccountPolicyItem[] {
  const raw = accountPolicy.value?.missing_items;

  if (!Array.isArray(raw)) {
    return [];
  }

  return raw.filter(isAccountPolicyItem);
}

/** 后端拼好的整句提示，前端不二次拼接 */
export function currentPolicyMessage(): string {
  return String(accountPolicy.value?.message ?? '').trim();
}

export function policyItemRoute(item: ClientAccountPolicyItem): { path: string; query?: Record<string, string> } {
  return { ...ITEM_ROUTES[item] };
}

/** 一组缺失项里的第一个补齐落点（40301 对话框只有一个直达按钮） */
export function firstPolicyItemRoute(
  items: readonly ClientAccountPolicyItem[],
): { path: string; query?: Record<string, string> } | null {
  const [first] = items;

  return first ? policyItemRoute(first) : null;
}
