import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { applyDocumentTitle, deriveInitials, syncDocumentTitle, updateFavicon } from '@turaidc/shared/runtime'
import siteApi from '@/api/site'
import { DEFAULT_SUPPORT_CONTACTS } from '@/data/supportContacts'
import { resolveApiAssetUrl } from '@/utils/apiAssetUrl'

declare global {
  interface Window {
    __CW_SITE_CONFIG__?: Record<string, unknown> | null
  }
}

const DEFAULT_SITE_NAME = import.meta.env.VITE_APP_TITLE || '图拉云'
const DEFAULT_SITE_LOGO = '/branding/logo.svg'
const DEFAULT_FAVICON = '/branding/logo1.svg'
const API_BASE_URL = String(import.meta.env.VITE_API_BASE_URL || '')

function pick(raw, keys, fallback) {
  for (const key of keys) {
    const value = raw?.[key]
    if (value !== undefined && value !== null && String(value).trim() !== '') {
      return String(value).trim()
    }
  }
  return fallback
}

function readBootstrappedSiteConfig() {
  if (typeof window === 'undefined') {
    return {}
  }

  return window.__CW_SITE_CONFIG__ && typeof window.__CW_SITE_CONFIG__ === 'object'
    ? window.__CW_SITE_CONFIG__
    : {}
}

