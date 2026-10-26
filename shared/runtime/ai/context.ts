/**
 * 页面上下文总线：页面告诉助手「用户正在看哪个商品/实例/账单」。
 *
 * 只影响提示词与工具默认参数，绝不构成权限——工具仍按登录用户校验资源归属。
 */
import { ref, type Ref } from 'vue';

import type { AiSubject } from './types';

const currentSubject = ref<AiSubject | null>(null);

export interface AiContextBus {
  /** 当前页面主体，null 表示无 */
  subject: Ref<AiSubject | null>;
  set: (subject: AiSubject | null) => void;
  clear: () => void;
  /** 页面 onMounted 时调用，onUnmounted 时执行返回的清理函数，避免离开页面后残留上下文 */
  register: (subject: AiSubject) => () => void;
}

export const aiContext: AiContextBus = {
  subject: currentSubject,
  set(subject: AiSubject | null) {
    currentSubject.value = subject;
  },
  clear() {
    currentSubject.value = null;
  },
  register(subject: AiSubject) {
    currentSubject.value = subject;
    return () => {
      if (
        currentSubject.value &&
        currentSubject.value.type === subject.type &&
        currentSubject.value.id === subject.id
      ) {
        currentSubject.value = null;
      }
    };
  },
};

/** 把上下文转成一条人类可读提示，浮窗顶部展示用 */
export function describeSubject(subject: AiSubject | null): string {
  if (!subject) {
    return '';
  }
  if (subject.label) {
    return subject.label;
  }
  const names: Record<string, string> = {
    product: '商品',
    service: '实例',
    invoice: '账单',
    ticket: '工单',
  };
  return `${names[subject.type] ?? '内容'} #${subject.id}`;
}
