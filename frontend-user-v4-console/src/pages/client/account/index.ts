/**
 * 「账户设置」各页面统一入口。
 *
 * 这几个页面同属一个菜单组、彼此跳转频繁，如果各自`() => import(...)`，
 * 打包器会给每个页面单独出一个 chunk，点一次菜单就下载一次。
 * 改成从同一个模块导出后，打包器会把它们合并进同一个 chunk：
 * 首次进入「账户设置」时一次加载，之后 个人资料 / 账户安全 / 消息提醒 /
 * 实名认证 / API 凭据 之间切换全部命中缓存，无需再次加载。
 *
 * 注意：这里只把「账户设置」这一组页面聚在一起，不影响其它页面按需加载。
 */
export { default as ProfilePage } from '@/pages/client/profile/index.vue';
export { default as VerificationPage } from '@/pages/client/verification/index.vue';
export { default as ApiKeysPage } from '@/pages/client/api-keys/index.vue';
