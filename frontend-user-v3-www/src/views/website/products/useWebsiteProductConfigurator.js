import { computed, reactive } from 'vue'
import {
  buildOsGroups,
  buildPricingEntries,
  OS_KEYS,
  parseField,
  parseParamOptions,
  REGION_FIELD_KEYS,
} from '@/utils/websiteProductConfig'
import { resolveNumberOptionBounds } from '@/domains/products/configNumberOptionBounds'
import { isCpuConfigKey, resolveMachineSpecPresentation } from './machineSpecResolver'

const MACHINE_KEYS = ['cpu', 'core', 'memory', 'ram', 'disk', 'storage', 'ssd', 'hdd', '系统盘', '数据盘', '内存', '硬盘']
const NETWORK_KEYS = ['bandwidth', 'traffic', 'ip', 'flow', 'net', 'port', 'speed', 'mbps', '带宽', '流量', '下行', '上行', 'ipv4', 'ipv6', '数量']
const REGION_FLAGS = {
  美国: '🇺🇸',
  US: '🇺🇸',
  香港: '🇭🇰',
  HK: '🇭🇰',
  日本: '🇯🇵',
  JP: '🇯🇵',
  新加坡: '🇸🇬',
  SG: '🇸🇬',
  韩国: '🇰🇷',
  KR: '🇰🇷',
  台湾: '🇹🇼',
  TW: '🇹🇼',
  德国: '🇩🇪',
  DE: '🇩🇪',
  英国: '🇬🇧',
  GB: '🇬🇧',
  中国: '🇨🇳',
  CN: '🇨🇳',
}
const QTY_OPTION_TYPES = [4, 7, 9, 11, 14, 15, 16, 17, 18, 19]

