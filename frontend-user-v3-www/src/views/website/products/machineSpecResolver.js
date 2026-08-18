import { parseField, parseParamOptions, resolveProductDisplayName } from '@/utils/websiteProductConfig'

export const CPU_KEYWORDS = ['cpu', 'core', '处理器', '核']
export const MEMORY_KEYWORDS = ['memory', 'ram', '内存']

export function isCpuConfigKey(key = '', label = '') {
  const text = `${key}${label}`.toLowerCase()
  return CPU_KEYWORDS.some((keyword) => text.includes(String(keyword).toLowerCase()))
}

export function isMemoryConfigKey(key = '', label = '') {
  const text = `${key}${label}`.toLowerCase()
  return MEMORY_KEYWORDS.some((keyword) => text.includes(String(keyword).toLowerCase()))
}

export function parseMachineSpecFromText(value) {
  const text = String(value || '')
  const compact = text.replace(/\s+/g, '')
  const hMatch = compact.match(/(\d+(?:\.\d+)?)H(\d+(?:\.\d+)?)G/i)
  const cpuMatch = compact.match(/(\d+(?:\.\d+)?)(?:核|核CPU|vCPU|vcpu|C(?![a-z]))/i)
  const memMatch = compact.match(/(\d+(?:\.\d+)?)(?:GiB|gib|GB|G|MiB|mib|MB|M)(?:内存|RAM|ram)?/i)
  const familyMatch = compact.match(/(?:ecs[.-])?([a-z]+[0-9][a-z0-9.-]*)/i)
  const processor = /amd|epyc|ryzen/i.test(text) ? 'AMD' : 'intel'

  const cpuValue = hMatch?.[1] || cpuMatch?.[1] || ''
  const memValue = hMatch?.[2] || memMatch?.[1] || ''

  return {
    cpuValue,
    memValue,
    cpuText: cpuValue ? `${formatSpecNumber(cpuValue)} vCPU` : '',
    memoryText: memValue ? `${formatSpecNumber(memValue)} GiB` : '',
    family: familyMatch?.[1] ? resolveSpecFamily(familyMatch[1]) : '',
    processor,
  }
}

export function normalizeMemorySpecText(value) {
  const text = String(value || '').trim()
  if (!text) {
    return ''
  }

  const compact = text.replace(/\s+/g, '')
  if (/^\d+(?:\.\d+)?g$/i.test(compact)) {
    return `${compact.slice(0, -1)}GiB`
  }

  if (/^\d+(?:\.\d+)?m$/i.test(compact)) {
    return `${compact.slice(0, -1)}MiB`
  }

  const normalized = compact
    .replace(/gib/ig, 'GiB')
    .replace(/gb/ig, 'GiB')
    .replace(/mib/ig, 'MiB')
    .replace(/mb/ig, 'MiB')

  return normalized
}

export function resolveMachineSpecSelection(configOptions, selectedConfig = {}) {
  const options = Array.isArray(configOptions) ? configOptions : []
  const cpuRaw = resolveConfigOptionSelectionLabel(options, isCpuConfigKey, selectedConfig.cpu)
  const memoryRaw = resolveConfigOptionSelectionLabel(options, isMemoryConfigKey, selectedConfig.memory)

  return {
    cpuRaw,
    memoryRaw,
    cpuText: cpuRaw ? (parseMachineSpecFromText(cpuRaw).cpuText || '') : '',
    memoryText: memoryRaw ? (normalizeMemorySpecText(memoryRaw) || parseMachineSpecFromText(memoryRaw).memoryText || '') : '',
  }
}

