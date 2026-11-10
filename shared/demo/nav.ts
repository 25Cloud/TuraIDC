/**
 * 演示站导览浮层：三端 + 说明页的互跳入口，加一栏演示账号。
 *
 * 为什么用统一的注入式导览，而不是每端改自己的布局：
 * 三个前端的顶栏/侧栏结构互不相同，逐个改既侵入业务模板，又会在后续改版里被顺手删掉。
 * 这里只碰 `document.body`，用 Shadow DOM 隔离样式，三端天然一致。
 *
 * 只在 demo 构建里调用（`installDemoTransport` 内部），生产构建不会挂。
 */

export type DemoNavApp = 'console' | 'admin' | 'user';

interface NavEntry {
  key: DemoNavApp | 'start';
  label: string;
  path: string;
  hint: string;
}

const ENTRIES: NavEntry[] = [
  { key: 'user', label: '用户端', path: '/', hint: '客户逛商品、下单' },
  { key: 'console', label: '控制台', path: '/console/', hint: '实例、账单、工单' },
  { key: 'admin', label: '管理端', path: '/admin/', hint: '看板、商品、用户' },
  { key: 'start', label: '演示说明', path: '/start/', hint: '三端入口与账号' },
];

const ACCOUNTS = [
  { label: '控制台', account: 'demo@example.com', password: 'demo123456' },
  { label: '管理端', account: 'admin', password: 'demo123456' },
];

const HOST_ID = 'turaidc-demo-nav';

const STYLE = `
  :host { all: initial; }
  .root {
    position: fixed; right: 16px; bottom: 16px; z-index: 2147483000;
    font-family: 'Inter', 'PingFang SC', 'Microsoft YaHei', system-ui, sans-serif;
    font-size: 13px; color: #e2e8f0; line-height: 1.5;
  }
  button { font: inherit; cursor: pointer; }
  .pill {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 14px; border-radius: 999px; border: 1px solid rgba(148,163,184,.28);
    background: rgba(15,23,42,.92); color: #e2e8f0;
    box-shadow: 0 8px 24px rgba(15,23,42,.35); backdrop-filter: blur(6px);
  }
  .pill:hover { border-color: rgba(56,189,248,.6); }
  .dot { width: 7px; height: 7px; border-radius: 50%; background: #38bdf8; }
  .panel {
    position: absolute; right: 0; bottom: 52px; width: 264px;
    padding: 14px; border-radius: 14px; border: 1px solid rgba(148,163,184,.24);
    background: rgba(9,16,30,.97); box-shadow: 0 18px 48px rgba(2,6,23,.55);
  }
  .panel[hidden] { display: none; }
  .title { margin: 0 0 10px; font-size: 12px; color: #94a3b8; letter-spacing: .04em; }
  .entries { display: grid; gap: 6px; margin-bottom: 12px; }
  .entry {
    display: flex; flex-direction: column; gap: 1px;
    padding: 8px 10px; border-radius: 9px; text-decoration: none; color: inherit;
    border: 1px solid transparent;
  }
  .entry:hover { background: rgba(56,189,248,.1); border-color: rgba(56,189,248,.35); }
  .entry.current { background: rgba(56,189,248,.14); border-color: rgba(56,189,248,.45); }
  .entry b { font-weight: 600; }
  .entry span { color: #94a3b8; font-size: 12px; }
  .accounts { border-top: 1px solid rgba(148,163,184,.18); padding-top: 10px; }
  .account { display: flex; justify-content: space-between; gap: 8px; font-size: 12px; color: #94a3b8; }
  .account + .account { margin-top: 4px; }
  .account code { color: #e2e8f0; font-family: ui-monospace, Menlo, monospace; }
  .foot { margin: 10px 0 0; color: #64748b; font-size: 11.5px; }
`;

export function installDemoNav(app: DemoNavApp): void {
  if (typeof document === 'undefined' || document.getElementById(HOST_ID)) {
    return;
  }

  const mount = () => {
    if (document.getElementById(HOST_ID)) {
      return;
    }

    const host = document.createElement('div');
    host.id = HOST_ID;
    const shadow = host.attachShadow({ mode: 'open' });

    const style = document.createElement('style');
    style.textContent = STYLE;

    const root = document.createElement('div');
    root.className = 'root';

    const panel = document.createElement('div');
    panel.className = 'panel';
    panel.hidden = true;

    const title = document.createElement('p');
    title.className = 'title';
    title.textContent = '演示环境 · 三端可互跳';
    panel.appendChild(title);

    const entries = document.createElement('div');
    entries.className = 'entries';

    for (const entry of ENTRIES) {
      const link = document.createElement('a');
      link.className = entry.key === app ? 'entry current' : 'entry';
      link.href = entry.path;

      const name = document.createElement('b');
      name.textContent = entry.label;
      const hint = document.createElement('span');
      hint.textContent = entry.hint;

      link.append(name, hint);
      entries.appendChild(link);
    }

    panel.appendChild(entries);

    const accounts = document.createElement('div');
    accounts.className = 'accounts';

    for (const item of ACCOUNTS) {
      const row = document.createElement('div');
      row.className = 'account';

      const label = document.createElement('span');
      label.textContent = item.label;
      const code = document.createElement('code');
      code.textContent = `${item.account} / ${item.password}`;

      row.append(label, code);
      accounts.appendChild(row);
    }

    panel.appendChild(accounts);

    const foot = document.createElement('p');
    foot.className = 'foot';
    foot.textContent = '数据为示例，操作不会生效。';
    panel.appendChild(foot);

    const button = document.createElement('button');
    button.className = 'pill';
    button.type = 'button';
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-label', '演示环境导览');

    const dot = document.createElement('span');
    dot.className = 'dot';
    const label = document.createElement('span');
    label.textContent = '演示环境 · 导览';
    button.append(dot, label);

    button.addEventListener('click', () => {
      const next = panel.hidden;
      panel.hidden = !next;
      button.setAttribute('aria-expanded', String(next));
    });

    root.append(panel, button);
    shadow.append(style, root);

    document.body.appendChild(host);
  };

  if (document.body) {
    mount();
    return;
  }

  document.addEventListener('DOMContentLoaded', mount, { once: true });
}