export function useWebsiteProductConfigurator(productDetail) {
  const configForm = reactive({})

  function isNumberField(item) {
    if (QTY_OPTION_TYPES.includes(Number(item.option_type))) {
      return true
    }

    const minQ = Number(item.qty_minimum ?? -1)
    const maxQ = Number(item.qty_maximum ?? -1)
    if (minQ >= 0 && maxQ >= 0 && minQ === maxQ && !item.parameter) {
      return true
    }

    const parameter = String(item.parameter || '').trim()
    return parameter !== '' && /^\d+$/.test(parameter)
  }

  function parseSubOptions(item) {
    const subs = Array.isArray(item.sub) ? item.sub.filter((subItem) => !subItem.hidden) : []
    if (subs.length > 1) {
      return subs.map((subItem) => {
        const version = String(subItem.version || subItem.option_name || subItem.option_name_first || '')
        const parts = version.split('^')
        const label = parts[parts.length - 1] || version || String(subItem.id)
        const value = String(
          subItem.option_name_first
          || subItem.value
          || subItem.qty_minimum
          || label
          || subItem.id
        )

        return {
          id: String(subItem.id),
          label,
          value,
        }
      })
    }

    return parseParamOptions(item.parameter)
  }

  function parseConfigItem(item) {
    const { key, label } = parseField(item)
    const isNum = isNumberField(item)
    const subOptions = parseSubOptions(item)
    const options = isNum ? [] : subOptions
    const bounds = resolveNumberOptionBounds(item)
    const defaultNum = isNum ? (bounds.hasExplicitMin ? bounds.min : (Number(item.parameter) || 1)) : 0

    return {
      key,
      label,
      hidden: item.hidden === 1,
      sortOrder: Number(item.sort_order || 0),
      isNumber: isNum,
      defaultNum,
      min: isNum ? bounds.min : undefined,
      max: isNum ? bounds.max : undefined,
      unit: String(item.unit || item.suffix_text || '').trim(),
      options,
      subOptions,
    }
  }

  function isMachine(cfg) {
    const text = (cfg.key + cfg.label).toLowerCase()
    return MACHINE_KEYS.some((keyword) => text.includes(keyword.toLowerCase()))
  }

  function isNetwork(cfg) {
    const text = (cfg.key + cfg.label).toLowerCase()
    return NETWORK_KEYS.some((keyword) => text.includes(keyword.toLowerCase()))
  }

  const allParsedConfigs = computed(() => {
    const raw = productDetail.value?.config_options || []
    return raw
      .map(parseConfigItem)
      .filter((cfg) => cfg.key && !cfg.hidden && !OS_KEYS.includes(cfg.key) && !REGION_FIELD_KEYS.includes(cfg.key))
      .sort((left, right) => left.sortOrder - right.sortOrder)
  })

  const machineConfigs = computed(() => allParsedConfigs.value.filter(isMachine))
  const networkConfigs = computed(() => allParsedConfigs.value.filter((cfg) => !isMachine(cfg) && isNetwork(cfg)))
  const otherConfigs = computed(() => allParsedConfigs.value.filter((cfg) => !isMachine(cfg) && !isNetwork(cfg)))

  const regionOptions = computed(() => {
    const raw = productDetail.value?.config_options || []
    const item = raw.find((configItem) => {
      const { key } = parseField(configItem)
      return REGION_FIELD_KEYS.includes(key)
    })

    if (!item) {
      return []
    }

    const options = parseSubOptions(item).length ? parseSubOptions(item) : parseParamOptions(item.parameter)
    return options.map((option) => {
      const flag = Object.entries(REGION_FLAGS).find(([keyword]) => option.label.includes(keyword))?.[1] || ''
      return { ...option, flag }
    })
  })

  const regionConfigItem = computed(() => {
    const raw = productDetail.value?.config_options || []
    return raw.find((item) => {
      const { key } = parseField(item)
      return REGION_FIELD_KEYS.includes(key)
    }) || null
  })
  const regionFieldKey = computed(() => parseField(regionConfigItem.value || {}).key || 'region')

  const osConfig = computed(() => {
    const raw = productDetail.value?.config_options || []
    return raw.find((item) => {
      const { key } = parseField(item)
      return OS_KEYS.includes(key) && item.hidden !== 1
    })
  })
  const osFieldKey = computed(() => parseField(osConfig.value || {}).key || 'os')
  const osGroups = computed(() => buildOsGroups(osConfig.value, { preferSub: true }))
  const currentOsGroup = computed(() => osGroups.value.find((group) => group.id === configForm.os_group))
  const currentOsVersionLabel = computed(() => {
    const version = currentOsGroup.value?.versions?.find((item) => item.id === configForm.os)
    return version?.label || '已选'
  })

  const pricingEntries = computed(() => buildPricingEntries(productDetail.value))
  const selectedMachineSpec = computed(() => resolveMachineSpecPresentation(
    productDetail.value,
    {
      cpu: configForm.cpu,
      memory: configForm.memory,
    },
  ))

  const summaryItems = computed(() => {
    const items = []

    if (configForm.region) {
      const region = regionOptions.value.find((item) => item.id === configForm.region)
      if (region) {
        items.push({ key: 'region', label: '区域', value: region.label })
      }
    }

    if (configForm.os) {
      const version = currentOsGroup.value?.versions?.find((item) => item.id === configForm.os)
      if (version) {
        items.push({ key: 'os', label: '系统安装', value: version.label })
      }
    }

    const cpuModelName = String(productDetail.value?.cpu_model_name || '').trim()
    if (cpuModelName) {
      items.push({ key: 'cpu_model_name', label: 'CPU型号', value: cpuModelName })
    }

    if (selectedMachineSpec.value.cpuText) {
      items.push({ key: 'cpu_spec', label: 'CPU', value: selectedMachineSpec.value.cpuText })
    }

    if (selectedMachineSpec.value.memoryText) {
      items.push({ key: 'memory_spec', label: '内存', value: selectedMachineSpec.value.memoryText })
    }

    const allConfigs = [...machineConfigs.value, ...networkConfigs.value, ...otherConfigs.value]
    for (const cfg of allConfigs) {
      if (isCpuConfigKey(cfg.key, cfg.label)) {
        continue
      }

      if (cfg.key === 'memory' || cfg.key === 'ram') {
        continue
      }

      if (cfg.isNumber) {
        const value = configForm[cfg.key + '_num']
        if (value !== undefined && value !== null) {
          items.push({ key: cfg.key, label: cfg.label, value: formatConfigSummaryValue(cfg, value) })
        }
        continue
      }

      const value = configForm[cfg.key]
      if (!value) {
        continue
      }

      const option = cfg.options.find((item) => item.id === value)
      if (option) {
        items.push({ key: cfg.key, label: cfg.label, value: option.label })
      }
    }

    return items
  })

  function selectOsGroup(os) {
    configForm.os_group = os.id
    if (os.versions?.length) {
      configForm.os = os.versions[0].id
    }
  }

  function formatConfigSummaryValue(cfg, value) {
    const text = String(value ?? '').trim()
    if (!text) {
      return ''
    }

    const key = String(cfg.key || '').toLowerCase()
    const unit = String(cfg.unit || '').trim()
    if (unit && Number.isFinite(Number(text))) {
      return `${text}${unit}`
    }

    if (['system_disk_size', 'data_disk_size'].includes(key)) {
      return `${text}G`
    }

    if (['bw', 'in_bw', 'out_bw', 'bandwidth'].includes(key)) {
      return `${text}Mbps`
    }

    if (['flow_limit', 'traffic'].includes(key)) {
      return `${text}G`
    }

    if (['ip_num', 'ipv6_num', 'snap_num', 'backup_num'].includes(key)) {
      return `${text}个`
    }

    return text
  }

  function resetConfigForm() {
    Object.keys(configForm).forEach((key) => {
      delete configForm[key]
    })
  }

  function buildConfigPayload() {
    const payload = {}

    if (configForm.region) {
      payload[regionFieldKey.value] = configForm.region
    }

    if (configForm.os) {
      payload[osFieldKey.value] = configForm.os
    }

    const allConfigs = [...machineConfigs.value, ...networkConfigs.value, ...otherConfigs.value]
    for (const cfg of allConfigs) {
      if (cfg.isNumber) {
        const value = Number(configForm[cfg.key + '_num'] ?? cfg.defaultNum ?? 0)
        if (Number.isFinite(value) && value > 0) {
          payload[cfg.key] = value
        }
        continue
      }

      const value = configForm[cfg.key]
      if (value !== undefined && value !== null && value !== '') {
        payload[cfg.key] = value
      }
    }

    return payload
  }

  function initProductDefaults({ selectedCycleRef, quantityRef, resetQuoteState }) {
    resetConfigForm()

    if (pricingEntries.value.length) {
      selectedCycleRef.value = pricingEntries.value[0].cycle
    }

    allParsedConfigs.value.forEach((cfg) => {
      if (cfg.isNumber) {
        configForm[cfg.key + '_num'] = cfg.defaultNum
      } else if (cfg.options.length) {
        configForm[cfg.key] = cfg.options[0].id
      }
    })

    if (regionOptions.value.length) {
      configForm.region = regionOptions.value[0].id
    }

    if (osGroups.value.length) {
      selectOsGroup(osGroups.value[0])
    }

    quantityRef.value = 1
    resetQuoteState?.()
  }

  return {
    configForm,
    osConfig,
    regionFieldKey,
    regionOptions,
    osGroups,
    currentOsGroup,
    currentOsVersionLabel,
    selectedMachineSpec,
    summaryItems,
    machineConfigs,
    networkConfigs,
    otherConfigs,
    pricingEntries,
    selectOsGroup,
    resetConfigForm,
    buildConfigPayload,
    initProductDefaults,
  }
}
