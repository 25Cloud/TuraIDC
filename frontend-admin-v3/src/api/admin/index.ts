import { agentDiscountApi } from './agentDiscount';
import { contentApi, mediaApi, siteHeroApi } from './content';
import { couponCampaignsApi, couponsApi } from './coupon';
import { dashboardApi } from './dashboard';
import { databaseApi } from './database';
import { financeMenuApi, invoiceApi, orderApi } from './finance';
import { logsApi } from './logs';
import { memberLevelsApi } from './memberLevel';
import { pluginsApi } from './plugins';
import { referralApi } from './referral';
import { schedulesApi, settingsApi } from './settings';
import { cpuModelCatalogApi, instanceSpecCatalogApi } from './spec';
import { ticketsApi } from './ticket';
import { verificationsApi } from './verification';

// Shared admin API types.
export * from './types';

// Combine all domain APIs into a single adminApi object (matching original structure)
export const adminApi = {
  // Dashboard
  dashboardStats: dashboardApi.stats,
  dashboardRecentInvoices: dashboardApi.recentInvoices,
  dashboardMonthlyRevenue: dashboardApi.monthlyRevenue,

  // Invoices
  invoices: invoiceApi,

  // Orders
  orders: orderApi,

  // Finance Menu
  financeMenu: financeMenuApi,

  // Settings
  settings: settingsApi,

  // Database
  database: databaseApi,

  // Logs
  logs: logsApi,

  // Schedules
  schedules: schedulesApi,

  // Member Levels
  memberLevels: memberLevelsApi,

  // Agent Discounts
  agentDiscount: agentDiscountApi,

  // Content
  content: contentApi,

  // Media
  media: mediaApi,

  // Site Hero
  siteHero: siteHeroApi,

  // Instance Spec Catalog
  instanceSpecCatalog: instanceSpecCatalogApi,

  // CPU Model Catalog
  cpuModelCatalog: cpuModelCatalogApi,

  // Coupons
  coupons: couponsApi,

  // Coupon Campaigns
  couponCampaigns: couponCampaignsApi,

  // Referral
  referral: referralApi,

  // Tickets
  tickets: ticketsApi,

  // Verifications
  verifications: verificationsApi,

  // Integration Plugins
  plugins: pluginsApi,
};
