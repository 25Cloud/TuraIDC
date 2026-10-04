import clientApi from '@/api/client';
import type { ServiceConsoleAreaTicket } from '@/types/client';

/**
 * 控制台自定义区域（面板信息）的访问票据。
 *
 * 提到模块级而不是写在 AreaTab 组件里，原因有两个：
 *   1. 面板 iframe 常驻挂载做后台预热，预加载与用户实际切到该 tab 时会先后调用，
 *      放组件内每次重建都要重新签发，白白多打一轮上游；
 *   2. in-flight 去重：预热请求还没回来时用户就点了 tab，两次调用会打同一张票。
 */

const TICKET_TTL_MS = 10 * 60 * 1000;
/** 剩余有效期低于这个值就重新签发，避免边界上带着将过期的票据去请求面板 */
const TICKET_SAFE_MARGIN_MS = 60 * 1000;

interface TicketEntry {
  ticket: string;
  expiresAt: number;
}

const ticketCache = new Map<number, TicketEntry>();
const inflightTickets = new Map<number, Promise<string>>();

function readCachedTicket(serviceId: number): string {
  const cached = ticketCache.get(serviceId);
  if (!cached) return '';

  // 顺手清掉过期条目，别让 Map 无限增长
  if (cached.expiresAt - Date.now() <= TICKET_SAFE_MARGIN_MS) {
    ticketCache.delete(serviceId);
    return '';
  }

  return cached.ticket;
}

/**
 * 拿到可用票据，优先复用缓存 / 复用正在飞行的请求。
 * 调用方负责处理失败（预热场景静默忽略即可，真实打开面板时才提示错误）。
 */
export function ensureAreaTicket(serviceId: number): Promise<string> {
  if (!(serviceId > 0)) {
    return Promise.reject(new Error('服务ID无效'));
  }

  const cached = readCachedTicket(serviceId);
  if (cached) {
    return Promise.resolve(cached);
  }

  const inflight = inflightTickets.get(serviceId);
  if (inflight) {
    return inflight;
  }

  const task = clientApi
    .createServiceConsoleAreaTicket(serviceId)
    .then((res) => {
      const next = String((res.data as ServiceConsoleAreaTicket | undefined)?.ticket || '');
      if (!next) {
        throw new Error('访问凭证生成失败，请稍后重试');
      }

      ticketCache.set(serviceId, { ticket: next, expiresAt: Date.now() + TICKET_TTL_MS });
      return next;
    })
    .finally(() => {
      inflightTickets.delete(serviceId);
    });

  inflightTickets.set(serviceId, task);
  return task;
}

/** 手动刷新面板：丢掉缓存强制换一张新票 */
export function invalidateAreaTicket(serviceId: number) {
  if (!(serviceId > 0)) return;
  ticketCache.delete(serviceId);
}