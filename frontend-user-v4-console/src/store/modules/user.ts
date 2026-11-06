import { defineStore } from 'pinia';

import { clientAuthApi } from '@/api/auth';
import { getClientToken, removeClientToken, setClientToken } from '@/app/runtime/session';
import { resetProfileState } from '@/domains/account/profileState';
import { store } from '@/store';
import type { ClientAccountPolicy, ClientAuthSessionPayload, ClientUserInfo } from '@/types/client';
import { setAccountPolicy } from '@/utils/accountPolicy';

const initUserInfo: ClientUserInfo = {
  name: '',
  roles: ['client'],
};

/**
 * 可选功能可用性归一化：后端下发的是 PHP bool，只认 true。
 * 缺键、非对象、历史持久化数据里的旧结构一律当不可用，与 App\Support\OptionalFeature 口径一致。
 */
function normalizeFeatures(raw: unknown): Record<string, boolean> {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {};

  const features: Record<string, boolean> = {};
  Object.entries(raw as Record<string, unknown>).forEach(([key, value]) => {
    features[key] = value === true;
  });
  return features;
}

function normalizeUserInfo(raw: Record<string, unknown> = {}): ClientUserInfo {
  const nickname = String(raw.nickname || raw.name || raw.email || '').trim();
  const accountPolicy =
    raw.account_policy && typeof raw.account_policy === 'object' && !Array.isArray(raw.account_policy)
      ? (raw.account_policy as ClientAccountPolicy)
      : null;

  // 账户策略要同步给请求拦截器（40301 对话框按缺失项给直达按钮）；
  // 拦截器不能反向 import store，因此这里把快照写进 utils/accountPolicy。
  setAccountPolicy(accountPolicy);

  return {
    ...raw,
    name: nickname || '用户',
    nickname,
    roles: ['client'],
    features: normalizeFeatures(raw.features),
    account_policy: accountPolicy,
  };
}

export const useUserStore = defineStore('user', {
  state: () => ({
    token: getClientToken() || '',
    profileHydrated: false,
    userInfo: { ...initUserInfo },
  }),
  getters: {
    roles: (state) => state.userInfo?.roles || ['client'],
    info: (state) => state.userInfo,
    /**
     * 可选功能可用性（插件提供能力、页面托管在核心）。
     * 侧栏与路由 meta.requiresFeature 都按它判定；后端缺键一律视为不可用。
     */
    features: (state): Record<string, boolean> => normalizeFeatures(state.userInfo?.features),
  },
  actions: {
    syncTokenFromSession() {
      this.token = getClientToken() || '';
      if (!this.token) {
        this.profileHydrated = false;
      }
    },
    clearLocalSession() {
      removeClientToken();
      if (typeof window !== 'undefined') {
        window.localStorage.removeItem('client_user');
      }
      this.token = '';
      this.profileHydrated = false;
      this.userInfo = { ...initUserInfo };
      // 退出登录 / 会话失效时一并清空账户设置的共享资料，
      // 避免下一个登录的人看到上一个账号的昵称、实名信息等
      resetProfileState();
      // 账户策略快照同理：不清会让提示条与 40301 对话框带上一个账号的缺失项
      setAccountPolicy(null);
    },
    async clientLogin(loginData: Record<string, unknown>) {
      const res = await clientAuthApi.login(loginData);
      const payload = res.data as ClientAuthSessionPayload | undefined;
      const token = String(payload?.token || '');
      if (token) {
        setClientToken(token);
        this.token = token;
      }
      this.userInfo = normalizeUserInfo(payload?.user || {});
      this.profileHydrated = true;
      return res;
    },
    async clientLoginByCode(loginData: Record<string, unknown>) {
      const res = await clientAuthApi.loginByCode(loginData);
      const payload = res.data as ClientAuthSessionPayload | undefined;
      const token = String(payload?.token || '');
      if (token) {
        setClientToken(token);
        this.token = token;
      }
      this.userInfo = normalizeUserInfo(payload?.user || {});
      this.profileHydrated = true;
      return res;
    },
    async login(loginData: Record<string, unknown>) {
      return this.clientLogin(loginData);
    },
    async clientRegister(data: Record<string, unknown>) {
      const res = await clientAuthApi.register(data);
      const payload = res.data as ClientAuthSessionPayload | undefined;
      const token = String(payload?.token || '');
      if (token) {
        setClientToken(token);
        this.token = token;
      }
      this.userInfo = normalizeUserInfo(payload?.user || {});
      this.profileHydrated = true;
      return res;
    },
    async exchangeLoginAsCode(code: string) {
      const res = await clientAuthApi.exchangeLoginAsCode({ code });
      const token = String(res.data?.token || '');
      if (token) {
        setClientToken(token);
        this.token = token;
      }
      await this.getUserInfo();
      return res;
    },
    async getUserInfo() {
      const res = await clientAuthApi.info();
      this.userInfo = normalizeUserInfo(res.data || {});
      this.syncTokenFromSession();
      this.profileHydrated = true;
      return this.userInfo;
    },
    async logout() {
      try {
        if (this.token) {
          await clientAuthApi.logout();
        }
      } catch {
        // Logout must always clear the local client session even if the API is unavailable.
      } finally {
        this.clearLocalSession();
      }
    },
  },
  persist: {
    key: 'client_user',
    pick: ['userInfo'],
  },
});

export function getUserStore() {
  return useUserStore(store);
}
