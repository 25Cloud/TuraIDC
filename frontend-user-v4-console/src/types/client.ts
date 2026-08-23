export interface ApiEnvelope<T> {
  code: number;
  message?: string;
  data: T;
}

export interface PagedList<T> {
  list: T[];
  total: number;
  page?: number;
  page_size?: number;
}

export interface ClientFinanceListParams {
  page?: number;
  page_size?: number;
  keyword?: string;
  status?: string | number;
  type?: string;
  start_date?: string;
  end_date?: string;
}

export interface SummaryRecord {
  [key: string]: unknown;
}

export interface ClientAlipayAccount {
  real_name?: string;
  account?: string;
  is_bound?: boolean | number;
  [key: string]: unknown;
}

export interface ClientMemberLevel {
  id?: number;
  name?: string;
  code?: string;
  reward_rate?: number | string;
  [key: string]: unknown;
}

export interface ClientAgentGroup {
  id?: number | string;
  name?: string;
  code?: string;
  [key: string]: unknown;
}

export interface ClientUserInfo {
  id?: number | string;
  name?: string;
  nickname?: string;
  display_name?: string;
  email?: string;
  phone?: string;
  status?: number | string;
  cash_balance?: string | number;
  credit_limit?: string | number;
  referral_frozen_balance?: string | number;
  referral_available_balance?: string | number;
  referral_pending_withdrawal_balance?: string | number;
  referral_withdrawn_balance?: string | number;
  referral_code?: string;
  referrer_user_id?: number | string | null;
  member_level_id?: number | string | null;
  total_sales_amount?: string | number;
  member_level?: ClientMemberLevel | null;
  agent_group?: ClientAgentGroup | null;
  is_verified?: number | string;
  real_name?: string;
  id_card_masked?: string;
  verification_status?: number | string;
  verification_message?: string;
  verification_certify_id?: string;
  login_email_alert?: number | string;
  login_notify?: number | string;
  login_location_alert?: number | string;
  password_change_alert?: number | string;
  phone_change_alert?: number | string;
  email_change_alert?: number | string;
  marketing_alert?: number | string;
  alipay_account?: ClientAlipayAccount | null;
  last_login_at?: string;
  last_login_ip?: string;
  verified_at?: string;
  created_at?: string;
  roles?: string[];
  [key: string]: unknown;
}

export interface ClientAuthSessionPayload {
  token?: string;
  user?: ClientUserInfo | null;
  [key: string]: unknown;
}

export interface ClientNotificationPreferences {
  login_notify?: number | boolean;
  login_location_alert?: number | boolean;
  password_change_alert?: number | boolean;
  phone_change_alert?: number | boolean;
  email_change_alert?: number | boolean;
  marketing_alert?: number | boolean;
  [key: string]: unknown;
}

export interface ClientVerificationPayload {
  certify_id?: string;
  status?: number | string;
  message?: string;
  msg?: string;
  url?: string;
  qrcode_url?: string;
  qr_code_url?: string;
  scan_url?: string;
  expires_at?: string;
  qrcode_expires_at?: string;
  qr_code_expires_at?: string;
  expires_in?: number | string;
  expires_in_seconds?: number | string;
  user_verification_status?: number | string;
  is_verified?: number | string;
  can_restart?: boolean | number;
  [key: string]: unknown;
}

export interface ServiceSpecItem {
  label?: string;
  value?: string | number | null;
  [key: string]: unknown;
}

export interface ServiceProduct {
  id?: number;
  display_name?: string;
  group_name?: string;
  type_label?: string;
  type?: string;
  catalog_type?: string;
  name?: string;
  [key: string]: unknown;
}

export interface ServiceInvoiceLink {
  id?: number;
  invoice_no?: string;
  order_no?: string;
  status?: number | string;
  [key: string]: unknown;
}

export interface ServiceUpstreamInfo {
  provider_key?: string;
  host_id?: number | string;
  status?: string;
  remote_error?: string;
  os?: string;
  dedicated_ip?: string;
  [key: string]: unknown;
}

export interface ConsoleMachineCategory {
  key?: string;
  label?: string;
  [key: string]: unknown;
}

