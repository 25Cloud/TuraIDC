import { defineStore } from 'pinia';

import { clientAuthApi } from '@/api/auth';
import { getClientToken, removeClientToken, setClientToken } from '@/app/runtime/session';
import { store } from '@/store';
import type { ClientAuthSessionPayload, ClientUserInfo } from '@/types/client';

const initUserInfo: ClientUserInfo = {
  name: '',
  roles: ['client'],
};

function normalizeUserInfo(raw: Record<string, unknown> = {}): ClientUserInfo {
  const nickname = String(raw.nickname || raw.name || raw.email || '').trim();
  return {
    ...raw,
    name: nickname || '二五云用户',
    nickname,
    roles: ['client'],
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
