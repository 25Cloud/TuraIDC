import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, shallowRef } from 'vue';
import { useRouter } from 'vue-router';

import clientApi from '@/api/client';
import { t } from '@/locales';
import type { AutoRefundApplication, AutoRefundBootstrapPayload, AutoRefundCandidate } from '@/types/client';
import { formatMoney } from '@/utils/format';

/**
 * 自助退款中心（插件 addons/auto_refund）。
 *
 * 契约唯一真源：docs/internal/execution-plans/active/auto-refund-plugin-2026-11-01.md
 * 三条不能违背的口径：
 * 1. 金额只做预估，真正退多少由上游核算，页面必须写清「以上游核算为准」。
 * 2. 上游没有进度查询接口，状态一律以本地台账为准，不猜「已到账」。
 * 3. 网络超时属于「结果未知」，不能当失败让用户重试，否则会造出第二笔上游申请。
 */

/** 与后端 `auto_refund_applications.reason` 的 varchar(500) 保持一致 */
export const AUTO_REFUND_REASON_MAX_LENGTH = 500;

type TagTheme = 'primary' | 'success' | 'warning' | 'danger' | 'default';

/** 状态 → 标签主题，见执行计划的状态机 */
const STATUS_THEME_MAP: Record<string, TagTheme> = {
  pending_review: 'primary',
  settled: 'success',
  uncertain: 'warning',
  rejected: 'danger',
  failed: 'danger',
  cancelled: 'default',
  closed: 'default',
};

function normalizeText(value: unknown): string {
  if (value === null || value === undefined) return '';
  return String(value).trim();
}

/** 后端 bool 落到 JSON 可能仍是 1/0，页面只认「明确为真」 */
function isTruthyFlag(value: unknown): boolean {
  return value === true || value === 1 || value === '1';
}

function resolveFirstNumber(...values: unknown[]): number | null {
  for (const value of values) {
    if (value === null || value === undefined || value === '') continue;
    const num = Number(value);
    if (Number.isFinite(num)) return num;
  }
  return null;
}

/**
 * 枚举码 → 本地化文案。vue-i18n 缺键时原样返回 key，
 * 这里退回后端枚举码本身，避免页面上出现 `client.refunds.status.xxx` 这种 key 路径。
 */
function resolveLocalizedText(key: string, fallback: string): string {
  const text = normalizeText(t(key));
  return text && text !== key ? text : fallback;
}

function getErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof Error && error.message) return error.message;
  if (typeof error === 'object' && error !== null && 'message' in error && typeof error.message === 'string') {
    return error.message;
  }
  return fallback;
}

/** 拦截器已弹过提示的错误不再重复弹 */
function isHandledError(error: unknown): boolean {
  return typeof error === 'object' && error !== null && (error as { __handled?: boolean }).__handled === true;
}

/**
 * 是否拿到了服务端响应。
 * 没有响应 = 网络异常/超时：对 submit 而言这是「结果未知」，不能提示失败让用户重试。
 */
function hasServerResponse(error: unknown): boolean {
  return Boolean((error as { response?: unknown } | null | undefined)?.response);
}

export function autoRefundStatusLabel(status: unknown): string {
  const code = normalizeText(status);
  if (!code) return '--';
  return resolveLocalizedText(`client.refunds.status.${code}`, code);
}

export function autoRefundStatusTheme(status: unknown): TagTheme {
  return STATUS_THEME_MAP[normalizeText(status)] || 'default';
}

/** `uncertain` = 上游结果未知，必须留住幂等闸门，页面要明显提示不要重复提交 */
export function isUncertainStatus(status: unknown): boolean {
  return normalizeText(status) === 'uncertain';
}

/**
 * 能不能自行取消。
 *
 * 只有「待上游审核 + 走上游 api.php」的申请才可取消：上游没有可撤销记录的
 * （人工审核、自动退款、API 工单）取消在服务端会被拒，所以这里优先用后端下发的
 * `cancellable`，避免给用户一个点了就报错的按钮。
 */
