import CapCaptchaCard from '@shared/components/CapCaptchaCard.vue';
import { createApp, onScopeDispose, ref } from 'vue';

import { request } from '@/utils/request';

interface GeeTestConfig {
  enabled: boolean;
  captcha_id: string;
  script_url?: string;
  /** 验证码 provider 标识：geetest / vaptcha / cap */
  provider?: string;
  /** Cap 等自托管验证码的前端初始化端点（{server}/{siteId}/） */
  api_endpoint?: string;
}

interface CaptchaInstance {
  onReady?: (callback: () => void) => void;
  onSuccess?: (callback: () => void) => void;
  onError?: (callback: (error: unknown) => void) => void;
  onClose?: (callback: () => void) => void;
  showCaptcha?: () => void;
  validate?: () => unknown;
  getValidate?: () => unknown;
  getVerifyResult?: () => unknown;
  reset?: () => void;
  destroy?: () => void;
}

type GeeTestInitializer = NonNullable<Window['initGeetest4']>;

declare global {
  interface Window {
    initGeetest4?: (options: Record<string, unknown>, callback: (instance: CaptchaInstance) => void) => void;
    __TURA_GEETEST_FALLBACK__?: boolean;
  }
}

const defaultConfig: GeeTestConfig = {
  enabled: false,
  captcha_id: '',
  script_url: 'https://static.geetest.com/v4/gt4.js',
};

let captchaConfigPromise: Promise<GeeTestConfig> | null = null;
let geetestScriptPromise: Promise<GeeTestInitializer> | null = null;

async function getCaptchaConfig() {
  if (!captchaConfigPromise) {
    captchaConfigPromise = request
      .get<GeeTestConfig>({ url: '/v2/client/auth/captcha-config' })
      .then((response: unknown) => {
        const config = { ...defaultConfig, ...(response as GeeTestConfig) };
        if (!config.enabled || !config.captcha_id) {
          throw new Error('人机验证插件未启用或配置不完整');
        }

        return config;
      })
      .catch((error: unknown) => {
        captchaConfigPromise = null;
        const message = error instanceof Error ? error.message : '人机验证配置请求失败';
        throw new Error(`人机验证配置加载失败：${message}`);
      });
  }

  return captchaConfigPromise;
}

function resolveScriptUrl(src: string) {
  const raw = src.trim();
  if (!raw) return '';

  try {
    const apiBaseUrl = String(import.meta.env.VITE_API_BASE_URL || '').trim();
    if (/^\/(?!\/)/.test(raw) && apiBaseUrl) {
      const apiUrl = new URL(apiBaseUrl);
      return `${apiUrl.origin}${raw}`;
    }

    return new URL(raw, apiBaseUrl || window.location.origin).toString();
  } catch {
    return raw;
  }
}

function appendScriptCacheKey(src: string, cacheKey: string) {
  try {
    const url = new URL(src);
    url.searchParams.set('_captcha_key', cacheKey);
    return url.toString();
  } catch {
    return src;
  }
}

function loadGeeTestScript(src: string, cacheKey: string): Promise<GeeTestInitializer> {
  if (typeof window === 'undefined') {
    return Promise.reject(new Error('浏览器环境不可用'));
  }

  const scriptUrl = appendScriptCacheKey(resolveScriptUrl(src), cacheKey);
  if (!scriptUrl) {
    return Promise.reject(new Error('GeeTest 脚本地址为空'));
  }

  const existing = document.querySelector<HTMLScriptElement>('script[data-geetest-script="gt4"]');
  if (existing && existing.dataset.captchaKey !== cacheKey) {
    existing.remove();
    window.initGeetest4 = undefined;
    geetestScriptPromise = null;
  }

  if (window.initGeetest4 && (!existing || existing.dataset.captchaKey === cacheKey)) {
    return Promise.resolve(window.initGeetest4 as GeeTestInitializer);
  }

  if (!geetestScriptPromise) {
    geetestScriptPromise = new Promise<GeeTestInitializer>((resolve, reject) => {
      const script = document.createElement('script');
      script.src = scriptUrl;
      script.async = true;
      script.defer = true;
      script.dataset.geetestScript = 'gt4';
      script.dataset.captchaKey = cacheKey;
      const timeout = window.setTimeout(() => {
        script.remove();
        reject(new Error(`GeeTest 脚本加载超时：${scriptUrl}`));
      }, 15000);
      script.onload = () => {
        window.clearTimeout(timeout);
        if (window.__TURA_GEETEST_FALLBACK__) {
          reject(new Error(`GeeTest 代理脚本不可用：${scriptUrl}`));
          return;
        }

        if (typeof window.initGeetest4 !== 'function') {
          reject(new Error(`GeeTest 脚本未提供初始化方法：${scriptUrl}`));
          return;
        }

        resolve(window.initGeetest4 as GeeTestInitializer);
      };
      script.onerror = () => {
        window.clearTimeout(timeout);
        reject(new Error(`GeeTest 脚本加载失败：${scriptUrl}`));
      };
      document.head.appendChild(script);
    }).catch((error: unknown) => {
      geetestScriptPromise = null;
      const failed = document.querySelector<HTMLScriptElement>('script[data-geetest-script="gt4"]');
      failed?.remove();
      window.initGeetest4 = undefined;
      window.__TURA_GEETEST_FALLBACK__ = false;
      throw error;
    });
  }

  return geetestScriptPromise;
}

