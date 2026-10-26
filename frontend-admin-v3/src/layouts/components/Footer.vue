<template>
  <div :class="`${prefix}-footer`">
    <span class="footer-copyright">{{ $t('footer.copyright', { year: currentYear }) }}</span>
    <a class="footer-repository" :href="repositoryUrl" target="_blank" rel="noopener noreferrer">
      {{ $t('footer.repository') }}
    </a>
  </div>
</template>
<script setup lang="ts">
import { prefix } from '@/config/global';

// 页脚版权口径与用户控制台、官网保持一致，只署本项目品牌；
// TDesign 模板自带的腾讯版权字样属于脚手架残留，必须清掉。
const repositoryUrl = 'https://github.com/25Cloud/TuraIDC';
const currentYear = new Date().getFullYear();
</script>
<style lang="less" scoped>
.@{starter-prefix}-footer {
  color: var(--td-text-color-placeholder);
  line-height: 20px;
  text-align: center;

  .footer-repository {
    margin-left: 6px;
    color: var(--td-text-color-placeholder);
    text-decoration: none;

    &:hover {
      color: var(--td-brand-color);
      text-decoration: underline;
    }
  }
}
</style>