export function canCancelApplication(row: AutoRefundApplication | null | undefined): boolean {
  if (row?.cancellable !== undefined) return row.cancellable === true;
  return normalizeText(row?.status) === 'pending_review';
}

export function autoRefundBlockedReasonLabel(code: unknown): string {
  const reason = normalizeText(code);
  if (!reason) return '';
  return resolveLocalizedText(`client.refunds.blockedReasons.${reason}`, reason);
}

/** 后端下发的原始枚举码，去空保序 */
function rawBlockedCodes(row: AutoRefundCandidate | null | undefined): string[] {
  const reasons = Array.isArray(row?.blocked_reasons) ? row?.blocked_reasons : [];
  return reasons.map((item) => normalizeText(item)).filter(Boolean);
}

/** `policy_configured` 可能是 true/false 也可能是 1/0；缺省返回 null，表示后端没下发 */
function policyConfiguredFlag(row: AutoRefundCandidate | null | undefined): boolean | null {
  const raw = row?.policy_configured;
  if (raw === null || raw === undefined) return null;
  return isTruthyFlag(raw);
}

/**
 * 该商品是否配了退款策略。
 *
 * 第二阶段起「能不能退」由每个商品自己的策略决定：没配策略（`policy_configured=false`）
 * 或后端已经把它列进 `rule_missing`，都不允许自助申请——按钮必须禁用并说明原因，
 * 不能让用户点了才收到一句「当前商品不支持退款」。
 */
export function isAutoRefundPolicyConfigured(row: AutoRefundCandidate | null | undefined): boolean {
  if (policyConfiguredFlag(row) === false) return false;
  return !rawBlockedCodes(row).includes('rule_missing');
}

/** 拦截原因枚举码：后端给的码 + `policy_configured=false` 补出的 `rule_missing` */
export function resolveAutoRefundBlockedReasonCodes(row: AutoRefundCandidate | null | undefined): string[] {
  const codes = rawBlockedCodes(row);
  if (!isAutoRefundPolicyConfigured(row) && !codes.includes('rule_missing')) {
    return ['rule_missing', ...codes];
  }
  return codes;
}

export function resolveBlockedReasonLabels(row: AutoRefundCandidate | null | undefined): string[] {
  return resolveAutoRefundBlockedReasonCodes(row)
    .map((item) => autoRefundBlockedReasonLabel(item))
    .filter(Boolean);
}

/** 申请按钮是否必须禁用：不可退或带任何拦截原因 */
export function isAutoRefundCandidateBlocked(row: AutoRefundCandidate | null | undefined): boolean {
  const refundable = row?.refundable as unknown;
  // 后端可能下发 false / 0 / "0"，只拦「明确为假」，缺省时只认 blocked_reasons
  const explicitlyUnrefundable =
    refundable !== null && refundable !== undefined && refundable !== '' && !isTruthyFlag(refundable);

  return explicitlyUnrefundable || resolveAutoRefundBlockedReasonCodes(row).length > 0;
}

/** 退款类型 / 规则 / 要求：后端优先给 `*_label`，缺了才按枚举码本地化 */
function resolveRuleLabel(
  group: 'refundType' | 'refundRule' | 'requirement',
  code: unknown,
  explicitLabel: unknown,
): string {
  const value = normalizeText(code);
  const label = normalizeText(explicitLabel);
  if (label) return label;
  if (!value) return '';
  return resolveLocalizedText(`client.refunds.${group}.${value}`, value);
}

export function autoRefundTypeLabel(code: unknown, explicitLabel?: unknown): string {
  return resolveRuleLabel('refundType', code, explicitLabel);
}

export function autoRefundRuleLabel(code: unknown, explicitLabel?: unknown): string {
  return resolveRuleLabel('refundRule', code, explicitLabel);
}