export interface ConsoleRuntimeInfo {
  power_state?: string;
  power_label?: string;
  description?: string;
  [key: string]: unknown;
}

export interface ConsoleTrafficInfo {
  usage?: string | number;
  limit?: number | string | null;
  remaining?: string | number | null;
  usage_label?: string;
  limit_label?: string;
  remaining_label?: string;
  usage_percent?: number | null;
  limited?: boolean;
  button_text?: string;
  purchase_enabled?: boolean;
  [key: string]: unknown;
}

export interface ServiceTrafficPackageOption {
  option_id?: number | string;
  target_value?: number | string;
  target_label?: string;
  label?: string;
  price?: number | string;
  sort_order?: number | string;
  mode?: string;
  [key: string]: unknown;
}

export interface ServiceTrafficPackagePreview {
  supported?: boolean;
  message?: string;
  service_id?: number;
  service_name?: string;
  traffic?: ConsoleTrafficInfo | null;
  packages?: ServiceTrafficPackageOption[];
  [key: string]: unknown;
}

export interface ServiceTrafficPackageQuote {
  service_id?: number;
  service_name?: string;
  upstream_host_id?: number | string;
  mode?: string;
  traffic?: ConsoleTrafficInfo | null;
  selection?: ServiceTrafficPackageOption & {
    current_label?: string;
    target_snapshot?: number | string;
  };
  pricing?: {
    amount?: number | string;
    original_amount?: number | string;
    discount_amount?: number | string;
    billing_cycle?: string;
    [key: string]: unknown;
  };
  [key: string]: unknown;
}

export interface ServiceTrafficPackageOrderPayload {
  id?: number;
  invoice_no?: string;
  service_id?: number;
  [key: string]: unknown;
}

export interface ConsoleConnectionInfo {
  hostname?: string;
  username?: string;
  has_password?: boolean;
  password?: string;
  port?: number;
  dedicated_ip?: string;
  internal_ip?: string;
  assigned_ips?: string[];
  nat_remote_address?: string;
  nat_remote_host?: string;
  nat_remote_port?: number;
  [key: string]: unknown;
}

export interface ConsoleActionFlags {
  refresh?: boolean;
  power?: boolean;
  module_status?: boolean;
  password_reset?: boolean;
  reinstall?: boolean;
  traffic_package?: boolean;
  available?: string[];
  [key: string]: unknown;
}

export interface ConsoleServiceDetail extends ServiceInstance {
  combined_display_name?: string;
  domain?: string;
  can_manage?: boolean;
  console_template?: string;
  console_mode?: string;
  machine_category?: ConsoleMachineCategory | null;
  runtime?: ConsoleRuntimeInfo | null;
  traffic?: ConsoleTrafficInfo | null;
  connection?: ConsoleConnectionInfo | null;
  actions?: ConsoleActionFlags | null;
}

export interface ServiceInstance {
  id: number;
  status?: number | string;
  status_tone?: string;
  custom_service_name?: string;
  name?: string;
  remark?: string;
  product_spec_display?: string;
  product_display_name?: string;
  expires_at?: string;
  created_at?: string;
  updated_at?: string;
  billing_cycle?: string;
  billing_cycle_label?: string;
  auto_renew?: number | string;
  product?: ServiceProduct | null;
  upstream?: ServiceUpstreamInfo | null;
  specs?: ServiceSpecItem[];
  invoice?: ServiceInvoiceLink | null;
  [key: string]: unknown;
}

export interface ServiceCatalogTypeOption {
  label?: string;
  value?: string | number;
  count?: number;
  [key: string]: unknown;
}

export interface ServiceOverviewGroup {
  key?: string;
  id?: number | null;
  product_type?: string;
  product_type_label?: string;
  icon?: string;
  name?: string;
  title?: string;
  description?: string;
  count?: number;
  active_count?: number;
  pending_count?: number;
  expiring_count?: number;
  primary_service_id?: number;
  console_mode?: string;
  is_nat_console?: boolean;
  children?: ServiceOverviewCategoryCard[];
  items?: ServiceOverviewServiceItem[];
  [key: string]: unknown;
}

