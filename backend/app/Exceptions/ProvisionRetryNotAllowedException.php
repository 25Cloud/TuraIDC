<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 上游开通结果「已失败」或「不确定」时使用：这类失败再自动重试会向上游重复下单、
 * 重复扣供应商余额，队列重试（$tries=3）加上首轮同步履约就是 4 笔相同订单。
 *
 * 因此本异常携带明确语义：**不要自动重试**，必须人工核实上游后由管理员重新发起。
 * 继承 BusinessException 以便管理端接口照常渲染成可读的业务错误。
 */
class ProvisionRetryNotAllowedException extends BusinessException {}