export function autoRefundRequirementLabel(code: unknown, explicitLabel?: unknown, windowHours?: unknown): string {
  const label = resolveRuleLabel('requirement', code, explicitLabel);
  if (normalizeText(code) !== 'within_hours') return label;

  // 后端在非 within_hours 场景下发 0；within_hours 又是 0 表示不限，别写成「（0 小时内）」
  const hours = resolveFirstNumber(windowHours);
  if (hours === null || hours <= 0) return `${label}${t('client.refunds.policyWindowHoursUnlimited')}`;
  return `${label}${t('client.refunds.policyWindowHoursSuffix', { hours })}`;
}

/**
 * 候选行的策略摘要，形如「插件间对接 · 按时长折算 · 产品首次」。
 * 未配策略时直接给「该商品未开放自助退款」，与按钮禁用原因同一句话。
 */
export function resolveAutoRefundPolicyText(row: AutoRefundCandidate | null | undefined): string {
  if (!isAutoRefundPolicyConfigured(row)) return autoRefundBlockedReasonLabel('rule_missing');

  const parts = [
    autoRefundTypeLabel(row?.refund_type, row?.refund_type_label),
    autoRefundRuleLabel(row?.refund_rule, row?.refund_rule_label),
    autoRefundRequirementLabel(row?.requirement, row?.requirement_label, row?.window_hours),
  ].filter(Boolean);

  return parts.length ? parts.join(' · ') : '--';
}

/**
 * 「实际走哪条通道」只有三个取值：`bridge` 上游接口 / `api_ticket` 上游工单 / `local` 仅本站。
 * 它不决定话术（`manual` 与 `auto` 都是 `local`），只作退款方式的副标题。
 */
export function autoRefundChannelLabel(channel: unknown, explicitLabel?: unknown): string {
  const code = normalizeText(channel);
  if (!code) return '--';

  const label = normalizeText(explicitLabel);
  if (label) return label;

  return resolveLocalizedText(`client.refunds.channel.${code}`, code);
}

/**
 * 提交成功后的话术：四种退款类型的「成功」含义完全不同，
 * 一律套「已提交，请等待审核」会把「已退款到余额」说错。
 *
 * 判定顺序：`status=settled` 一律「已退款到余额」→ 否则看 `refund_type`
 * （`channel` 只有三条通道，`manual` 与 `auto` 都是 `local`，用它判会把两者说成同一句）。
 */
export function resolveAutoRefundSubmitResultMessage(refundType: unknown, status?: unknown): string {
  const fallback = resolveLocalizedText('client.refunds.submitSuccess', '');
  if (normalizeText(status) === 'settled') return resolveLocalizedText('client.refunds.submitResult.auto', fallback);

  const code = normalizeText(refundType);
  if (!code) return fallback;

  const key = `client.refunds.submitResult.${code}`;
  const text = normalizeText(t(key));
  return text && text !== key ? text : fallback;
}

/** 关联的本站工单 id（api_ticket 通道才有），0 表示没有 */
export function resolveAutoRefundTicketId(row: AutoRefundApplication | null | undefined): number {
  const id = resolveFirstNumber(row?.ticket_id);
  return id !== null && id > 0 ? Math.trunc(id) : 0;
}

/** 上游工单号：zjfm 协议拿不到，为空显示 `-` */
export function resolveAutoRefundUpstreamTicketText(row: AutoRefundApplication | null | undefined): string {
  return normalizeText(row?.upstream_ticket_id) || '-';
}

export function resolveAutoRefundAmountText(value: unknown): string {
  const raw = normalizeText(value);
  if (!raw) return '--';
  // 未配策略的行没有预估金额，后端可能给 `--`：不能显示成 `¥0.00` 让人以为只能退 0 元
  if (!Number.isFinite(Number(raw))) return '--';
  return `¥${formatMoney(raw)}`;
}

