<template>
  <section class="console-panel-section">
    <t-card title="VNC 控制台" :bordered="false">
      <div class="vnc-panel">
        <div class="vnc-toolbar">
          <p>{{ vncUrl ? 'VNC 控制台已在当前页面载入。' : '点击连接后将向后端申请一次性 VNC 地址。' }}</p>
          <t-space>
            <t-button theme="primary" :loading="actionLoading" @click="handleOpenVnc()">连接 VNC</t-button>
            <t-button variant="outline" :loading="actionLoading" @click="handleOpenVnc('window')">新窗口打开</t-button>
          </t-space>
        </div>

        <div class="vnc-frame-shell">
          <iframe
            v-if="vncUrl"
            :key="vncUrl"
            class="vnc-frame"
            :src="vncUrl"
            title="VNC 控制台"
            allow="clipboard-read; clipboard-write; fullscreen"
            referrerpolicy="no-referrer"
          />
          <div v-else class="vnc-empty">等待连接</div>
        </div>
      </div>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { vncUrl, actionLoading, handleOpenVnc } = useServiceConsoleContext();
</script>
