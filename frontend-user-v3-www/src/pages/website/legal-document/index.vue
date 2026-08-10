<template>
  <div class="legal-page">
    <div class="legal-card">
      <h1>{{ pageTitle }}</h1>
      <p>{{ pageDescription }}</p>

      <div class="legal-actions">
        <el-button v-if="targetUrl" type="primary" @click="openDocument">打开原文</el-button>
        <el-button @click="router.push('/')">返回首页</el-button>
      </div>

      <el-empty
        v-if="!targetUrl"
        description="站点暂未配置该文档地址"
        :image-size="72"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAppStore } from '@/stores/app'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()

const documentKey = computed(() => String(route.meta?.documentKey || 'terms'))
const isPrivacy = computed(() => documentKey.value === 'privacy')
const targetUrl = computed(() => (
  isPrivacy.value ? appStore.privacyUrl : appStore.termsUrl
))
const pageTitle = computed(() => (isPrivacy.value ? '隐私政策' : '服务条款'))
const pageDescription = computed(() => (
  isPrivacy.value
    ? '查看平台的隐私政策与个人信息处理说明。'
    : '查看平台的服务条款、使用约定与相关说明。'
))

function openDocument() {
  if (!targetUrl.value) {
    return
  }
  window.open(targetUrl.value, '_blank', 'noopener,noreferrer')
}
</script>

<style scoped lang="scss">
.legal-page {
  min-height: 60vh;
  display: grid;
  place-items: center;
  padding: 56px 20px;
  background: #f5f8fd;
}

.legal-card {
  width: min(760px, 100%);
  padding: 36px 32px;
  border: 1px solid #e5eaf3;
  border-radius: 20px;
  background: #fff;
  box-shadow: 0 24px 48px rgba(15, 23, 42, 0.08);
}

.legal-card h1 {
  margin: 0;
  color: #0f172a;
}

.legal-card p {
  margin: 16px 0 0;
  color: #64748b;
  line-height: 1.8;
}

.legal-actions {
  display: flex;
  gap: 12px;
  margin: 24px 0;
}
</style>
