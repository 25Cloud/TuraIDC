<!--
  ISO / 启动项面板。

  三态同快照面板（PanelState 负责渲染）：
  - supported === false：不支持说明，操作区不出现；
  - error 非空：错误块 + 重试；
  - 正常但 images 为空：空态（文案用后端 message）。

  交互：镜像下拉 + 挂载；已挂载时显示当前镜像名与卸载；启动项用 RadioGroup，
  改完立即提交（后端会回一句人话，直接 toast），失败后回拉面板把单选框复原。
-->
<template>
  <section class="console-panel-section">
    <t-card :title="$t('serviceConsole.iso.title')" :bordered="false">
      <template #actions>
        <t-button :loading="isoState.loading" @click="loadIsoPanel">{{ $t('serviceConsole.refresh') }}</t-button>
      </template>

      <panel-state
        :loading="isoState.loading"
        :loaded="isoState.loaded"
        :error="isoState.error"
        :unsupported="isoState.supported === false"
        :unsupported-text="isoState.message || $t('serviceConsole.iso.unsupported')"
        :empty="isoState.images.length === 0 && !isoState.current_iso"
        :empty-text="isoState.message || $t('serviceConsole.iso.empty')"
        @retry="loadIsoPanel"
      >
        <div class="iso-current">
          <span class="iso-current__label">{{ $t('serviceConsole.iso.currentImage') }}</span>
          <strong class="iso-current__name">{{ currentImageText }}</strong>
          <t-button
            v-if="isoState.current_iso && isoState.can_unmount"
            size="small"
            variant="text"
            theme="danger"
            :disabled="isoState.submitting"
            @click="unmountIso"
          >
            {{ $t('serviceConsole.iso.unmount') }}
          </t-button>
        </div>

        <t-form label-align="top" class="iso-form">
          <t-form-item :label="$t('serviceConsole.iso.mountLabel')">
            <t-space class="iso-form__row">
              <t-select
                v-model="isoForm.iso_id"
                class="iso-form__select"
                :options="imageOptions"
                :disabled="!isoState.can_mount || isoState.submitting"
                :placeholder="$t('serviceConsole.iso.selectPlaceholder')"
                clearable
              />
              <t-button
                theme="primary"
                :disabled="!isoState.can_mount || isoForm.iso_id === ''"
                :loading="isoState.submitting"
                @click="mountIso"
              >
                {{ $t('serviceConsole.iso.mount') }}
              </t-button>
            </t-space>
          </t-form-item>

          <t-form-item v-if="isoState.bootorder_options.length" :label="$t('serviceConsole.iso.bootOrder')">
            <t-radio-group
              :value="isoState.bootorder"
              :disabled="!isoState.can_set_bootorder || isoState.submitting"
              @change="setIsoBootOrder"
            >
              <t-radio-button v-for="option in isoState.bootorder_options" :key="option.value" :value="option.value">
                {{ option.label || option.value }}
              </t-radio-button>
            </t-radio-group>
          </t-form-item>
        </t-form>
      </panel-state>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { computed } from 'vue';

import { t } from '@/locales';

import { useServiceConsoleContext } from '../context';
import PanelState from '../PanelState.vue';

const { isoState, isoForm, loadIsoPanel, mountIso, unmountIso, setIsoBootOrder } = useServiceConsoleContext();

const currentImageText = computed(() => {
  if (!isoState.current_iso) return t('serviceConsole.iso.notMounted');

  return isoState.current_iso_name || isoState.current_iso;
});

const imageOptions = computed(() =>
  isoState.images.map((item) => ({
    label: item.name || String(item.id),
    value: String(item.id),
  })),
);
</script>
