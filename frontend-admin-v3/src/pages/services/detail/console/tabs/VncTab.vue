<template>
  <section class="console-panel-section">
    <t-card :title="$t('services.console.vncTab.title')" :bordered="false">
      <div class="vnc-panel">
        <div class="vnc-toolbar">
          <p>
            {{ vncUrl ? $t('services.console.vncTab.loadedHint') : $t('services.console.vncTab.connectHint') }}
          </p>
          <t-space>
            <t-button theme="primary" :loading="actionLoading" @click="handleOpenVnc()">
              {{ $t('services.console.vncTab.connect') }}
            </t-button>
            <t-button variant="outline" :loading="actionLoading" @click="handleOpenVnc('window')">
              {{ $t('services.console.vncTab.openInNewWindow') }}
            </t-button>
          </t-space>
        </div>

        <div class="vnc-frame-shell">
          <iframe
            v-if="vncUrl"
            :key="vncUrl"
            class="vnc-frame"
            :src="vncUrl"
            :title="$t('services.console.vncTab.frameTitle')"
            allow="clipboard-read; clipboard-write; fullscreen"
            referrerpolicy="no-referrer"
          />
          <div v-else class="vnc-empty">{{ $t('services.console.vncTab.waiting') }}</div>
        </div>
      </div>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { vncUrl, actionLoading, handleOpenVnc } = useServiceConsoleContext();
</script>