export interface ServiceOverviewCategoryCard {
  key?: string;
  id?: number | null;
  name?: string;
  title?: string;
  description?: string;
  count?: number;
  active_count?: number;
  pending_count?: number;
  expiring_count?: number;
  status_label?: string;
  status_tone?: string;
  primary_service_id?: number;
  preview_names?: string[];
  console_mode?: string;
  is_nat_console?: boolean;
  [key: string]: unknown;
}

export interface ServiceOverviewServiceItem {
  id?: number;
  name?: string;
  product_name?: string;
  group_name?: string;
  root_group_name?: string;
  status?: number | string;
  status_label?: string;
  status_tone?: string;
  billing_cycle_label?: string;
  expires_at?: string;
  amount?: number | string;
  console_mode?: string;
  is_nat_console?: boolean;
  [key: string]: unknown;
}

export interface ServiceOverviewPayload {
  total: number;
  category_total: number;
  list: ServiceOverviewGroup[];
  catalog_types: ServiceCatalogTypeOption[];
  [key: string]: unknown;
}

export interface RenewCycleOption {
  billing_cycle?: string;
  billing_cycle_label?: string;
  amount?: number | string;
  original_amount?: number | string;
  agent_discount_rate?: number | string;
  agent_group_name?: string;
  [key: string]: unknown;
}

export interface CouponOption {
  id: number;
  name?: string;
  discount_label?: string;
  [key: string]: unknown;
}

export interface CouponProductScopeItem {
  id: number;
  name?: string;
  product_name?: string;
  service_type_code?: string;
  service_type_label?: string;
  type_label?: string;
  first_product_group_id?: number | null;
  first_product_group_name?: string;
  second_product_group_id?: number | null;
  second_product_group_name?: string;
  parent_group_name?: string;
  third_product_group_id?: number | null;
  third_product_group_name?: string;
  group_name?: string;
  effective_product_group_id?: number | null;
  effective_product_group_level?: number | null;
  [key: string]: unknown;
}

export interface CouponRecord {
  id: number;
  uid?: string;
  coupon_id?: number;
  name?: string;
  description?: string;
  status?: 'available' | 'used_up' | 'expired' | string;
  status_label?: string;
  status_reason?: string;
  discount_type?: 'fixed' | 'percentage' | string;
  discount_type_label?: string;
  discount_value?: number | string;
  discount_label?: string;
  discount_amount?: number | string;
  max_discount_amount?: number | string | null;
  min_amount?: number | string;
  can_claim?: boolean;
  product_scope_text?: string;
  validity_text?: string;
  expires_at?: string;
  starts_at?: string;
  receive_type?: string;
  receive_type_label?: string;
  per_user_limit?: number | null;
  used_times?: number;
  remaining_times?: number | null;
  first_order_only?: boolean;
  total_usage_limit?: number | null;
  remaining_stock?: number | null;
  used_at?: string | null;
  revoked_at?: string | null;
  billing_cycle_text?: string;
  products?: CouponProductScopeItem[];
  [key: string]: unknown;
}

export interface CouponSummary {
  total?: number;
  available?: number;
  used_up?: number;
  expired?: number;
  [key: string]: unknown;
}

export interface ReferralUserBrief {
  id?: number;
  email?: string;
  nickname?: string;
  display_name?: string;
  created_at?: string;
  referred_at?: string;
  [key: string]: unknown;
}

export interface ReferralMemberLevel {
  id?: number;
  name?: string;
  code?: string;
  reward_rate?: number | string;
  sales_amount_min?: number | string;
  sales_amount_max?: number | string | null;
  distance_amount?: number | string;
  [key: string]: unknown;
}

export interface ReferralOverviewPayload {
  referral_code?: string;
  register_path?: string;
  referral_link?: string;
  available_coupons?: number;
  reward_rate?: number | string;
  reward_freeze_days?: number | string;
  withdraw_min_amount?: number | string;
  total_sales_amount?: number | string;
  referral_frozen_amount?: number | string;
  referral_available_amount?: number | string;
  referral_withdrawing_amount?: number | string;
  referral_withdrawn_amount?: number | string;
  direct_referral_count?: number;
  rewarded_orders_count?: number;
  total_reward_amount?: number | string;
  current_member_level?: ReferralMemberLevel | null;
  next_member_level?: ReferralMemberLevel | null;
  member_levels?: ReferralMemberLevel[];
  recent_referrals?: ReferralUserBrief[];
  [key: string]: unknown;
}

