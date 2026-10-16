<template>
  <section class="console-panel-section">
    <t-card :title="$t('serviceConsole.security.title')" :bordered="false">
      <template #actions>
        <t-space>
          <t-button :loading="securityState.loading" @click="loadSecurityGroups(true)">{{
            $t('serviceConsole.security.refresh')
          }}</t-button>
          <t-button
            v-if="securityState.canCreate"
            theme="primary"
            :disabled="securityState.supported === false"
            @click="openSecurityGroupDialog"
            >{{ $t('serviceConsole.security.createGroup') }}</t-button
          >
        </t-space>
      </template>

      <!-- 加载中盖整块遮罩 + 骨架；「加载失败」与「真的没有安全组」是两个视觉完全不同的视图，
           前者是错误块 + 重试按钮，后者是引导文案，避免用户把失败误读成空。 -->
      <panel-state
        :loading="securityState.loading"
        :loaded="securityState.loaded"
        :error="securityState.error"
        :unsupported="securityState.supported === false"
        :unsupported-text="securityState.message || $t('serviceConsole.security.unsupported')"
        :empty="securityState.groups.length === 0"
        :empty-text="
          securityState.canCreate
            ? $t('serviceConsole.security.emptyCanCreate')
            : $t('serviceConsole.security.emptyNotCreatable')
        "
        :skeleton="3"
        @retry="loadSecurityGroups(true)"
      >
        <div class="security-group-list">
          <article
            v-for="group in securityState.groups"
            :key="group.id"
            class="security-group-card"
            :class="{ 'is-active': activeSecurityGroup?.id === group.id }"
            @click="group.can_view !== false && selectSecurityGroup(group)"
          >
            <div class="security-group-card__main">
              <div class="security-row__name">
                <strong>{{ group.name || `${$t('serviceConsole.security.groupName')} #${group.id}` }}</strong>
                <t-tag size="small" variant="outline">ID {{ group.id }}</t-tag>
                <t-tag v-if="group.is_applied" size="small" theme="success" variant="light">{{
                  $t('serviceConsole.security.applied')
                }}</t-tag>
                <t-tag v-else size="small" variant="light">{{ $t('serviceConsole.security.notApplied') }}</t-tag>
              </div>
              <p>{{ group.description || $t('serviceConsole.security.noDescription') }}</p>
            </div>
            <div class="security-group-card__actions">
              <t-button
                v-if="group.can_view !== false"
                size="small"
                variant="outline"
                :disabled="securityState.submitting"
                @click.stop="selectSecurityGroup(group)"
                >{{ $t('serviceConsole.security.view') }}</t-button
              >
              <t-button
                v-if="!group.is_applied"
                size="small"
                theme="primary"
                variant="outline"
                :disabled="!group.can_apply || group.apply_disabled || securityState.submitting"
                @click.stop="applySecurityGroup(group)"
              >
                {{ $t('serviceConsole.security.apply') }}
              </t-button>
              <t-button
                v-if="group.can_delete !== false"
                size="small"
                theme="danger"
                variant="outline"
                :disabled="!group.can_delete || group.delete_disabled || securityState.submitting"
                @click.stop="deleteSecurityGroup(group)"
              >
                {{ $t('serviceConsole.security.delete') }}
              </t-button>
            </div>
          </article>
        </div>

        <!-- 规则子面板同样分三态：切换安全组时是遮罩，失败时是错误块 + 重试，真的没有规则才是空态 -->
        <div v-if="activeSecurityGroup" class="security-rules-panel">
          <div class="security-rules-panel__head">
            <div>
              <strong
                >{{
                  activeSecurityGroup.name || `${$t('serviceConsole.security.groupName')} #${activeSecurityGroup.id}`
                }}
                {{ $t('serviceConsole.security.ruleLabel') }}</strong
              >
              <span>{{ $t('serviceConsole.security.ruleCount', { count: securityState.rules.length }) }}</span>
            </div>
            <t-button
              theme="primary"
              :disabled="!activeSecurityGroup.can_add_rule || securityState.submitting"
              @click="openSecurityRuleDialog"
              >{{ $t('serviceConsole.security.addRule') }}</t-button
            >
          </div>

          <panel-state
            class="security-rules-state"
            :loading="securityState.rulesLoading"
            :loaded="securityState.rulesLoaded"
            :error="securityState.rulesError"
            :empty="securityState.rules.length === 0"
            :empty-text="$t('serviceConsole.security.rulesEmpty')"
            :skeleton="3"
            @retry="retryLoadRules"
          >
            <t-table
              class="security-rules-table"
              row-key="id"
              :data="securityState.rules"
              :columns="securityColumns"
              :pagination="null"
              size="small"
            >
              <template #operation="{ row }">
                <t-button
                  theme="danger"
                  variant="text"
                  :disabled="securityState.submitting"
                  @click="deleteSecurityRule(row)"
                  >{{ $t('serviceConsole.security.delete') }}</t-button
                >
              </template>
            </t-table>

            <div class="security-rules-cards">
              <div v-for="rule in securityState.rules" :key="rule.id" class="security-rule-card">
                <div class="security-rule-card__row">
                  <span class="security-rule-card__label">{{ $t('serviceConsole.security.direction') }}</span>
                  <span class="security-rule-card__value">{{ rule.direction_label }}</span>
                </div>
                <div class="security-rule-card__row">
                  <span class="security-rule-card__label">{{ $t('serviceConsole.security.protocol') }}</span>
                  <span class="security-rule-card__value">{{ rule.protocol_label || rule.protocol || '--' }}</span>
                </div>
                <div class="security-rule-card__row">
                  <span class="security-rule-card__label">{{ $t('serviceConsole.security.port') }}</span>
                  <span class="security-rule-card__value">{{ rule.port }}</span>
                </div>
                <div class="security-rule-card__row">
                  <span class="security-rule-card__label">{{ $t('serviceConsole.security.source') }}</span>
                  <span class="security-rule-card__value">{{ rule.ip }}</span>
                </div>
                <div v-if="rule.description" class="security-rule-card__row">
                  <span class="security-rule-card__label">{{ $t('serviceConsole.security.description') }}</span>
                  <span class="security-rule-card__value">{{ rule.description }}</span>
                </div>
                <div class="security-rule-card__actions">
                  <t-button
                    theme="danger"
                    variant="text"
                    size="small"
                    :disabled="securityState.submitting"
                    @click="deleteSecurityRule(rule)"
                    >{{ $t('serviceConsole.security.delete') }}</t-button
                  >
                </div>
              </div>
            </div>
          </panel-state>
        </div>
      </panel-state>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { securityColumns } from '../../composables/useConsoleTables';
import { useServiceConsoleContext } from '../context';
import PanelState from '../PanelState.vue';

const {
  securityState,
  activeSecurityGroup,
  loadSecurityGroups,
  loadSecurityGroupRules,
  selectSecurityGroup,
  openSecurityGroupDialog,
  openSecurityRuleDialog,
  applySecurityGroup,
  deleteSecurityGroup,
  deleteSecurityRule,
} = useServiceConsoleContext();

/** 规则加载失败后的重试：重新拉当前选中的安全组规则 */
function retryLoadRules() {
  const groupId = Number(activeSecurityGroup.value?.id || 0);
  if (groupId > 0) void loadSecurityGroupRules(groupId);
}
</script>