export function resolveAutoRefundServiceTitle(
  row: AutoRefundCandidate | AutoRefundApplication | null | undefined,
): string {
  const serviceName = normalizeText(row?.service_name);
  if (serviceName) return serviceName;

  const productName = normalizeText(row?.product_name);
  if (productName) return productName;

  const serviceId = Number(row?.service_id || 0);
  return serviceId > 0 ? `#${serviceId}` : '--';
}

/** 域名/IP 一列：域名、独立 IP、附加 IP 都可能只有其一 */
export function resolveAutoRefundAddress(row: AutoRefundCandidate | null | undefined): string {
  const parts = [row?.domain, row?.dedicatedip, row?.assignedips].map((item) => normalizeText(item)).filter(Boolean);

  return parts.length ? Array.from(new Set(parts)).join(' / ') : '--';
}

export function resolveAutoRefundSubmittedAt(row: AutoRefundApplication | null | undefined): string {
  return normalizeText(row?.submitted_at) || normalizeText(row?.created_at) || '--';
}

export function resolveAutoRefundApplicationNo(row: AutoRefundApplication | null | undefined): string {
  return normalizeText(row?.application_no) || '--';
}

/**
 * 顶部通用说明。第二阶段起 `/bootstrap` 的 `policy` 只是「默认值 + 说明」：
 * 真正生效的是每个商品自己的策略，所以这里**只取说明与站点级最低金额**，
 * 后端即使仍下发 `mode` / `days` 也不当全局口径渲染（否则页面会写出一个不成立的「开通后 X 天内」）。
 */
export function resolveAutoRefundPolicyLines(payload: AutoRefundBootstrapPayload | null | undefined): string[] {
  const lines: string[] = [];
  const policy = payload?.policy;

  if (typeof policy === 'string') {
    const text = normalizeText(policy);
    if (text) lines.push(text);
    return lines;
  }

  if (!policy || typeof policy !== 'object') return lines;

  const description = normalizeText(policy.text);
  if (description) lines.push(description);

  const minAmount = resolveFirstNumber(policy.min_amount);
  if (minAmount !== null && minAmount > 0) {
    lines.push(
      t('common.labelValue', {
        label: t('client.refunds.policyMinAmountLabel'),
        value: `¥${formatMoney(minAmount)}`,
      }),
    );
  }

  return lines;
}