export function buildMachineSpecDisplayName({ combinedDisplayName, displayName, cpuText, memoryText }) {
  const explicitCombinedName = String(combinedDisplayName || '').trim()
  const rawBaseName = String(displayName || explicitCombinedName || '').trim()
  const baseName = hasMachineSpecSuffix(rawBaseName)
    ? stripMachineSpecSuffix(rawBaseName)
    : rawBaseName

  if (!baseName) {
    return ''
  }

  const cpuSlug = normalizeCpuSlug(cpuText)
  const memorySlug = normalizeMemorySlug(memoryText)
  if (/^未配置规格\s*#\d+$/i.test(baseName) && (cpuSlug || memorySlug)) {
    return [cpuText, memoryText].filter(Boolean).join(' ')
  }

  const nextSegments = [baseName]

  if (cpuSlug && !containsSlugSegment(baseName, cpuSlug)) {
    nextSegments.push(cpuSlug)
  }

  if (memorySlug && !containsSlugSegment(baseName, memorySlug)) {
    nextSegments.push(memorySlug)
  }

  return nextSegments.join('-')
}

function stripMachineSpecSuffix(value) {
  return String(value || '')
    .replace(
      /[-_\s]+\d+(?:\.\d+)?vcpu(?:[-_\s]+\d+(?:\.\d+)?(?:gib|mib|tib|g|m|t))?\s*$/i,
      ''
    )
    .replace(
      /[-_\s]+\d+(?:\.\d+)?(?:gib|mib|tib)\s*$/i,
      ''
    )
    .trim()
}

export function buildInstanceSpecName(name, spec, id) {
  const compactName = String(name || '').replace(/\s+/g, '')
  const explicit = compactName.match(/ecs[.-][a-z0-9.-]+/i)?.[0]
  if (explicit) {
    return explicit
  }

  if (spec?.cpuValue && spec?.memValue) {
    return `ecs.${resolveSpecCodePrefix(spec.family)}.${formatSpecNumber(spec.cpuValue)}c${formatSpecNumber(spec.memValue)}g`
  }

  return compactName || `ecs.${id}`
}

export function resolveMachineSpecPresentation(product, selectedConfig = {}) {
  const sourceProduct = product && typeof product === 'object' ? product : {}
  const displayName = resolveProductDisplayName(sourceProduct)
  const specSource = String(
    sourceProduct.instance_spec_text
    || sourceProduct.instance_spec_alias
    || displayName
    || ''
  ).trim()
  const cpuMemoryDisplay = String(sourceProduct.cpu_memory_display || '').trim()
  const configSpec = resolveMachineSpecSelection(sourceProduct.config_options, selectedConfig)
  const parsedSpec = parseMachineSpecFromText([
    cpuMemoryDisplay,
    specSource,
    displayName,
    configSpec.cpuRaw,
    configSpec.memoryRaw,
  ].filter(Boolean).join(' '))

  // 用户已选 CPU/内存时，当前配置优先于 API 静态默认规格
  const hasSelectedCpu = String(selectedConfig.cpu || '').trim() !== ''
  const hasSelectedMemory = String(selectedConfig.memory || '').trim() !== ''

  const cpuText =
    (hasSelectedCpu ? configSpec.cpuText : '')
    || String(sourceProduct.cpu_display || '').trim()
    || configSpec.cpuText
    || parsedSpec.cpuText
    || ''

  const memoryText =
    (hasSelectedMemory ? configSpec.memoryText : '')
    || normalizeMemorySpecText(sourceProduct.memory_display)
    || configSpec.memoryText
    || parsedSpec.memoryText
    || ''
  const specName = buildMachineSpecDisplayName({
    combinedDisplayName: sourceProduct.combined_display_name,
    displayName,
    cpuText,
    memoryText,
  }) || displayName || buildInstanceSpecName(specSource, parsedSpec, sourceProduct.id || sourceProduct.product_id || 0)

  return {
    displayName: specName,
    cpuText,
    memoryText,
    parsedSpec,
    configSpec,
  }
}

function resolveConfigOptionSelectionLabel(configOptions, matcher, selectedValue) {
  const target = configOptions.find((option) => {
    const { key, label } = parseField(option || {})
    return matcher(key, label)
  })

  if (!target) {
    return ''
  }

  const entries = buildConfigOptionEntries(target)
  if (!entries.length) {
    return ''
  }

  const normalizedSelectedValue = String(selectedValue || '').trim()
  if (normalizedSelectedValue) {
    const matchedEntry = entries.find((entry) => (
      normalizeComparableValue(entry.id) === normalizeComparableValue(normalizedSelectedValue)
      || normalizeComparableValue(entry.value) === normalizeComparableValue(normalizedSelectedValue)
      || normalizeComparableValue(entry.label) === normalizeComparableValue(normalizedSelectedValue)
    ))

    if (matchedEntry?.label) {
      return matchedEntry.label
    }
  }

  return entries[0]?.label || ''
}

