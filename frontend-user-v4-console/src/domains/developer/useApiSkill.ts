import { onMounted, ref } from 'vue';

import { apiBaseUrl } from '@/utils/request';

/**
 * AI Skill 接入信息（后端 /v2/site/ai-skill/{slug}/install.json 下发）。
 *
 * 站点地址由后端按 config('app.url') 生成，前端不猜域名：换部署域名后卡片
 * 里的链接会自动跟着变，不会出现「文档指向老域名」的漂移。
 */
export interface AiSkillInstallInfo {
  slug: string;
  version: string;
  skill_url: string;
  manifest_url: string;
  api_base_url: string;
  required_scopes: string[];
  commands: {
    install_by_url: string;
    curl: string;
  };
}

/**
 * 公开只读端点，不经过 axios 实例：它没有登录态，也不该被 401/风控拦截器接管。
 * 拿不到就静默隐藏整块卡片——密钥管理是主流程，不能被附属信息拖垮。
 */
export function useApiSkill(slug = 'turaidc') {
  const info = ref<AiSkillInstallInfo | null>(null);
  const loading = ref(false);

  async function load() {
    loading.value = true;

    try {
      const response = await fetch(`${apiBaseUrl}/v2/site/ai-skill/${slug}/install.json`, {
        headers: { Accept: 'application/json' },
      });

      if (!response.ok) {
        info.value = null;

        return;
      }

      const body = (await response.json()) as { data?: AiSkillInstallInfo };
      info.value = body?.data ?? null;
    } catch {
      info.value = null;
    } finally {
      loading.value = false;
    }
  }

  onMounted(load);

  return { info, loading, reload: load };
}
