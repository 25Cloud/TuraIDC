import { MessagePlugin } from 'tdesign-vue-next';

/**
 * 统一的错误消息提取工具。
 *
 * 收敛自 12 处分散的 getErrorMessage 定义。
 * 行为：提取 Error.message；若非 Error 实例则返回 fallback。
 */
export function getErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof Error && error.message) return error.message;
  if (
    typeof error === 'object' &&
    error !== null &&
    'message' in error &&
    typeof (error as Record<string, unknown>).message === 'string'
  ) {
    return (error as Record<string, unknown>).message as string;
  }
  return fallback;
}

/**
 * 该错误是否已经由请求拦截器给过用户反馈。
 *
 * 拦截器弹出提示（或账户策略对话框）时会把 `__handled` 置为 true，
 * 调用方据此跳过自己的 MessagePlugin.error，避免同一条错误连弹两次。
 */
export function isHandledRequestError(error: unknown): boolean {
  return typeof error === 'object' && error !== null && (error as { __handled?: unknown }).__handled === true;
}

/**
 * 统一上报请求错误。
 *
 * 规则：拦截器已经给过反馈（包括 40301 + `data.scope=account_policy` 的补齐对话框）就完全
 * 交给它；其余的（没被拦截器提示过，例如 silentError 请求）照常 toast。
 * 下单 / 支付 / 充值 / 提现这类会被账户策略拦截的写操作都该走这个函数。
 */
export function reportRequestError(error: unknown, fallback: string): void {
  if (isHandledRequestError(error)) {
    return;
  }

  MessagePlugin.error(getErrorMessage(error, fallback));
}
