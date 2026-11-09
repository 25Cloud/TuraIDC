const BADGE_ID = 'turaidc-demo-badge';

/**
 * 演示站固定角标。
 *
 * 用注入 DOM 的方式而不是改三端的布局组件：三个前端的顶栏 / 侧边栏结构互不相同，
 * 逐个改既会侵入业务模板，又会在后续改版时被顺手删掉。这里只碰 `document.body`，
 * 与三端产物天然共存，且只在 demo 构建里被调用。
 */
export function installDemoBadge(text: string): void {
  if (typeof document === 'undefined' || document.getElementById(BADGE_ID)) {
    return;
  }

  const mount = () => {
    if (document.getElementById(BADGE_ID)) {
      return;
    }

    const badge = document.createElement('div');
    badge.id = BADGE_ID;
    badge.textContent = text;
    badge.setAttribute('role', 'note');
    badge.style.cssText = [
      'position:fixed',
      'right:16px',
      'bottom:16px',
      'z-index:2147483000',
      'padding:8px 14px',
      'border-radius:999px',
      'background:rgba(15,23,42,.86)',
      'color:#f8fafc',
      'font-size:12px',
      'line-height:1.4',
      'letter-spacing:.02em',
      'box-shadow:0 8px 24px rgba(15,23,42,.28)',
      'backdrop-filter:blur(6px)',
      'pointer-events:none',
      'user-select:none',
      'max-width:calc(100vw - 32px)',
    ].join(';');

    document.body.appendChild(badge);
  };

  if (document.body) {
    mount();
    return;
  }

  document.addEventListener('DOMContentLoaded', mount, { once: true });
}
