<template>
  <t-card class="console-header-card" :bordered="false">
    <div class="console-header-body">
      <!-- 第一层：识别信息（主机名 / 实例状态 / 所属用户 / 套餐商品），首屏即可见 -->
      <div class="console-identity">
        <div class="console-title-line">
          <h3 class="console-title">{{ displayName }}</h3>
          <t-tag :theme="instanceStatusTheme" variant="light">{{ instanceStatusText }}</t-tag>
          <t-tag v-if="isServiceRefunded" theme="danger" variant="light">{{ refundedText }}</t-tag>
          <slot name="badges" />
        </div>
        <div class="console-identity-grid">
          <div class="detail-cell">
            <span>{{ $t('services.console.hostname') }}</span>
            <div class="detail-cell-value">
              <strong>{{ connectionEndpointText }}</strong>
              <button
                v-if="connectionEndpointText !== '--'"
                type="button"
                class="copy-link"
                :aria-label="`${$t('services.console.copy')}${connectionEndpointText}`"
                @click="copyText(connectionEndpointText)"
              >
                <copy-icon size="1rem" />
              </button>
            </div>
          </div>
          <div class="detail-cell">
            <span>{{ $t('services.console.ownerUser') }}</span>
            <div class="detail-cell-value">
              <strong>{{ priceFacts.ownerName.value }}</strong>
              <t-button variant="text" theme="primary" size="small" @click="goUserDetail">
                {{ $t('services.index.viewUser') }}
              </t-button>
            </div>
          </div>
          <div class="detail-cell">
            <span>{{ $t('services.console.productLabel') }}</span>
            <div class="detail-cell-value">
              <strong>{{ productText }}</strong>
            </div>
          </div>
        </div>
      </div>

      <!-- 第二层：商务信息（成交价 / 续费价格 / 到期时间 / 上游入口） -->
      <div class="console-commerce">
        <div class="detail-cell">
          <span>{{ $t('services.console.dealPrice') }}</span>
          <div class="detail-cell-value">
            <template v-if="priceFacts.hasAgentDiscount.value">
              <strong class="console-price-original">{{ priceFacts.originalPriceText.value }}</strong>
              <t-tag theme="primary" variant="light" size="small" class="console-price-tag">
                {{ priceFacts.agentDiscountPercent.value }}
              </t-tag>
              <span v-if="priceFacts.agentGroupName.value" class="console-price-group">
                {{ priceFacts.agentGroupName.value }}
              </span>
              <strong class="console-price-deal">{{ priceFacts.dealPriceText.value }}</strong>
            </template>
            <strong v-else>{{ priceFacts.dealPriceText.value }}</strong>
          </div>
        </div>
        <div class="detail-cell">
          <span>{{ $t('services.console.renewPrice') }}</span>
          <div class="detail-cell-value">
            <strong>{{ priceFacts.renewPriceText.value }}</strong>
          </div>
        </div>
        <div class="detail-cell">
          <span>{{ $t('services.console.expireTime') }}</span>
          <div class="detail-cell-value">
            <strong>{{ detail.expires_at || $t('services.console.longTermValid') }}</strong>
          </div>
        </div>
        <div v-if="isZjmfUpstream" class="detail-cell">
          <span>{{ $t('services.index.upstream') }}</span>
          <div class="detail-cell-value">
            <!-- 上游地址为空时不渲染入口，避免拼出坏链接 -->
            <t-button
              v-if="upstreamAdminUrl"
              theme="primary"
              variant="outline"
              size="small"
              tag="a"
              :href="upstreamAdminUrl"
              target="_blank"
              rel="noopener noreferrer"
            >
              {{ $t('services.console.openUpstreamAdmin') }}
            </t-button>
            <strong v-else>{{ upstreamName || $t('services.console.notBound') }}</strong>
          </div>
        </div>
      </div>

      <!-- 第三层：次级信息，折叠下沉，不与主信息争抢首屏 -->
      <t-collapse :value="['identity']" :borderless="true" class="console-header-collapse">
        <t-collapse-panel value="identity" :header="$t('services.console.specAndUpstream')">
          <div class="console-meta-grid">
            <div class="detail-cell">
              <span>{{ $t('services.console.upstreamName') }}</span>
              <div class="detail-cell-value">
                <strong>{{ upstreamName || $t('services.console.notBound') }}</strong>
              </div>
            </div>
            <div class="detail-cell">
              <span>{{ $t('services.console.upstreamInstanceId') }}</span>
              <div class="detail-cell-value">
                <strong>{{ upstreamHostId > 0 ? upstreamHostId : $t('services.console.notBound') }}</strong>
              </div>
            </div>
            <div class="detail-cell">
              <span>{{ $t('services.console.instanceId') }}</span>
              <div class="detail-cell-value">
                <strong>{{ detail.id || '--' }}</strong>
                <button
                  v-if="detail.id"
                  type="button"
                  class="copy-link"
                  :aria-label="`${$t('services.console.copy')}${detail.id}`"
                  @click="copyText(detail.id)"
                >
                  <copy-icon size="1rem" />
                </button>
              </div>
            </div>
            <div class="detail-cell">
              <span>{{ $t('services.console.createdAt') }}</span>
              <div class="detail-cell-value">
                <strong>{{ detail.created_at || '--' }}</strong>
              </div>
            </div>
            <div class="detail-cell">
              <span>{{ $t('services.console.billingCycle') }}</span>
              <div class="detail-cell-value">
                <strong>{{ detail.billing_cycle_label || '--' }}</strong>
              </div>
            </div>
            <div class="detail-cell">
              <span>{{ $t('services.console.region') }}</span>
              <div class="detail-cell-value">
                <strong>{{ serviceRegion }}</strong>
              </div>
            </div>
            <div v-if="hasServiceOs" class="detail-cell">
              <span>{{ $t('services.console.os') }}</span>
              <div class="detail-cell-value">
                <strong>{{ serviceOs }}</strong>
              </div>
            </div>
            <div class="detail-cell">
              <span>{{ $t('services.console.publicIpLabel') }}</span>
              <div class="detail-cell-value">
                <strong>{{ primaryConnectionText }}</strong>
              </div>
            </div>
            <div class="detail-cell">
              <span>{{ $t('services.console.autoRenewLabel') }}</span>
              <div class="detail-cell-value">
                <strong>{{ autoRenewLabel }}</strong>
              </div>
            </div>
          </div>
          <div v-if="specChips.length" class="spec-grid">
            <div v-for="item in specChips" :key="item.label" class="spec-chip">
              <span>{{ item.label }}</span>
              <strong>{{ item.value }}</strong>
            </div>
          </div>
        </t-collapse-panel>
      </t-collapse>
    </div>
  </t-card>
