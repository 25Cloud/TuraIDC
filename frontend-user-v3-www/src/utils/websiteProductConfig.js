import {
  BILLING_CYCLE_LABELS,
  BILLING_CYCLE_ORDER,
  billingCycleLabel,
  billingCycleSortRank,
} from '@shared/billingCycle'

// 保留原导出名，避免波及站点其它引用；真源已收敛到 @shared/billingCycle
export const CYCLE_MAP = BILLING_CYCLE_LABELS

export const CYCLE_ORDER = BILLING_CYCLE_ORDER

export const OS_KEYS = ['os', 'operating_system']

export const REGION_FIELD_KEYS = ['region', 'area', 'location', 'zone', 'datacenter', 'node']

const OS_ICON_MAP = {
  windows: '/img/os/Windows.svg',
  win: '/img/os/Windows.svg',
  tencentos: '/img/os/TencentOS.png',
  ubuntu: '/img/os/Ubuntu.svg',
  debian: '/img/os/Debian.svg',
  centos: '/img/os/CentOS.svg',
  rocky: '/img/os/Rocky.svg',
  almalinux: '/img/os/AlmaLinux.svg',
  alma: '/img/os/AlmaLinux.svg',
  archlinux: '/img/os/ArchLinux.svg',
  arch: '/img/os/ArchLinux.svg',
  fedora: '/img/os/Fedora.svg',
  freebsd: '/img/os/FreeBSD.svg',
  bsd: '/img/os/FreeBSD.svg',
  esxi: '/img/os/ESXi.svg',
  vmware: '/img/os/ESXi.svg',
  openeuler: '/img/os/OpenEuler.svg',
  euler: '/img/os/OpenEuler.svg',
  xenserver: '/img/os/XenServer.svg',
  xen: '/img/os/XenServer.svg',
  other: '/img/os/其他.svg',
  其他: '/img/os/其他.svg',
}

export function normalizeMoneyText(value) {
  if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) {
    return '0.00'
  }

  return Number(value).toFixed(2)
}

export function parseField(item = {}) {
  const raw = String(item.field || item.spec_key || '').trim()
  const parts = raw.split('|')
  const key = parts[0]?.trim() || ''
  const label = (parts.length > 1 ? parts.slice(1).join('|').trim() : '') || String(item.name || '').trim() || key

  return { key, label }
}

export function parseParamOptions(raw) {
  const text = String(raw || '').trim()
  if (!text) {
    return []
  }

  const segments = text.split(',').map((item) => item.trim()).filter(Boolean)
  const pipeSegments = segments.filter((item) => item.includes('|'))

  if (pipeSegments.length <= 1 && segments.length > 1) {
    const lastPipe = text.lastIndexOf('|')
    if (lastPipe > 0) {
      return [{
        id: text.slice(0, lastPipe).trim(),
        label: text.slice(lastPipe + 1).trim(),
      }]
    }
  }

  return segments.map((item) => {
    const [id, ...rest] = item.split('|')

    return {
      id: id.trim(),
      label: rest.join('|').trim() || id.trim(),
    }
  })
}

export function getOsIcon(name) {
  if (!name) {
    return null
  }

  const normalized = String(name).toLowerCase().replace(/[^a-z\u4e00-\u9fa5]/g, '')
  for (const [key, icon] of Object.entries(OS_ICON_MAP)) {
    if (normalized.includes(key)) {
      return icon
    }
  }

  return null
}

export function defaultOsGroups() {
  return [
    {
      id: 'centos',
      label: 'CentOS',
      icon: '/img/os/CentOS.svg',
      versions: [
        { id: 'centos7', label: 'CentOS-7.6.1810-x64' },
        { id: 'centos8s', label: 'CentOS-8-Stream-x64' },
        { id: 'centos79', label: 'CentOS-7.9.2111-x64' },
        { id: 'centos78', label: 'CentOS-7.8.2003-x64' },
        { id: 'centos78bt', label: 'CentOS-7.8.2003-x64-BT' },
        { id: 'centos9s', label: 'CentOS-9-Stream-x64' },
      ],
    },
    {
      id: 'ubuntu',
      label: 'Ubuntu',
      icon: '/img/os/Ubuntu.svg',
      versions: [
        { id: 'ubuntu22', label: 'Ubuntu-22.04-x64' },
        { id: 'ubuntu20', label: 'Ubuntu-20.04-x64' },
      ],
    },
    {
      id: 'windows',
      label: 'Windows',
      icon: '/img/os/Windows.svg',
      versions: [
        { id: 'win2022', label: 'Windows Server 2022' },
        { id: 'win2019', label: 'Windows Server 2019' },
      ],
    },
    {
      id: 'debian',
      label: 'Debian',
      icon: '/img/os/Debian.svg',
      versions: [
        { id: 'debian12', label: 'Debian-12.x-x64' },
        { id: 'debian11', label: 'Debian-11.x-x64' },
      ],
    },
  ]
}