export function useAutoRefundCenter() {
  const router = useRouter();
  const bootstrapLoading = ref(true);
  const bootstrap = shallowRef<AutoRefundBootstrapPayload | null>(null);
  const enabled = ref(false);
  const configured = ref(false);

  const candidatesLoading = ref(false);
  const applicationsLoading = ref(false);
  const candidates = shallowRef<AutoRefundCandidate[]>([]);
  const applications = shallowRef<AutoRefundApplication[]>([]);
  const candidatesError = ref('');
  const applicationsError = ref('');
  const upstreamError = ref('');
  const total = ref(0);
  const filters = reactive({ page: 1, page_size: 10 });

  const submitting = ref(false);
  const cancelingId = ref(0);
  const submitVisible = ref(false);
  const submitTarget = shallowRef<AutoRefundCandidate | null>(null);
  const submitForm = reactive({ reason: '' });

  const available = computed(() => enabled.value && configured.value);
  const noticeText = computed(
    () =>
      normalizeText(bootstrap.value?.notice) ||
      normalizeText(bootstrap.value?.console_notice) ||
      normalizeText(bootstrap.value?.policy_text),
  );
  const policyLines = computed(() => resolveAutoRefundPolicyLines(bootstrap.value));
  const upstreamUnavailable = computed(() => Boolean(normalizeText(upstreamError.value)));
  const submitTargetTitle = computed(() => resolveAutoRefundServiceTitle(submitTarget.value));
  const reasonLength = computed(() => normalizeText(submitForm.reason).length);
  const canSubmit = computed(
    () => !submitting.value && reasonLength.value > 0 && reasonLength.value <= AUTO_REFUND_REASON_MAX_LENGTH,
  );
  /** 有结果未知的申请时页面顶部给一条明确提示，避免用户重复提交 */
  const hasUncertainApplication = computed(() => applications.value.some((item) => isUncertainStatus(item?.status)));

  async function loadBootstrap() {
    bootstrapLoading.value = true;
    try {
      const res = await clientApi.autoRefundBootstrap();
      const payload = res.data || {};
      bootstrap.value = payload;
      enabled.value = isTruthyFlag(payload.enabled);
      configured.value = isTruthyFlag(payload.configured);
    } catch {
      // 插件停用/未配置时后端可能是 404 或业务错误：整页按「暂未开放」处理，不弹错、不继续请求。
      bootstrap.value = null;
      enabled.value = false;
      configured.value = false;
    } finally {
      bootstrapLoading.value = false;
    }
  }

  /**
   * 可申请列表。上游不可用时不报错，只带 `upstream_error`：
   * 列表照常展示本地候选，申请按钮全禁用，「我的申请」不受影响。
   */
  async function loadCandidates() {
    if (!available.value) return;

    candidatesLoading.value = true;
    candidatesError.value = '';
    upstreamError.value = '';
    try {
      const res = await clientApi.autoRefundCandidates();
      const payload = res.data || {};
      candidates.value = Array.isArray(payload.list) ? payload.list : [];
      upstreamError.value = normalizeText(payload.upstream_error);
    } catch (error) {
      candidates.value = [];
      candidatesError.value = getErrorMessage(error, t('client.refunds.loadCandidatesFailed'));
    } finally {
      candidatesLoading.value = false;
    }
  }

  async function loadApplications() {
    if (!available.value) return;

    applicationsLoading.value = true;
    applicationsError.value = '';
    try {
      const res = await clientApi.autoRefundApplications({
        page: filters.page,
        page_size: filters.page_size,
      });
      applications.value = Array.isArray(res.data?.list) ? res.data.list : [];
      total.value = Number(res.data?.total || 0);
    } catch (error) {
      applications.value = [];
      total.value = 0;
      applicationsError.value = getErrorMessage(error, t('client.refunds.loadApplicationsFailed'));
    } finally {
      applicationsLoading.value = false;
    }
  }

  async function refreshCenter() {
    await Promise.allSettled([loadCandidates(), loadApplications()]);
  }

  function handlePageChange() {
    void loadApplications();
  }

  function handlePageSizeChange() {
    filters.page = 1;
    void loadApplications();
  }

  function openSubmitDialog(row: AutoRefundCandidate) {
    submitTarget.value = row;
    submitForm.reason = '';
    submitVisible.value = true;
  }

  function closeSubmitDialog() {
    if (submitting.value) return;
    submitVisible.value = false;
    submitTarget.value = null;
    submitForm.reason = '';
  }

  function showUncertainNotice() {
    DialogPlugin.alert({
      header: t('client.refunds.uncertainTitle'),
      body: t('client.refunds.uncertainTip'),
      confirmBtn: t('common.confirm'),
    });
  }

  async function submitApplication(target: AutoRefundCandidate, reason: string): Promise<boolean> {
    submitting.value = true;
    try {
      const res = await clientApi.submitAutoRefundApplication({
        service_id: target.service_id,
        reason,
      });

      if (isUncertainStatus(res.data?.status)) {
        // 「结果未知」优先于任何通道话术：这时候说「已退款」或「已提交」都是在猜。
        showUncertainNotice();
      } else {
        // 四种退款类型的「成功」含义不同，按后端回填的 refund_type / status 分开说
        MessagePlugin.success(resolveAutoRefundSubmitResultMessage(res.data?.refund_type, res.data?.status));
      }

      submitVisible.value = false;
      submitTarget.value = null;
      submitForm.reason = '';
      await refreshCenter();
      return true;
    } catch (error) {
      if (!hasServerResponse(error)) {
        // 请求没拿到响应：上游可能已经建了申请，重试会造第二笔。按「结果未知」处理并刷新台账。
        showUncertainNotice();
        submitVisible.value = false;
        submitTarget.value = null;
        await refreshCenter();
        return true;
      }

      if (!isHandledError(error)) {
        MessagePlugin.error(getErrorMessage(error, t('client.refunds.submitFailed')));
      }
      return false;
    } finally {
      submitting.value = false;
    }
  }

  function handleSubmit() {
    const target = submitTarget.value;
    if (!target || submitting.value) return;

    const reason = normalizeText(submitForm.reason);
    if (!reason) {
      MessagePlugin.warning(t('client.refunds.reasonRequired'));
      return;
    }
    if (reason.length > AUTO_REFUND_REASON_MAX_LENGTH) {
      MessagePlugin.warning(t('client.refunds.reasonTooLong', { max: AUTO_REFUND_REASON_MAX_LENGTH }));
      return;
    }

    const dialog = DialogPlugin.confirm({
      header: t('client.refunds.submitConfirmTitle'),
      body: t('client.refunds.submitConfirmBody', { name: resolveAutoRefundServiceTitle(target) }),
      confirmBtn: t('client.refunds.submitAction'),
      cancelBtn: t('common.cancel'),
      theme: 'warning',
      async onConfirm() {
        dialog.setConfirmLoading(true);
        try {
          await submitApplication(target, reason);
        } finally {
          dialog.setConfirmLoading(false);
          dialog.hide();
        }
      },
    });
  }

  function handleCancel(row: AutoRefundApplication) {
    const id = Number(row?.id || 0);
    if (!id) return;

    const dialog = DialogPlugin.confirm({
      header: t('client.refunds.cancelConfirmTitle'),
      body: t('client.refunds.cancelConfirmBody', {
        no: resolveAutoRefundApplicationNo(row),
      }),
      confirmBtn: t('client.refunds.cancelAction'),
      cancelBtn: t('common.cancel'),
      theme: 'warning',
      async onConfirm() {
        dialog.setConfirmLoading(true);
        cancelingId.value = id;
        try {
          await clientApi.cancelAutoRefundApplication(id);
          MessagePlugin.success(t('client.refunds.cancelSuccess'));
          await refreshCenter();
          dialog.hide();
        } catch (error) {
          if (!isHandledError(error)) {
            MessagePlugin.error(getErrorMessage(error, t('client.refunds.cancelFailed')));
          }
        } finally {
          cancelingId.value = 0;
          dialog.setConfirmLoading(false);
        }
      },
    });
  }

  /**
   * 「上游工单」列里的入口：跳该端既有的工单详情路径
   * （工单列表的 openDetail 也走 `/client/ticket-conversations/:id`，不另造一条）。
   */
  function openApplicationTicket(row: AutoRefundApplication) {
    const id = resolveAutoRefundTicketId(row);
    if (!id) return;
    void router.push(`/client/ticket-conversations/${id}`);
  }

  onMounted(() => {
    void (async () => {
      await loadBootstrap();
      if (!available.value) return;
      await refreshCenter();
    })();
  });

  return {
    // 开关
    bootstrapLoading,
    available,
    noticeText,
    policyLines,
    // 可申请列表
    candidatesLoading,
    candidates,
    candidatesError,
    upstreamError,
    upstreamUnavailable,
    // 我的申请
    applicationsLoading,
    applications,
    applicationsError,
    hasUncertainApplication,
    total,
    filters,
    // 弹窗与动作
    submitting,
    cancelingId,
    submitVisible,
    submitTargetTitle,
    submitForm,
    canSubmit,
    handlePageChange,
    handlePageSizeChange,
    openSubmitDialog,
    closeSubmitDialog,
    handleSubmit,
    handleCancel,
    openApplicationTicket,
  };
}