export const useSiteBrandingStore = defineStore('site-branding', () => {
  const initialSiteConfig = readBootstrappedSiteConfig()
  const sidebarCollapsed = ref(false)
  const siteName = ref(pick(initialSiteConfig, ['site_name'], DEFAULT_SITE_NAME))
  const browserTitle = ref(pick(initialSiteConfig, ['browser_title'], siteName.value || DEFAULT_SITE_NAME))
  const siteLogo = ref(resolveApiAssetUrl(pick(initialSiteConfig, ['site_logo'], DEFAULT_SITE_LOGO), API_BASE_URL))
  const siteFavicon = ref(resolveApiAssetUrl(pick(initialSiteConfig, ['site_favicon'], DEFAULT_FAVICON), API_BASE_URL))
  const serviceQqGroup = ref(pick(initialSiteConfig, ['service_qq_group', 'serviceQqGroup'], DEFAULT_SUPPORT_CONTACTS.qqGroup))
  const serviceEmail = ref(pick(initialSiteConfig, ['service_email', 'serviceEmail'], DEFAULT_SUPPORT_CONTACTS.email))
  const serviceHours = ref(pick(initialSiteConfig, ['service_hours', 'serviceHours'], DEFAULT_SUPPORT_CONTACTS.hours))
  const supportGroupTitle = ref(pick(initialSiteConfig, ['support_group_title', 'supportGroupTitle'], ''))
  const supportGroupText = ref(pick(initialSiteConfig, ['support_group_text', 'supportGroupText'], ''))
  const supportGroupQr = ref(resolveApiAssetUrl(
    pick(initialSiteConfig, ['support_group_qr', 'supportGroupQr'], DEFAULT_SUPPORT_CONTACTS.groupQr),
    API_BASE_URL,
  ))
  const supportGroupLink = ref(pick(initialSiteConfig, ['support_group_link', 'supportGroupLink'], ''))
  const termsUrl = ref(pick(initialSiteConfig, ['terms_url'], ''))
  const privacyUrl = ref(pick(initialSiteConfig, ['privacy_url'], ''))
  const icpRecord = ref(pick(initialSiteConfig, ['icp_record', 'icpRecord'], String(import.meta.env.VITE_ICP_RECORD || '')))
  const valueAddedLicense = ref(
    pick(
      initialSiteConfig,
      ['value_added_license', 'valueAddedLicense', 'value_added_telecom_license'],
      String(import.meta.env.VITE_VALUE_ADDED_LICENSE || ''),
    ),
  )
  const brandInitials = computed(() => deriveInitials(siteName.value))

  let fetchPromise = null

  function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value
  }

  function applyPageTitle(pageTitle = '') {
    const baseTitle = browserTitle.value || siteName.value || DEFAULT_SITE_NAME
    applyDocumentTitle(pageTitle, baseTitle, siteFavicon.value || DEFAULT_FAVICON, DEFAULT_FAVICON)
  }

  function hydrateSiteConfig(data = {}) {
    const previousBaseTitle = browserTitle.value || siteName.value || DEFAULT_SITE_NAME
    siteName.value = pick(data, ['site_name'], siteName.value || DEFAULT_SITE_NAME)
    browserTitle.value = pick(data, ['browser_title'], siteName.value || DEFAULT_SITE_NAME)
    siteLogo.value = resolveApiAssetUrl(pick(data, ['site_logo'], siteLogo.value || DEFAULT_SITE_LOGO), API_BASE_URL)
    siteFavicon.value = resolveApiAssetUrl(pick(data, ['site_favicon'], siteFavicon.value || DEFAULT_FAVICON), API_BASE_URL)
    serviceQqGroup.value = pick(data, ['service_qq_group', 'serviceQqGroup', 'service_phone', 'servicePhone'], serviceQqGroup.value || DEFAULT_SUPPORT_CONTACTS.qqGroup)
    serviceEmail.value = pick(data, ['service_email', 'serviceEmail'], serviceEmail.value || DEFAULT_SUPPORT_CONTACTS.email)
    serviceHours.value = pick(data, ['service_hours', 'serviceHours'], serviceHours.value || DEFAULT_SUPPORT_CONTACTS.hours)
    supportGroupTitle.value = pick(data, ['support_group_title', 'supportGroupTitle'], supportGroupTitle.value || '')
    supportGroupText.value = pick(data, ['support_group_text', 'supportGroupText'], supportGroupText.value || '')
    supportGroupQr.value = resolveApiAssetUrl(
      pick(data, ['support_group_qr', 'supportGroupQr'], supportGroupQr.value || DEFAULT_SUPPORT_CONTACTS.groupQr),
      API_BASE_URL,
    )
    supportGroupLink.value = pick(data, ['support_group_link', 'supportGroupLink'], supportGroupLink.value || '')
    termsUrl.value = pick(data, ['terms_url'], termsUrl.value || '')
    privacyUrl.value = pick(data, ['privacy_url'], privacyUrl.value || '')
    icpRecord.value = pick(data, ['icp_record', 'icpRecord'], icpRecord.value || '')
    valueAddedLicense.value = pick(data, ['value_added_license', 'valueAddedLicense', 'value_added_telecom_license'], valueAddedLicense.value || '')
    syncDocumentTitle(browserTitle.value || siteName.value || DEFAULT_SITE_NAME, previousBaseTitle, DEFAULT_SITE_NAME)
    updateFavicon(siteFavicon.value || DEFAULT_FAVICON, DEFAULT_FAVICON)
  }

  async function fetchSiteConfig() {
    if (fetchPromise) {
      return fetchPromise
    }

    fetchPromise = siteApi.config()
      .then((res) => {
        hydrateSiteConfig(res.data || {})
      })
      .catch(() => {
        siteLogo.value = siteLogo.value || DEFAULT_SITE_LOGO
        siteFavicon.value = siteFavicon.value || DEFAULT_FAVICON
      })
      .finally(() => {
        updateFavicon(siteFavicon.value || DEFAULT_FAVICON, DEFAULT_FAVICON)
        fetchPromise = null
      })

    return fetchPromise
  }

  return {
    sidebarCollapsed,
    siteName,
    browserTitle,
    siteLogo,
    siteFavicon,
    serviceQqGroup,
    serviceEmail,
    serviceHours,
    supportGroupTitle,
    supportGroupText,
    supportGroupQr,
    supportGroupLink,
    termsUrl,
    privacyUrl,
    icpRecord,
    valueAddedLicense,
    brandInitials,
    toggleSidebar,
    applyPageTitle,
    hydrateSiteConfig,
    fetchSiteConfig,
  }
})
