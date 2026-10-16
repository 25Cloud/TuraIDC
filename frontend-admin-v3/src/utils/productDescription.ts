/**
 * 商品描述的结构化编辑工具。
 *
 * 结构化模式本质是一组「键 → 值」行，落库仍是后端已消毒的 HTML；
 * 这两个纯函数负责两种模式之间的双向转换，供编辑器组件与单测复用。
 */

/** 结构化模式的一行：一个键名 + 一个值 */
export interface ProductDescriptionRow {
  key: string;
  value: string;
}

/** HTML 实体转义，避免键值里的尖括号破坏生成的列表结构 */
function escapeHtml(value: string): string {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** 去掉标签、还原 HTML 实体，得到可放进输入框的纯文本 */
function decodePlainText(value: string): string {
  return (
    String(value ?? '')
      .replace(/<[^>]*>/g, '')
      // 后端消毒输出的实体名一律为小写，这里只还原小写形式
      .replace(/&nbsp;/g, ' ')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&#0*39;/g, "'")
      .replace(/&apos;/g, "'")
      .replace(/&amp;/g, '&')
      .replace(/[\t\u00A0 ]+/g, ' ')
      .trim()
  );
}

/**
 * 把结构化行还原成 HTML。
 *
 * 键和值都为空视为空行并跳过；只有值没有键时整段作为一条列表项。
 */
export function buildDescriptionHtml(rows: ProductDescriptionRow[]): string {
  const items = (Array.isArray(rows) ? rows : [])
    .map((row) => {
      const key = String(row?.key ?? '').trim();
      const value = String(row?.value ?? '').trim();
      if (!key && !value) return '';
      if (!key) return `<li>${escapeHtml(value)}</li>`;
      return `<li>${escapeHtml(key)}: ${escapeHtml(value)}</li>`;
    })
    .filter(Boolean);

  return items.length ? `<ul>${items.join('')}</ul>` : '';
}

/**
 * 把 HTML 拆回结构化行。
 *
 * 先按 `</li>`、`<br>`、段落与列表结束标签以及源码换行切成行，去标签取纯文本，
 * 再按第一个半角/全角冒号切分键值。解析不出键值的整行不会被丢弃，
 * 而是以「key 留空 + 整段文本放进 value」的形式保留，商家切回结构化模式不丢内容。
 */
export function parseDescriptionRows(html: string): ProductDescriptionRow[] {
  const source = String(html ?? '').trim();
  if (!source) return [];

  const rows: ProductDescriptionRow[] = [];

  source
    .split(/<\/(?:li|p|ul|ol|div|h[1-6])>|<br\s*\/?>|\r\n|\r|\n/i)
    .map((line) => decodePlainText(line))
    .filter((line) => line !== '')
    .forEach((line) => {
      // 前后两个片段用互斥的字符类切分，不引入重叠量词；键值两侧空白交给 trim
      const matched = line.match(/^([^:：]+)[:：](.*)$/);
      const key = matched ? matched[1].trim() : '';
      if (matched && key) {
        rows.push({ key, value: matched[2].trim() });
        return;
      }
      rows.push({ key: '', value: line });
    });

  return rows;
}
