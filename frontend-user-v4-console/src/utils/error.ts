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
