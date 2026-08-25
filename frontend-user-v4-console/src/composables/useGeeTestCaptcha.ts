import type { CapCaptchaLabels } from '@shared/components/CapCaptchaCard.vue';
import CapCaptchaCard from '@shared/components/CapCaptchaCard.vue';
import { createApp, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { clientAuthApi } from '@/api/auth';
import { resolveApiProxyUrl } from '@/utils/apiOrigin';

interface GeeTestConfig {
  enabled: boolean;
  captcha_id: string;
  script_url?: string;
  /** 当前生效的验证码提供商（geetest / vaptcha / corptcha / cap / turnstile ...） */
  provider?: string;
  /** popup：插件自行弹窗；inline：需页面在提交按钮上方提供容器 */
  render_mode?: string;
  /** 适配层脚本的配置指纹，用作脚本缓存键 */
  script_version?: string;
  /** Cap 等自托管验证码的前端初始化端点（{server}/{siteId}/） */
  api_endpoint?: string;
  /** 各场景开关：场景标识 => 是否需要人机验证 */
  scenes?: Record<string, boolean>;
}

/** 后端 CaptchaPolicyService 的场景标识 */
export type CaptchaScene = 'client_login' | 'client_register' | 'admin_login' | 'email_code' | 'phone_code';

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

declare global {
  interface Window {
    initGeetest4?: (options: Record<string, unknown>, callback: (instance: CaptchaInstance) => void) => void;
  }
}

let captchaConfigPromise: Promise<GeeTestConfig> | null = null;
let geetestScriptPromise: Promise<typeof window.initGeetest4> | null = null;

const defaultConfig: GeeTestConfig = {
  enabled: false,
  captcha_id: '',
  script_url: 'https://static.geetest.com/v4/gt4.js',
};

const captchaErrorMessages = new Map<string, string>([
  ['verification closed', '请先完成行为验证'],
  ['verification timeout', '行为验证超时，请重试'],
  ['verification_failed', '行为验证失败，请重试'],
  ['verification failed', '行为验证失败，请重试'],
  ['vaptcha is validating', '行为验证正在进行中，请稍后重试'],
  ['vaptcha initialization failed', '行为验证初始化失败，请稍后重试'],
  ['failed to load vaptcha core', '行为验证组件加载失败，请稍后重试'],
]);

function normalizeCaptchaError(error: unknown, fallback = '行为验证失败，请重试') {
  const rawMessage = error instanceof Error ? error.message : String(error || '');
  const message = rawMessage.trim();

  if (!message) {
    return new Error(fallback);
  }

  const normalized = message.toLowerCase();
  const mappedMessage = captchaErrorMessages.get(normalized);
  if (mappedMessage) {
    return new Error(mappedMessage);
  }

  if (/^\p{ASCII}+$/u.test(message) && /verification|vaptcha|geetest/i.test(message)) {
    return new Error(fallback);
  }

  return error instanceof Error ? error : new Error(message);
}

async function getCaptchaConfig() {
  if (!captchaConfigPromise) {
    // 显式标注类型：下面的 catch 回调引用了 pending 自身（用于判断是否仍是最新一次请求），
    // 不标注会让 TS 陷入循环推断（TS7011）。
    const pending: Promise<GeeTestConfig> = clientAuthApi
      .captchaConfig()
      .then((response: any) => response.data || defaultConfig)
      .catch(() => {
        // 失败绝不能留缓存。captchaConfigPromise 是模块级单例，一旦把 defaultConfig
        // （enabled: false）固化进去，本页此后永久不唤起验证码，而后端 requiresCaptcha
        // 仍返回 true 并以 42220 / captcha_required 拒绝提交——用户提交必然失败，
        // 且 reinit() 复用同一个已缓存 promise 也无法自恢复，只能整页刷新。
        // 清掉缓存后本次调用仍降级为 defaultConfig（不阻塞 UI），但下次会重新拉取。
        if (captchaConfigPromise === pending) {
          captchaConfigPromise = null;
        }

        return defaultConfig;
      });

    captchaConfigPromise = pending;
  }

  return captchaConfigPromise;
}

function appendScriptCacheKey(src: string, cacheKey: string) {
  if (!cacheKey) {
    return src;
  }

  try {
    const url = new URL(src, window.location.href);
    url.searchParams.set('_captcha_key', cacheKey);
    return url.toString();
  } catch {
    return src;
  }
}

type CaptchaAppendTarget =
  string | HTMLElement | { value?: string | HTMLElement | null | undefined } | null | undefined;

function resolveAppendTarget(target: CaptchaAppendTarget): string | HTMLElement | undefined {
  if (target && typeof target === 'object' && 'value' in target) {
    return resolveAppendTarget((target as { value?: string | HTMLElement | null | undefined }).value);
  }

  if (typeof target === 'string' || target instanceof HTMLElement) {
    return target;
  }

  return undefined;
}

function loadGeeTestScript(src: string, cacheKey = '') {
  if (typeof window === 'undefined') {
    throw new TypeError('浏览器环境不可用');
  }

  const scriptKey = cacheKey || src;
  const existing = document.querySelector<HTMLScriptElement>('script[data-geetest-script="gt4"]');

  if (existing && existing.dataset.captchaKey !== scriptKey) {
    existing.remove();
    window.initGeetest4 = undefined;
    geetestScriptPromise = null;
  }

  if (window.initGeetest4 && (!existing || existing.dataset.captchaKey === scriptKey)) {
    return Promise.resolve(window.initGeetest4);
  }

  if (!geetestScriptPromise) {
    geetestScriptPromise = new Promise((resolve, reject) => {
      const current = document.querySelector<HTMLScriptElement>('script[data-geetest-script="gt4"]');
      if (current) {
        current.addEventListener('load', () => resolve(window.initGeetest4), { once: true });
        current.addEventListener('error', () => reject(new Error('GeeTest 脚本加载失败')), { once: true });
        return;
      }

      const script = document.createElement('script');
      script.src = appendScriptCacheKey(src, scriptKey);
      script.async = true;
      script.defer = true;
      script.dataset.geetestScript = 'gt4';
      script.dataset.captchaKey = scriptKey;
      script.onload = () => resolve(window.initGeetest4);
      script.onerror = () => reject(new Error('GeeTest 脚本加载失败'));
      document.head.appendChild(script);
    });
  }

  return geetestScriptPromise;
}

/**
 * Cap 验证卡片适配器：以项目统一的 CaptchaInstance 表面暴露 CapCaptchaCard。
 * 验证结果统一为 { token }（与后端 GeeTestService::verify 的数组 payload 契约一致）。
 */
function createCapInstance(
  appendTarget: HTMLElement | string | undefined,
  apiEndpoint: string,
  labels: CapCaptchaLabels,
): CaptchaInstance {
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
    // 无挂载容器时明确报错：静默返回会让 initPromise 永久 pending，登录/发码按钮无响应
    if (!target) {
      throw new Error('未找到人机验证挂载容器');
    }

    holder = document.createElement('div');
    holder.className = 'cap-card-holder';
    // 容器多为 flex 居中布局，holder 必须占满宽度，否则卡片会被压缩成窄条
    holder.style.width = '100%';
    holder.style.minWidth = '0';
    target.appendChild(holder);
    app = createApp(CapCaptchaCard, {
      apiEndpoint,
      labels,
      onSolve: (value: string) => {
        token = value;
        successCallback?.();
      },
      onError: (message: string) => {
        errorCallback?.(new Error(message || labels.error || 'Cap 人机验证失败，请重试'));
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
      // 只回退内部状态并卸载，不重新挂载：下次 showCaptcha 会按需重建
      unmount();
    },
    destroy: () => {
      unmount();
      token = null;
    },
  };
}

export function useGeeTestCaptcha(options: Record<string, unknown> = {}) {
  const { t } = useI18n();

  /**
   * 本页面涉及的场景是否还需要人机验证。
   *
   * 一个页面可能横跨多个场景（登录页既有密码登录、又有验证码登录的发码动作），
   * 因此只要其中任一场景仍开启，就保留验证组件——宁可多验一次，也不能出现
   * 「前端不验、后端要求」的死循环。
   *
   * 后端未下发 scenes 时（旧版本）用 !== false 保持「插件启用即验证」的旧行为，
   * 不因字段缺失而静默关掉验证。
   */
  const sceneRequired = (config: GeeTestConfig) => {
    const scenes = Array.isArray(options.scenes) ? (options.scenes as string[]) : [];
    if (scenes.length === 0) {
      return true;
    }

    return scenes.some((scene) => config.scenes?.[scene] !== false);
  };

  // 最近一次解析到的配置。verify() 需要按「当前动作的场景」而非「本页任一场景」判定，
  // 否则页面横跨多个场景时开关会失去独立性：只开 client_login 也会让发码要求验证。
  let lastConfig: GeeTestConfig | null = null;

  /**
   * 单个场景当前是否要求验证。
   *
   * 用 !== false 而非 === true：后端未下发 scenes 时（旧版本）保持「插件启用即验证」，
   * 不因字段缺失而静默关掉验证。
   */
  const sceneActive = (scene: string) => {
    if (!scene) {
      return true;
    }

    return lastConfig?.scenes?.[scene] !== false;
  };

  const loading = ref(false);
  const ready = ref(false);
  const enabled = ref(false);
  const initialized = ref(false);
  // popup：插件自行弹窗（极验）；inline：需页面在提交按钮上方提供容器（Turnstile）
  const renderMode = ref<'popup' | 'inline'>('popup');

  /** Cap 状态文案随当前界面语言注入，避免英文界面显示中文 */
  const capLabels: CapCaptchaLabels = {
    idle: t('components.captcha.clickToVerify'),
    verifying: t('components.captcha.verifying'),
    solved: t('components.captcha.solved'),
    error: t('components.captcha.failed'),
  };

  let captchaObj: CaptchaInstance | null = null;
  let initPromise: Promise<CaptchaInstance | null> | null = null;
  let pendingResolver: ((value: unknown) => void) | null = null;
  let pendingRejecter: ((error: Error) => void) | null = null;
  // 内嵌组件预先完成的验证结果（一次性消费）：无挂起动作时保留，按钮动作触发时取出并重置组件
  let verifiedResult: unknown = null;
  let componentUnmounted = false;

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

    if (pendingResolver) {
      // 有挂起动作（按钮触发）：一次性消费并重置组件，保证 token 单次使用语义
      pendingResolver(result);
      instance.reset?.();
      clearPending();
      return;
    }

    // 无挂起动作（内嵌组件预先完成）：保留结果，不重置组件，避免"回退到未认证状态"
    verifiedResult = result;
  };

  const openCaptcha = (instance: CaptchaInstance) => {
    if (typeof instance.showCaptcha === 'function') {
      instance.showCaptcha();
      return;
    }

    if (typeof instance.validate === 'function') {
      Promise.resolve(instance.validate())
        .then(() => resolveSuccess(instance))
        .catch((error: unknown) => {
          rejectPending(normalizeCaptchaError(error));
        });
      return;
    }

    rejectPending(new Error('行为验证组件版本不兼容，请刷新页面后重试'));
  };

  /**
   * 只拉取配置，决定「本场景是否需要验证」与「组件渲染形态」，不加载任何验证 SDK。
   *
   * 页面挂载时只做到这一步：验证脚本要等用户真的点击提交才加载，
   * 避免每次打开登录/注册页都去拉第三方 SDK。
   */
  // 显式标注返回类型：verify() 内 await resolveConfig().catch(() => null) 会与本函数的
  // 推断构成循环（TS7011），标注后打断。
  const resolveConfig = async (): Promise<GeeTestConfig | null> => {
    if (componentUnmounted) {
      return null;
    }

    const config = await getCaptchaConfig();
    lastConfig = config;
    enabled.value = Boolean(config.enabled && config.captcha_id) && sceneRequired(config);
    renderMode.value = config.render_mode === 'inline' ? 'inline' : 'popup';
    initialized.value = true;

    return config;
  };

  const initCaptcha = async () => {
    const config = await resolveConfig();
    if (!config || !enabled.value) {
      return null;
    }

    if (captchaObj) {
      return captchaObj;
    }

    if (initPromise) {
      return initPromise;
    }

    // 容器用 v-show 控制显隐：等 DOM 更新（display 恢复）后再渲染组件，
    // 避免 widget 渲染进 display:none 容器导致不可见或尺寸测量为 0。
    await nextTick();

    const scriptUrl = resolveApiProxyUrl(
      config.script_url || defaultConfig.script_url || '',
      import.meta.env.VITE_API_BASE_URL,
    );

    if (config.provider === 'cap') {
      const apiEndpoint = config.api_endpoint || '';
      if (!apiEndpoint) {
        throw new Error('Cap 人机验证配置缺少服务端地址');
      }

      const appendTarget = resolveAppendTarget((options.appendTo ?? options.container) as CaptchaAppendTarget);
      const currentInitPromise = new Promise<CaptchaInstance | null>((resolve, reject) => {
        try {
          const instance = createCapInstance(appendTarget, apiEndpoint, capLabels);
          captchaObj = instance;
          instance.onReady?.(() => {
            ready.value = true;
            resolve(instance);
          });
          instance.onSuccess?.(() => resolveSuccess(instance));
          instance.onError?.((error) => {
            rejectPending(normalizeCaptchaError(error));
          });
          instance.onClose?.(() => {
            rejectPending(new Error('请先完成行为验证'));
          });
          instance.showCaptcha?.();
        } catch (error) {
          // 挂载失败（如容器未就绪）：清理实例并复位，允许后续重试
          captchaObj?.destroy?.();
          captchaObj = null;
          reject(normalizeCaptchaError(error, '行为验证初始化失败，请稍后重试'));
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

    // 缓存键优先用配置指纹：脚本内容随插件配置变化，仅用 captcha_id 会让改动 12 小时不生效
    const initGeetest4 = await loadGeeTestScript(scriptUrl, config.script_version || config.captcha_id);
    // 只有 inline 形态（Turnstile）才需要页面提供容器；
    // popup 形态（极验等）交给插件自己弹窗，传容器反而会让它内联展开。
    const appendTarget =
      renderMode.value === 'inline'
        ? resolveAppendTarget((options.appendTo ?? options.container) as CaptchaAppendTarget)
        : undefined;
    const currentInitPromise = new Promise<CaptchaInstance | null>((resolve, reject) => {
      // 初始化是否已落地。验证组件的 SDK 由插件适配层内部异步加载（点击提交时才加载），
      // 加载失败/超时只会以 onError 事件的形式抛出来。若不在这里把它转成 initPromise 的
      // reject，调用方就会一直 await 一个永不落地的 Promise——表现为提交按钮无限转圈。
      let initSettled = false;

      try {
        initGeetest4?.(
          {
            captchaId: config.captcha_id,
            product: 'bind',
            language: 'zho',
            ...options,
            ...(appendTarget ? { appendTo: appendTarget, container: appendTarget } : {}),
          },
          (instance) => {
            captchaObj = instance;
            const markReady = () => {
              initSettled = true;
              ready.value = true;
              resolve(instance);
            };

            if (typeof instance.onReady === 'function') {
              instance.onReady(markReady);
            } else {
              markReady();
            }

            instance.onSuccess?.(() => resolveSuccess(instance));
            instance.onError?.((error) => {
              const normalized = normalizeCaptchaError(error);

              if (!initSettled) {
                // 尚未就绪就报错（典型是 SDK 加载超时）：让初始化本身失败，
                // 并丢弃缓存的实例与 promise，下次提交可以重新尝试加载。
                initSettled = true;
                captchaObj = null;
                initPromise = null;
                ready.value = false;
                reject(normalized);

                return;
              }

              rejectPending(normalized);
            });
            instance.onClose?.(() => {
              rejectPending(new Error('请先完成行为验证'));
            });
          },
        );
      } catch (error) {
        initSettled = true;
        reject(normalizeCaptchaError(error, '行为验证初始化失败，请稍后重试'));
      }
    });

    initPromise = currentInitPromise;

    try {
      return await currentInitPromise;
    } catch (error) {
      // 失败的初始化不留缓存，否则后续每次提交都会拿到同一个失败的 promise
      if (initPromise === currentInitPromise) {
        initPromise = null;
      }

      throw error;
    }
  };

  const verify = async ({ required = false, scene = '' } = {}) => {
    // 先解析配置再判场景：该场景已关闭时不必加载第三方 SDK。
    // required=true 表示后端已经以 captcha_required 明确索要验证，后端口径优先，不跳过。
    if (scene && !required) {
      try {
        await resolveConfig();
      } catch {
        // 配置拉取失败不在这里处理：不缓存失败结果（见 getCaptchaConfig），
        // 后续 initCaptcha 会走既有的失败路径给出可读提示。
      }

      if (!sceneActive(scene)) {
        return null;
      }
    }

    const instance = await initCaptcha();
    if (!enabled.value) {
      if (required) {
        throw new Error('行为验证当前不可用，请稍后重试');
      }
      return null;
    }

    if (!instance || !ready.value) {
      throw new Error('行为验证组件初始化中，请稍后重试');
    }

    // 已有预完成结果：直接复用（取出 + 清空 + 重置组件，保证 token 单次使用语义）
    if (verifiedResult !== null && verifiedResult !== undefined) {
      const result = verifiedResult;
      verifiedResult = null;
      instance.reset?.();
      return result;
    }

    // inline 形态：组件已渲染在表单容器内，用户直接点组件完成挑战。
    // 未完成验证时只提示，不弹窗、不挂起 loading，避免提交按钮无限转圈。
    if (renderMode.value === 'inline') {
      if (typeof options.onPrompt === 'function') {
        options.onPrompt();
      }
      const error = new Error('请先完成人机验证') as Error & { __handled?: boolean };
      error.__handled = true;
      throw error;
    }

    loading.value = true;

    return new Promise((resolve, reject) => {
      pendingResolver = resolve;
      pendingRejecter = reject;

      // 未完成验证：先提示用户"请先完成人机验证"再唤起组件
      if (typeof options.onPrompt === 'function') {
        options.onPrompt();
      }

      try {
        openCaptcha(instance);
      } catch (error) {
        rejectPending(normalizeCaptchaError(error, '行为验证打开失败'));
      }
    });
  };

  /**
   * 带人机验证执行一次动作。
   *
   * @param callback 拿到验证结果后要执行的动作
   * @param options 验证选项
   * @param options.required 后端已以 captcha_required 明确索要验证，不跳过
   * @param options.scene 本次动作对应的**单一**场景（client_login / email_code / phone_code …）。
   *                      按该场景的开关判定，避免同页多场景互相牵连。
   */
  const runWithCaptcha = async <T>(
    callback: (captcha: unknown) => Promise<T>,
    options: { required?: boolean; scene?: string } = {},
  ) => {
    const captcha = await verify(options);
    return callback(captcha);
  };

  const reinit = async () => {
    // 组件销毁时清空预完成结果（旧 token 失效）
    verifiedResult = null;
    captchaObj?.destroy?.();
    captchaObj = null;
    initPromise = null;
    ready.value = false;
    await resolveConfig().catch(() => {
      initialized.value = true;
    });
  };

  /**
   * 页面加载后主动初始化并渲染验证组件（inline 形态提前展示，可提前完成挑战）。
   * 仅启用且为 inline 时执行；初始化失败静默忽略，不阻断页面。
   */
  const prepare = async () => {
    if (componentUnmounted) {
      return;
    }

    try {
      await initCaptcha();
    } catch {
      // 加载失败不打扰用户，点击提交时仍会走 verify() 的完整错误提示
    }
  };

  // 挂载时只拉配置，不加载验证 SDK——SDK 在用户点击提交时（verify）才加载
  onMounted(() => {
    resolveConfig().catch(() => {
      initialized.value = true;
    });
  });

  onBeforeUnmount(() => {
    componentUnmounted = true;
    verifiedResult = null;
    rejectPending(new Error('行为验证已取消'));
    captchaObj?.destroy?.();
  });

  return {
    enabled,
    initialized,
    loading,
    ready,
    renderMode,
    verify,
    runWithCaptcha,
    reinit,
    prepare,
  };
}
