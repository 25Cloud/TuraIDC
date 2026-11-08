<template>
  <t-dialog
    v-model:visible="upgradeVisible"
    :header="$t('serviceConsole.upgrade.title')"
    width="min(38rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <loading-state :loading="upgradeLoading" :text="$t('serviceConsole.upgrade.loading')" compact>
      <template v-if="upgradeOptions.length">
        <section class="upgrade-current">
          <div class="upgrade-current__title">{{ $t('serviceConsole.upgrade.currentConfig') }}</div>
          <div class="upgrade-current__grid">
            <div v-for="row in currentConfigRows" :key="`${row.label}-${row.value}`" class="upgrade-current__item">
              <span>{{ row.label }}</span>
              <strong>{{ row.value }}</strong>
            </div>
          </div>
        </section>

        <section class="upgrade-target">
          <div class="upgrade-current__title">{{ $t('serviceConsole.upgrade.targetConfig') }}</div>
          <t-radio-group
            v-model="upgradeForm.product_id"
            class="upgrade-option-group"
            @change="handleUpgradeOptionChange"
          >
            <t-radio-button
              v-for="item in upgradeOptions"
              :key="String(item.product_id)"
              :value="String(item.product_id)"
            >
              <span class="upgrade-option">
                <strong>{{ item.name || `#${item.product_id}` }}</strong>
                <em v-if="item.price !== undefined && item.price !== ''">¥{{ formatMoney(item.price) }}</em>
              </span>
            </t-radio-button>
          </t-radio-group>

          <t-form label-align="top" class="upgrade-form">
            <t-form-item v-if="upgradeCycleOptions.length > 1" :label="$t('serviceConsole.upgrade.targetCycle')">
              <t-select
                v-model="upgradeForm.billing_cycle"
                :options="upgradeCycleOptions.map((cycle) => ({ label: cycle, value: cycle }))"
                @change="handleUpgradeCycleChange"
              />
            </t-form-item>
            <t-form-item :label="$t('serviceConsole.upgrade.promoCode')">
              <t-input
                v-model="upgradeForm.promo_code"
                :placeholder="$t('serviceConsole.upgrade.promoCodePlaceholder')"
                @blur="reapplyUpgradePromoCode"
                @enter="reapplyUpgradePromoCode"
              />
            </t-form-item>
          </t-form>
        </section>

        <div class="upgrade-total-line">
          <span>{{ upgradeOptionName }}</span>
          <strong v-if="upgradeQuoting">{{ $t('serviceConsole.upgrade.quoting') }}</strong>
          <strong v-else-if="upgradeQuoteKind === 'payable'">¥{{ upgradePayableAmount }}</strong>
          <strong v-else-if="upgradeQuoteKind === 'free'">{{ $t('serviceConsole.upgrade.noExtraCharge') }}</strong>
          <strong v-else-if="upgradeQuoteKind === 'downgrade'" class="is-downgrade">
            -¥{{ formatMoney(Math.abs(upgradeQuoteAmount ?? 0)) }}
          </strong>
          <strong v-else class="is-muted">{{ $t('serviceConsole.upgrade.amountUnknown') }}</strong>
        </div>
        <p v-if="upgradeQuoteKind === 'downgrade'" class="upgrade-hint upgrade-hint--warning">
          {{ $t('serviceConsole.upgrade.downgradeNotice') }}
        </p>
        <p v-else-if="upgradeQuoteKind === 'unknown' && !upgradeQuoting" class="upgrade-hint">
          {{ $t('serviceConsole.upgrade.amountUnknownHint') }}
        </p>
      </template>
      <template v-else-if="!upgradeLoading">
        <t-empty :description="upgradeEmptyText">
          <template #action>
            <t-button v-if="upgradeError" variant="outline" size="small" @click="loadUpgradePreview">
              {{ $t('serviceConsole.upgrade.retry') }}
            </t-button>
          </template>
        </t-empty>
      </template>
    </loading-state>
    <template #footer>
      <t-button variant="outline" @click="upgradeVisible = false">
        {{ $t('serviceConsole.upgrade.cancel') }}
      </t-button>
      <t-button
        theme="primary"
        :loading="upgradeSubmitting"
        :disabled="upgradeSubmitBlocked"
        @click="submitUpgradeOrder"
      >
        {{ $t('serviceConsole.upgrade.createInvoice') }}
      </t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import LoadingState from '@shared/user-v3/components/LoadingState.vue';

import { useServiceConsoleContext } from '../context';

const {
  upgradeVisible,
  upgradeLoading,
  upgradeQuoting,
  upgradeSubmitting,
  upgradeError,
  upgradeOptions,
  upgradeForm,
  upgradeQuoteKind,
  upgradeQuoteAmount,
  upgradePayableAmount,
  upgradeOptionName,
  upgradeCycleOptions,
  upgradeEmptyText,
  currentConfigRows,
  upgradeSubmitBlocked,
  loadUpgradePreview,
  handleUpgradeOptionChange,
  handleUpgradeCycleChange,
  reapplyUpgradePromoCode,
  submitUpgradeOrder,
  formatMoney,
} = useServiceConsoleContext();
</script>
<style scoped lang="less">
.upgrade-current {
  margin-bottom: var(--td-comp-margin-l);
}

.upgrade-current__title {
  margin-bottom: var(--td-comp-margin-s);
  font: var(--td-font-body-medium);
  color: var(--td-text-color-secondary);
}

.upgrade-current__grid {
  display: grid;
  gap: var(--td-comp-margin-s) var(--td-comp-margin-l);
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-l);
  background: var(--td-bg-color-container-hover);
  border-radius: var(--td-radius-medium);
}

.upgrade-current__item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;

  span {
    font: var(--td-font-body-small);
    color: var(--td-text-color-placeholder);
  }

  strong {
    font: var(--td-font-body-medium);
    color: var(--td-text-color-primary);
    word-break: break-all;
  }
}

.upgrade-option-group {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  margin-bottom: var(--td-comp-margin-l);
}

.upgrade-option {
  display: inline-flex;
  gap: var(--td-comp-margin-xs);
  align-items: baseline;

  em {
    font: var(--td-font-body-small);
    font-style: normal;
    color: var(--td-text-color-secondary);
  }
}

.upgrade-form {
  margin-bottom: var(--td-comp-margin-s);
}

.upgrade-total-line {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--td-comp-margin-m);
  padding-top: var(--td-comp-margin-m);
  border-top: 1px solid var(--td-component-stroke);

  span {
    min-width: 0;
    overflow: hidden;
    font: var(--td-font-body-medium);
    color: var(--td-text-color-secondary);
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  strong {
    flex: none;
    font: var(--td-font-title-small);
    color: var(--td-text-color-primary);
  }

  .is-downgrade {
    color: var(--td-warning-color);
  }

  .is-muted {
    font: var(--td-font-body-medium);
    color: var(--td-text-color-placeholder);
  }
}

.upgrade-hint {
  margin: var(--td-comp-margin-s) 0 0;
  font: var(--td-font-body-small);
  color: var(--td-text-color-secondary);
}

.upgrade-hint--warning {
  color: var(--td-warning-color);
}
</style>
