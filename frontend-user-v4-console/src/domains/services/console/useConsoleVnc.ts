import { MessagePlugin } from 'tdesign-vue-next';
import { ref } from 'vue';

import clientApi from '@/api/client';
import type { ServiceVncCredentials } from '@/types/client';

import { resolveErrorMessage, VNC_CREDENTIAL_STORAGE_PREFIX } from './useConsoleCore';

export interface UseConsoleVncOptions {
  serviceId: { value: number };
  actionLoading: { value: boolean };
  activeTab: { value: string };
}

export function useConsoleVnc(options: UseConsoleVncOptions) {
  const { serviceId, actionLoading, activeTab } = options;

  const vncUrl = ref('');

  function decorateVncUrl(rawUrl: unknown): string {
    const url = String(rawUrl || '').trim();
    if (!url) return '';
    try {
      const target = new URL(url, window.location.origin);
      target.searchParams.set('service_id', String(serviceId.value));
      return target.toString();
    } catch {
      return url;
    }
  }

  function extractVncLaunchToken(rawUrl: unknown): string {
    try {
      return new URL(String(rawUrl || ''), window.location.origin).searchParams.get('token') || '';
    } catch {
      return '';
    }
  }

  function normalizeVncCredentials(payload: unknown): ServiceVncCredentials | null {
    if (!payload || typeof payload !== 'object') return null;
    const source = payload as ServiceVncCredentials;
    const credentials: ServiceVncCredentials = {};
    const username = String(source.username || '').trim();
    const target = String(source.target || '').trim();
    const password = String(source.password || '').trim();

    if (username) credentials.username = username;
    if (target) credentials.target = target;
    if (password) credentials.password = password;

    return Object.keys(credentials).length > 0 ? credentials : null;
  }

  function storeVncCredentialsForUrl(rawUrl: string, payload: unknown, targetWindow: Window | null = window) {
    const token = extractVncLaunchToken(rawUrl);
    const credentials = normalizeVncCredentials(payload);
    if (!token || !credentials) return;

    try {
      targetWindow?.sessionStorage?.setItem?.(
        `${VNC_CREDENTIAL_STORAGE_PREFIX}${token}`,
        JSON.stringify({ ...credentials, service_id: serviceId.value, saved_at: Date.now() }),
      );
    } catch {
      // sessionStorage can be unavailable in restricted or cross-origin windows.
    }
  }

  async function requestVncUrl(targetWindow: Window | null = null): Promise<string> {
    const res = await clientApi.serviceVnc(serviceId.value, { silentError: true });
    const url = String(res.data?.url || '').trim();
    if (!url) throw new Error('未获取到可用的 VNC 地址');
    const decoratedUrl = decorateVncUrl(url);
    const credentials = res.data?.vnc_credentials;
    storeVncCredentialsForUrl(decoratedUrl, credentials);
    if (targetWindow) storeVncCredentialsForUrl(decoratedUrl, credentials, targetWindow);
    return decoratedUrl;
  }

  async function handleOpenVnc(mode: 'embed' | 'window' = 'embed') {
    const popup = mode === 'window' ? window.open('about:blank', '_blank') : null;
    if (mode === 'window' && !popup) {
      MessagePlugin.warning('浏览器已拦截新窗口，请允许弹窗后重试');
      return;
    }

    if (popup) {
      popup.opener = null;
    }

    actionLoading.value = true;
    try {
      const url = await requestVncUrl(popup);
      activeTab.value = 'vnc';
      if (mode === 'window' && popup) {
        popup.location.replace(url);
        MessagePlugin.success('VNC 新窗口已打开');
      } else {
        vncUrl.value = url;
        MessagePlugin.success('VNC 控制台已载入');
      }
    } catch (error: unknown) {
      if (popup) popup.close();
      MessagePlugin.error(resolveErrorMessage(error, '获取 VNC 地址失败'));
    } finally {
      actionLoading.value = false;
    }
  }

  return {
    vncUrl,
    handleOpenVnc,
  };
}
