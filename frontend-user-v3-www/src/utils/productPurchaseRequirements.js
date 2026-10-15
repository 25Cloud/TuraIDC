import { t } from '@/locales';

const PURCHASE_REQUIREMENT_DEFINITIONS = Object.freeze({
  require_verification: {
    key: 'require_verification',
    label: t('layout.user.realNameVerification'),
    badge: '需实名认证',
    description: t('product.requirements.realNameVerificationHint'),
    unmetMessage: '该商品购买前需先完成实名认证。',
    route: '/client/verification',
    actionLabel: '去实名认证',
  },
  require_phone: {
    key: 'require_phone',
    label: t('product.requirements.phoneBinding'),
    badge: '需绑定手机号',
    description: t('product.requirements.phoneBindingHint'),
    unmetMessage: '该商品购买前需先绑定手机号。',
    route: '/client/profile',
    actionLabel: '去绑定手机号',
  },
})

function isRequirementEnabled(product, key) {
  return Boolean(product?.purchase_requires?.[key])
}

function isRequirementSatisfied(requirement, user) {
  if (requirement.key === 'require_verification') {
    return Number(user?.is_verified || 0) === 1 || Number(user?.verification_status || 0) === 2
  }

  if (requirement.key === 'require_phone') {
    return String(user?.phone || '').trim() !== ''
  }

  return false
}

export function resolvePurchaseRequirementList(product) {
  return Object.values(PURCHASE_REQUIREMENT_DEFINITIONS).filter((item) => isRequirementEnabled(product, item.key))
}

export function resolvePurchaseRequirementSummary(product) {
  return resolvePurchaseRequirementList(product)
    .map((item) => item.description)
    .join('、')
}

export function resolvePurchaseRequirementChecks(product, user, options = {}) {
  const hasToken = Boolean(options.hasToken)
  const loading = Boolean(options.loading)

  return resolvePurchaseRequirementList(product).map((item) => {
    let status = 'missing'
    let statusLabel = '未完成'
    let tone = 'warning'

    if (!hasToken) {
      status = 'login'
      statusLabel = '请先登录'
      tone = 'info'
    } else if (loading && !user) {
      status = 'loading'
      statusLabel = '校验中'
      tone = 'info'
    } else if (isRequirementSatisfied(item, user)) {
      status = 'passed'
      statusLabel = '已通过'
      tone = 'success'
    }

    return {
      ...item,
      status,
      statusLabel,
      tone,
      passed: status === 'passed',
      needsAction: status === 'login' || status === 'missing',
    }
  })
}

export function resolvePrimaryPurchaseRequirementAction(checks = []) {
  return checks.find((item) => item?.needsAction) || null
}

export function resolveMissingPurchaseRequirements(product, user) {
  return resolvePurchaseRequirementList(product).filter((item) => !isRequirementSatisfied(item, user))
}
