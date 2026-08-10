<template>
  <aside class="side-nav-shell">
    <router-link class="side-nav-shell__brand" to="/client/dashboard">二五云</router-link>
    <nav>
      <router-link v-for="item in items" :key="item.path" :to="item.path">{{ item.label }}</router-link>
    </nav>
  </aside>
</template>

<script setup lang="ts">
const items = [
  { label: '控制台', path: '/client/dashboard' },
  { label: '我的服务', path: '/client/services' },
  { label: '产品目录', path: '/client/catalog' },
  { label: '账单记录', path: '/client/invoices' },
  { label: '工单支持', path: '/client/tickets' },
  { label: '个人资料', path: '/client/profile' },
];
</script>