export interface ReferralRewardRecord {
  id: number;
  reward_amount?: number | string;
  reward_rate?: number | string;
  order_amount?: number | string;
  order_type?: string;
  status?: number | string;
  rewarded_at?: string;
  available_at?: string;
  released_at?: string;
  remark?: string;
  referred_user?: ReferralUserBrief | null;
  invoice?: {
    id?: number;
    invoice_no?: string;
    product_display_name?: string;
    [key: string]: unknown;
  } | null;
  product?: {
    id?: number;
    name?: string;
    display_name?: string;
    [key: string]: unknown;
  } | null;
  [key: string]: unknown;
}

export interface ReferralAccountLogRecord {
  id: number;
  event_type?: string;
  type?: string;
  change_amount?: number | string;
  amount?: number | string;
  frozen_balance?: number | string;
  frozen_amount?: number | string;
  available_balance?: number | string;
  available_amount?: number | string;
  pending_withdrawal_balance?: number | string;
  withdrawing_amount?: number | string;
  withdrawn_balance?: number | string;
  withdrawn_amount?: number | string;
  remark?: string;
  operator?: string;
  created_at?: string;
  user?: ReferralUserBrief | null;
  [key: string]: unknown;
}

export interface ReferralWithdrawalRecord {
  id: number;
  amount?: number | string;
  method?: 'balance' | 'alipay' | string;
  account_name?: string;
  account_no?: string;
  status?: number | string;
  remark?: string;
  created_at?: string;
  processed_at?: string;
  [key: string]: unknown;
}

export interface ReferralWithdrawalApplyResult {
  id?: number;
  amount?: number | string;
  status?: number | string;
  created_at?: string;
  [key: string]: unknown;
}

export interface ServiceRenewPreview {
  expires_at?: string;
  auto_renew?: number | string;
  billing_cycle?: string;
  renew_price?: number | string;
  default_cycle?: string;
  selected_user_coupon_id?: number | string;
  cycles?: RenewCycleOption[];
  available_coupons?: CouponOption[];
  [key: string]: unknown;
}

export interface RechargeOrderPayload {
  payment_no?: string;
  qr_code?: string;
  amount?: number | string;
  gateway?: string;
  gateway_key?: string;
  gateway_label?: string;
  payment_type?: string;
  payment_type_label?: string;
  poll_token?: string;
  poll_expires_at?: string;
  [key: string]: unknown;
}

export interface RechargeGatewayOption {
  key: string;
  option_key?: string;
  name?: string;
  label?: string;
  payment_type?: string;
}

export interface RechargeGatewayOptionsPayload {
  list?: RechargeGatewayOption[];
  [key: string]: unknown;
}

export interface RechargeStatusPayload {
  paid?: boolean;
  trade_no?: string;
  trade_status?: string;
  cash_balance?: number | string;
  message?: string;
  [key: string]: unknown;
}

export interface ServiceNameUpdatePayload {
  name?: string;
  custom_service_name?: string;
  [key: string]: unknown;
}

export interface ServiceRemarkUpdatePayload extends ServiceInstance {}

export interface ClientActionDetailPayload {
  action?: string;
  action_label?: string;
  message?: string;
  second_verify_required?: boolean;
  status?: SummaryRecord | null;
  [key: string]: unknown;
}

export interface ClientActionResultPayload {
  id?: number | string;
  status?: string;
  message?: string;
  detail?: ClientActionDetailPayload | null;
  [key: string]: unknown;
}

export interface ServicePowerActionPayload extends ClientActionResultPayload {}

export interface ServicePasswordResetPayload extends ClientActionResultPayload {}

export interface ServiceVncCredentials {
  username?: string;
  target?: string;
  password?: string;
  [key: string]: unknown;
}

export interface ServiceVncPayload {
  url?: string;
  vnc_credentials?: ServiceVncCredentials | null;
  [key: string]: unknown;
}

export interface ConsoleSelectOption {
  value?: string | number;
  label?: string;
  port?: string | number;
  [key: string]: unknown;
}

