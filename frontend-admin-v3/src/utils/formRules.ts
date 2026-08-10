import type { FormRule } from 'tdesign-vue-next';

type Trigger = 'change' | 'blur' | 'submit' | 'all';

/** 必填规则 */
export function required(message: string, trigger: Trigger = 'all'): FormRule {
  return { required: true, message, type: 'error', trigger };
}

/** 邮箱规则 */
export function emailRule(message = '请输入正确的邮箱地址'): FormRule {
  return {
    pattern: /^[\w.%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i,
    message,
    type: 'warning',
    trigger: 'blur',
  };
}

/** 手机号规则（中国大陆 11 位） */
export function phoneRule(message = '请输入正确的手机号'): FormRule {
  return { pattern: /^1[3-9]\d{9}$/, message, type: 'warning', trigger: 'blur' };
}

/** 金额规则（正数，最多 2 位小数） */
export function amountRule(message = '请输入有效金额'): FormRule {
  return { pattern: /^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/, message, type: 'warning', trigger: 'blur' };
}

/** 密码规则（最少 min 位） */
export function passwordRule(min = 6, message?: string): FormRule {
  return { min, message: message || `密码至少 ${min} 位`, type: 'error', trigger: 'blur' };
}

/** 组合规则工厂：返回 FormRule[] */
export function composeRules(...rules: FormRule[]): FormRule[] {
  return rules;
}