function capitalizeLabel(value) {
  const text = String(value || '').trim()
  if (!text) {
    return ''
  }

  return text.charAt(0).toUpperCase() + text.slice(1)
}

function appendOsGroup(groups, groupName, versionId, versionLabel) {
  if (!groupName || !versionId) {
    return
  }

  if (!groups[groupName]) {
    groups[groupName] = {
      id: groupName,
      label: capitalizeLabel(groupName),
      icon: getOsIcon(groupName),
      versions: [],
    }
  }

  groups[groupName].versions.push({
    id: String(versionId),
    label: versionLabel || String(versionId),
  })
}

export function buildOsGroups(configItem, options = {}) {
  const { preferSub = false } = options
  if (!configItem) {
    return defaultOsGroups()
  }

  const groups = {}
  const subItems = Array.isArray(configItem.sub) ? configItem.sub.filter((item) => !item.hidden) : []

  if (preferSub && subItems.length) {
    subItems.forEach((item) => {
      const versionText = String(item.version || item.option_name || item.label || '')
      const parts = versionText.split('^')
      const groupName = parts[0]?.trim() || versionText
      const versionLabel = parts[1]?.trim() || versionText || String(item.id || '')
      appendOsGroup(groups, groupName, item.id, versionLabel)
    })
  }

  if (Object.keys(groups).length === 0) {
    parseParamOptions(configItem.parameter).forEach((item) => {
      const parts = item.label.split('^')
      const groupName = parts[0]?.trim() || item.label
      const versionLabel = parts[1]?.trim() || item.label
      appendOsGroup(groups, groupName, item.id, versionLabel)
    })
  }

  if (Object.keys(groups).length === 0 && subItems.length) {
    subItems.forEach((item) => {
      const versionText = String(item.version || item.option_name || item.label || '')
      const parts = versionText.split('^')
      const groupName = parts[0]?.trim() || versionText
      const versionLabel = parts[1]?.trim() || versionText || String(item.id || '')
      appendOsGroup(groups, groupName, item.id, versionLabel)
    })
  }

  return Object.keys(groups).length > 0 ? Object.values(groups) : defaultOsGroups()
}

export function buildPricingEntries(product) {
  const entries = Array.isArray(product?.pricing_entries) ? product.pricing_entries : []
  if (entries.length) {
    return entries
      .filter((item) => Number(item?.amount || 0) > 0)
      // 原用 CYCLE_ORDER.indexOf()，未知周期得到 -1 会被排到最前；
      // billingCycleSortRank() 把未知周期排到末尾，更符合展示预期
      .sort((left, right) => billingCycleSortRank(left.cycle) - billingCycleSortRank(right.cycle))
  }

  const pricing = product?.pricing || {}
  return Object.entries(pricing)
    .filter(([, value]) => Number(value) > 0)
    .sort((left, right) => billingCycleSortRank(left[0]) - billingCycleSortRank(right[0]))
    .map(([cycle, value]) => ({
      cycle,
      label: billingCycleLabel(cycle, cycle),
      amount: normalizeMoneyText(value),
      total_amount: normalizeMoneyText(Number(value || 0) + Number(product?.setup_fee || 0)),
    }))
}

export function resolveProductDisplayName(product) {
  const text = String(
    product?.combined_display_name
    || product?.product_display_name
    || product?.display_name
    || product?.instance_spec_text
    || product?.instance_spec_alias
    || ''
  ).trim()

  if (text) {
    return text
  }

  const productId = Number(product?.id || product?.product_id || 0)
  return productId > 0 ? `未配置规格 #${productId}` : ''
}

export function resolveProductSpecName(product) {
  const text = String(
    product?.product_display_name
    || product?.display_name
    || product?.instance_spec_text
    || product?.instance_spec_alias
    || ''
  ).trim()

  if (text) {
    return text
  }

  const productId = Number(product?.id || product?.product_id || 0)
  return productId > 0 ? `未配置规格 #${productId}` : ''
}
