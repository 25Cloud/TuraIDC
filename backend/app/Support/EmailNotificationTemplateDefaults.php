<?php

declare(strict_types=1);

namespace App\Support;

final class EmailNotificationTemplateDefaults
{
    private const THEME_BLUE = '#1f5eff';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function templates(): array
    {
        return [
            self::email('100001', '验证码邮件', '发送邮箱验证码时使用。', '【{{site_name}}】验证码邮件', '请使用以下验证码完成验证。', [['验证码', '{{code}}'], ['有效期', '{{expire_minutes}} 分钟']], '如非本人操作，可以忽略此邮件；验证码不会影响您的账号状态。', ['code', 'expire_minutes'], 'user', '#1f5eff'),
            self::email('100002', '注册成功', '客户注册成功后发送欢迎通知。', '【{{site_name}}】注册成功', '{{display_name}}，您的账号已注册成功。', [['登录邮箱', '{{email}}'], ['注册时间', '{{registered_at}}']], '建议尽快完善账号安全信息，便于后续接收服务和账单通知。', ['display_name', 'email', 'registered_at'], 'user', '#0f766e'),
            self::email('100003', '登录IP提醒', '客户登录或登录异常时发送 IP 安全提醒。', '【{{site_name}}】登录IP提醒', '{{display_name}}，您的账号发生登录行为。', [['登录账号', '{{email}}{{account}}'], ['登录时间', '{{login_at}}{{attempt_at}}'], ['当前 IP', '{{ip}}'], ['上次 IP', '{{previous_ip}}'], ['设备', '{{device}}']], '如果这不是您本人操作，请立即修改密码并检查账号安全设置。', ['display_name', 'email', 'account', 'login_at', 'attempt_at', 'ip', 'previous_ip', 'device'], 'user', '#b45309'),
            self::email('100004', '绑定通知', '客户绑定或变更安全信息后发送提醒。', '【{{site_name}}】绑定通知', '{{display_name}}，您的账号安全信息已更新。', [['绑定类型', '{{bind_type}}'], ['绑定账号', '{{bind_account}}'], ['原手机号', '{{old_phone}}'], ['新手机号', '{{new_phone}}'], ['原邮箱', '{{old_email}}'], ['新邮箱', '{{new_email}}'], ['变更时间', '{{bound_at}}{{changed_at}}'], ['IP 地址', '{{ip}}'], ['设备', '{{device}}']], '如非本人操作，请尽快联系管理员处理。', ['display_name', 'bind_type', 'bind_account', 'old_phone', 'new_phone', 'old_email', 'new_email', 'bound_at', 'changed_at', 'ip', 'device'], 'user', '#2563eb'),
            self::email('100005', '产品开通通知', '产品或服务开通成功后发送通知。', '【{{site_name}}】产品开通通知', '{{display_name}}，您的产品已开通。', [['产品名称', '{{product_name}}{{service_name}}'], ['主机名', '{{hostname}}'], ['主 IP', '{{product_main_ip}}'], ['用户名', '{{product_username}}'], ['操作系统', '{{operating_system}}'], ['开通时间', '{{activated_at}}'], ['到期时间', '{{expires_at}}'], ['付款周期', '{{billing_cycle_label}}']], '请登录控制台核对产品信息，并按需完成初始化配置。', ['display_name', 'product_name', 'service_name', 'hostname', 'product_main_ip', 'product_username', 'operating_system', 'activated_at', 'expires_at', 'billing_cycle_label'], 'user', '#15803d'),
            self::email('100006', '产品停用通知', '产品或服务被停用时发送通知。', '【{{site_name}}】产品停用通知', '{{display_name}}，您的产品已被停用。', [['产品名称', '{{product_name}}{{service_name}}'], ['停用原因', '{{reason}}'], ['到期时间', '{{expires_at}}'], ['停用时间', '{{suspended_at}}']], '请按停用原因完成处理，必要时联系技术支持协助恢复。', ['display_name', 'product_name', 'service_name', 'reason', 'expires_at', 'suspended_at'], 'user', '#b91c1c'),
            self::email('100007', '产品恢复通知', '产品或服务恢复后发送通知。', '【{{site_name}}】产品恢复通知', '{{display_name}}，您的产品已恢复。', [['产品名称', '{{product_name}}{{service_name}}'], ['恢复时间', '{{resumed_at}}'], ['新的到期时间', '{{expires_at}}']], '您可以继续使用该产品；建议确认业务状态是否已恢复正常。', ['display_name', 'product_name', 'service_name', 'resumed_at', 'expires_at'], 'user', '#15803d'),
            self::email('100008', '产品删除通知', '产品或服务删除后发送通知。', '【{{site_name}}】产品删除通知', '{{display_name}}，您的产品已删除。', [['产品名称', '{{product_name}}{{service_name}}'], ['删除时间', '{{terminated_at}}'], ['说明', '{{notice_message}}']], '删除操作完成后资源可能无法恢复，如有疑问请及时联系支持。', ['display_name', 'product_name', 'service_name', 'terminated_at', 'notice_message'], 'user', '#b91c1c'),
            self::email('100009', '重装系统成功通知', '产品重装系统成功后发送通知。', '【{{site_name}}】重装系统成功通知', '{{display_name}}，您的产品已完成系统重装。', [['产品名称', '{{product_name}}{{service_name}}'], ['操作系统', '{{operating_system}}'], ['主 IP', '{{product_main_ip}}'], ['用户名', '{{product_username}}'], ['完成时间', '{{completed_at}}']], '请使用新的系统信息登录产品，并重新检查业务运行环境。', ['display_name', 'product_name', 'service_name', 'operating_system', 'product_main_ip', 'product_username', 'completed_at'], 'user', '#0f766e'),
            self::email('100010', '新订单待支付', '客户创建新订单或账单后发送待支付通知。', '【{{site_name}}】新订单待支付 #{{invoice_no}}', '{{display_name}}，您有一笔新订单待支付。', [['订单号', '{{order_no}}'], ['账单号', '{{invoice_no}}'], ['产品名称', '{{product_name}}'], ['付款周期', '{{billing_cycle_label}}'], ['应付金额', '¥{{amount}}{{order_amount}}'], ['到期日期', '{{due_date}}']], '请在订单有效期内完成付款，付款后系统会继续处理开通流程。', ['display_name', 'order_no', 'invoice_no', 'product_name', 'billing_cycle_label', 'amount', 'order_amount', 'due_date'], 'user', '#1d4ed8'),
            self::email('100011', '第1次支付提醒', '订单或账单第一次支付提醒。', '【{{site_name}}】第1次支付提醒 #{{invoice_no}}', '{{display_name}}，您的账单仍未支付。', [['账单号', '{{invoice_no}}'], ['订单号', '{{order_no}}'], ['产品名称', '{{product_name}}'], ['待付金额', '¥{{amount}}'], ['到期日期', '{{due_date}}']], '{{notice_message}}', ['display_name', 'invoice_no', 'order_no', 'product_name', 'amount', 'due_date', 'notice_message'], 'user', '#1d4ed8'),
            self::email('100012', '第2次支付提醒', '订单或账单第二次支付提醒。', '【{{site_name}}】第2次支付提醒 #{{invoice_no}}', '{{display_name}}，您的账单即将逾期。', [['账单号', '{{invoice_no}}'], ['订单号', '{{order_no}}'], ['产品名称', '{{product_name}}'], ['待付金额', '¥{{amount}}'], ['到期日期', '{{due_date}}']], '{{notice_message}}', ['display_name', 'invoice_no', 'order_no', 'product_name', 'amount', 'due_date', 'notice_message'], 'user', '#b45309'),
            self::email('100013', '第3次支付提醒', '订单或账单第三次支付提醒。', '【{{site_name}}】第3次支付提醒 #{{invoice_no}}', '{{display_name}}，您的账单已逾期，请尽快处理。', [['账单号', '{{invoice_no}}'], ['订单号', '{{order_no}}'], ['产品名称', '{{product_name}}'], ['逾期金额', '¥{{amount}}'], ['到期日期', '{{due_date}}']], '{{notice_message}}', ['display_name', 'invoice_no', 'order_no', 'product_name', 'amount', 'due_date', 'notice_message'], 'user', '#b91c1c'),
            self::email('100014', '自动续费预告通知', '自动续费执行前发送提醒。', '【{{site_name}}】自动续费预告通知', '{{display_name}}，您的产品即将自动续费。', [['产品名称', '{{service_name}}{{product_name}}'], ['到期时间', '{{expires_at}}'], ['付款周期', '{{billing_cycle_label}}'], ['自动续费日期', '{{auto_renew_date}}']], '{{urgency_message}}', ['display_name', 'service_name', 'product_name', 'expires_at', 'billing_cycle_label', 'auto_renew_date', 'urgency_message'], 'user', '#b45309'),
            self::email('100015', '自动续费通知', '自动续费订单生成或扣款时发送通知。', '【{{site_name}}】自动续费通知', '{{display_name}}，您的产品自动续费流程已触发。', [['产品名称', '{{service_name}}{{product_name}}'], ['订单号', '{{order_no}}'], ['账单号', '{{invoice_no}}'], ['续费金额', '¥{{amount}}{{order_amount}}'], ['续费时间', '{{renewed_at}}']], '{{notice_message}}', ['display_name', 'service_name', 'product_name', 'order_no', 'invoice_no', 'amount', 'order_amount', 'renewed_at', 'notice_message'], 'user', '#0f766e'),
            self::email('100016', '付款成功通知', '客户付款成功或管理员手动入账后发送通知。', '【{{site_name}}】付款成功通知 #{{invoice_no}}', '{{display_name}}，您的付款已确认。', [['账单号', '{{invoice_no}}'], ['订单号', '{{order_no}}'], ['产品名称', '{{product_name}}'], ['支付金额', '¥{{paid_amount}}{{amount}}'], ['支付方式', '{{payment_method}}'], ['交易号', '{{trade_no}}'], ['支付时间', '{{paid_at}}']], '{{notice_message}}{{remark}}', ['display_name', 'invoice_no', 'order_no', 'product_name', 'paid_amount', 'amount', 'payment_method', 'trade_no', 'paid_at', 'notice_message', 'remark'], 'user', '#15803d'),
            self::email('100017', '账单退款通知', '账单或订单退款后发送通知。', '【{{site_name}}】账单退款通知 #{{invoice_no}}', '{{display_name}}，您的账单已退款。', [['账单号', '{{invoice_no}}'], ['订单号', '{{order_no}}'], ['产品名称', '{{product_name}}'], ['退款金额', '¥{{refund_amount}}'], ['退款方式', '{{refund_method_label}}'], ['退款时间', '{{refunded_at}}'], ['说明', '{{refund_reason}}']], '退款到账时间以支付渠道实际处理为准，请以后续渠道通知为准。', ['display_name', 'invoice_no', 'order_no', 'product_name', 'refund_amount', 'refund_method_label', 'refunded_at', 'refund_reason'], 'user', '#0f766e'),
            self::email('100018', '第1次续费提醒', '产品到期前第一次续费提醒。', '【{{site_name}}】第1次续费提醒', '{{display_name}}，您的产品即将到期。', [['产品名称', '{{service_name}}{{product_name}}'], ['剩余时间', '{{days_left}} 天'], ['到期时间', '{{expires_at}}'], ['付款周期', '{{billing_cycle_label}}']], '{{urgency_message}}', ['display_name', 'service_name', 'product_name', 'days_left', 'expires_at', 'billing_cycle_label', 'urgency_message'], 'user', '#b45309'),
            self::email('100019', '第2次续费提醒', '产品到期前第二次续费提醒。', '【{{site_name}}】第2次续费提醒', '{{display_name}}，您的产品到期时间临近。', [['产品名称', '{{service_name}}{{product_name}}'], ['剩余时间', '{{days_left}} 天'], ['到期时间', '{{expires_at}}'], ['付款周期', '{{billing_cycle_label}}']], '{{urgency_message}}', ['display_name', 'service_name', 'product_name', 'days_left', 'expires_at', 'billing_cycle_label', 'urgency_message'], 'user', '#b91c1c'),
            self::email('100020', '信用额账单已生成', '信用额账单生成后发送通知。', '【{{site_name}}】信用额账单已生成 #{{invoice_no}}', '{{display_name}}，您的信用额账单已生成。', [['账单号', '{{invoice_no}}'], ['产品名称', '{{product_name}}'], ['账单金额', '¥{{amount}}'], ['到期时间', '{{due_at}}{{due_date}}']], '{{notice_message}}', ['display_name', 'invoice_no', 'product_name', 'amount', 'due_at', 'due_date', 'notice_message'], 'user', '#7c3aed'),
            self::email('100021', '管理员新订单通知', '客户创建新订单后通知管理员。', '【{{site_name}}】管理员新订单通知 #{{invoice_no}}', '{{recipient_name}}，有客户创建了新订单。', [['客户', '{{user_name}}'], ['客户邮箱', '{{user_email}}'], ['订单号', '{{order_no}}'], ['账单号', '{{invoice_no}}'], ['订单类型', '{{order_type_label}}'], ['产品名称', '{{product_name}}'], ['付款周期', '{{billing_cycle_label}}'], ['订单金额', '¥{{order_amount}}'], ['订单状态', '{{order_status_label}}'], ['创建时间', '{{created_at}}']], '请在后台确认订单状态，必要时跟进支付或开通流程。', ['recipient_name', 'user_name', 'user_email', 'order_no', 'invoice_no', 'order_type_label', 'product_name', 'billing_cycle_label', 'order_amount', 'order_status_label', 'created_at'], 'admin', '#7c3aed'),
            self::email('100022', '管理员订单支付通知', '客户订单支付完成后通知管理员。', '【{{site_name}}】管理员订单支付通知 #{{invoice_no}}', '{{recipient_name}}，有客户完成了订单支付。', [['客户', '{{user_name}}'], ['客户邮箱', '{{user_email}}'], ['订单号', '{{order_no}}'], ['账单号', '{{invoice_no}}'], ['产品名称', '{{product_name}}'], ['付款周期', '{{billing_cycle_label}}'], ['支付金额', '¥{{paid_amount}}'], ['支付方式', '{{payment_method}}'], ['交易号', '{{trade_no}}'], ['支付时间', '{{paid_at}}']], '请核对支付记录，并关注后续开通或交付结果。', ['recipient_name', 'user_name', 'user_email', 'order_no', 'invoice_no', 'product_name', 'billing_cycle_label', 'paid_amount', 'payment_method', 'trade_no', 'paid_at'], 'admin', '#0f766e'),
            self::email('100023', '工单开通通知', '客户工单创建成功后发送通知。', '【{{site_name}}】工单开通通知 #{{ticket_id}}', '{{display_name}}，您的工单已创建。', [['工单编号', '#{{ticket_id}}'], ['工单标题', '{{ticket_subject}}'], ['部门', '{{department}}'], ['优先级', '{{priority}}'], ['创建时间', '{{ticket_created_at}}']], '{{message_preview}} {{login_tip}}', ['display_name', 'ticket_id', 'ticket_subject', 'department', 'priority', 'ticket_created_at', 'message_preview', 'login_tip'], 'user', '#2563eb'),
            self::email('100024', '工单新回复通知', '管理员回复客户工单后发送通知。', '【{{site_name}}】工单新回复通知 #{{ticket_id}}', '{{display_name}}，您的工单有新回复。', [['工单编号', '#{{ticket_id}}'], ['工单标题', '{{ticket_subject}}'], ['状态', '{{status}}'], ['回复人', '{{staff_name}}']], '{{message_preview}} {{login_tip}}', ['display_name', 'ticket_id', 'ticket_subject', 'status', 'staff_name', 'message_preview', 'tickets_url', 'login_tip'], 'user', '#2563eb'),
            self::email('100025', '工单自动关闭通知', '工单自动关闭后发送通知。', '【{{site_name}}】工单自动关闭通知 #{{ticket_id}}', '{{display_name}}，您的工单已自动关闭。', [['工单编号', '#{{ticket_id}}'], ['工单标题', '{{ticket_subject}}'], ['关闭时间', '{{ticket_closed_at}}'], ['关闭原因', '{{close_reason}}']], '如果问题仍未解决，可以重新提交工单并说明最新情况。', ['display_name', 'ticket_id', 'ticket_subject', 'ticket_closed_at', 'close_reason'], 'user', '#667085'),
            self::email('100026', '管理员新工单提示', '客户提交新工单后通知管理员。', '【{{site_name}}】管理员新工单提示 #{{ticket_id}}', '{{recipient_name}}，有客户提交了新工单。', [['工单编号', '#{{ticket_id}}'], ['标题', '{{ticket_subject}}'], ['部门', '{{department}}'], ['优先级', '{{priority}}'], ['状态', '{{status}}'], ['客户', '{{client_name}}'], ['客户邮箱', '{{client_email}}']], '{{message_preview}}', ['recipient_name', 'ticket_id', 'ticket_subject', 'department', 'priority', 'status', 'client_name', 'client_email', 'message_preview'], 'admin', '#7c3aed'),
            self::email('100027', '管理员工单回复通知', '客户回复工单后通知管理员。', '【{{site_name}}】管理员工单回复通知 #{{ticket_id}}', '{{recipient_name}}，客户补充了新的工单回复。', [['工单编号', '#{{ticket_id}}'], ['标题', '{{ticket_subject}}'], ['部门', '{{department}}'], ['优先级', '{{priority}}'], ['状态', '{{status}}'], ['客户', '{{client_name}}'], ['客户邮箱', '{{client_email}}']], '{{message_preview}}', ['recipient_name', 'ticket_id', 'ticket_subject', 'department', 'priority', 'status', 'client_name', 'client_email', 'message_preview'], 'admin', '#7c3aed'),
            self::email('100028', '管理员登录提示', '管理员登录后台后发送安全提醒。', '【{{site_name}}】管理员登录提示', '{{recipient_name}}，您的管理员账号发生登录行为。', [['登录账号', '{{account}}'], ['登录时间', '{{login_at}}'], ['IP 地址', '{{ip}}'], ['设备', '{{device}}']], '如果不是您本人登录，请立即修改密码并检查后台账号权限。', ['recipient_name', 'account', 'login_at', 'ip', 'device'], 'admin', '#b45309'),
            self::email('100029', '产品解除停用失败通知', '产品解除停用失败后发送通知。', '【{{site_name}}】产品解除停用失败通知', '{{display_name}}，您的产品解除停用失败。', [['产品名称', '{{product_name}}{{service_name}}'], ['失败原因', '{{failure_reason}}'], ['处理时间', '{{failed_at}}']], '系统已记录本次失败原因，请联系支持继续处理恢复事项。', ['display_name', 'product_name', 'service_name', 'failure_reason', 'failed_at'], 'user', '#b91c1c'),
        ];
    }

