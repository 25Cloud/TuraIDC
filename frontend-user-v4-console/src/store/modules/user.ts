import { defineStore } from 'pinia';

import { clientAuthApi } from '@/api/auth';
import { getClientToken, removeClientToken, setClientToken } from '@/app/runtime/session';
import { resetProfileState } from '@/domains/account/profileState';
import { store } from '@/store';
import type { ClientAuthSessionPayload, ClientUserInfo } from '@/types/client';

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
  return {
    ...raw,
    name: nickname || '用户',
    nickname,
    roles: ['client'],
    features: normalizeFeatures(raw.features),
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
