import { request } from '@/utils/request';

/**
 * 站点公开配置（接口本身无需登录，管理端已登录故复用统一 request 层）。
 *
 * 管理端用它取品牌主色与圆角，好让内部界面和对外官网用同一套品牌视觉。
 * 返回值是后端 SiteConfigResource 的白名单字段，已做严格格式校验，
 * 这里只做透传，不在前端重复校验。
 */
export interface SiteConfigResponse {
  site_name?: string;
  site_logo?: string;
  site_favicon?: string;
  brand_primary_color?: string;
  brand_radius?: string;
  [key: string]: string | undefined;
}

export const siteApi = {
  config: () => request.get<SiteConfigResponse>({ url: '/v2/site/config' }),
};