export interface ServiceReinstallOption {
  os_id?: string;
  name?: string;
  group_name?: string;
  [key: string]: unknown;
}

export interface ServiceReinstallGroup {
  group_name?: string;
  img?: string;
  [key: string]: unknown;
}

export interface ServiceReinstallOptionsPayload {
  os?: ServiceReinstallOption[];
  os_groups?: ServiceReinstallGroup[];
  [key: string]: unknown;
}

export interface MonitorRangePayload {
  preset?: string;
  start?: number;
  end?: number;
  [key: string]: unknown;
}

export interface MonitorSummaryItem {
  text?: string;
  time?: string;
  value?: number | string;
  [key: string]: unknown;
}

export interface MonitorSummaryPayload {
  latest?: MonitorSummaryItem | null;
  average?: MonitorSummaryItem | null;
  peak?: MonitorSummaryItem | null;
  lowest?: MonitorSummaryItem | null;
  [key: string]: unknown;
}

export interface MonitorChartPoint {
  time?: string;
  timestamp?: number;
  value?: number | string;
  display_value?: string;
  text?: string;
  [key: string]: unknown;
}

export interface MonitorChartSeries {
  key?: string;
  name?: string;
  list?: MonitorChartPoint[];
  [key: string]: unknown;
}

export interface MonitorChartData {
  type?: string;
  chart_type?: string;
  unit?: string;
  y_max?: number | null;
  list?: MonitorChartPoint[];
  series?: MonitorChartSeries[];
  [key: string]: unknown;
}

export interface MonitorChartRecord {
  type?: string;
  label?: string;
  message?: string;
  error?: string;
  chart?: MonitorChartData | null;
  summary?: MonitorSummaryPayload | null;
  [key: string]: unknown;
}

export interface MonitorBatchPayload {
  supported?: boolean;
  message?: string;
  error?: string;
  options?: ConsoleSelectOption[];
  range?: MonitorRangePayload | null;
  charts?: MonitorChartRecord[];
  [key: string]: unknown;
}

export interface NatForwardingRecord {
  id?: number;
  name?: string;
  external_address?: string;
  external_host?: string;
  external_port?: string;
  internal_port?: string;
  protocol?: string;
  protocol_label?: string;
  can_delete?: boolean;
  is_default?: boolean;
  [key: string]: unknown;
}

export interface NatForwardingPayload {
  supported?: boolean;
  message?: string;
  error?: string;
  module_key?: string;
  module_name?: string;
  endpoint?: string;
  can_create?: boolean;
  protocols?: ConsoleSelectOption[];
  list?: NatForwardingRecord[];
  summary?: {
    total?: number;
    [key: string]: unknown;
  } | null;
  [key: string]: unknown;
}

export interface ServiceOperationDetailItem {
  label?: string;
  value?: string;
  [key: string]: unknown;
}

export interface ServiceOperationLogSummary {
  total?: number;
  today_total?: number;
  latest_created_at?: string;
  service_name?: string;
  [key: string]: unknown;
}

export interface ServiceOperationLogRecord {
  id: number;
  created_at?: string;
  action?: string;
  action_label?: string;
  category?: string;
  category_label?: string;
  summary?: string;
  actor_type?: string;
  actor_label?: string;
  actor_name?: string;
  ip_address?: string;
  detail_items?: ServiceOperationDetailItem[];
  [key: string]: unknown;
}

export interface ServiceOperationLogPayload extends PagedList<ServiceOperationLogRecord> {
  summary?: ServiceOperationLogSummary;
}

export interface SecurityGroupRecord {
  id?: number;
  name?: string;
  description?: string;
  can_view?: boolean;
  can_add_rule?: boolean;
  can_apply?: boolean;
  can_delete?: boolean;
  apply_disabled?: boolean;
  delete_disabled?: boolean;
  apply_text?: string;
  delete_text?: string;
  view_text?: string;
  add_rule_text?: string;
  is_applied?: boolean;
  [key: string]: unknown;
}

