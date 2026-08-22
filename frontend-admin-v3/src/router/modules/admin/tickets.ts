import type { RouteRecordRaw } from 'vue-router';

export const ticketsRoutes: RouteRecordRaw[] = [
  {
    path: 'tickets',
    name: 'AdminTickets',
    component: () => import('@/pages/tickets/index.vue'),
    meta: {
      title: {
        zh_CN: '工单列表',
        en_US: 'Tickets',
      },
      permission: 'ticket.list',
    },
  },
  {
    path: 'ticket-delivery-rules',
    name: 'AdminTicketDeliveryRules',
    component: () => import('@/pages/tickets/delivery-settings/index.vue'),
    meta: {
      title: {
        zh_CN: '工单传递设置',
        en_US: 'Ticket Delivery Settings',
      },
      permission: 'ticket.manage',
    },
  },
  {
    path: 'ticket-conversations/:id',
    name: 'AdminTicketConversation',
    component: () => import('@/pages/tickets/detail/index.vue'),
    meta: {
      title: {
        zh_CN: '工单交流',
        en_US: 'Ticket Conversation',
      },
      permission: 'ticket.list',
      hidden: true,
    },
  },
];
