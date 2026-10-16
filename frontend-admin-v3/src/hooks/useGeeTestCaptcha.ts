import type { CapCaptchaLabels } from '@shared/components/CapCaptchaCard.vue';
import CapCaptchaCard from '@shared/components/CapCaptchaCard.vue';
import { createApp, nextTick, onMounted, onScopeDispose, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { request } from '@/utils/request';
import { t } from '@/locales';

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
/** 最近一次配置请求的失败原因，供初始化路径区分「接口失败」与「插件未启用」两种错误分支 */
let captchaConfigLoadError: unknown = null;

/**
 * 拉取人机验证配置——全模块唯一的配置请求入口。
 *
 * 这里**永不抛错**：插件未配置、接口失败都归一为「不需要验证」。登录页必须这样——
 * 验证码配置出问题不应该把管理员挡在后台之外，后端在插件未启用时同样不会要求验证，
 * 两侧语义一致。需要区分失败原因时读 captchaConfigLoadError。
 *
 * 此前探测与初始化各持一个模块级 promise，同一接口在一次登录页打开过程中被打了两遍，
 * 且两段串行往返都排在首屏之后。现在合并为单例：一次打开只请求一次，且不再阻塞首屏。
 *
 * 失败后必须清缓存：captchaConfigPromise 是模块级单例，一旦把 defaultConfig
 * （enabled: false）固化进去，本页此后永久不唤起验证码，而后端仍会要求验证并拒绝提交，
 * 且后续调用复用同一个已缓存 promise 无法自恢复，只能整页刷新。
 */
function loadCaptchaConfig(): Promise<GeeTestConfig> {
  if (!captchaConfigPromise) {
    // 显式标注类型：下面的 catch 回调引用了 pending 自身（判断是否仍是最新一次请求），
    // 不标注会让 TS 陷入循环推断（TS7011）。
    const pending: Promise<GeeTestConfig> = request
      .get<GeeTestConfig>({ url: '/v2/client/auth/captcha-config' })
      .then((response: unknown) => {
        captchaConfigLoadError = null;

        return { ...defaultConfig, ...(response as GeeTestConfig) };
      })
      .catch((error: unknown) => {
        captchaConfigLoadError = error;
        if (captchaConfigPromise === pending) {
          captchaConfigPromise = null;
        }

        return defaultConfig;
      });

    captchaConfigPromise = pending;
  }

  return captchaConfigPromise;
}

/** 由配置推导「是否可用 / 某场景是否要求验证 / 渲染形态」 */
function deriveCaptchaState(config: GeeTestConfig, scene: CaptchaScene) {
  const enabled = Boolean(config.enabled && config.captcha_id);

  return {
    enabled,
    required: enabled && config.scenes?.[scene] === true,
    renderMode: config.render_mode === 'inline' ? ('inline' as const) : ('popup' as const),
  };
}

type CaptchaAppendTarget = string | HTMLElement | { value?: string | HTMLElement | null } | null | undefined;

/** appendTo 允许直接传 ref，这里统一解引用 */
function resolveAppendTarget(target: CaptchaAppendTarget): string | HTMLElement | undefined {
  if (target && typeof target === 'object' && 'value' in target) {
    return resolveAppendTarget((target as { value?: string | HTMLElement | null }).value);
  }

  if (typeof target === 'string' || target instanceof HTMLElement) {
    return target;
  }

  return undefined;
}

/**
 * 初始化组件用的配置读取：与 loadCaptchaConfig 共用同一份缓存，不产生第二次请求。
 * 与 loadCaptchaConfig 的区别是这里**会抛错**——渲染组件必须有真实配置兜底，
 * 否则会把「配置没到」渲染成一个永远不会出现的空容器。
 */
async function getCaptchaConfig(): Promise<GeeTestConfig> {
  const config = await loadCaptchaConfig();

  if (captchaConfigLoadError) {
    const message =
      captchaConfigLoadError instanceof Error ? captchaConfigLoadError.message : t('captcha.config.loadFailed');
    throw new Error(`人机验证配置加载失败：${message}`);
  }

  if (!config.enabled || !config.captcha_id) {
    // 插件未启用时不留缓存：管理端可能在同一次会话里刚把插件打开，下次调用要能重新读到
    captchaConfigPromise = null;
    throw new Error('人机验证插件未启用或配置不完整');
  }

  return config;
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
    holder = document.createElement('div');
    holder.className = 'cap-card-holder';
    // 容器多为 flex 居中布局，holder 必须占满宽度，否则卡片会被压缩成窄条
    holder.style.width = '100%';
    holder.style.minWidth = '0';
    (target || document.body).appendChild(holder);
    app = createApp(CapCaptchaCard, {
      apiEndpoint,
      floating: !target,
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
export interface GeeTestCaptchaOptions extends Record<string, unknown> {
  /** 后端场景标识（CaptchaPolicyService），决定 scenes[scene] 是否要求验证 */
  scene?: CaptchaScene;
  /** inline 形态的挂载容器，可直接传 ref */
  appendTo?: CaptchaAppendTarget;
  /** appendTo 的兼容别名 */
  container?: CaptchaAppendTarget;
  /** inline 形态下用户尚未完成验证时的提示回调 */
  onPrompt?: () => void;
  /**
   * 是否在组件挂载后自动预热，默认 false。
   *
   * 只有「首屏就要展示验证码」的页面才应打开：登录页需要它来并行首屏与验证码初始化。
   * 插件配置页的自测按钮不需要——那里预加载等于每次进页面都白拉一次外网 SDK。
   */
  autoPrepare?: boolean;
}

/**
 * 管理端人机验证 hook。
 *
 * @param options 透传给验证组件的初始化参数。传 appendTo（可为 ref）即改为内联渲染，
 *                不传则沿用弹窗模式——插件配置页的自测按钮用的就是弹窗模式。
 */
export function useGeeTestCaptcha(options: GeeTestCaptchaOptions = {}) {
  const { t } = useI18n();
  /** 管理端默认场景即管理员登录；插件自测页不关心 required，用默认值无副作用 */
  const scene = options.scene || 'admin_login';
  const loading = ref(false);
  // 以下状态全部由配置驱动：页面不必先 await 探测接口再决定要不要渲染验证码区域
  /** 配置是否可用（enabled 且 captcha_id 完整） */
  const enabled = ref(false);
  /** 当前场景是否要求人机验证；配置拿不到时为 false（降级放行，与后端语义一致） */
  const required = ref(false);
  /** popup：插件自行弹窗；inline：渲染进页面提供的容器 */
  const renderMode = ref<'popup' | 'inline'>('popup');
  /** 配置是否已解析完成（无论成功失败），页面据此决定要不要继续占位 */
  const initialized = ref(false);
  /** 验证组件是否已渲染就绪 */
  const ready = ref(false);
  /** 最近一次初始化/校验失败原因，供页面提供重试入口，避免验证码区域永久空白 */
  const initError = ref('');

  /** Cap 状态文案随当前界面语言注入（当前仅简体中文，预留扩展） */
  const capLabels: CapCaptchaLabels = {
    idle: t('components.captcha.clickToVerify'),
    verifying: t('components.captcha.verifying'),
    solved: t('components.captcha.solved'),
    error: t('components.captcha.failed'),
  };

  let captchaObj: CaptchaInstance | null = null;
  let initPromise: Promise<CaptchaInstance | null> | null = null;
  let initRejecter: ((error: Error) => void) | null = null;
  let pendingResolver: ((value: unknown) => void) | null = null;
  let pendingRejecter: ((error: Error) => void) | null = null;
  let destroyed = false;
  // 当前渲染形态：popup（极验弹窗）/ inline（页面容器内联组件，如 Corptcha）
  let currentRenderMode: 'popup' | 'inline' = 'popup';
  // 内联组件在无人等待时预先完成的验证结果，一次性消费
  let verifiedResult: unknown = null;

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

    // 无挂起动作：内联渲染的组件会自行完成验证（Turnstile 即为此类），
    // 此时必须保留结果且不重置——否则 reset 会让组件重新解题，
    // 形成「解出 → 丢弃 → 重置 → 再解出」的死循环。
    verifiedResult = result;
  };

  /** 把配置结果写进响应式状态：页面读的 required / renderMode 与实际渲染形态始终一致 */
  const applyCaptchaState = (config: GeeTestConfig) => {
    const state = deriveCaptchaState(config, scene);
    enabled.value = state.enabled;
    required.value = state.required;
    renderMode.value = state.renderMode;
    initialized.value = true;

    return config;
  };

  /** 解析配置并同步状态；永不抛错，失败降级为「不要求验证」 */
  const resolveConfig = () => loadCaptchaConfig().then(applyCaptchaState);

  /** 提交前的兜底：配置尚未回来时先等它落地，避免把「未知」当成「不需要验证」而漏带 captcha */
  const ensureConfig = () => (initialized.value ? Promise.resolve() : resolveConfig());

  const initCaptcha = async (): Promise<CaptchaInstance | null> => {
    const config = applyCaptchaState(await getCaptchaConfig());

    if (destroyed) {
      throw new Error('行为验证组件已卸载');
    }

    // 容器由页面常驻渲染（不再等配置回来才 display），这里仍等一次 DOM 更新，
    // 确保 appendTo 传的是 ref 时组件已解引用到真实元素
    await nextTick();

    currentRenderMode = config.render_mode === 'inline' ? 'inline' : 'popup';
    const appendTarget =
      currentRenderMode === 'inline'
        ? resolveAppendTarget((options.appendTo ?? options.container) as CaptchaAppendTarget)
        : undefined;

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
          const instance = createCapInstance(appendTarget, apiEndpoint, capLabels);
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

    // 缓存键优先用配置指纹：脚本内容随插件配置变化，仅用 captcha_id 会让改动 12 小时不生效
    const scriptKey = config.script_version || config.captcha_id;

    const candidates =
      proxyUrl && proxyUrl !== directUrl
        ? [
            { url: proxyUrl, key: scriptKey },
            { url: directUrl, key: `${scriptKey}:direct` },
          ]
        : [{ url: directUrl, key: `${scriptKey}:direct` }];
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
            ...options,
            // appendTo / container 同时给出，兼容各插件适配层的不同取名
            ...(appendTarget ? { appendTo: appendTarget, container: appendTarget } : {}),
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
              const normalized = new Error(error instanceof Error ? error.message : String(error || '行为验证失败'));

              // 尚未就绪就报错（典型是 SDK 加载超时）：让初始化本身失败。
              // 验证组件的 SDK 由适配层内部异步加载，加载失败只会以 onError 抛出；
              // 不在这里 reject 的话，调用方会一直 await 一个永不落地的 promise。
              if (initRejecter) {
                const rejectInit = initRejecter;
                initRejecter = null;
                captchaObj = null;
                initPromise = null;
                rejectInit(normalized);

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
    // 提交路径同样要先把配置落地：required 由配置驱动，未解析完就提交会漏带 captcha
    await ensureConfig();

    const instance = await initCaptcha();
    if (!instance) {
      throw new Error('行为验证组件初始化失败，请稍后重试');
    }
    ready.value = true;

    // 组件已自行完成验证：取出结果并重置，同样保证 token 只用一次
    if (verifiedResult !== null && verifiedResult !== undefined) {
      const result = verifiedResult;
      verifiedResult = null;
      instance.reset?.();

      return result;
    }

    // inline 形态：组件已渲染在表单容器内，用户直接点组件完成挑战。
    // 未完成验证时只提示，不弹窗、不挂起 loading，避免提交按钮无限转圈。
    if (currentRenderMode === 'inline') {
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
        rejectPending(new Error(error instanceof Error ? error.message : t('captcha.openFailed')));
      }
    });
  };

  /**
   * 主动初始化并渲染验证组件（inline 形态提前展示，可提前完成挑战）。
   * 失败不阻断首屏，但会记下原因供页面给出重试入口，点击提交时仍会走
   * verify() 的完整错误提示。
   */
  const prepare = async () => {
    if (destroyed) {
      return;
    }

    initError.value = '';

    try {
      // 先解析配置（同步写出 required / renderMode），再决定是否预加载：
      // - popup 形态：只加载 SDK 不弹窗，页面零 UI 副作用；
      // - inline 形态：必须由页面提供挂载容器，否则不预加载——避免适配层把
      //   验证码渲染到页面默认位置（如插件配置页没有容器）。
      await resolveConfig();
      if (!enabled.value) {
        return;
      }

      if (
        renderMode.value === 'inline' &&
        !resolveAppendTarget((options.appendTo ?? options.container) as CaptchaAppendTarget)
      ) {
        return;
      }

      ready.value = Boolean(await initCaptcha());
    } catch (error: unknown) {
      ready.value = false;
      // 加载失败不打扰首屏，但页面要知道该给出重试入口
      initError.value = error instanceof Error ? error.message : t('captcha.config.loadFailed');
    }
  };

  // 预热放在 hook 内部：页面不再自己判断何时准备，首屏渲染与验证码初始化并行
  if (options.autoPrepare === true) {
    onMounted(() => {
      void prepare();
    });
  }

  const cleanup = () => {
    destroyed = true;
    // 组件销毁后旧 token 即失效，不能留到下次使用
    verifiedResult = null;
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
    enabled,
    required,
    renderMode,
    initialized,
    ready,
    initError,
    verify,
    prepare,
    ensureConfig,
  };
}
