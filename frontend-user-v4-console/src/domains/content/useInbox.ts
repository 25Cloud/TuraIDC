import { ref } from 'vue';

import clientApi from '@/api/client';

export interface InboxItem {
  id: string;
  raw_id?: number;
  source: 'notice' | 'message';
  type: string;
  type_label: string;
  title: string;
  summary: string;
  link: string | null;
  read: boolean;
  created_at: string | null;
}

// 模块级单例：未读数与下拉列表在铃铛、侧栏、Dashboard 间共享
const unreadCount = ref(0);
const feedItems = ref<InboxItem[]>([]);
const feedLoading = ref(false);
let lastCountFetch = 0;
const COUNT_TTL = 30_000;

function extractData<T>(res: unknown): T | undefined {
  return (res as { data?: T })?.data;
}

export function useInbox() {
  async function fetchUnreadCount(force = false) {
    const now = Date.now();
    if (!force && now - lastCountFetch < COUNT_TTL) return;
    try {
      const res = await clientApi.notificationUnreadCount();
      unreadCount.value = extractData<{ count: number }>(res)?.count ?? 0;
      lastCountFetch = Date.now();
    } catch {
      // 静默失败，避免影响页面
    }
  }

  async function fetchFeed(limit = 10) {
    feedLoading.value = true;
    try {
      const res = await clientApi.notificationFeed(limit);
      const data = extractData<{ list: InboxItem[]; unread_count: number }>(res);
      feedItems.value = data?.list ?? [];
      if (typeof data?.unread_count === 'number') {
        unreadCount.value = data.unread_count;
        lastCountFetch = Date.now();
      }
    } catch {
      feedItems.value = [];
    } finally {
      feedLoading.value = false;
    }
  }

  // 标记单条已读：公告进详情页自动已读，这里主要处理个性化消息
  async function markRead(item: InboxItem) {
    if (item.read) return;
    try {
      if (item.source === 'message' && item.raw_id != null) {
        await clientApi.markNotificationRead(item.raw_id);
      } else if (item.source === 'notice') {
        const noticeId = item.id.replace('notice-', '');
        await clientApi.markNoticeRead(noticeId);
      }
      item.read = true;
      if (unreadCount.value > 0) unreadCount.value--;
    } catch {
      // 静默失败
    }
  }

  async function markAllRead() {
    try {
      await clientApi.markAllNotificationsRead();
      unreadCount.value = 0;
      lastCountFetch = Date.now();
      feedItems.value = feedItems.value.map((item) => ({ ...item, read: true }));
    } catch {
      // 静默失败
    }
  }

  // 公告详情页打开后已读，需重新拉取未读数
  function invalidate() {
    lastCountFetch = 0;
  }

  return {
    unreadCount,
    feedItems,
    feedLoading,
    fetchUnreadCount,
    fetchFeed,
    markRead,
    markAllRead,
    invalidate,
  };
}
