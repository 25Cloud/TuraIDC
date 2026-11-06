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

/** 账户策略的缺失项；枚举码由后端 App\Services\Auth\AccountPolicyService::ITEM_* 定义 */
export type ClientAccountPolicyItem = 'verification' | 'phone' | 'email';

/**
 * 账户策略（`GET /api/v2/client/auth/info` 的 `data.account_policy`）。
 *
 * 唯一判据在后端（`App\Services\Auth\AccountPolicyService`）：前端不自己判断「要不要实名」，
 * 只按 `missing_items` 决定是否提示、给哪些补齐动作；`message` 是后端拼好的整句，前端直接展示。
 * 后端未下发该字段（老版本或策略未收紧前）时整块不渲染。
 */
export interface ClientAccountPolicy {
  require_verification?: boolean;
  require_phone?: boolean;
  require_email?: boolean;
  missing_items?: Array<ClientAccountPolicyItem | string>;
  message?: string;
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
  qq?: string;
  avatar?: string;
  roles?: string[];
  /**
   * 可选功能可用性（后端 App\Support\OptionalFeature::map() 下发）。
   * 键名是插件 slug，例如 `ai_assistant`、`auto_refund`；缺键一律按不可用处理。
   */
  features?: Record<string, boolean>;
  /** 账户策略（实名 / 手机 / 邮箱是否强制）；后端未下发时为 undefined */
  account_policy?: ClientAccountPolicy | null;
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

/**
 * 面板访问信息（CDN / 虚拟主机等面板型产品）
 *
 * 各家上游摆放面板信息的位置差异很大（配置项键值对、host_data 字段、自定义区域 HTML），
 * 后端统一归一到这五个键；三个字段全空时后端直接不下发该结构。
 */
export interface ConsolePanelAccess {
  /** 面板地址（可能带一次性 token，用完即失效） */
  panel_url?: string;
  panel_username?: string;
  panel_password?: string;
  /** 面板类型标识，如 cpanel / btpanel / 厂商自有控制台 */
  panel_type?: string;
  /** 面板信息来源，便于排查「为什么抽不到」 */
  panel_source?: string;
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
  rescue?: boolean;
  traffic_package?: boolean;
  available?: string[];
  [key: string]: unknown;
}

export interface ConsoleSyncMarker {
  changed?: boolean;
  changed_at?: string;
}

export interface ConsoleAreaDescriptor {
  key: string;
  name: string;
  [key: string]: unknown;
}

export interface ServiceConsoleCapabilities {
  supported?: boolean;
  error?: string;
  areas?: ConsoleAreaDescriptor[];
  nat_supported?: boolean;
  monitor_supported?: boolean;
  fetchable?: boolean;
  /**
   * 是否首屏全量预热面板区域（魔方兼容 tab）。缺省按开启处理。
   *
   * 后台开关 system.console_area_preload_all_enabled 控制：开启时进入控制台就把所有
   * 自定义区域的 iframe 拉起来，切换 tab 零等待；关闭时只预热第一个，其余按需加载，
   * 用来规避多个 iframe 同时回源上游对首屏带宽的占用。
   */
  area_preload_all?: boolean;
  [key: string]: unknown;
}

export interface ServiceConsoleAreaTicket {
  ticket?: string;
  expires_in?: number;
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
  /** 面板访问信息（CDN / 虚拟主机等面板型产品）。后端抽不到时整体不下发 */
  panel?: ConsolePanelAccess | null;
  actions?: ConsoleActionFlags | null;
  _sync?: ConsoleSyncMarker | null;
}

export interface ServiceInstance {
  id: number;
  status?: number | string;
  status_tone?: string;
  /** 暂停原因（流量超额 / 线路清退 / 服务到期等），仅暂停状态有值 */
  suspended_reason?: string;
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

/** 一级分组图标的解析结果（后端 App\Support\ProductGroupIcon 统一计算） */
export interface ServiceGroupIconResolved {
  /** image=历史图片模式（已下线，仅兼容旧缓存）；text=文字图标；icon=内置 TDesign 图标名；abbr=名称缩写 */
  mode?: 'image' | 'text' | 'icon' | 'abbr' | string;
  /** 图片图标地址（仅历史兼容字段，前端不再渲染） */
  image?: string | null;
  /** 文字图标 */
  text?: string | null;
  /** 内置 TDesign 图标名 */
  icon_name?: string | null;
  /** 一级分组名缩写（最多 4 个半角宽度） */
  abbr?: string | null;
}

export interface ServiceCatalogTypeOption {
  label?: string;
  value?: string | number;
  count?: number;
  /** 内置图标名（既有 icon 列，TDesign 图标名） */
  icon?: string | null;
  /** 文字图标（如「轻量」「游戏」） */
  icon_text?: string | null;
  /** 图片图标地址（后端已解析为可访问绝对地址） */
  icon_image?: string | null;
  /** 解析后的图标（内置图标 > 文字图标 > 名称缩写；图片图标已下线，mode=image 仅历史缓存） */
  icon_resolved?: ServiceGroupIconResolved | null;
  [key: string]: unknown;
}

export interface ServiceOverviewGroup {
  key?: string;
  id?: number | null;
  group_level?: number;
  group_level_label?: string;
  first_product_group_code?: string;
  first_product_group_name?: string;
  product_type?: string;
  product_type_label?: string;
  icon?: string;
  /** 一级分组图标：文字 / 图片 / 解析结果（与 catalog_types 同源） */
  icon_text?: string | null;
  icon_image?: string | null;
  icon_resolved?: ServiceGroupIconResolved | null;
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

export interface ServiceOverviewGroupPathNode {
  level?: number;
  level_label?: string;
  id?: number;
  code?: string;
  name?: string;
  [key: string]: unknown;
}

export interface ServiceOverviewCategoryCard {
  key?: string;
  id?: number | null;
  group_level?: number;
  group_level_label?: string;
  group_path?: ServiceOverviewGroupPathNode[];
  group_path_text?: string;
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
  group_path?: ServiceOverviewGroupPathNode[];
  group_path_text?: string;
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
  coupon_code?: string;
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
  launch_mode?: 'external';
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
  items?: InvoiceItemInfo[];
  coupon?: OrderCouponInfo | null;
  [key: string]: unknown;
}

export interface InvoiceItemInfo {
  id?: number;
  description?: string;
  quantity?: number | string;
  unit_price?: number | string;
  discount_amount?: number | string;
  amount?: number | string;
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

export interface ApiKeyRecord {
  id: number;
  name?: string;
  key_prefix?: string;
  secret_last4?: string;
  scopes?: Record<string, 'read' | 'write'>;
  expires_at?: string | null;
  ip_allowlist?: string[];
  status?: 'enabled' | 'disabled';
  last_used_at?: string | null;
  created_at?: string;
  [key: string]: unknown;
}

export interface ApiKeyStorePayload {
  key?: ApiKeyRecord | null;
  secret?: string;
  secret_warning?: string;
  [key: string]: unknown;
}

export interface ApiKeyUsageLogRecord {
  method?: string;
  path?: string;
  status_code?: number;
  ip?: string;
  duration_ms?: number;
  created_at?: string;
  [key: string]: unknown;
}

/** 魔方财务上游 API 凭据状态（与开放接口密钥是两条独立鉴权链路） */
export interface UpstreamApiStatus {
  enabled: boolean;
  username: string;
  has_password: boolean;
  login_url: string;
  /** IP 白名单，空数组表示不限制 */
  ip_allowlist?: string[];
  /** 到期时间，null 表示永不过期 */
  expires_at?: string | null;
  is_expired?: boolean;
  /** 最近一次成功换取 JWT 的时间 */
  last_used_at?: string | null;
  [key: string]: unknown;
}

/** 开启/重置后返回的凭据：明文密码仅返回一次 */
export interface UpstreamApiCredential {
  username: string;
  password: string;
  login_url: string;
  [key: string]: unknown;
}

/** 魔方链路安全策略（IP 白名单 / 有效期），字段缺失表示保留原值 */
export interface UpstreamApiPolicyPayload {
  ip_allowlist?: string[];
  expires_at?: string | null;
  [key: string]: unknown;
}

/**
 * 自助退款（插件 addons/auto_refund）在控制台侧的契约。
 * 唯一真源：docs/internal/execution-plans/active/auto-refund-plugin-2026-11-01.md
 *
 * 计算口径：真正退多少钱由上游按其退款规则算，本地只给预估金额，
 * 因此 `estimated_amount` 一律标注「以上游核算为准」。
 */

/**
 * 退款策略的**默认值 + 说明**。
 *
 * 第二阶段起真正生效的是每个商品自己的策略（`/candidates` 每项自带
 * `refund_type` / `refund_rule` / `requirement`），这里只保留一段通用说明与站点级的
 * 最低可退金额；`mode` / `days` 这类字段即使后端仍下发，页面也不当全局规则用。
 */
export interface AutoRefundPolicyPayload {
  mode?: string;
  mode_label?: string;
  days?: number | string;
  window_days?: number | string;
  min_amount?: number | string;
  require_first_only?: boolean | number | string;
  text?: string;
  [key: string]: unknown;
}

/** `GET /v2/client/auto-refund/bootstrap`：开关与规则文案，不请求上游 */
export interface AutoRefundBootstrapPayload {
  enabled?: boolean | number;
  configured?: boolean | number;
  /** 插件启用 + 上游已配置 + 台账表已就绪，三者同时成立才可自助申请 */
  open?: boolean | number;
  table_ready?: boolean | number;
  /** 站长在插件配置里写的控制台说明 */
  console_notice?: string;
  notice?: string;
  request_timeout?: number;
  policy?: AutoRefundPolicyPayload | string | null;
  policy_text?: string;
  [key: string]: unknown;
}

/** 退款申请（台账行），状态见执行计划的状态机 */
export interface AutoRefundApplication {
  id: number;
  application_no?: string;
  service_id?: number;
  service_name?: string;
  product_name?: string;
  order_id?: number;
  invoice_id?: number;
  reason?: string;
  /** 提交时的本地预估，不是最终退款金额 */
  estimated_amount?: string | number;
  /** 实际结算金额，未结算时为 null */
  settled_amount?: string | number | null;
  /** pending_review / cancelled / settled / rejected / failed / uncertain / closed */
  status?: string;
  status_label?: string;
  /** 提交时的商品退款类型快照：manual / auto / api_ticket / bridge，决定提交成功后的话术与列表展示 */
  refund_type?: string;
  refund_type_label?: string;
  /** 实际走的通道，只有三个值：bridge 上游接口 / api_ticket 上游工单 / local 仅本站 */
  channel?: string;
  channel_label?: string;
  /** api_ticket 通道落在本站的工单 id，可跳到工单详情 */
  ticket_id?: number | string | null;
  /** 上游工单号；zjfm 协议拿不到，允许为空 */
  upstream_ticket_id?: string | number | null;
  /** 提交时的商品策略快照，用于事后解释「当时为什么能退」 */
  policy_snapshot?: Record<string, unknown> | string | null;
  /**
   * 后端判定「本申请是否允许用户自行取消」：只有走上游接口、上游存在可撤销记录的才算。
   * 缺省时前端回落到「状态为待审核」这一条，避免旧快照直接崩。
   */
  cancellable?: boolean;
  upstream_code?: number | null;
  upstream_msg?: string | null;
  trace_id?: string | null;
  submitted_at?: string;
  cancelled_at?: string | null;
  settled_at?: string | null;
  created_at?: string;
  [key: string]: unknown;
}

/** `GET /v2/client/auto-refund/candidates` 的单项 */
export interface AutoRefundCandidate {
  service_id: number;
  service_name?: string;
  product_name?: string;
  domain?: string;
  dedicatedip?: string;
  assignedips?: string;
  regdate?: string;
  nextduedate?: string;
  estimated_amount?: string | number;
  estimate_hint?: string;
  refundable?: boolean;
  /** 该商品的退款类型：manual / auto / api_ticket / bridge */
  refund_type?: string;
  refund_type_label?: string;
  /** 该商品的退款规则：duration / month / full */
  refund_rule?: string;
  refund_rule_label?: string;
  /** 该商品的退款要求：product_first / category_first / within_hours */
  requirement?: string;
  requirement_label?: string;
  /** 仅 requirement=within_hours 生效的小时窗口 */
  window_hours?: number | string | null;
  /** false = 该商品没配退款策略，整行不可申请（blocked_reasons 会带 rule_missing） */
  policy_configured?: boolean | number;
  /**
   * 枚举码，前端本地化：upstream_missing / no_paid_invoice / window_expired / first_only_used /
   * pending_application / amount_too_low / upstream_unavailable /
   * rule_missing / renewed / upgraded / free_order / service_status / expired / unpaid
   */
  blocked_reasons?: string[];
  /** 已有申请时回填，前端据此显示当前状态而不是「申请退款」 */
  application?: AutoRefundApplication | null;
  [key: string]: unknown;
}

/** `/candidates` 上游不可用时不报错，改为带 `upstream_error` 返回本地候选 */
export interface AutoRefundCandidatesPayload {
  list?: AutoRefundCandidate[];
  upstream_error?: string | null;
  [key: string]: unknown;
}