</template>
<script setup lang="ts">
import { CopyIcon } from 'tdesign-icons-vue-next';
import { computed } from 'vue';
import { useRouter } from 'vue-router';

import { useServiceConsoleContext } from '../console/context';
import { useServiceHeaderFacts } from './useServiceHeaderFacts';
import { useServicePriceFacts } from './useServicePriceFacts';

defineOptions({ name: 'ServiceHeaderPanel' });

const props = defineProps<{
  userId: string;
  displayName: string;
  productText: string;
  refundedText: string;
  specChips: Array<{ label: string; value: string }>;
}>();

const router = useRouter();

const {
  detail,
  instanceStatusText,
  instanceStatusTheme,
  connectionEndpointText,
  primaryConnectionText,
  serviceRegion,
  serviceOs,
  autoRenewLabel,
  copyText,
} = useServiceConsoleContext();

const headerFacts = useServiceHeaderFacts({ userId: props.userId, detail });
const priceFacts = useServicePriceFacts({ userId: props.userId, detail });

const upstreamName = headerFacts.upstreamName;
const upstreamHostId = headerFacts.upstreamHostId;
const isZjmfUpstream = headerFacts.isZjmfUpstream;
const upstreamAdminUrl = headerFacts.upstreamAdminUrl;

/** 已退款的实例状态码与已取消一致，不再单列文案。 */
const isServiceRefunded = computed(() => [5, 6].includes(Number(detail.value.status)));

/** CDN 等无操作系统的品类不展示空字段。 */
const hasServiceOs = computed(() => serviceOs.value !== '--' && serviceOs.value !== '');

function goUserDetail() {
  if (Number(props.userId) > 0) {
    router.push({ name: 'AdminUserDetail', params: { id: props.userId } });
  }
}
</script>
<style lang="less" scoped>
/* 顶部改为纵向三层（识别 → 商务 → 次级），覆盖全局的横向两栏布局 */
.console-header-body {
  display: block;
}

.console-identity-grid,
.console-meta-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
  gap: 0.5rem 1.5rem;
  margin-top: 0.75rem;
}

.console-commerce {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: 0.5rem 1.5rem;
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 0.0625rem dashed var(--td-border-color);
}

.console-price-original {
  color: var(--td-text-color-placeholder);
  text-decoration: line-through;
}

.console-price-tag {
  flex: none;
}

.console-price-deal {
  color: var(--td-error-color);
  font-size: 1rem;
}

.console-price-group {
  color: var(--td-text-color-secondary);
  font-size: 0.8125rem;
  font-weight: 400;
}

.console-header-collapse {
  margin-top: 0.75rem;
}

@media (width <= 56.25rem) {
  .console-commerce {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
  }
}
</style>
