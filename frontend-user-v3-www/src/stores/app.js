import { defineStore } from 'pinia'
import { computed } from 'vue'
import { useSiteBrandingStore } from '@/app/stores/siteBranding'

export const useAppStore = defineStore('app', () => {
  const siteBrandingStore = useSiteBrandingStore()

  return {
    sidebarCollapsed: computed(() => siteBrandingStore.sidebarCollapsed),
    siteName: computed(() => siteBrandingStore.siteName),
    browserTitle: computed(() => siteBrandingStore.browserTitle),
    siteLogo: computed(() => siteBrandingStore.siteLogo),
    siteFavicon: computed(() => siteBrandingStore.siteFavicon),
    serviceQqGroup: computed(() => siteBrandingStore.serviceQqGroup),
    serviceEmail: computed(() => siteBrandingStore.serviceEmail),
    serviceHours: computed(() => siteBrandingStore.serviceHours),
    supportGroupTitle: computed(() => siteBrandingStore.supportGroupTitle),
    supportGroupText: computed(() => siteBrandingStore.supportGroupText),
    supportGroupQr: computed(() => siteBrandingStore.supportGroupQr),
    supportGroupLink: computed(() => siteBrandingStore.supportGroupLink),
    termsUrl: computed(() => siteBrandingStore.termsUrl),
    privacyUrl: computed(() => siteBrandingStore.privacyUrl),
    icpRecord: computed(() => siteBrandingStore.icpRecord),
    valueAddedLicense: computed(() => siteBrandingStore.valueAddedLicense),
    brandInitials: computed(() => siteBrandingStore.brandInitials),
    toggleSidebar: siteBrandingStore.toggleSidebar,
    applyPageTitle: siteBrandingStore.applyPageTitle,
    hydrateSiteConfig: siteBrandingStore.hydrateSiteConfig,
    fetchSiteConfig: siteBrandingStore.fetchSiteConfig,
  }
})
