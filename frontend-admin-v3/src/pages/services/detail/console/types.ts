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

export interface SummaryRecord {
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
  console_template?: string;
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
  [key: string]: unknown;
}

export interface ServiceConsoleAreaTicket {
  ticket?: string;
  expires_in?: number;
  [key: string]: unknown;
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
  amount?: number | string;
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
  _sync?: ConsoleSyncMarker | null;
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

export interface ServiceRenewOrderPayload {
  id?: number;
  message?: string;
  invoice_no?: string;
  detail?: Record<string, unknown>;
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
