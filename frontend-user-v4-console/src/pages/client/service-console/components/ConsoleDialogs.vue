<template>
  <security-group-dialog />
  <security-rule-dialog />
  <password-reset-dialog />
  <reinstall-dialog />
  <renew-dialog />
  <traffic-package-dialog />
  <name-dialog />
  <remark-dialog />
</template>
<script setup lang="ts">
import NameDialog from './dialogs/NameDialog.vue';
import PasswordResetDialog from './dialogs/PasswordResetDialog.vue';
import ReinstallDialog from './dialogs/ReinstallDialog.vue';
import RemarkDialog from './dialogs/RemarkDialog.vue';
import RenewDialog from './dialogs/RenewDialog.vue';
import SecurityGroupDialog from './dialogs/SecurityGroupDialog.vue';
import SecurityRuleDialog from './dialogs/SecurityRuleDialog.vue';
import TrafficPackageDialog from './dialogs/TrafficPackageDialog.vue';
</script>