/**
 * Cap 验证卡片适配器：以项目统一的 CaptchaInstance 表面暴露 CapCaptchaCard。
 * 无挂载容器时（管理端弹窗测试）悬浮展示于 body。
 * 验证结果统一为 { token }（与后端 GeeTestService::verify 的数组 payload 契约一致）。
 */
function createCapInstance(appendTarget: HTMLElement | string | undefined, apiEndpoint: string): CaptchaInstance {
  let token: string | null = null;
  let successCallback: (() => void) | null = null;
  let errorCallback: ((error: unknown) => void) | null = null;
  let readyCallback: (() => void) | null = null;
  let app: ReturnType<typeof createApp> | null = null;
  let holder: HTMLElement | null = null;

  const unmount = () => {
    if (app) {
      app.unmount();
      app = null;
    }
    holder?.remove();
    holder = null;
  };

  const mount = () => {
    unmount();
    const target = typeof appendTarget === 'string' ? document.querySelector<HTMLElement>(appendTarget) : appendTarget;
    holder = document.createElement('div');
    holder.className = 'cap-card-holder';
    // 容器多为 flex 居中布局，holder 必须占满宽度，否则卡片会被压缩成窄条
    holder.style.width = '100%';
    holder.style.minWidth = '0';
    (target || document.body).appendChild(holder);
    app = createApp(CapCaptchaCard, {
      apiEndpoint,
      floating: !target,
      onSolve: (value: string) => {
        token = value;
        successCallback?.();
      },
      onError: (message: string) => {
        errorCallback?.(new Error(message || 'Cap 人机验证失败，请重试'));
      },
    });
    app.mount(holder);
    readyCallback?.();
  };

  return {
    onReady: (callback: () => void) => {
      readyCallback = callback;
    },
    onSuccess: (callback: () => void) => {
      successCallback = callback;
    },
    onError: (callback: (error: unknown) => void) => {
      errorCallback = callback;
    },
    onClose: () => {},
    showCaptcha: () => {
      if (!holder) {
        mount();
      }
    },
    getValidate: () => (token ? { token } : null),
    reset: () => {
      token = null;
      // 只回退内部状态并卸载，不重新挂载：悬浮模式下重挂会在页面残留一张无法关闭的卡片
      unmount();
    },
    destroy: () => {
      unmount();
      token = null;
    },
  };
}

/**
 * 管理端极验弹窗验证。用于插件测试：真人完成一次行为验证后，
 * 把 getValidate() 结果交给后端走完整验证链路。
 */
