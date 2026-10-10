import { reactive, ref } from 'vue';

import type { ClientAgentGroup, ClientNotificationPreferences } from '@/types/client';

export type NotificationKey = keyof ClientNotificationPreferences;
export interface NotificationItem {
  key: NotificationKey;
  name: string;
  desc: string;
  enabled: boolean;
}

// ---------------------------------------------------------------------------
// 账户设置的共享状态（模块级单例）
//
// 「个人资料 / 账户安全 / 消息提醒」三个路由虽然共用同一个页面组件，但跳去
// 「实名认证」等其它页面时组件会被卸载，回来时重新执行 setup，数据就会被清空。
// 这里把数据提到独立模块作用域，只要不手动刷新页面就一直沿用。
//
// 该模块不依赖 store，因此 store 可以在清理会话时直接调用 resetProfileState，
// 避免出现「退出登录后上个账号资料残留」的问题。
// ---------------------------------------------------------------------------

export const profileForm = reactive({
  id: '',
  email: '',
  nickname: '',
  phone: '',
  qq: '',
  cash_balance: '0.00',
  createdAt: '',
  is_verified: 0,
  real_name: '',
  id_card_masked: '',
});

export const agentGroup = ref<ClientAgentGroup | null>(null);

export const notificationList = reactive<NotificationItem[]>([
  {
    key: 'login_notify',
    name: '账号登录提醒',
    desc: '每次账户成功登录后，向绑定邮箱发送登录安全提醒。',
    enabled: false,
  },
  {
    key: 'login_location_alert',
    name: '异地登录提醒',
    desc: '检测到新的登录 IP 环境时，额外发送一次异地登录风险提醒。',
    enabled: false,
  },
  {
    key: 'password_change_alert',
    name: '更改密码提醒',
    desc: '账户密码修改成功后，立即发送安全提醒邮件。',
    enabled: false,
  },
  {
    key: 'phone_change_alert',
    name: '更改手机号提醒',
    desc: '安全手机号发生变更时，及时发送变更提醒。',
    enabled: false,
  },
  {
    key: 'email_change_alert',
    name: '更改邮箱提醒',
    desc: '安全邮箱发生变更时，向原邮箱和新邮箱发送提醒。',
    enabled: false,
  },
  { key: 'marketing_alert', name: '营销提醒接收', desc: '接收产品更新、活动优惠和运营消息。', enabled: false },
]);

/** 是否已经拉取过数据：只首次进入时请求接口，之后路由来回切换直接复用 */
let profileDataLoaded = false;

export function isProfileDataLoaded() {
  return profileDataLoaded;
}

export function setProfileDataLoaded(loaded: boolean) {
  profileDataLoaded = loaded;
}

/** 退出登录 / 会话失效 / 切换账号时清空共享数据 */
export function resetProfileState() {
  profileForm.id = '';
  profileForm.email = '';
  profileForm.nickname = '';
  profileForm.phone = '';
  profileForm.qq = '';
  profileForm.cash_balance = '0.00';
  profileForm.createdAt = '';
  profileForm.is_verified = 0;
  profileForm.real_name = '';
  profileForm.id_card_masked = '';
  agentGroup.value = null;
  notificationList.forEach((item) => {
    item.enabled = false;
  });
  profileDataLoaded = false;
}