export interface SecurityRuleRecord {
  id?: number;
  description?: string;
  direction?: string;
  direction_label?: string;
  protocol?: string;
  port?: string;
  ip?: string;
  action?: string;
  action_label?: string;
  priority?: number | null;
  lock?: number;
  create_time?: string;
  host_type?: string;
  raw?: Record<string, unknown>;
  [key: string]: unknown;
}

export interface SecurityGroupPayload {
  supported?: boolean;
  can_create?: boolean;
  message?: string;
  error?: string;
  module_key?: string;
  module_name?: string;
  host_type?: string;
  directions?: ConsoleSelectOption[];
  protocols?: ConsoleSelectOption[];
  groups?: SecurityGroupRecord[];
  [key: string]: unknown;
}

export interface SecurityRulePayload {
  group_id?: number;
  host_type?: string;
  list?: SecurityRuleRecord[];
  [key: string]: unknown;
}

export interface ServiceReinstallPayload extends ClientActionResultPayload {}

export interface FinanceLedgerDisplayMeta {
  badge_type?: string;
  business_scene_label?: string;
  [key: string]: unknown;
}

export interface FinanceLedgerInvoice {
  id?: number;
  invoice_no?: string;
  type?: string;
  type_label?: string;
  business_scene?: string;
  business_scene_label?: string;
  status?: number | string;
  status_label?: string;
  amount?: number | string;
  paid_amount?: number | string;
  [key: string]: unknown;
}

export interface FinanceLedgerPayment {
  id?: number;
  payment_no?: string;
  gateway?: string;
  gateway_key?: string;
  gateway_label?: string;
  status?: number | string;
  status_label?: string;
  trade_no?: string;
  amount?: number | string;
  paid_at?: string;
  [key: string]: unknown;
}

export interface FinanceLedgerUser {
  id?: number;
  email?: string;
  nickname?: string;
  display_name?: string;
  [key: string]: unknown;
}

export interface FinanceLedgerRecord {
  id: number;
  ledger_id?: number;
  account_type?: string;
  event_type?: string;
  event_type_label?: string;
  event_category?: string;
  direction?: string;
  amount?: number | string;
  change_amount?: number | string;
  balance_after?: number | string;
  occurred_at?: string;
  created_at?: string;
  remark?: string;
  source_type?: string;
  source_id?: number | null;
  origin_type?: string;
  origin_id?: number | null;
  operator?: string;
  business_scene?: string;
  business_scene_label?: string;
  invoice?: FinanceLedgerInvoice | null;
  payment?: FinanceLedgerPayment | null;
  user?: FinanceLedgerUser | null;
  display?: FinanceLedgerDisplayMeta | null;
  [key: string]: unknown;
}

export interface ContentCategoryRecord {
  id: number;
  name?: string;
  slug?: string;
  description?: string;
  status?: number | string;
  sort_order?: number;
  articles_count?: number;
  [key: string]: unknown;
}

export interface ContentArticleRecord {
  id: number;
  content_type?: string;
  type?: string;
  category_id?: number;
  content_category_id?: number;
  title?: string;
  slug?: string;
  summary?: string;
  excerpt?: string;
  content?: string;
  category_name?: string;
  category?: string;
  category_slug?: string;
  keywords?: string | string[];
  status?: number | string;
  is_pinned?: number | string;
  is_recommended?: number | string;
  cover_image?: string;
  sort_order?: number;
  view_count?: number;
  publish_at?: string;
  last_published_at?: string;
  operator?: string;
  created_at?: string;
  updated_at?: string;
  creator?: {
    id?: number;
    username?: string;
    nickname?: string;
    [key: string]: unknown;
  } | null;
  category_detail?: ContentCategoryRecord | null;
  [key: string]: unknown;
}

export interface ContentOverviewPayload {
  notices: ContentArticleRecord[];
  help_articles: ContentArticleRecord[];
  notice_categories: ContentCategoryRecord[];
  help_categories: ContentCategoryRecord[];
  [key: string]: unknown;
}

export interface ContentListPayload extends PagedList<ContentArticleRecord> {
  categories?: ContentCategoryRecord[];
}

export interface ContentDetailPayload extends ContentArticleRecord {}

export interface ContentUnreadCountPayload {
  count?: number;
  [key: string]: unknown;
}

