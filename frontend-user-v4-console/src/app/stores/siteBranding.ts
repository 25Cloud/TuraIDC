import { applyDocumentTitle, deriveInitials, syncDocumentTitle, updateFavicon } from '@turaidc/shared/runtime';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

import siteApi from '@/api/site';
import { DEFAULT_SUPPORT_CONTACTS } from '@/data/supportContacts';
import { resolveApiManagedAssetUrl } from '@/utils/apiOrigin';

const DEFAULT_SITE_NAME = import.meta.env.VITE_APP_TITLE || '图拉云';
const DEFAULT_SITE_LOGO = '/favicon.ico';
const DEFAULT_FAVICON = '/favicon.ico';
const DEFAULT_CLIENT_CONSOLE_ICON = DEFAULT_FAVICON;

function normalizeBrandAsset(raw: unknown, fallback: string) {
  const value = String(raw ?? '').trim();

  if (!value) {
    return fallback;
  }

  const normalized = value.replace(/\\/g, '/');

  if (/^https?:\/\//i.test(normalized) || normalized.startsWith('//') || normalized.startsWith('data:')) {
    return normalized;
  }

  if (/(?:^|\/)branding\/logo\.svg(?:[?#].*)?$/i.test(normalized)) {
    return DEFAULT_SITE_LOGO;
  }

  if (/(?:^|\/)branding\/logo1\.svg(?:[?#].*)?$/i.test(normalized)) {
    return DEFAULT_FAVICON;
  }

  const managedAssetUrl = resolveApiManagedAssetUrl(normalized, import.meta.env.VITE_API_BASE_URL);
  if (managedAssetUrl) {
    return managedAssetUrl;
  }

  return normalized.startsWith('/') ? normalized : `/${normalized.replace(/^\/+/, '')}`;
}

function pick(raw: Record<string, unknown> | undefined, keys: string[], fallback: string) {
  for (const key of keys) {
    const value = raw?.[key];
    if (value !== undefined && value !== null && String(value).trim() !== '') {
      return String(value).trim();
    }
  }
  return fallback;
}

function pickOptional(raw: Record<string, unknown> | undefined, keys: string[]) {
  for (const key of keys) {
    const value = raw?.[key];
    if (value !== undefined && value !== null && String(value).trim() !== '') {
      return String(value).trim();
    }
  }
  return undefined;
}

export const useSiteBrandingStore = defineStore('site-branding', () => {
  const sidebarCollapsed = ref(false);
  const siteName = ref(DEFAULT_SITE_NAME);
  const browserTitle = ref(DEFAULT_SITE_NAME);
  const siteLogo = ref(DEFAULT_SITE_LOGO);
  const siteFavicon = ref(DEFAULT_FAVICON);
  const clientConsoleIcon = ref(DEFAULT_CLIENT_CONSOLE_ICON);
  const serviceQqGroup = ref<string>(DEFAULT_SUPPORT_CONTACTS.qqGroup);
  const serviceEmail = ref<string>(DEFAULT_SUPPORT_CONTACTS.email);
  const serviceHours = ref<string>(DEFAULT_SUPPORT_CONTACTS.hours);
  const supportGroupTitle = ref<string>('');
  const supportGroupText = ref<string>('');
  const supportGroupQr = ref<string>(DEFAULT_SUPPORT_CONTACTS.groupQr);
  const supportGroupLink = ref<string>('');
  const termsUrl = ref<string>('');
  const privacyUrl = ref<string>('');
  const brandInitials = computed(() => deriveInitials(siteName.value));

  let fetchPromise: Promise<void> | null = null;

  function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value;
  }

  function applyPageTitle(pageTitle = '') {
    const baseTitle = browserTitle.value || siteName.value || DEFAULT_SITE_NAME;
    applyDocumentTitle(pageTitle, baseTitle, siteFavicon.value || DEFAULT_FAVICON, DEFAULT_FAVICON);
  }

  function hydrateSiteConfig(data: Record<string, unknown> = {}) {
    const previousBaseTitle = browserTitle.value || siteName.value || DEFAULT_SITE_NAME;
    siteName.value = pick(data, ['site_name'], siteName.value || DEFAULT_SITE_NAME);
    browserTitle.value = pick(data, ['browser_title'], siteName.value || DEFAULT_SITE_NAME);
    siteLogo.value = normalizeBrandAsset(
      pick(data, ['site_logo'], siteLogo.value || DEFAULT_SITE_LOGO),
      DEFAULT_SITE_LOGO,
    );
    siteFavicon.value = normalizeBrandAsset(
      pick(data, ['site_favicon'], siteFavicon.value || DEFAULT_FAVICON),
      DEFAULT_FAVICON,
    );
    clientConsoleIcon.value = normalizeBrandAsset(
      pickOptional(data, ['client_console_icon', 'clientConsoleIcon']) ||
        siteFavicon.value ||
        DEFAULT_CLIENT_CONSOLE_ICON,
      siteFavicon.value || DEFAULT_CLIENT_CONSOLE_ICON,
    );
    serviceQqGroup.value = pick(
      data,
      ['service_qq_group', 'serviceQqGroup', 'service_phone', 'servicePhone'],
      serviceQqGroup.value || DEFAULT_SUPPORT_CONTACTS.qqGroup,
    );
    serviceEmail.value = pick(
      data,
      ['service_email', 'serviceEmail'],
      serviceEmail.value || DEFAULT_SUPPORT_CONTACTS.email,
    );
    serviceHours.value = pick(
      data,
      ['service_hours', 'serviceHours'],
      serviceHours.value || DEFAULT_SUPPORT_CONTACTS.hours,
    );
    supportGroupTitle.value = pick(data, ['support_group_title', 'supportGroupTitle'], supportGroupTitle.value || '');
    supportGroupText.value = pick(data, ['support_group_text', 'supportGroupText'], supportGroupText.value || '');
    supportGroupQr.value = pick(
      data,
      ['support_group_qr', 'supportGroupQr'],
      supportGroupQr.value || DEFAULT_SUPPORT_CONTACTS.groupQr,
    );
    supportGroupLink.value = pick(data, ['support_group_link', 'supportGroupLink'], supportGroupLink.value || '');
    termsUrl.value = pick(data, ['terms_url'], termsUrl.value || '');
    privacyUrl.value = pick(data, ['privacy_url'], privacyUrl.value || '');
    syncDocumentTitle(browserTitle.value || siteName.value || DEFAULT_SITE_NAME, previousBaseTitle, DEFAULT_SITE_NAME);
    updateFavicon(siteFavicon.value || DEFAULT_FAVICON, DEFAULT_FAVICON);
  }

  async function fetchSiteConfig() {
    if (fetchPromise) {
      return fetchPromise;
    }

    fetchPromise = siteApi
      .config({ silentError: true })
      .then((res: any) => {
        hydrateSiteConfig(res.data || {});
      })
      .catch(() => {
        siteLogo.value = normalizeBrandAsset(siteLogo.value || DEFAULT_SITE_LOGO, DEFAULT_SITE_LOGO);
        siteFavicon.value = normalizeBrandAsset(siteFavicon.value || DEFAULT_FAVICON, DEFAULT_FAVICON);
        clientConsoleIcon.value = normalizeBrandAsset(
          clientConsoleIcon.value || siteFavicon.value || DEFAULT_CLIENT_CONSOLE_ICON,
          siteFavicon.value || DEFAULT_CLIENT_CONSOLE_ICON,
        );
      })
      .finally(() => {
        updateFavicon(siteFavicon.value || DEFAULT_FAVICON, DEFAULT_FAVICON);
        fetchPromise = null;
      });

    return fetchPromise;
  }

  return {
    sidebarCollapsed,
    siteName,
    browserTitle,
    siteLogo,
    siteFavicon,
    clientConsoleIcon,
    serviceQqGroup,
    serviceEmail,
    serviceHours,
    supportGroupTitle,
    supportGroupText,
    supportGroupQr,
    supportGroupLink,
    termsUrl,
    privacyUrl,
    brandInitials,
    toggleSidebar,
    applyPageTitle,
    hydrateSiteConfig,
    fetchSiteConfig,
  };
});
