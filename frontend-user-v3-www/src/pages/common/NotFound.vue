<template>
  <div class="not-found-page">
    <div class="not-found-card">
      <p class="not-found-code">404</p>
      <h1>页面不存在</h1>
      <p>你访问的页面可能已迁移，或者链接地址有误。</p>
      <div class="not-found-actions">
        <el-button type="primary" @click="router.push('/')">返回首页</el-button>
        <el-button @click="router.back()">返回上一页</el-button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router'

const router = useRouter()
</script>

<style scoped lang="scss">
.not-found-page {
  min-height: 60vh;
  display: grid;
  place-items: center;
  padding: 48px 20px;
}

.not-found-card {
  width: min(520px, 100%);
  padding: 40px 32px;
  border: 1px solid #e5eaf3;
  border-radius: 20px;
  background: #fff;
  box-shadow: 0 24px 48px rgba(15, 23, 42, 0.08);
  text-align: center;
}

.not-found-code {
  margin: 0 0 12px;
  color: #2f5ef3;
  font-size: 56px;
  font-weight: 700;
  line-height: 1;
}

h1 {
  margin: 0;
  color: #0f172a;
  font-size: 28px;
}

p {
  margin: 12px 0 0;
  color: #64748b;
  line-height: 1.8;
}

.not-found-actions {
  display: flex;
  justify-content: center;
  gap: 12px;
  margin-top: 24px;
}
</style>