export interface FinanceLedgerSummary {
  cash_balance?: number | string;
  total_out?: number | string;
  total_count?: number;
  total_in?: number | string;
  recharge_in?: number | string;
  invoice_payment_out?: number | string;
  refund_in?: number | string;
  manual_adjust_out?: number | string;
  unpaid_count?: number;
  unpaid_amount?: number | string;
  total_invoices?: number;
  recent_30d_recharge?: number | string;
  recent_30d_refund?: number | string;
  [key: string]: unknown;
}

export interface InvoiceProduct {
  id?: number;
  display_name?: string;
  group_name?: string;
  type_label?: string;
  [key: string]: unknown;
}

export interface InvoiceSummaryInfo {
  headline?: string;
  subheadline?: string;
  remark?: string;
  [key: string]: unknown;
}

export interface InvoicePaymentMethod {
  key?: 'balance' | 'alipay' | 'free' | string;
  option_key?: string;
  name?: string;
  label?: string;
  payment_type?: string;
  [key: string]: unknown;
}

export interface InvoicePaymentSecurity {
  can_pay?: boolean;
  session_token?: string;
  expires_at?: string;
  [key: string]: unknown;
}

export interface PaymentSummary {
  gateway?: string;
  gateway_key?: string;
  gateway_label?: string;
  status?: number | string;
  payment_no?: string;
  trade_no?: string;
  amount?: number | string;
  [key: string]: unknown;
}

export interface InvoiceRecord {
  id: number;
  invoice_no?: string;
  status?: number | string;
  type?: string;
  type_label?: string;
  amount?: number | string;
  paid_amount?: number | string;
  payable_amount?: number | string;
  discount?: number | string;
  due_date?: string;
  created_at?: string;
  updated_at?: string;
  paid_at?: string;
  billing_cycle?: string;
  combined_display_name?: string;
  product_display_name?: string;
  product_spec_display?: string;
  payment_no?: string;
  coupon_code?: string;
  payment_summary?: PaymentSummary | null;
  product?: InvoiceProduct | null;
  service?: ServiceInstance | null;
  service_id?: number;
  product_id?: number;
  order?: InvoiceOrderInfo | null;
  payments?: PaymentRecord[];
  pay_methods?: InvoicePaymentMethod[];
  payment_security?: InvoicePaymentSecurity | null;
  summary?: InvoiceSummaryInfo | null;
  config_snapshot?: Record<string, unknown> | null;
  config_pricing_snapshot?: Record<string, unknown> | null;
  [key: string]: unknown;
}

export interface InvoiceOrderInfo {
  id?: number;
  order_no?: string;
  type?: string;
  type_label?: string;
  status?: number | string;
  [key: string]: unknown;
}

export interface InvoiceListSummary {
  total_count?: number;
  unpaid_count?: number;
  unpaid_amount?: number | string;
  paid_count?: number;
  paid_amount?: number | string;
  paid_total?: number | string;
  total_amount?: number | string;
  [key: string]: unknown;
}

export interface InvoiceBalancePaymentResult {
  invoice?: InvoiceRecord | null;
  [key: string]: unknown;
}

export interface InvoiceCreatePayload {
  id?: number;
  invoice?: InvoiceRecord | null;
  [key: string]: unknown;
}

export interface InvoiceAlipayPaymentPayload {
  qr_code?: string;
  payment_no?: string;
  poll_token?: string;
  balance_amount?: number | string;
  amount?: number | string;
  gateway?: string;
  gateway_key?: string;
  gateway_label?: string;
  payment_type?: string;
  payment_type_label?: string;
  invoice?: InvoiceRecord | null;
  [key: string]: unknown;
}

export interface InvoiceAlipayStatusPayload {
  paid?: boolean;
  message?: string;
  invoice?: InvoiceRecord | null;
  [key: string]: unknown;
}

export interface PaymentRecord {
  id: number;
  payment_no?: string;
  trade_no?: string;
  gateway?: string;
  gateway_key?: string;
  gateway_label?: string;
  amount?: number | string;
  status?: number | string;
  status_label?: string;
  paid_at?: string;
  created_at?: string;
  invoice_id?: number;
  invoice_no?: string;
  invoice_type?: string;
  invoice_status?: number | string;
  invoice?: PaymentInvoiceInfo | null;
  [key: string]: unknown;
}