    /**
     * @param  array<int, string>  $variables
     * @param  array<int, array{0: string, 1: string}>  $rows
     * @return array<string, mixed>
     */
    private static function email(
        string $code,
        string $name,
        string $description,
        string $subject,
        string $lead,
        array $rows,
        string $notice,
        array $variables,
        string $audience,
        string $accent
    ): array {
        $variables = array_values(array_unique(array_merge(['site_name', 'site_logo'], $variables)));

        return [
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'subject' => $subject,
            'content' => self::content($code, $name, $lead, $rows, $notice, self::THEME_BLUE),
            'variables' => $variables,
            'audience' => $audience,
            'accent' => self::THEME_BLUE,
        ];
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rows
     */
    private static function content(string $code, string $title, string $lead, array $rows, string $notice, string $accent): string
    {
        $style = self::style($code, $accent);
        $rowHtml = '';
        $actionTitle = '处理建议';

        foreach ($rows as $row) {
            $rowHtml .= '                  <tr><td class="detail-label">'.(string) $row[0].'</td><td class="detail-value">'.(string) $row[1].'</td></tr>'."\n";
        }

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
{$style}
  </style>
</head>
<body>
  <div class="cw-email-template cw-email-template-{$code}">
    <table role="presentation" class="email-shell" cellpadding="0" cellspacing="0" width="100%">
      <tr>
        <td align="center">
          <table role="presentation" class="email-card" cellpadding="0" cellspacing="0" width="100%">
            <tr>
              <td class="email-brand">
                {{#site_logo}}<img class="email-logo" src="{{site_logo}}" alt="{{site_name}}">{{/site_logo}}<span class="email-brand-name">{{site_name}}</span>
              </td>
            </tr>
            <tr>
              <td class="email-hero">
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                  <tr>
                    <td class="hero-copy">
                      <div class="email-eyebrow">系统通知</div>
                      <h1>{$title}</h1>
                      <p class="email-lead">{$lead}</p>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td class="email-section">
                <div class="section-title">关键信息</div>
                <table role="presentation" class="detail-table" cellpadding="0" cellspacing="0" width="100%">
{$rowHtml}                </table>
              </td>
            </tr>
            <tr>
              <td class="email-action">
                <div class="action-title">{$actionTitle}</div>
                <div class="action-copy">{$notice}</div>
              </td>
            </tr>
            <tr>
              <td class="email-footer">本邮件由 {{site_name}} 系统发送，请勿直接回复。</td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </div>
</body>
</html>
HTML;
    }

    private static function style(string $code, string $accent): string
    {
        return <<<CSS
body {
  margin: 0;
  background: #f3f6fb;
  color: #111827;
  font-family: "PingFang SC", "Microsoft YaHei", Arial, sans-serif;
}
.cw-email-template-{$code} {
  width: 100%;
  background: #f3f6fb;
}
.cw-email-template-{$code} .email-shell {
  width: 100%;
  background: #f3f6fb;
  padding: 28px 12px;
}
.cw-email-template-{$code} .email-card {
  max-width: 680px;
  border: 1px solid #d8e1ef;
  border-top: 4px solid {$accent};
  background: #ffffff;
  border-collapse: separate;
  border-spacing: 0;
}
.cw-email-template-{$code} .email-brand {
  padding: 22px 32px 18px;
  border-bottom: 1px solid #e7edf6;
  white-space: nowrap;
}
.cw-email-template-{$code} .email-logo {
  max-width: 148px;
  max-height: 36px;
  margin-right: 12px;
  vertical-align: middle;
}
.cw-email-template-{$code} .email-brand-name {
  color: #182033;
  font-size: 15px;
  font-weight: 700;
  vertical-align: middle;
}
.cw-email-template-{$code} .email-hero {
  padding: 28px 32px 12px;
}
.cw-email-template-{$code} .hero-copy {
  vertical-align: top;
}
.cw-email-template-{$code} .email-eyebrow {
  color: {$accent};
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0;
}
.cw-email-template-{$code} h1 {
  margin: 8px 0 0;
  color: #111827;
  font-size: 24px;
  line-height: 1.35;
}
.cw-email-template-{$code} .email-lead {
  margin: 12px 0 0;
  color: #4b5563;
  font-size: 14px;
  line-height: 1.8;
}
.cw-email-template-{$code} .email-section {
  padding: 14px 32px 0;
}
.cw-email-template-{$code} .section-title {
  margin-bottom: 10px;
  color: #182033;
  font-size: 14px;
  font-weight: 700;
}
.cw-email-template-{$code} .detail-table {
  border: 1px solid #e1e7f0;
  background: #ffffff;
  border-collapse: collapse;
}
.cw-email-template-{$code} .detail-label,
.cw-email-template-{$code} .detail-value {
  padding: 13px 14px;
  border-bottom: 1px solid #e1e7f0;
  font-size: 14px;
  line-height: 1.6;
  vertical-align: top;
}
.cw-email-template-{$code} .detail-label {
  width: 34%;
  color: #667085;
  background: #f8fafc;
}
.cw-email-template-{$code} .detail-value {
  color: #111827;
  font-weight: 700;
  text-align: right;
  word-break: break-word;
}
.cw-email-template-{$code} .email-action {
  padding: 20px 32px 0;
}
.cw-email-template-{$code} .action-title {
  padding-left: 12px;
  border-left: 3px solid {$accent};
  color: #182033;
  font-size: 14px;
  font-weight: 700;
}
.cw-email-template-{$code} .action-copy {
  margin-top: 8px;
  padding-left: 15px;
  color: #4b5563;
  font-size: 14px;
  line-height: 1.8;
}
.cw-email-template-{$code} .email-footer {
  padding: 24px 32px 26px;
  color: #8a95a5;
  font-size: 12px;
  line-height: 1.7;
}
@media screen and (max-width: 640px) {
  .cw-email-template-{$code} .email-shell {
    padding: 16px 8px;
  }
  .cw-email-template-{$code} .email-brand,
  .cw-email-template-{$code} .email-hero,
  .cw-email-template-{$code} .email-section,
  .cw-email-template-{$code} .email-action,
  .cw-email-template-{$code} .email-footer {
    padding-left: 18px;
    padding-right: 18px;
  }
  .cw-email-template-{$code} .hero-copy {
    display: block;
    width: auto;
  }
  .cw-email-template-{$code} .hero-copy {
    padding-right: 0;
  }
  .cw-email-template-{$code} h1 {
    font-size: 21px;
  }
  .cw-email-template-{$code} .detail-label,
  .cw-email-template-{$code} .detail-value {
    display: block;
    width: auto;
    text-align: left;
  }
}
CSS;
    }
}
