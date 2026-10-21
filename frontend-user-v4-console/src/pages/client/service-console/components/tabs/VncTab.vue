<template>
  <section class="console-panel-section">
    <t-card :title="$t('serviceConsole.vnc.title')" :bordered="false">
      <div class="vnc-panel">
        <div class="vnc-toolbar">
          <p>{{ vncUrl ? $t('serviceConsole.vnc.loadedTip') : $t('serviceConsole.vnc.connectTip') }}</p>
          <t-space>
            <t-button theme="primary" :loading="actionLoading" @click="handleOpenVnc()">{{
              $t('serviceConsole.vnc.connect')
            }}</t-button>
            <t-button variant="outline" :loading="actionLoading" @click="handleOpenVnc('window')">{{
              $t('serviceConsole.vnc.openInWindow')
            }}</t-button>
          </t-space>
        </div>

        <div class="vnc-frame-shell">
          <a v-if="externalVncUrl" :href="externalVncUrl" target="_blank" rel="noopener noreferrer" class="vnc-empty">
            {{ $t('serviceConsole.vnc.openInWindow') }}
          </a>
          <iframe
            v-else-if="vncUrl"
            :key="vncUrl"
            class="vnc-frame"
            :src="vncUrl"
            :title="$t('serviceConsole.vnc.title')"
            allow="clipboard-read; clipboard-write; fullscreen"
            referrerpolicy="no-referrer"
          />
          <div v-else class="vnc-empty">{{ $t('serviceConsole.vnc.waiting') }}</div>
        </div>
      </div>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { vncUrl, externalVncUrl, actionLoading, handleOpenVnc } = useServiceConsoleContext();
</script>
