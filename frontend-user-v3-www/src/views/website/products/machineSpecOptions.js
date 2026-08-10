export function buildMachineSpecOptions(configs, form = {}, fallbackKind = '') {
  return configs.flatMap((cfg, cfgIndex) => {
    const subOptions = Array.isArray(cfg.subOptions) ? cfg.subOptions : []
    if (subOptions.length) {
      return subOptions.map((option) => ({
        id: cfg.isNumber ? String(option.label || option.value || option.id || '') : option.id,
        label: option.label,
        configKey: cfg.isNumber ? cfg.key + '_num' : cfg.key,
      }))
    }

    if (cfg.options?.length) {
      return cfg.options.map((option) => ({
        id: option.id,
        label: option.label,
        configKey: cfg.key,
      }))
    }

    if (cfg.isNumber) {
      return [{
        id: String(form[cfg.key + '_num'] ?? cfg.defaultNum ?? cfgIndex + 1),
        label: selectedNumberConfigLabel(cfg, form, fallbackKind),
        configKey: cfg.key + '_num',
      }]
    }

    return []
  })
}

function selectedNumberConfigLabel(cfg, form, fallbackKind) {
  const value = form[cfg.key + '_num'] ?? cfg.defaultNum
  if (value !== undefined && value !== null && value !== '') {
    return String(value)
  }

  return fallbackKind === 'cpu' ? '1 核' : '1 GiB'
}