function buildConfigOptionEntries(option) {
  const visibleSubs = Array.isArray(option?.sub)
    ? option.sub.filter((item) => Number(item?.hidden || 0) !== 1)
    : []

  if (visibleSubs.length) {
    return visibleSubs.map((item) => ({
      id: String(item?.id || '').trim(),
      value: String(item?.option_name_first || item?.value || item?.qty_minimum || item?.id || '').trim(),
      label: String(item?.version || item?.option_name || item?.label || item?.option_name_first || item?.value || item?.id || '').trim(),
    })).filter((item) => item.label)
  }

  return parseParamOptions(option?.parameter).map((item) => ({
    id: String(item?.id || '').trim(),
    value: String(item?.id || '').trim(),
    label: String(item?.label || item?.id || '').trim(),
  })).filter((item) => item.label)
}

function normalizeComparableValue(value) {
  return String(value || '').trim().toLowerCase()
}

function resolveSpecFamily(code) {
  const value = String(code || '').toLowerCase()
  if (value.includes('c9i') || value.includes('c9')) return '计算型 c9i'
  if (value.includes('g9i') || value.includes('g9')) return '通用型 g9i'
  if (value.includes('e')) return '经济型 e'
  if (value.includes('t6')) return '突发性能实例 t6'
  return value ? `规格族 ${code}` : ''
}

function hasMachineSpecSuffix(value) {
  const text = String(value || '').trim().toLowerCase()
  return /(?:^|[-_])\d+(?:\.\d+)?vcpu(?:[-_]\d+(?:\.\d+)?(?:gib|mib|tib|g|m|t))?/.test(text)
    || /(?:^|[-_])\d+(?:\.\d+)?(?:gib|mib|tib)(?:$|[-_])/.test(text)
}

function normalizeCpuSlug(value) {
  const text = String(value || '').trim()
  const number = text.match(/\d+(?:\.\d+)?/)?.[0] || ''
  return number ? `${formatSpecNumber(number)}vcpu` : ''
}

function normalizeMemorySlug(value) {
  const normalized = normalizeMemorySpecText(value)
  if (!normalized) {
    return ''
  }

  const compact = normalized.replace(/\s+/g, '')
  if (/^\d+(?:\.\d+)?GiB$/i.test(compact)) {
    return compact.replace(/GiB/i, 'gib')
  }

  if (/^\d+(?:\.\d+)?MiB$/i.test(compact)) {
    return compact.replace(/MiB/i, 'mib')
  }

  if (/^\d+(?:\.\d+)?TiB$/i.test(compact)) {
    return compact.replace(/TiB/i, 'tib')
  }

  return compact.toLowerCase()
}

function containsSlugSegment(value, segment) {
  const source = normalizeSlugComparable(value)
  const target = normalizeSlugComparable(segment)
  return Boolean(source && target && source.includes(target))
}

function normalizeSlugComparable(value) {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '')
    .replace(/(\d+(?:\.\d+)?)v?cpu/g, '$1vcpu')
    .replace(/(\d+(?:\.\d+)?)(?:gb|g)(?![a-z])/g, '$1gib')
    .replace(/(\d+(?:\.\d+)?)(?:mb|m)(?![a-z])/g, '$1mib')
}

function resolveSpecCodePrefix(family) {
  const value = String(family || '').toLowerCase()
  if (value.includes('c9')) return 'c9i'
  if (value.includes('g9')) return 'g9i'
  if (value.includes('t6')) return 't6'
  if (value.includes('e')) return 'e'
  return 'g9i'
}

function formatSpecNumber(value) {
  const number = Number(value)
  if (!Number.isFinite(number)) {
    return String(value || '')
  }

  return Number.isInteger(number) ? String(number) : String(number)
}
