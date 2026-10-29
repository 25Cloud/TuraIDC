import { t } from '@/locales';

/** 同步接口返回的逐条结果 */
export interface DescriptionSyncItem {
  id?: number | string;
  /** synced / skipped / failed */
  status?: string;
  /** 只有 status=synced 才有意义：内容是否真的被改写 */
  changed?: boolean | null;
  message?: string;
}

/** 同步接口返回的汇总 */
export interface DescriptionSyncSummary {
  total?: number;
  synced?: number;
  /** 其中真正改写了库内描述的条数（synced 的子集） */
  changed?: number;
  skipped?: number;
  failed?: number;
}

export interface DescriptionSyncResult {
  items?: DescriptionSyncItem[];
  summary?: DescriptionSyncSummary;
  /** synced / unchanged / skipped / failed / partial */
  outcome?: string;
}

export type DescriptionSyncTone = 'success' | 'warning' | 'error';

export interface DescriptionSyncNotice {
  tone: DescriptionSyncTone;
  message: string;
}

/**
 * 把「从上游同步描述」接口的真实结果翻译成一句管理员能看懂的提示。
 *
 * 为什么需要它：接口此前无论结果如何都回「上游描述已同步」，于是
 * 「上游商品没有描述」「上游目录里已找不到该商品」「拉取上游目录失败」
 * 以及「上游描述与本地逐字一致，其实什么都没改」在界面上长得一模一样——
 * 管理员看到成功提示、前台却没有任何变化，只能判断成「同步坏了」。
 *
 * 口径（与后端 ProductDescriptionSyncController::outcome 对应）：
 * - 有商品被真实改写 → 成功；夹带跳过/失败 → 警告；
 * - 一条都没改写但拉到了上游 → 警告并说明「上游与本地一致」；
 * - 一条都没拉到且是拉取失败 → 错误，带原因；
 * - 全部跳过 → 警告，带第一条跳过原因。
 */
export function describeDescriptionSyncResult(
  result: DescriptionSyncResult | null | undefined,
): DescriptionSyncNotice {
  const summary = result?.summary ?? {};
  const synced = Number(summary.synced ?? 0);
  const changed = Number(summary.changed ?? 0);
  const skipped = Number(summary.skipped ?? 0);
  const failed = Number(summary.failed ?? 0);
  const reason = firstReason(result) || t('products.description.syncNoReason');

  if (changed > 0) {
    if (skipped === 0 && failed === 0) {
      return {
        tone: 'success',
        message:
          changed === 1
            ? t('products.description.syncDone')
            : t('products.description.syncAllDone', { changed }),
      };
    }

    return {
      tone: 'warning',
      message: t('products.description.syncPartial', { changed, skipped, failed }),
    };
  }

  if (failed > 0 && synced === 0) {
    return { tone: 'error', message: t('products.description.syncRejected', { reason }) };
  }

  if (synced > 0) {
    return { tone: 'warning', message: t('products.description.syncUnchanged') };
  }

  if (skipped > 0) {
    return { tone: 'warning', message: t('products.description.syncSkipped', { reason }) };
  }

  return { tone: 'success', message: t('products.description.syncDone') };
}

/**
 * 第一条「没做成」的原因，交给调用方拼进提示。
 *
 * 单商品同步时它就是唯一原因；批量时给管理员一个可复现的排查起点，
 * 完整逐条结果仍在响应里。
 */
export function firstDescriptionSyncReason(result: DescriptionSyncResult | null | undefined): string {
  return firstReason(result);
}

function firstReason(result: DescriptionSyncResult | null | undefined): string {
  const items = Array.isArray(result?.items) ? result.items : [];

  for (const item of items) {
    const status = String(item?.status ?? '');
    if (status === 'skipped' || status === 'failed') {
      const message = String(item?.message ?? '').trim();
      if (message !== '') {
        return message;
      }
    }
  }

  return '';
}