export function useGeeTestCaptcha() {
  const loading = ref(false);

  let captchaObj: CaptchaInstance | null = null;
  let initPromise: Promise<CaptchaInstance | null> | null = null;
  let initRejecter: ((error: Error) => void) | null = null;
  let pendingResolver: ((value: unknown) => void) | null = null;
  let pendingRejecter: ((error: Error) => void) | null = null;
  let destroyed = false;

  const clearPending = () => {
    pendingResolver = null;
    pendingRejecter = null;
    loading.value = false;
  };

  const rejectPending = (error: Error) => {
    pendingRejecter?.(error);
    clearPending();
  };

  const readCaptchaResult = (instance: CaptchaInstance) => {
    if (typeof instance.getValidate === 'function') {
      return instance.getValidate();
    }

    if (typeof instance.getVerifyResult === 'function') {
      return instance.getVerifyResult();
    }

    return null;
  };

  const resolveSuccess = (instance: CaptchaInstance) => {
    const result = readCaptchaResult(instance);
    instance.reset?.();
    pendingResolver?.(result);
    clearPending();
  };

  const initCaptcha = async (): Promise<CaptchaInstance | null> => {
    const config = await getCaptchaConfig();

    if (destroyed) {
      throw new Error('行为验证组件已卸载');
    }

    if (!config.enabled || !config.captcha_id) {
      throw new Error('行为验证当前不可用，请检查人机验证插件配置');
    }

    if (captchaObj) {
      return captchaObj;
    }

    if (initPromise) {
      return initPromise;
    }

    const proxyUrl = resolveScriptUrl(config.script_url || '');
    const directUrl = defaultConfig.script_url || '';

    if (config.provider === 'cap') {
      const apiEndpoint = config.api_endpoint || '';
      if (!apiEndpoint) {
        throw new Error('Cap 人机验证配置缺少服务端地址');
      }

      const currentInitPromise = new Promise<CaptchaInstance | null>((resolve, reject) => {
        initRejecter = reject;
        try {
          const instance = createCapInstance(undefined, apiEndpoint);
          captchaObj = instance;
          instance.onReady?.(() => {
            initRejecter = null;
            resolve(instance);
          });
          instance.onSuccess?.(() => resolveSuccess(instance));
          instance.onError?.((error) => {
            rejectPending(new Error(error instanceof Error ? error.message : String(error || '行为验证失败')));
          });
          instance.onClose?.(() => {
            rejectPending(new Error('请先完成行为验证'));
          });
          instance.showCaptcha?.();
        } catch (error) {
          initRejecter = null;
          reject(error instanceof Error ? error : new Error('行为验证初始化失败'));
        }
      });

      initPromise = currentInitPromise;
      try {
        return await currentInitPromise;
      } catch (error) {
        if (initPromise === currentInitPromise) {
          initPromise = null;
        }
        throw error;
      }
    }

    const candidates =
      proxyUrl && proxyUrl !== directUrl
        ? [
            { url: proxyUrl, key: config.captcha_id },
            { url: directUrl, key: `${config.captcha_id}:direct` },
          ]
        : [{ url: directUrl, key: `${config.captcha_id}:direct` }];
    let initGeetest4: typeof window.initGeetest4;
    let lastError: unknown = null;

    for (const candidate of candidates) {
      try {
        initGeetest4 = await loadGeeTestScript(candidate.url, candidate.key);
        if (destroyed) {
          throw new Error('行为验证组件已卸载');
        }
        break;
      } catch (error) {
        lastError = error;
      }
    }

    if (!initGeetest4) {
      throw lastError instanceof Error ? lastError : new Error('GeeTest 脚本加载失败');
    }
    const currentInitPromise = new Promise<CaptchaInstance | null>((resolve, reject) => {
      initRejecter = reject;
      try {
        initGeetest4?.(
          {
            captchaId: config.captcha_id,
            product: 'bind',
            language: 'zho',
          },
          (instance) => {
            if (destroyed) {
              reject(new Error('行为验证组件已卸载'));
              return;
            }

            captchaObj = instance;
            const markReady = () => {
              initRejecter = null;
              resolve(instance);
            };

            if (typeof instance.onReady === 'function') {
              instance.onReady(markReady);
            } else {
              markReady();
            }

            instance.onSuccess?.(() => resolveSuccess(instance));
            instance.onError?.((error) => {
              rejectPending(new Error(error instanceof Error ? error.message : String(error || '行为验证失败')));
            });
            instance.onClose?.(() => {
              rejectPending(new Error('请先完成行为验证'));
            });
          },
        );
      } catch (error) {
        initRejecter = null;
        reject(error instanceof Error ? error : new Error('行为验证初始化失败'));
      }
    });

    initPromise = currentInitPromise;
    try {
      return await currentInitPromise;
    } catch (error) {
      if (initPromise === currentInitPromise) {
        initPromise = null;
      }
      throw error;
    }
  };

  const verify = async () => {
    const instance = await initCaptcha();
    if (!instance) {
      throw new Error('行为验证组件初始化失败，请稍后重试');
    }

    loading.value = true;

    return new Promise((resolve, reject) => {
      pendingResolver = resolve;
      pendingRejecter = reject;

      try {
        if (typeof instance.showCaptcha === 'function') {
          instance.showCaptcha();
        } else if (typeof instance.validate === 'function') {
          Promise.resolve(instance.validate())
            .then(() => resolveSuccess(instance))
            .catch((error: unknown) => {
              rejectPending(new Error(error instanceof Error ? error.message : String(error || '行为验证失败')));
            });
        } else {
          rejectPending(new Error('行为验证组件版本不兼容，请刷新页面后重试'));
        }
      } catch (error) {
        rejectPending(new Error(error instanceof Error ? error.message : '行为验证打开失败'));
      }
    });
  };

  const cleanup = () => {
    destroyed = true;
    initRejecter?.(new Error('行为验证组件已卸载'));
    initRejecter = null;
    if (captchaObj) {
      captchaObj.destroy?.();
      captchaObj = null;
    }
    initPromise = null;
    rejectPending(new Error('行为验证组件已卸载'));
  };

  onScopeDispose(cleanup);

  return {
    loading,
    verify,
  };
}
