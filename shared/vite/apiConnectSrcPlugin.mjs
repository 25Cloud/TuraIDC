/**
 * 把开发/构建期真实的 API 源站补进 index.html 的 CSP 指令。
 *
 * 背景：三端 index.html 的 CSP 是静态写法，资源类指令统一用 `https:` 兜底
 * （`connect-src 'self' https:`、`img-src 'self' data: blob: https:` 等），只覆盖同源与 https 源站。
 * 开发态 `VITE_API_BASE_URL` 指向 `http://127.0.0.1:8000/api`（http + 跨源），API 请求与它返回的
 * 图片/视频/面板 iframe 都会被 CSP 直接拦掉，表现为登录/列表 "Network Error"、logo 与首屏媒体空白。
 * 生产态 API 是 https，本就被 `https:` 覆盖，因此资源类指令只在源站协议为 http 时补一条精确 origin，
 * 既不把开发地址写进产物，也不放宽到 `http:` 通配。
 *
 * script-src 与资源类指令不同：它没有 `https:` 兜底（放开 `https:` 等于允许任意第三方脚本），
 * 而管理端与控制台的极验脚本可能由后端代理到 API 源站（`/api/v2/client/auth/captcha-script`）。
 * 三端是独立域名、浏览器直连 API，该脚本天然跨源，必须把 API 源站精确写进 script-src；
 * 因此由调用方显式传 `scriptDirective: 'script-src'`，此时 http/https 源站都要补。
 *
 * @param {Record<string, string | undefined>} env loadEnv() 的结果
 * @param {{ placeholder?: string, directives?: string[], scriptDirective?: string }} [options]
 * @returns {import('vite').Plugin}
 */

/** 开发态要补 API 源站的资源类指令；frame-src 覆盖服务面板 iframe */
const DEFAULT_DIRECTIVES = ['connect-src', 'img-src', 'media-src', 'frame-src'];

export function createApiConnectSrcPlugin(env, options = {}) {
  const placeholder = options.placeholder ?? '%VITE_API_BASE_URL%';
  const directives = options.directives ?? DEFAULT_DIRECTIVES;
  const scriptDirective = String(options.scriptDirective || '').trim();

  return {
    name: 'shared-api-connect-src',
    transformIndexHtml: {
      // post：等 vite 先替换完 %VITE_*% 占位，再在最终 policy 上追加
      order: 'post',
      handler(html) {
        const raw = String(env.VITE_API_BASE_URL || '').trim();
        if (!raw || raw === placeholder) {
          return html;
        }

        let origin;
        try {
          origin = new URL(raw, 'http://localhost').origin;
        } catch {
          return html;
        }

        const originIsHttp = origin.startsWith('http://');
        // 生产 https 源站已被 CSP 里的 `https:` 覆盖，资源类指令无需注入；
        // script-src 没有该兜底，即使源站是 https 也要精确补一条。
        if (!originIsHttp && !scriptDirective) {
          return html;
        }

        const marker = 'http-equiv="Content-Security-Policy"';
        const metaIndex = html.indexOf(marker);
        if (metaIndex === -1) {
          return html;
        }

        const contentStart = html.indexOf('content="', metaIndex);
        if (contentStart === -1) {
          return html;
        }
        const valueStart = contentStart + 'content="'.length;
        const valueEnd = html.indexOf('"', valueStart);
        if (valueEnd === -1) {
          return html;
        }

        const policy = html.slice(valueStart, valueEnd);
        let patched = policy;

        if (originIsHttp) {
          for (const directive of directives) {
            const pattern = new RegExp(`(^|;\\s*)${directive}([^;]*)`);
            if (!pattern.test(patched)) {
              continue;
            }
            patched = patched.replace(pattern, (match, lead, targets) =>
              targets.split(/\s+/).includes(origin) ? match : `${lead}${directive}${targets} ${origin}`,
            );
          }
        }

        if (scriptDirective) {
          const scriptPattern = new RegExp(`(^|;\\s*)${scriptDirective}([^;]*)`);
          patched = patched.replace(scriptPattern, (match, lead, targets) =>
            targets.split(/\s+/).includes(origin) ? match : `${lead}${scriptDirective}${targets} ${origin}`,
          );
        }

        if (patched === policy) {
          return html;
        }

        return html.slice(0, valueStart) + patched + html.slice(valueEnd);
      },
    },
  };
}