export interface PaymentInvoiceInfo {
  id?: number;
  invoice_no?: string;
  status?: number | string;
  amount?: number | string;
  paid_amount?: number | string;
  type?: string;
  [key: string]: unknown;
}

export interface OrderRecord {
  id: number;
  order_no?: string;
  type?: string;
  type_label?: string;
  status?: number | string;
  status_label?: string;
  amount?: number | string;
  paid_amount?: number | string;
  discount?: number | string;
  billing_cycle?: string;
  quantity?: number;
  product_name?: string;
  product_full_path?: string;
  product_path_segments?: string[];
  service_name?: string;
  coupon_code?: string;
  remark?: string;
  paid_at?: string;
  created_at?: string;
  invoice?: OrderInvoiceInfo | null;
  service?: OrderServiceInfo | null;
  coupon?: OrderCouponInfo | null;
  config_snapshot?: Record<string, unknown> | null;
  config_pricing_snapshot?: Record<string, unknown> | null;
  [key: string]: unknown;
}

export interface OrderInvoiceInfo {
  id?: number;
  invoice_no?: string;
  type?: string;
  status?: number | string;
  amount?: number | string;
  paid_amount?: number | string;
  paid_at?: string;
  due_date?: string;
  created_at?: string;
  payments?: OrderPaymentInfo[];
  [key: string]: unknown;
}

export interface OrderServiceInfo {
  id?: number;
  name?: string;
  domain?: string;
  status?: number | string;
  expires_at?: string;
  [key: string]: unknown;
}

export interface OrderCouponInfo {
  id?: number;
  code?: string;
  name?: string;
  type?: string;
  value?: string;
  [key: string]: unknown;
}

export interface OrderPaymentInfo {
  id?: number;
  payment_no?: string;
  gateway?: string;
  trade_no?: string;
  amount?: number | string;
  status?: number | string;
  paid_at?: string;
  [key: string]: unknown;
}

export interface OrderListSummary {
  total?: number;
  pending?: number;
  paid?: number;
  processing?: number;
  completed?: number;
  cancelled?: number;
  refunded?: number;
  unpaid_amount?: number | string;
  month_amount?: number | string;
  [key: string]: unknown;
}

export interface BalanceLog {
  id: number;
  event_type?: string | number;
  change_amount?: number | string;
  balance_after?: number | string;
  remark?: string;
  created_at?: string;
  invoice_id?: number;
  [key: string]: unknown;
}

export interface TicketUser {
  id?: number;
  display_name?: string;
  nickname?: string;
  email?: string;
  username?: string;
  [key: string]: unknown;
}

export interface TicketServiceOption {
  id: number;
  status?: number | string;
  name?: string;
  display_name?: string;
  product_name?: string;
  [key: string]: unknown;
}

export interface TicketAttachment {
  id?: number | string;
  uid?: number | string;
  url?: string;
  path?: string;
  [key: string]: unknown;
}

export interface TicketReplyRecord {
  id: number;
  user_id?: number;
  sender_name?: string;
  content?: string;
  created_at?: string;
  recalled?: boolean;
  is_staff?: boolean;
  quote?: {
    sender_name?: string;
    recalled?: boolean;
    content?: string;
    [key: string]: unknown;
  } | null;
  attachments?: Array<TicketAttachment | string>;
  attachment_urls?: string[];
  [key: string]: unknown;
}

export interface TicketRecord {
  id: number;
  subject?: string;
  status?: number | string;
  priority?: number | string;
  department?: string;
  content?: string;
  close_reason?: string | null;
  close_reason_label?: string | null;
  created_at?: string;
  updated_at?: string;
  user_id?: number;
  service_id?: number;
  user?: TicketUser | null;
  assignee?: TicketUser | null;
  service?: TicketServiceOption | null;
  replies?: TicketReplyRecord[];
  attachments?: Array<TicketAttachment | string>;
  attachment_urls?: string[];
  [key: string]: unknown;
}

export interface TicketImageUploadPayload {
  id?: number | string;
  path?: string;
  url?: string;
  [key: string]: unknown;
}
