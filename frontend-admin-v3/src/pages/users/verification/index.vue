<template>
  <div class="users-verification-page">
    <t-card :bordered="false">
      <verification-panel />
    </t-card>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import VerificationPanel from '@/pages/users/components/VerificationPanel.vue';

defineOptions({
  name: 'AdminUserVerification',
});
</script>
