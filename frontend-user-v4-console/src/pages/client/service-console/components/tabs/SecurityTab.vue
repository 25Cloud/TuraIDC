<template>
  <section class="console-panel-section">
    <t-card title="安全组" :bordered="false">
      <template #actions>
        <t-space>
          <t-button :loading="securityState.loading" @click="loadSecurityGroups(true)">刷新</t-button>
          <t-button
            v-if="securityState.canCreate"
            theme="primary"
            :disabled="securityState.supported === false"
            @click="openSecurityGroupDialog"
            >新建安全组</t-button
          >
        </t-space>
      </template>

      <t-alert v-if="securityState.error" theme="warning" class="console-inline-alert">{{
        securityState.error
      }}</t-alert>
      <t-empty
        v-else-if="securityState.supported === false"
        :description="securityState.message || '当前实例暂不支持安全组'"
      />
      <template v-else>
        <div v-if="securityState.groups.length" class="security-group-list">
          <article
            v-for="group in securityState.groups"
            :key="group.id"
            class="security-group-card"
            :class="{ 'is-active': activeSecurityGroup?.id === group.id }"
            @click="group.can_view !== false && selectSecurityGroup(group)"
          >
            <div class="security-group-card__main">
              <div class="security-row__name">
                <strong>{{ group.name || `安全组 #${group.id}` }}</strong>
                <t-tag size="small" variant="outline">ID {{ group.id }}</t-tag>
                <t-tag v-if="group.is_applied" size="small" theme="success" variant="light">已应用</t-tag>
                <t-tag v-else size="small" variant="light">未应用</t-tag>
              </div>
              <p>{{ group.description || '暂无备注说明' }}</p>
            </div>
            <div class="security-group-card__actions">
              <t-button
                v-if="group.can_view !== false"
                size="small"
                variant="outline"
                :disabled="securityState.submitting"
                @click.stop="selectSecurityGroup(group)"
                >查看</t-button
              >
              <t-button
                v-if="!group.is_applied"
                size="small"
                theme="primary"
                variant="outline"
                :disabled="!group.can_apply || group.apply_disabled || securityState.submitting"
                @click.stop="applySecurityGroup(group)"
              >
                应用
              </t-button>
              <t-button
                v-if="group.can_delete !== false"
                size="small"
                theme="danger"
                variant="outline"
                :disabled="!group.can_delete || group.delete_disabled || securityState.submitting"
                @click.stop="deleteSecurityGroup(group)"
              >
                删除
              </t-button>
            </div>
          </article>
        </div>
        <t-empty
          v-else
          :description="securityState.canCreate ? '当前没有安全组，可先创建后再添加规则' : '当前没有可应用的安全组'"
        />

        <div v-if="activeSecurityGroup" class="security-rules-panel">
          <div class="security-rules-panel__head">
            <div>
              <strong>{{ activeSecurityGroup.name || `安全组 #${activeSecurityGroup.id}` }} 规则</strong>
              <span>{{ securityState.rules.length }} 条规则</span>
            </div>
            <t-button
              theme="primary"
              :disabled="!activeSecurityGroup.can_add_rule || securityState.submitting"
              @click="openSecurityRuleDialog"
              >新增规则</t-button
            >
          </div>

          <t-table
            class="security-rules-table"
            row-key="id"
            :data="securityState.rules"
            :columns="securityColumns"
            :pagination="null"
            :loading="securityState.rulesLoading"
            size="small"
          >
            <template #operation="{ row }">
              <t-button
                theme="danger"
                variant="text"
                :disabled="securityState.submitting"
                @click="deleteSecurityRule(row)"
                >删除</t-button
              >
            </template>
          </t-table>

          <div class="security-rules-cards">
            <div v-for="rule in securityState.rules" :key="rule.id" class="security-rule-card">
              <div class="security-rule-card__row">
                <span class="security-rule-card__label">方向</span>
                <span class="security-rule-card__value">{{ rule.direction_label }}</span>
              </div>
              <div class="security-rule-card__row">
                <span class="security-rule-card__label">协议</span>
                <span class="security-rule-card__value">{{ rule.protocol_label || rule.protocol || '--' }}</span>
              </div>
              <div class="security-rule-card__row">
                <span class="security-rule-card__label">端口</span>
                <span class="security-rule-card__value">{{ rule.port }}</span>
              </div>
              <div class="security-rule-card__row">
                <span class="security-rule-card__label">来源</span>
                <span class="security-rule-card__value">{{ rule.ip }}</span>
              </div>
              <div v-if="rule.description" class="security-rule-card__row">
                <span class="security-rule-card__label">说明</span>
                <span class="security-rule-card__value">{{ rule.description }}</span>
              </div>
              <div class="security-rule-card__actions">
                <t-button
                  theme="danger"
                  variant="text"
                  size="small"
                  :disabled="securityState.submitting"
                  @click="deleteSecurityRule(rule)"
                  >删除</t-button
                >
              </div>
            </div>
          </div>
        </div>
      </template>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { securityColumns } from '../../composables/useConsoleTables';
import { useServiceConsoleContext } from '../context';

const {
  securityState,
  activeSecurityGroup,
  loadSecurityGroups,
  selectSecurityGroup,
  openSecurityGroupDialog,
  openSecurityRuleDialog,
  applySecurityGroup,
  deleteSecurityGroup,
  deleteSecurityRule,
} = useServiceConsoleContext();
</script>
