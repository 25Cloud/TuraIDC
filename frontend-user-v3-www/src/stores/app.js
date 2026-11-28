import { defineStore } from "pinia";
import { computed } from "vue";
import { useSiteBrandingStore } from "@/app/stores/siteBranding";

export const useAppStore = defineStore("app", () => {
  const siteBrandingStore = useSiteBrandingStore();

  return {
    sidebarCollapsed: computed(() => siteBrandingStore.sidebarCollapsed),
    brandingResolved: computed(() => siteBrandingStore.brandingResolved),
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
    icpFilingLink: computed(() => siteBrandingStore.icpFilingLink),
    publicSecurityRecord: computed(() => siteBrandingStore.publicSecurityRecord),
    publicSecurityFilingLink: computed(() => siteBrandingStore.publicSecurityFilingLink),
    valueAddedLicense: computed(() => siteBrandingStore.valueAddedLicense),
    valueAddedLicenseLink: computed(() => siteBrandingStore.valueAddedLicenseLink),
    aboutCompanyName: computed(() => siteBrandingStore.aboutCompanyName),
    aboutFoundedYear: computed(() => siteBrandingStore.aboutFoundedYear),
    aboutSummary: computed(() => siteBrandingStore.aboutSummary),
    aboutTeamSize: computed(() => siteBrandingStore.aboutTeamSize),
    aboutCustomerCount: computed(() => siteBrandingStore.aboutCustomerCount),
    aboutDatacenterCount: computed(() => siteBrandingStore.aboutDatacenterCount),
    aboutMilestones: computed(() => siteBrandingStore.aboutMilestones),
    aboutSla: computed(() => siteBrandingStore.aboutSla),
    homeSections: computed(() => siteBrandingStore.homeSections),
    brandInitials: computed(() => siteBrandingStore.brandInitials),
    toggleSidebar: siteBrandingStore.toggleSidebar,
    applyPageTitle: siteBrandingStore.applyPageTitle,
    hydrateSiteConfig: siteBrandingStore.hydrateSiteConfig,
    fetchSiteConfig: siteBrandingStore.fetchSiteConfig,
  };
});
