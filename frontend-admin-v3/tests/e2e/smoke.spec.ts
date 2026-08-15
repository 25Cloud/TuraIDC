import { Buffer } from 'node:buffer';
import { readFileSync } from 'node:fs';

import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

test('keeps core API fixtures on v2 admin contracts', () => {
  const source = readFileSync(new URL('./smoke.spec.ts', import.meta.url), 'utf8');
  const legacyPath = `/${['api', 'admin'].join('/')}/`;

  expect(source).not.toContain(legacyPath);
});

async function mockAdminInfo(page: import('@playwright/test').Page) {
  await page.route(/\/api\/v2\/admin\/auth\/info(?:\?.*)?$/, async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          admin: {
            id: 1,
            username: 'cerbo',
            nickname: 'cerbo',
            email: 'admin@example.com',
            permissions: ['*'],
          },
        },
      }),
    });
  });
}

async function mockDashboard(page: import('@playwright/test').Page) {
  await page.route('**/api/v2/admin/dashboard/stats**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          today: { income: 128.5, new_invoices: 3, new_users: 2 },
          month: { income: 4096, new_invoices: 42, new_users: 12 },
          counts: { active_services: 16, total_invoices: 88, open_tickets: 4, total_users: 32 },
        },
      }),
    });
  });
  await page.route('**/api/v2/admin/dashboard/recent-invoices**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          recent_invoices: [
            { id: 1, invoice_no: 'INV-TEST-001', amount: 128.5, status: 1, created_at: '2026-06-06 10:00:00' },
          ],
        },
      }),
    });
  });
  await page.route('**/api/v2/admin/dashboard/monthly-revenue**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          month_label: '2026-06',
          revenue_by_product: [{ product_name: '云服务器', income: 4096 }],
          daily_revenue: [{ date: '06-06', income: 128.5 }],
        },
      }),
    });
  });
}

async function mockUsers(page: import('@playwright/test').Page) {
  await page.route(/\/api\/v2\/admin\/users(?:\?.*)?$/, async (route) => {
    const request = route.request();
    if (request.method() !== 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: {} }),
      });
      return;
    }

    const url = new URL(request.url());
    const keyword = url.searchParams.get('keyword') || '';
    const pageIndex = Number(url.searchParams.get('page') || 1);
    const account = keyword ? 'filtered@example.com' : '2908990438@qq.com';

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [
            {
              id: pageIndex,
              email: account,
              phone: '',
              nickname: keyword ? '筛选用户' : '测试用户',
              display_name: keyword ? '筛选用户' : '测试用户',
              cash_balance: 128.5,
              opened_product_count: 2,
              status: 1,
              verification_status: 2,
              is_verified: 1,
              created_at: '2026-06-06 10:00:00',
            },
          ],
          total: 40,
          page: pageIndex,
          page_size: Number(url.searchParams.get('page_size') || 20),
        },
      }),
    });
  });
}

async function mockTickets(page: import('@playwright/test').Page) {
  await page.route(/\/api\/v2\/admin\/tickets(?:\?.*)?$/, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const keyword = url.searchParams.get('keyword') || '';
    const pageIndex = Number(url.searchParams.get('page') || 1);
    const subject = keyword ? '筛选工单' : '网络无法连接';

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [
            {
              id: 101,
              subject,
              status: keyword ? 2 : 1,
              priority: keyword ? 3 : 4,
              department: 'support',
              user_id: 1,
              user: { id: 1, nickname: '测试用户', email: '2908990438@qq.com' },
              assignee: keyword ? { id: 2, username: 'cerbo' } : null,
              updated_at: '2026-06-06 10:00:00',
              created_at: '2026-06-06 09:30:00',
            },
          ],
          total: 21,
          page: pageIndex,
          page_size: Number(url.searchParams.get('page_size') || 20),
        },
      }),
    });
  });
}

async function mockTicketConversation(page: import('@playwright/test').Page) {
  let assigneeId: number | null = null;
  let replied = false;
  let recalled = false;
  let closed = false;
  const currentTime = () => {
    const date = new Date();
    const pad = (item: number) => String(item).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(
      date.getMinutes(),
    )}:${pad(date.getSeconds())}`;
  };

  const detailPayload = () => ({
    id: 101,
    user_id: 1,
    department: 'support',
    subject: '网络无法连接',
    priority: 4,
    status: closed ? 3 : 1,
    service_id: 11,
    assignee_id: assigneeId,
    created_at: '2026-06-06 09:30:00',
    updated_at: '2026-06-06 10:00:00',
    user: { id: 1, nickname: '测试用户', display_name: '测试用户', email: '2908990438@qq.com' },
    assignee: assigneeId ? { id: assigneeId, username: 'cerbo', nickname: 'cerbo' } : null,
    service: {
      id: 11,
      name: '测试云服务器',
      display_name: '测试云服务器',
      expires_at: '2026-07-06 10:00:00',
      connection: {
        dedicated_ip: '192.0.2.10',
        username: 'root',
        password: 'TempPass123',
        has_password: true,
        port: 22,
      },
      specs: [{ label: 'CPU', value: '2 核' }],
    },
    replies: [
      {
        id: 201,
        ticket_id: 101,
        user_id: 1,
        content: '客户反馈无法访问服务器',
        is_staff: 0,
        sender_name: '测试用户',
        attachments: [{ id: 'att-1', path: 'private/tickets/client.png', url: '/mock-ticket.png', name: 'client.png' }],
        recalled: false,
        created_at: '2026-06-06 09:30:00',
      },
      {
        id: 202,
        ticket_id: 101,
        user_id: 1,
        content: recalled ? '' : '请先检查安全组规则',
        is_staff: 1,
        sender_name: 'cerbo',
        attachments: [] as Array<Record<string, unknown>>,
        recalled,
        created_at: currentTime(),
      },
      ...(replied
        ? [
            {
              id: 203,
              ticket_id: 101,
              user_id: 1,
              content: '后台回复内容',
              is_staff: 1,
              sender_name: 'cerbo',
              attachments: [] as Array<Record<string, unknown>>,
              quote: { id: 201, sender_name: '测试用户', content: '客户反馈无法访问服务器', recalled: false },
              recalled: false,
              created_at: currentTime(),
            },
          ]
        : []),
    ],
  });

  await page.route('**/api/v2/admin/tickets/admin-users**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [
            { id: 1, username: 'cerbo', nickname: 'cerbo', email: 'ticket@example.com' },
            { id: 2, username: 'support', nickname: 'support', email: 'support@example.com' },
          ],
        },
      }),
    });
  });

  await page.route('**/api/v2/admin/tickets/upload-images**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        message: '图片上传成功',
        data: {
          attachment: {
            id: 'upload-1',
            path: 'private/tickets/admin.png',
            url: '/mock-upload.png',
            name: 'admin.png',
            type: 'image',
          },
        },
      }),
    });
  });

  await page.route('**/api/v2/admin/tickets/101**', async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;

    if (pathname.endsWith('/replies') && request.method() === 'GET') {
      const detail = detailPayload();
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: { list: detail.replies, total: detail.replies.length, page: 1, page_size: 100 },
        }),
      });
      return;
    }

    if (pathname.endsWith('/replies') && request.method() === 'POST') {
      replied = true;
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '回复成功', data: { reply: { id: 203 } } }),
      });
      return;
    }

    if (pathname.endsWith('/assignment') && request.method() === 'PUT') {
      const body = request.postDataJSON() as { assignee_id?: number };
      assigneeId = Number(body.assignee_id || 0) || null;
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '指派成功', data: {} }),
      });
      return;
    }

    if (pathname.endsWith('/closures') && request.method() === 'POST') {
      closed = true;
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '工单已关闭', data: null }),
      });
      return;
    }

    if (pathname.endsWith('/replies/202/recalls') && request.method() === 'POST') {
      recalled = true;
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '消息已撤回', data: null }),
      });
      return;
    }

    const detail = detailPayload();
    const { replies: _replies, ...ticket } = detail;
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ code: 0, data: { ticket } }),
    });
  });
}

async function mockCouponProductTree(
  page: import('@playwright/test').Page,
  labels: { root: string; child: string } = { root: '云服务器', child: '通用型' },
) {
  const groupPath = `${labels.root} / ${labels.child}`;
  const paginate = (list: Record<string, unknown>[]) => ({
    code: 0,
    data: { list, total: list.length, page: 1, page_size: 100 },
  });
  const root = {
    id: 11,
    node_key: '1:11',
    name: labels.root,
    label: labels.root,
    level: 1,
    product_type: 'cloud_server',
    product_type_label: '云服务器',
    first_product_group_id: 11,
    first_product_group_code: 'cloud_server',
    first_product_group_name: labels.root,
    effective_product_group_id: 11,
    effective_product_group_level: 1,
    group_path: labels.root,
    has_children: true,
    direct_products_count: 0,
    status: 1,
  };
  const child = {
    id: 12,
    node_key: '2:12',
    name: labels.child,
    label: labels.child,
    parent_id: 11,
    parent_level: 1,
    level: 2,
    product_type: 'cloud_server',
    product_type_label: '云服务器',
    first_product_group_id: 11,
    first_product_group_code: 'cloud_server',
    first_product_group_name: labels.root,
    second_product_group_id: 12,
    second_product_group_name: labels.child,
    effective_product_group_id: 12,
    effective_product_group_level: 2,
    group_path: groupPath,
    has_children: false,
    direct_products_count: 1,
    status: 1,
  };
  const product = {
    id: 101,
    product_id: 101,
    label: '标准云服务器',
    name: '标准云服务器',
    display_name: '标准云服务器',
    cpu_memory_display: '2C4G',
    product_type: 'cloud_server',
    product_type_label: '云服务器',
    group_path: groupPath,
    category_full_name: groupPath,
    first_product_group_id: 11,
    first_product_group_name: labels.root,
    second_product_group_id: 12,
    second_product_group_name: labels.child,
    effective_product_group_id: 12,
    effective_product_group_level: 2,
    node_type: 'product',
    leaf: true,
    primary_price: { cycle: 'monthly', amount: '99.00' },
    status: 1,
  };

  await page.route(/\/api\/v2\/admin\/coupon-product-groups(?:\?.*)?$/, async (route) => {
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(paginate([root])) });
  });
  await page.route(/\/api\/v2\/admin\/coupon-product-groups\/11\/children(?:\?.*)?$/, async (route) => {
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(paginate([child])) });
  });
  await page.route(/\/api\/v2\/admin\/coupon-product-groups\/12\/products(?:\?.*)?$/, async (route) => {
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(paginate([product])) });
  });
  await page.route('**/api/v2/admin/coupon-product-groups/batch-products', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ code: 0, data: { '2:12': [product] } }),
    });
  });
}

async function mockNestedCouponProductTree(page: import('@playwright/test').Page) {
  const paginate = (list: Record<string, unknown>[]) => ({
    code: 0,
    data: { list, total: list.length, page: 1, page_size: 100 },
  });
  const root = {
    id: 1,
    node_key: '1:1',
    name: '云服务器',
    label: '云服务器',
    level: 1,
    product_type: 'cloud_server',
    product_type_label: '云服务器',
    first_product_group_id: 1,
    first_product_group_name: '云服务器',
    effective_product_group_id: 1,
    effective_product_group_level: 1,
    group_path: '云服务器',
    has_children: true,
    direct_products_count: 0,
    status: 1,
  };
  const secondGroup = {
    id: 4,
    node_key: '2:4',
    name: '特价云服务器',
    label: '特价云服务器',
    parent_id: 1,
    parent_level: 1,
    level: 2,
    product_type: 'cloud_server',
    product_type_label: '云服务器',
    first_product_group_id: 1,
    first_product_group_name: '云服务器',
    second_product_group_id: 4,
    second_product_group_name: '特价云服务器',
    effective_product_group_id: 4,
    effective_product_group_level: 2,
    group_path: '云服务器 / 特价云服务器',
    has_children: true,
    direct_products_count: 0,
    status: 1,
  };
  const thirdGroup = {
    id: 4,
    node_key: '3:4',
    name: '高宽',
    label: '高宽',
    parent_id: 4,
    parent_level: 2,
    level: 3,
    product_type: 'cloud_server',
    product_type_label: '云服务器',
    first_product_group_id: 1,
    first_product_group_name: '云服务器',
    second_product_group_id: 4,
    second_product_group_name: '特价云服务器',
    third_product_group_id: 4,
    third_product_group_name: '高宽',
    effective_product_group_id: 4,
    effective_product_group_level: 3,
    group_path: '云服务器 / 特价云服务器 / 高宽',
    has_children: false,
    direct_products_count: 1,
    status: 1,
  };
  const product = {
    id: 101,
    product_id: 101,
    node_type: 'product',
    label: '高宽云服务器 2C4G',
    product_display_name: '高宽云服务器 2C4G',
    product_type: 'cloud_server',
    service_type_code: 'cloud_server',
    category_full_name: '云服务器 / 特价云服务器 / 高宽',
    first_product_group_id: 1,
    first_product_group_name: '云服务器',
    second_product_group_id: 4,
    second_product_group_name: '特价云服务器',
    third_product_group_id: 4,
    third_product_group_name: '高宽',
    effective_product_group_id: 4,
    effective_product_group_level: 3,
    primary_price: { cycle: 'monthly', amount: '99.00' },
    status: 1,
  };

  await page.route(/\/api\/v2\/admin\/coupon-product-groups(?:\?.*)?$/, async (route) => {
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(paginate([root])) });
  });
  await page.route(/\/api\/v2\/admin\/coupon-product-groups\/1\/children(?:\?.*)?$/, async (route) => {
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(paginate([secondGroup])) });
  });
  await page.route(/\/api\/v2\/admin\/coupon-product-groups\/4\/children(?:\?.*)?$/, async (route) => {
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(paginate([thirdGroup])) });
  });
  await page.route('**/api/v2/admin/coupon-product-groups/batch-products', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ code: 0, data: { '3:4': [product] } }),
    });
  });
}

async function mockProductsHub(page: import('@playwright/test').Page) {
  await mockCouponProductTree(page, { root: '襄阳', child: '高宽' });
  await page.route('**/api/v2/admin/products/summary**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          groups_total: 3,
          root_groups_total: 1,
          sub_groups_total: 2,
          products_total: 12,
          products_active: 10,
          products_low_stock: 1,
        },
      }),
    });
  });

  await page.route('**/api/v2/admin/product-types**', async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;

    if (pathname.endsWith('/product-types/reorders')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          message: '商品种类排序已更新',
          data: {
            list: [
              {
                value: 'storage_server',
                label: '存储服务器',
                usage_count: 0,
                group_count: 0,
                first_product_group_id: 21,
                first_product_group_name: '存储',
              },
              {
                value: 'cloud_server',
                label: '云服务器',
                usage_count: 8,
                group_count: 2,
                icon: 'ServerIcon',
                first_product_group_id: 11,
                first_product_group_name: '计算',
              },
            ],
          },
        }),
      });
      return;
    }

    if (request.method() !== 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '操作成功', data: { value: 'storage_server', label: '存储服务器' } }),
      });
      return;
    }

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [
            {
              value: 'cloud_server',
              label: '云服务器',
              usage_count: 8,
              group_count: 2,
              icon: 'ServerIcon',
              first_product_group_id: 11,
              first_product_group_name: '计算',
            },
            {
              value: 'storage_server',
              label: '存储服务器',
              usage_count: 0,
              group_count: 0,
              first_product_group_id: 21,
              first_product_group_name: '存储',
            },
          ],
        },
      }),
    });
  });

  await page.route('**/api/v2/admin/product-groups**', async (route) => {
    if (route.request().method() !== 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '操作成功', data: {} }),
      });
      return;
    }

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          tree: [
            {
              id: 11,
              name: '计算',
              label: '计算',
              level: 1,
              product_type: 'cloud_server',
              product_type_label: '云服务器',
              first_product_group_id: 11,
              first_product_group_code: 'cloud_server',
              first_product_group_name: '计算',
              effective_product_group_id: 11,
              effective_product_group_level: 1,
              group_path: '计算',
              has_children: true,
              direct_products_count: 0,
              children: [
                {
                  id: 12,
                  name: '通用型',
                  label: '通用型',
                  parent_id: 11,
                  parent_level: 1,
                  level: 2,
                  product_type: 'cloud_server',
                  product_type_label: '云服务器',
                  first_product_group_id: 11,
                  first_product_group_code: 'cloud_server',
                  first_product_group_name: '计算',
                  second_product_group_id: 12,
                  second_product_group_name: '通用型',
                  effective_product_group_id: 12,
                  effective_product_group_level: 2,
                  group_path: '计算 / 通用型',
                  has_children: false,
                  direct_products_count: 1,
                },
                {
                  id: 13,
                  name: '存储型',
                  label: '存储型',
                  parent_id: 11,
                  parent_level: 1,
                  level: 2,
                  product_type: 'cloud_server',
                  product_type_label: '云服务器',
                  first_product_group_id: 11,
                  first_product_group_code: 'cloud_server',
                  first_product_group_name: '计算',
                  second_product_group_id: 13,
                  second_product_group_name: '存储型',
                  effective_product_group_id: 13,
                  effective_product_group_level: 2,
                  group_path: '计算 / 存储型',
                  has_children: false,
                  direct_products_count: 0,
                },
              ],
            },
          ],
        },
      }),
    });
  });

  await page.route(
    /\/api\/v2\/admin\/products(?:\/(?:category-batches|split-previews|splits|provision-hostname-batches|traffic-package-pulls))?(?:\?.*)?$/,
    async (route) => {
      const request = route.request();
      const url = new URL(request.url());
      const pathname = url.pathname;

      if (pathname.endsWith('/products/traffic-package-pulls')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            message: '流量包配置拉取成功',
            data: {
              packages: [{ label: '200GB', target_value: 200, price: 29.9, enabled: 1, sort_order: 1 }],
              source: { mode: 'local_product_template', product_name: '标准云服务器' },
            },
          }),
        });
        return;
      }

      if (pathname.endsWith('/products/category-batches')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            message: '商品分类已批量更新',
            data: { updated_count: 1, target_category_name: '存储型' },
          }),
        });
        return;
      }

      if (pathname.endsWith('/products/split-previews')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            message: '商品拆分预览完成',
            data: {
              requested_count: 1,
              preview_count: 1,
              skipped_count: 0,
              items: [
                {
                  source_product_id: 101,
                  source_display_name: '筛选云服务器',
                  action: 'preview',
                  variants: [
                    {
                      product_id: null,
                      display_name: '筛选云服务器 2C4G',
                      source_display_name: '筛选云服务器',
                      variant_key: 'cpu_2-memory_4',
                      action: 'create',
                    },
                  ],
                },
              ],
            },
          }),
        });
        return;
      }

      if (pathname.endsWith('/products/splits')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            message: '商品拆分完成',
            data: { created_count: 1, updated_count: 0, skipped_count: 0, items: [] },
          }),
        });
        return;
      }

      if (pathname.endsWith('/products/provision-hostname-batches')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({ code: 0, message: '商品开通主机名规则已更新', data: { updated_count: 1 } }),
        });
        return;
      }

      if (pathname.endsWith('/products/101') || request.method() !== 'GET') {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({ code: 0, message: '操作成功', data: {} }),
        });
        return;
      }

      const keyword = url.searchParams.get('keyword') || '';
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            list: [
              {
                id: 101,
                name: keyword ? '筛选云服务器' : '标准云服务器',
                display_name: keyword ? '筛选云服务器' : '标准云服务器',
                product_type: 'cloud_server',
                product_type_label: '云服务器',
                first_product_group_id: 11,
                first_product_group_name: '计算',
                second_product_group_id: 12,
                second_product_group_name: '通用型',
                effective_product_group_id: 12,
                effective_product_group_level: 2,
                effective_product_group_full_name: '计算 / 通用型',
                status: 1,
                primary_price: { cycle: 'monthly', amount: '88.00' },
                monthly_price: 88,
                lifecycle_status: 'active',
                provision_hostname: { mode: 'system', value: '', length: 12 },
                provision_hostname_summary: '跟随上游',
              },
            ],
            total: 1,
            page: Number(url.searchParams.get('page') || 1),
            page_size: Number(url.searchParams.get('page_size') || 20),
          },
        }),
      });
    },
  );

  await page.route('**/api/v2/admin/products/101**', async (route) => {
    if (route.request().method() === 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            product: {
              id: 101,
              display: {
                display_name: '标准云服务器',
                product_display_name: '标准云服务器',
                product_spec_display: '2C4G',
                cpu_memory_display: '2C4G',
                combined_display_name: '标准云服务器 2C4G',
              },
              classification: {
                product_type: 'cloud_server',
                product_type_label: '云服务器',
                first_product_group_id: 11,
                first_product_group_code: 'cloud_server',
                first_product_group_name: '计算',
                second_product_group_id: 12,
                second_product_group_name: '通用型',
                effective_product_group_id: 12,
                effective_product_group_level: 2,
                category_full_name: '计算 / 通用型',
              },
              pricing: {
                items: { monthly: 88 },
                primary_price: { cycle: 'monthly', amount: '88.00' },
                setup_fee: '0.00',
              },
              configuration: {
                console_template: 'compute',
                config_options: [
                  {
                    uid: 'cpu',
                    field: 'cpu',
                    name: 'CPU',
                    option_name: 'CPU',
                    option_mode: 'select',
                    parameter: '2|2核',
                    sub_items: [{ value: '2', label: '2核', option_name: '2核', option_name_first: '2' }],
                    required: true,
                    hidden: false,
                    sort_order: 1,
                  },
                ],
              },
              purchase_requirements: {
                require_verification: false,
                require_phone: false,
                provision_hostname: { mode: 'system', value: '', length: 12 },
              },
              provisioning: { stock: -1, auto_setup: 1 },
              upstream_binding: { supplier_id: 3, upstream_product_id: 300 },
              statistics: { orders_count: 0, services_count: 0, active_services_count: 0 },
              lifecycle: { status: 1, lifecycle_status: 'active', deleted_at: null, sort_order: 1 },
              timestamps: { created_at: '2026-06-06 10:00:00', updated_at: '2026-06-06 10:00:00' },
            },
          },
        }),
      });
      return;
    }

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ code: 0, message: '操作成功', data: {} }),
    });
  });

  await page.route('**/api/v2/admin/product-groups/12**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ code: 0, message: '操作成功', data: {} }),
    });
  });

  await page.route(/\/api\/v2\/admin\/settings(?:\?.*)?$/, async (route) => {
    if (route.request().method() !== 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '保存成功', data: {} }),
      });
      return;
    }

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [
            {
              key: 'items',
              value: JSON.stringify([
                {
                  traffic_group_id: 'traffic:cloud_server:12:seed',
                  group_name: '基础流量包',
                  first_product_group_id: 11,
                  second_product_group_id: 12,
                  third_product_group_id: null,
                  effective_product_group_id: 12,
                  product_group_label: '通用型',
                  product_type: 'cloud_server',
                  product_ids: [101],
                  label: '100GB',
                  target_value: 100,
                  price: '19.90',
                  enabled: 1,
                  sort_order: 1,
                },
              ]),
            },
            {
              key: 'groups',
              value: JSON.stringify([
                {
                  id: 'traffic:cloud_server:12:seed',
                  name: '基础流量包',
                  product_type: 'cloud_server',
                  product_group_key: '2:12',
                  first_product_group_id: 11,
                  second_product_group_id: 12,
                  third_product_group_id: null,
                  effective_product_group_id: 12,
                  product_group_label: '通用型',
                  product_ids: [101],
                  sort_order: 1,
                },
              ]),
            },
          ],
        },
      }),
    });
  });

  await page.route('**/api/v2/admin/suppliers/summary**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ code: 0, data: { total: 2, active: 1, inactive: 1 } }),
    });
  });

  await page.route('**/api/v2/admin/suppliers/provider-types**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ code: 0, data: { list: [{ value: 'zjmf_finance_api', label: 'ZJMF 财务' }] } }),
    });
  });

  await page.route(/\/api\/v2\/admin\/suppliers(?:\?.*)?$/, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname;

    if (pathname.endsWith('/suppliers/3') && request.method() === 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            id: 3,
            name: 'ZJMF 财务详情',
            interface_type: 'zjmf_finance_api',
            api_url: 'https://example.test',
            api_username: 'api-user',
            api_key: 'secret',
            status: 1,
          },
        }),
      });
      return;
    }

    if (pathname.endsWith('/suppliers/3/balance') && request.method() === 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: { balance: '123.45', client: { name: 'ZJMF 财务' } } }),
      });
      return;
    }

    if (pathname.endsWith('/suppliers/3/products') && request.method() === 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            products: [
              {
                id: 9001,
                name: '上游云服务器 A',
                type_label: '月付',
                group_name: '云服务器',
                is_connected: 0,
              },
            ],
          },
        }),
      });
      return;
    }

    if (request.method() !== 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          message: '操作成功',
          data: {
            supplier: {
              id: 4,
              name: '新供应商',
              provider_key: 'zjmf_finance_api',
              provider_label: 'ZJMF 财务',
              status: 1,
              connection: { base_url: 'https://new.example.test', base_url_configured: true, account_name: 'new-user' },
              credentials: { api_credential_configured: true, provider_values_configured: { api_key: true } },
              upstream_binding: {
                provider_key: 'zjmf_finance_api',
                base_url: 'https://new.example.test',
                base_url_configured: true,
              },
            },
          },
        }),
      });
      return;
    }

    const keyword = url.searchParams.get('keyword') || '';
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [
            {
              id: 3,
              name: keyword ? '筛选供应商' : 'ZJMF 财务',
              provider_key: 'zjmf_finance_api',
              provider_label: 'ZJMF 财务',
              status: 1,
              connection: { base_url: 'https://example.test', base_url_configured: true, account_name: 'api-user' },
              credentials: { api_credential_configured: true, provider_values_configured: { api_key: true } },
              upstream_binding: {
                id: 3,
                provider_key: 'zjmf_finance_api',
                base_url: 'https://example.test',
                base_url_configured: true,
                account_name: 'api-user',
                credentials_configured: { api_key: true },
              },
              card: {
                provided: true,
                title: 'ZJMF 财务',
                status: { label: '可用', theme: 'success' },
                fields: [{ key: 'balance', label: '余额', value: '123.45' }],
                actions: [
                  {
                    key: 'bulk_connect',
                    label: '批量对接',
                    action: 'supplier.batch_connect',
                    request_action: 'server.supplier.bulk_connect',
                    disabled: false,
                  },
                ],
              },
              updated_at: '2026-06-06 10:00:00',
            },
          ],
          total: 1,
          page: Number(url.searchParams.get('page') || 1),
          page_size: Number(url.searchParams.get('page_size') || 20),
        },
      }),
    });
  });

  await page.route('**/api/v2/admin/suppliers/3**', async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;

    if (pathname.endsWith('/suppliers/3/products') && request.method() === 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            products: [
              {
                id: 9001,
                name: '上游云服务器 A',
                type_label: '月付',
                group_name: '云服务器',
                is_connected: 0,
              },
            ],
          },
        }),
      });
      return;
    }

    if (pathname.endsWith('/suppliers/3/tasks') && request.method() === 'POST') {
      const body = request.postDataJSON() as { type?: string; payload?: Record<string, unknown> };
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            id: 3,
            status: 'completed',
            message: '批量对接完成',
            detail: {
              type: body.type || 'server.supplier.bulk_connect',
              result: { created_count: 1, updated_count: 0, skipped_count: 0 },
            },
          },
        }),
      });
      return;
    }

    if (pathname.endsWith('/suppliers/3/status') && request.method() === 'PATCH') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: { id: 3, status: 'completed', message: '供应商状态已更新' } }),
      });
      return;
    }

    if (pathname.endsWith('/suppliers/3/products/300/config-template')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            config_options: [
              {
                uid: 'memory',
                field: 'memory',
                name: '内存',
                option_name: '内存',
                option_mode: 'select',
                parameter: '4|4G',
                sub_items: [{ value: '4', label: '4G', option_name: '4G', option_name_first: '4' }],
                required: true,
                hidden: false,
                sort_order: 1,
              },
            ],
          },
        }),
      });
      return;
    }

    if (pathname.endsWith('/suppliers/3/balance') && request.method() === 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: { balance: '123.45', client: { name: 'ZJMF 财务' } } }),
      });
      return;
    }

    if (request.method() === 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            supplier: {
              id: 3,
              name: 'ZJMF 财务详情',
              provider_key: 'zjmf_finance_api',
              provider_label: 'ZJMF 财务',
              status: 1,
              connection: { base_url: 'https://example.test', base_url_configured: true, account_name: 'api-user' },
              credentials: { api_credential_configured: true, provider_values_configured: { api_key: true } },
              upstream_binding: {
                id: 3,
                provider_key: 'zjmf_finance_api',
                base_url: 'https://example.test',
                base_url_configured: true,
                account_name: 'api-user',
                credentials_configured: { api_key: true },
              },
            },
          },
        }),
      });
      return;
    }

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ code: 0, message: '操作成功', data: {} }),
    });
  });
}

async function mockInstanceSpecs(page: import('@playwright/test').Page) {
  await mockCouponProductTree(page);

  await page.route('**/api/v2/admin/instance-spec-catalog**', async (route) => {
    const request = route.request();
    if (request.method() === 'POST') {
      const body = request.postDataJSON() as { list?: unknown[] };
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '保存成功', data: { list: body.list || [] } }),
      });
      return;
    }

    const url = new URL(request.url());
    const keyword = url.searchParams.get('keyword') || '';
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [
            {
              id: 'spec-1',
              value: 'ecs_g9i_2c2g',
              text: keyword ? '筛选规格' : 'ecs.g9i.2c2g',
              alias: '2 核 2G',
              note: '入门实例规格',
              status: '展示中',
              sort_order: 1,
              bindings: [],
            },
          ],
        },
      }),
    });
  });
}

async function mockCpuModels(page: import('@playwright/test').Page) {
  await mockCouponProductTree(page);

  await page.route('**/api/v2/admin/cpu-model-catalog**', async (route) => {
    const request = route.request();
    if (request.method() === 'POST') {
      const body = request.postDataJSON() as { list?: unknown[] };
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '保存成功', data: { list: body.list || [] } }),
      });
      return;
    }

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [
            {
              id: 'cpu-group-1',
              value: 'intel_xeon',
              name: 'Intel Xeon',
              sort_order: 1,
              models: [
                {
                  id: 'cpu-model-1',
                  value: 'gold_6133',
                  name: 'Intel Xeon Gold 6133',
                  base_frequency: '2.50GHz',
                  turbo_frequency: '3.20GHz',
                  sort_order: 1,
                  bindings: [],
                },
              ],
            },
          ],
        },
      }),
    });
  });
}

async function mockCoupons(page: import('@playwright/test').Page) {
  await mockCouponProductTree(page);

  let coupons: Record<string, unknown>[] = [
    {
      id: 501,
      name: '新客首单立减券',
      description: '新用户首单可用',
      distribution_type: 'public',
      distribution_type_label: '公开优惠券',
      discount_scope: 'first_month',
      discount_scope_label: '首月优惠',
      discount_type: 'fixed',
      discount_type_label: '满减券',
      discount_value: 30,
      discount_value_raw: 30,
      discount_label: '立减 ¥30.00',
      min_amount: 99,
      min_amount_raw: 99,
      max_discount_amount: null,
      max_discount_amount_raw: null,
      billing_cycles: ['monthly'],
      billing_cycle_text: '月付',
      product_ids: [101],
      product_scope_text: '指定 1 个商品',
      first_order_only: true,
      user_ids: [],
      used_count: 3,
      total_usage_limit: 100,
      per_user_limit: 1,
      remaining_stock: 97,
      status: 1,
      display_status: 'active',
      display_status_label: '生效中',
      display_status_reason: '规则正常',
      validity_text: '长期有效',
      can_delete: true,
      sort_order: 1,
      updated_at: '2026-06-06 10:00:00',
    },
  ];

  await page.route('**/api/v2/admin/coupons**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname;

    if (pathname.endsWith('/coupons/summary')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: { enabled: true, total: coupons.length } }),
      });
      return;
    }

    if (pathname.endsWith('/coupons') && request.method() === 'GET') {
      const keyword = url.searchParams.get('keyword') || '';
      const list = keyword ? [{ ...coupons[0], name: '筛选优惠券', description: '筛选结果' }] : coupons;
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            list,
            total: list.length,
            page: Number(url.searchParams.get('page') || 1),
            page_size: Number(url.searchParams.get('page_size') || 20),
          },
        }),
      });
      return;
    }

    if (pathname.endsWith('/coupons') && request.method() === 'POST') {
      const body = request.postDataJSON();
      coupons = [
        { id: 502, ...body, display_status: 'active', display_status_label: '生效中', can_delete: true },
        ...coupons,
      ];
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: coupons[0] }) });
      return;
    }

    if (pathname.endsWith('/status') && request.method() === 'PATCH') {
      const id = Number(pathname.split('/').at(-2));
      coupons = coupons.map((item) => (item.id === id ? { ...item, status: Number(item.status) === 1 ? 0 : 1 } : item));
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: {} }) });
      return;
    }

    const id = Number(pathname.split('/').at(-1));
    if (request.method() === 'PUT') {
      const body = request.postDataJSON();
      coupons = coupons.map((item) => (item.id === id ? { ...item, ...body } : item));
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: coupons[0] }) });
      return;
    }

    if (request.method() === 'DELETE') {
      coupons = coupons.filter((item) => item.id !== id);
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: {} }) });
    }
  });
}

async function mockCouponCampaigns(page: import('@playwright/test').Page) {
  await mockCouponProductTree(page);

  let campaigns: Record<string, unknown>[] = [
    {
      id: 601,
      name: '周五特惠',
      description: '每周五自动发放',
      weekdays: [5],
      trigger_time: '18:00:00',
      schedule_text: '每周五 18:00',
      next_run_at: '2026-06-12 18:00:00',
      issue_quantity: 20,
      valid_duration_hours: 48,
      discount_type: 'percentage',
      discount_type_label: '折扣券',
      discount_scope: 'first_month',
      discount_scope_label: '首月优惠',
      discount_value: 80,
      discount_value_raw: 80,
      discount_label: '8 折',
      min_amount: 100,
      min_amount_raw: 100,
      max_discount_amount: null,
      max_discount_amount_raw: null,
      billing_cycles: ['monthly'],
      billing_cycle_text: '月付',
      product_ids: [101],
      product_scope_text: '指定 1 个商品',
      first_order_only: true,
      per_user_limit: 1,
      status: 1,
      display_status: 'active',
      display_status_label: '运行中',
      last_dispatched_at: '2026-06-05 18:00:00',
      last_coupon_name: '周五特惠 20260605',
      last_coupon_code: 'FRIDAY-0605',
      generated_coupon_count: 3,
      sort_order: 1,
      updated_at: '2026-06-06 10:00:00',
    },
  ];

  await page.route('**/api/v2/admin/coupon-campaigns**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname;

    if (pathname.endsWith('/coupon-campaigns') && request.method() === 'GET') {
      const keyword = url.searchParams.get('keyword') || '';
      const list = keyword ? [{ ...campaigns[0], name: '筛选活动', description: '筛选结果' }] : campaigns;
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            list,
            total: list.length,
            page: Number(url.searchParams.get('page') || 1),
            page_size: Number(url.searchParams.get('page_size') || 20),
          },
        }),
      });
      return;
    }

    if (pathname.endsWith('/coupon-campaigns') && request.method() === 'POST') {
      const body = request.postDataJSON();
      campaigns = [{ id: 602, ...body, display_status: 'active', display_status_label: '运行中' }, ...campaigns];
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: campaigns[0] }) });
      return;
    }

    if (pathname.endsWith('/tasks') && request.method() === 'POST') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: { dispatched: true } }),
      });
      return;
    }

    if (pathname.endsWith('/status') && request.method() === 'PATCH') {
      const id = Number(pathname.split('/').at(-2));
      campaigns = campaigns.map((item) =>
        item.id === id ? { ...item, status: Number(item.status) === 1 ? 0 : 1 } : item,
      );
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: {} }) });
      return;
    }

    const id = Number(pathname.split('/').at(-1));
    if (request.method() === 'PUT') {
      const body = request.postDataJSON();
      campaigns = campaigns.map((item) => (item.id === id ? { ...item, ...body } : item));
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: campaigns[0] }) });
      return;
    }

    if (request.method() === 'DELETE') {
      campaigns = campaigns.filter((item) => item.id !== id);
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: {} }) });
    }
  });
}

async function mockReferral(page: import('@playwright/test').Page) {
  const overview = {
    summary: {
      total_sales_amount: 12880,
      frozen_amount: 320,
      available_amount: 680,
      withdrawn_amount: 1200,
    },
    top_referrers: [
      {
        id: 21,
        display_name: '推广用户',
        email: 'referrer@example.test',
        member_level: { name: '金牌会员', reward_rate: 8 },
        total_sales_amount: 12880,
        referral_frozen_amount: 320,
        referral_available_amount: 680,
        referral_withdrawing_amount: 100,
        referral_withdrawn_amount: 1200,
      },
    ],
  };
  const rewardRow = (variant: 'default' | 'filtered' | 'page2' = 'default') => ({
    id: variant === 'page2' ? 702 : 701,
    referrer: {
      id: 21,
      display_name: variant === 'filtered' ? '筛选推荐人' : '推广用户',
      email: 'referrer@example.test',
    },
    referred_user: { id: 22, display_name: '新客户', email: 'new@example.test' },
    order: {
      order_no: variant === 'page2' ? 'ORD-REF-PAGE-002' : 'ORD-REF-001',
      product_spec_display: variant === 'filtered' ? '筛选云服务器' : '标准云服务器 2C4G',
    },
    product: { display_name: '标准云服务器' },
    order_amount: 200,
    reward_rate: 8,
    reward_amount: 16,
    status: variant === 'page2' ? 1 : 0,
    rewarded_at: '2026-06-06 10:00:00',
    available_at: '2026-06-13 10:00:00',
    released_at: variant === 'page2' ? '2026-06-14 10:00:00' : null,
    remark: variant === 'filtered' ? '筛选结果' : '首单返利',
  });
  let withdrawals: Record<string, unknown>[] = [
    {
      id: 801,
      user: { id: 22, display_name: '提现用户', email: 'withdraw@example.test' },
      amount: 88,
      method: 'alipay',
      account_name: '张三',
      account_no: 'alipay@example.test',
      status: 0,
      operator: null,
      remark: null,
      created_at: '2026-06-06 11:00:00',
      processed_at: null,
    },
    {
      id: 802,
      user: { id: 23, display_name: '待拒绝用户', email: 'reject@example.test' },
      amount: 66,
      method: 'alipay',
      account_name: '李四',
      account_no: 'reject@example.test',
      status: 0,
      operator: null,
      remark: null,
      created_at: '2026-06-06 12:00:00',
      processed_at: null,
    },
  ];

  await page.route('**/api/v2/admin/referral**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname;

    if (pathname.endsWith('/referral/overview')) {
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: overview }) });
      return;
    }

    if (pathname.endsWith('/referral/rewards')) {
      const keyword = url.searchParams.get('keyword');
      const pageNo = url.searchParams.get('page');
      const variant = keyword ? 'filtered' : pageNo === '2' ? 'page2' : 'default';
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: { list: [rewardRow(variant)], total: 21, page: Number(pageNo || 1), page_size: 20 },
        }),
      });
      return;
    }

    if (pathname.endsWith('/referral-withdrawals') && request.method() === 'GET') {
      const keyword = url.searchParams.get('keyword');
      const list = keyword
        ? [
            {
              ...withdrawals[0],
              user: { id: 24, display_name: '筛选提现用户', email: 'filtered-withdraw@example.test' },
            },
          ]
        : withdrawals;
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: { list, total: list.length, page: 1, page_size: 20 } }),
      });
      return;
    }

    const approveMatch = pathname.match(/\/referral-withdrawals\/(\d+)\/approvals$/);
    if (approveMatch && request.method() === 'POST') {
      const id = Number(approveMatch[1]);
      const body = request.postDataJSON();
      withdrawals = withdrawals.map((item) =>
        item.id === id
          ? {
              ...item,
              status: 1,
              operator: 'cerbo',
              remark: body.remark || '已通过',
              processed_at: '2026-06-06 13:00:00',
            }
          : item,
      );
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: withdrawals.find((item) => item.id === id) }),
      });
      return;
    }

    const rejectMatch = pathname.match(/\/referral-withdrawals\/(\d+)\/rejections$/);
    if (rejectMatch && request.method() === 'POST') {
      const id = Number(rejectMatch[1]);
      const body = request.postDataJSON();
      withdrawals = withdrawals.map((item) =>
        item.id === id
          ? {
              ...item,
              status: 2,
              operator: 'cerbo',
              remark: body.remark || '已拒绝',
              processed_at: '2026-06-06 14:00:00',
            }
          : item,
      );
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: withdrawals.find((item) => item.id === id) }),
      });
      return;
    }

    await route.fallback();
  });
}

async function mockMemberLevels(page: import('@playwright/test').Page) {
  let levels: Record<string, unknown>[] = [
    {
      id: 901,
      name: '黄金会员',
      code: 'gold',
      sales_amount_min: 1000,
      sales_amount_max: 9999,
      reward_rate: 8,
      status: 1,
      sort_order: 10,
      remark: '核心推广等级',
      created_at: '2026-06-01 10:00:00',
      updated_at: '2026-06-06 10:00:00',
    },
    {
      id: 902,
      name: '白银会员',
      code: 'silver',
      sales_amount_min: 100,
      sales_amount_max: 999,
      reward_rate: 5,
      status: 0,
      sort_order: 20,
      remark: '基础等级',
      created_at: '2026-06-01 10:00:00',
      updated_at: '2026-06-06 10:00:00',
    },
  ];

  await page.route('**/api/v2/admin/member-levels**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname;

    if (pathname.endsWith('/member-levels') && request.method() === 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: { list: levels, total: levels.length } }),
      });
      return;
    }

    if (pathname.endsWith('/member-levels') && request.method() === 'POST') {
      const body = request.postDataJSON();
      levels = [{ id: 903, ...body, created_at: '2026-06-06 12:00:00', updated_at: '2026-06-06 12:00:00' }, ...levels];
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: levels[0] }) });
      return;
    }

    const match = pathname.match(/\/member-levels\/(\d+)$/);
    if (match && request.method() === 'PUT') {
      const id = Number(match[1]);
      const body = request.postDataJSON();
      levels = levels.map((item) => (item.id === id ? { ...item, ...body, updated_at: '2026-06-06 13:00:00' } : item));
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: levels.find((item) => item.id === id) }),
      });
      return;
    }

    if (match && request.method() === 'DELETE') {
      const id = Number(match[1]);
      levels = levels.filter((item) => item.id !== id);
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: {} }) });
      return;
    }

    await route.fallback();
  });
}

async function mockContent(page: import('@playwright/test').Page) {
  const categoriesByType: Record<string, Record<string, unknown>[]> = {
    notice: [{ id: 1001, name: '系统通知', slug: 'system', status: 1, sort_order: 10, articles_count: 1 }],
    help: [{ id: 1002, name: '新手指南', slug: 'guide', status: 1, sort_order: 10, articles_count: 1 }],
  };
  const articleRow = (
    type: string,
    variant: 'default' | 'filtered' | 'page2' = 'default',
  ): Record<string, unknown> => ({
    id: type === 'help' ? 1201 : variant === 'page2' ? 1102 : 1101,
    title:
      type === 'help'
        ? '新手指南文章'
        : variant === 'filtered'
          ? '筛选公告'
          : variant === 'page2'
            ? '第二页公告'
            : '平台维护公告',
    category_id: type === 'help' ? 1002 : 1001,
    category_name: type === 'help' ? '新手指南' : '系统通知',
    slug: type === 'help' ? 'getting-started' : 'maintenance',
    summary: variant === 'filtered' ? '筛选摘要' : '维护窗口说明',
    content: variant === 'filtered' ? '筛选正文' : '公告正文内容',
    status: 1,
    status_label: '已发布',
    is_pinned: variant === 'page2' ? 0 : 1,
    is_recommended: 0,
    cover_image: '',
    sort_order: 10,
    publish_at: '2026-06-06 10:00:00',
    view_count: 18,
    operator: 'cerbo',
    remark: '测试内容',
    created_at: '2026-06-06 09:00:00',
    updated_at: '2026-06-06 10:00:00',
  });
  const articlesByType: Record<string, Record<string, unknown>[]> = {
    notice: [articleRow('notice')],
    help: [articleRow('help')],
  };

  await page.route('**/api/v2/admin/content/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname;
    const type = url.searchParams.get('content_type') || 'notice';

    if (pathname.endsWith('/content/categories') && request.method() === 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: categoriesByType[type] || [] }),
      });
      return;
    }

    if (pathname.endsWith('/content/categories') && request.method() === 'POST') {
      const body = request.postDataJSON();
      const list = categoriesByType[body.content_type] || [];
      const item = { id: 1003, ...body, articles_count: 0 };
      categoriesByType[body.content_type] = [item, ...list];
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: item }) });
      return;
    }

    if (pathname.endsWith('/content/articles') && request.method() === 'GET') {
      const keyword = url.searchParams.get('keyword');
      const pageNo = url.searchParams.get('page');
      const variant = keyword ? 'filtered' : pageNo === '2' ? 'page2' : 'default';
      const list = keyword || pageNo === '2' ? [articleRow(type, variant)] : articlesByType[type] || [];
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: { list, total: 21, page: Number(pageNo || 1), page_size: 20 } }),
      });
      return;
    }

    if (pathname.endsWith('/content/articles') && request.method() === 'POST') {
      const body = request.postDataJSON();
      const item = {
        id: 1103,
        ...body,
        category_name: '系统通知',
        status_label: '已发布',
        view_count: 0,
        updated_at: '2026-06-06 12:00:00',
      };
      articlesByType[body.content_type] = [item, ...(articlesByType[body.content_type] || [])];
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: item }) });
      return;
    }

    const articleMatch = pathname.match(/\/content\/articles\/(\d+)$/);
    if (articleMatch && request.method() === 'GET') {
      const id = Number(articleMatch[1]);
      const all = [...articlesByType.notice, ...articlesByType.help, articleRow('notice', 'page2')];
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: all.find((item) => item.id === id) || articleRow(type) }),
      });
      return;
    }

    if (articleMatch && request.method() === 'PUT') {
      const id = Number(articleMatch[1]);
      const body = request.postDataJSON();
      const list = articlesByType[body.content_type] || [];
      articlesByType[body.content_type] = list.map((item) =>
        item.id === id ? { ...item, ...body, updated_at: '2026-06-06 13:00:00' } : item,
      );
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: articlesByType[body.content_type].find((item) => item.id === id) }),
      });
      return;
    }

    if (articleMatch && request.method() === 'DELETE') {
      const id = Number(articleMatch[1]);
      articlesByType.notice = articlesByType.notice.filter((item) => item.id !== id);
      articlesByType.help = articlesByType.help.filter((item) => item.id !== id);
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: {} }) });
      return;
    }

    await route.fallback();
  });
}

const notificationEmailContentDefault =
  '<style>.cw-email-template-100001 { color: #1f5eff; } .cw-email-template-100001 .email-logo { height: 32px; }</style><div class="cw-email-template-100001">{{#site_logo}}<img class="email-logo" src="{{site_logo}}" alt="{{site_name}}">{{/site_logo}}<strong>{{site_name}}</strong><p>默认验证码 {{code}}</p></div>';

async function mockNotifications(page: import('@playwright/test').Page) {
  const emailContentDefault = notificationEmailContentDefault;
  const emailContentOverride =
    '<p>{{#site_logo}}<img class="email-logo" src="{{site_logo}}" alt="{{site_name}}">{{/site_logo}}验证码 {{code}}</p>';
  interface MockNotificationTemplate {
    channel: 'email' | 'sms';
    code: string;
    name: string;
    description: string;
    audience: 'admin' | 'user';
    subject: string | null;
    content: string;
    provider_template_id: string;
    variables: string[];
    provider_variables: string[];
    setting_keys: Record<string, string>;
  }

  await page.route(/\/api\/v2\/admin\/notification-templates(?:\?.*)?$/, async (route) => {
    const url = new URL(route.request().url());
    const channel = url.searchParams.get('channel') || 'email';
    const list: MockNotificationTemplate[] =
      channel === 'sms'
        ? [
            {
              channel: 'sms',
              code: '100001',
              name: '发送验证码',
              description: '验证码短信模板。',
              audience: 'user',
              subject: null,
              content: '验证码 {code}',
              provider_template_id: 'SMS_001',
              variables: ['code'],
              provider_variables: ['code'],
              setting_keys: {
                content: 'sms_template_content_100001',
                provider_template_id: 'sms_template_provider_template_id_100001',
              },
            },
          ]
        : [
            {
              channel: 'email',
              code: '100001',
              name: '验证码邮件',
              description: '发送邮箱验证码时使用。',
              audience: 'user',
              subject: '验证码邮件',
              content: emailContentDefault,
              provider_template_id: '',
              variables: ['site_name', 'site_logo', 'code', 'expire_minutes'],
              provider_variables: [],
              setting_keys: {
                subject: 'email_template_subject_100001',
                content: 'email_template_content_100001',
              },
            },
            {
              channel: 'email',
              code: '100026',
              name: '管理员新工单提示',
              description: '客户提交新工单后通知管理员。',
              audience: 'admin',
              subject: '管理员新工单提示',
              content: '<style>.cw-email-template-100026 { color: #7c3aed; }</style><div>工单 #{{ticket_id}}</div>',
              provider_template_id: '',
              variables: ['ticket_id', 'ticket_subject'],
              provider_variables: [],
              setting_keys: {
                subject: 'email_template_subject_100026',
                content: 'email_template_content_100026',
              },
            },
          ];

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ code: 0, data: { list, total: list.length } }),
    });
  });

  await page.route(/\/api\/v2\/admin\/settings(?:\?.*)?$/, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() === 'POST') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '保存成功', data: {} }),
      });
      return;
    }

    if (url.searchParams.get('group') === 'basic') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            list: [
              { key: 'site_name', value: '图拉云' },
              { key: 'site_logo', value: '/uploads/site/logo-new.png' },
            ],
          },
        }),
      });
      return;
    }

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [
            { key: 'email_enabled', value: 1 },
            { key: 'email_host', value: 'smtp.example.test' },
            { key: 'email_port', value: '465' },
            { key: 'email_username', value: 'notice@example.test' },
            { key: 'email_password', value: 'mail-secret' },
            { key: 'email_from_name', value: '图拉云通知' },
            { key: 'sms_enabled', value: 1 },
            { key: 'sms_provider', value: 'aliyun' },
            { key: 'sms_access_key', value: 'sms-ak' },
            { key: 'sms_secret_key', value: 'sms-sk' },
            { key: 'sms_sign_name', value: '图拉云' },
            { key: 'sms_template_code', value: 'SMS_001' },
            { key: 'email_template_subject_100001', value: '测试验证码邮件' },
            { key: 'email_template_content_100001', value: emailContentOverride },
            { key: 'email_template_subject_100026', value: '测试管理员新工单提示' },
            { key: 'email_template_content_100026', value: '工单 #{{ticket_id}}' },
          ],
        },
      }),
    });
  });
}

async function mockInvoices(page: import('@playwright/test').Page) {
  let cancelled = false;
  const invoiceRow = (keyword = ''): Record<string, unknown> => ({
    id: 900,
    invoice_no: keyword ? 'INV-FILTERED-001' : 'INV-20260606-001',
    type: 'new',
    type_label: '新购',
    amount: 128.5,
    paid_amount: cancelled ? 0 : 0,
    status: cancelled ? 2 : 0,
    raw_status: cancelled ? 2 : 0,
    status_label: cancelled ? '已取消' : '待支付',
    created_at: '2026-06-06 10:00:00',
    paid_at: null,
    due_date: '2026-06-13',
    user: { id: 1, nickname: '测试用户', email: '2908990438@qq.com' },
    order: { id: 700, order_no: 'ORD-20260606-001' },
    product_display_name: keyword ? '筛选云服务器' : '标准云服务器',
    product_spec_display: '2C4G',
    combined_display_name: keyword ? '筛选云服务器 2C4G' : '标准云服务器 2C4G',
    summary: { highlight: '新购云服务器' },
    payment_summary: { gateway_label: '余额支付' },
  });

  await page.route('**/api/v2/admin/invoices**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname;

    if (pathname.endsWith('/cancellations')) {
      cancelled = true;
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: {} }) });
      return;
    }

    if (pathname.endsWith('/invoices/900')) {
      const invoice = invoiceRow();
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            invoice: {
              id: invoice.id,
              basic: {
                invoice_no: invoice.invoice_no,
                type: invoice.type,
                type_label: invoice.type_label,
                status: invoice.status,
                status_label: invoice.status_label,
                raw_status: invoice.raw_status,
                raw_status_label: invoice.status_label,
                billing_cycle: 'monthly',
                quantity: 1,
                due_date: invoice.due_date,
              },
              display: {
                product_spec_snapshot: '2C4G',
                product_spec_display: invoice.product_spec_display,
                product_display_name: invoice.product_display_name,
                combined_display_name: invoice.combined_display_name,
                summary: invoice.summary,
              },
              financial: {
                amount: invoice.amount,
                discount: 0,
                paid_amount: invoice.paid_amount,
                payable_amount: invoice.amount,
                paid_at: invoice.paid_at,
              },
              user: invoice.user,
              order: invoice.order,
              product: {
                id: 101,
                name: invoice.product_display_name,
                full_path: invoice.combined_display_name,
                type: 'cloud_server',
              },
              service: null,
              configuration: { config_snapshot: {}, config_pricing_snapshot: {}, coupon_snapshot: null },
              payment_chain: {
                payment_summary: invoice.payment_summary,
                payments: [
                  {
                    id: 1,
                    payment_no: 'PAY-001',
                    gateway_label: '余额支付',
                    amount: 128.5,
                    created_at: '2026-06-06 10:01:00',
                  },
                ],
              },
              items: [{ id: 1, description: '标准云服务器 2C4G', amount: 128.5 }],
              logs: [{ id: 1, summary: '账单已创建', created_at: '2026-06-06 10:00:00' }],
              audit: { trace_id: 'trace-invoice-900' },
              actions: { can_cancel: !cancelled },
              timestamps: { created_at: invoice.created_at, updated_at: invoice.created_at },
            },
          },
        }),
      });
      return;
    }

    if (pathname.endsWith('/invoices') && request.method() === 'GET') {
      const keyword = url.searchParams.get('keyword') || '';
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            list: [invoiceRow(keyword)],
            total: 1,
            page: Number(url.searchParams.get('page') || 1),
            page_size: Number(url.searchParams.get('page_size') || 20),
          },
        }),
      });
    }
  });
}

async function mockOrders(page: import('@playwright/test').Page) {
  const orderRow = (variant: 'default' | 'filtered' | 'page2' = 'default'): Record<string, unknown> => ({
    id: variant === 'page2' ? 801 : 800,
    order_no: variant === 'filtered' ? 'ORD-FILTERED-001' : variant === 'page2' ? 'ORD-PAGE-002' : 'ORD-20260606-001',
    type: 'new',
    type_label: '新购',
    amount: variant === 'page2' ? 256 : 128.5,
    quantity: 1,
    status: variant === 'page2' ? 1 : 0,
    status_label: variant === 'page2' ? '已付款' : '待付款',
    created_at: '2026-06-06 10:00:00',
    user_id: 1,
    user: { id: 1, nickname: variant === 'filtered' ? '筛选用户' : '测试用户', email: '2908990438@qq.com' },
    product_name: variant === 'filtered' ? '筛选云服务器' : '标准云服务器',
    service: { id: 11, name: variant === 'page2' ? 'page2-vm' : 'vm-001' },
    invoice: {
      id: 900,
      invoice_no: variant === 'filtered' ? 'INV-FILTERED-001' : 'INV-20260606-001',
      paid_at: variant === 'page2' ? '2026-06-06 10:02:00' : null,
    },
  });

  await page.route('**/api/v2/admin/orders**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const keyword = url.searchParams.get('keyword') || '';
    const pageIndex = Number(url.searchParams.get('page') || 1);
    const variant = keyword ? 'filtered' : pageIndex === 2 ? 'page2' : 'default';

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [orderRow(variant)],
          total: 21,
          page: pageIndex,
          page_size: Number(url.searchParams.get('page_size') || 20),
        },
      }),
    });
  });
}

async function mockOrderDetail(page: import('@playwright/test').Page, variant: 'new' | 'renew' = 'new') {
  await page.route(/\/api\/v2\/admin\/orders\/\d+(?:\?.*)?$/, async (route) => {
    const isNew = variant === 'new';
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          order: {
            id: 800,
            basic: {
              order_no: 'ORD-DETAIL-001',
              type: isNew ? 'new' : 'renew',
              type_label: isNew ? '新购' : '续费',
              status: 3,
              status_label: '已完成',
              billing_cycle: 'monthly',
              quantity: 1,
              remark: '',
            },
            financial: {
              amount: '128.50',
              discount: '0.00',
              paid_amount: '128.50',
              paid_at: '2026-06-06 10:02:00',
            },
            user: { id: 1, email: '2908990438@qq.com', nickname: '测试用户', phone: '' },
            invoice: {
              id: 900,
              invoice_no: 'INV-DETAIL-001',
              status: 1,
              amount: '128.50',
              paid_amount: '128.50',
              paid_at: '2026-06-06 10:02:00',
            },
            product: { id: 10, name: '标准云服务器', full_path: '云产品 / 标准云服务器', type: 'vps' },
            service: {
              id: 11,
              name: 'vm-001',
              domain: 'vm-001.example.test',
              status: 1,
              expires_at: '2026-07-06 10:00:00',
            },
            coupon: null,
            configuration: {
              config_snapshot: { cpu: '2', memory: '4G' },
              config_pricing_snapshot: { total_amount: '128.50' },
              service_snapshot: isNew ? { instance_id: 11, hostname: 'vm-001.example.test' } : null,
            },
            payment_chain: { payments: [] },
            audit: { trace_id: 'trace-order-detail-001' },
            timestamps: { created_at: '2026-06-06 10:00:00', updated_at: '2026-06-06 10:02:00' },
          },
        },
      }),
    });
  });
}

async function mockFinanceModeOrders(page: import('@playwright/test').Page) {
  const modeOrderRow = (
    mode: 'renewals' | 'upgrade',
    variant: 'default' | 'filtered' | 'page2' = 'default',
  ): Record<string, unknown> => ({
    id: mode === 'renewals' ? 820 : 830,
    order_no:
      variant === 'filtered'
        ? mode === 'renewals'
          ? 'REN-FILTERED-001'
          : 'UPG-FILTERED-001'
        : variant === 'page2'
          ? mode === 'renewals'
            ? 'REN-PAGE-002'
            : 'UPG-PAGE-002'
          : mode === 'renewals'
            ? 'REN-20260606-001'
            : 'UPG-20260606-001',
    type: mode === 'renewals' ? 'renew' : 'upgrade',
    type_label: mode === 'renewals' ? '续费' : '附加配置',
    upgrade_kind_label: mode === 'upgrade' ? '流量包' : undefined,
    upgrade_target_label: mode === 'upgrade' ? '100GB 流量包' : undefined,
    amount: mode === 'renewals' ? 88 : 20,
    quantity: mode === 'renewals' ? 1 : 2,
    status: variant === 'page2' ? 3 : 1,
    status_label: variant === 'page2' ? '已完成' : '已付款',
    created_at: '2026-06-06 10:00:00',
    user_id: 1,
    user: { id: 1, nickname: variant === 'filtered' ? '筛选用户' : '测试用户', email: '2908990438@qq.com' },
    product_name: mode === 'renewals' ? '标准云服务器' : '标准云服务器附加配置',
    service: { id: 11, name: mode === 'renewals' ? 'renew-vm' : 'upgrade-vm' },
    invoice: {
      id: mode === 'renewals' ? 920 : 930,
      invoice_no: mode === 'renewals' ? 'INV-RENEW-001' : 'INV-UPGRADE-001',
      paid_at: '2026-06-06 10:02:00',
    },
  });

  const fulfillList = async (route: import('@playwright/test').Route, mode: 'renewals' | 'upgrade') => {
    const url = new URL(route.request().url());
    const keyword = url.searchParams.get('keyword') || '';
    const pageIndex = Number(url.searchParams.get('page') || 1);
    const variant = keyword ? 'filtered' : pageIndex === 2 ? 'page2' : 'default';

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [modeOrderRow(mode, variant)],
          total: 21,
          page: pageIndex,
          page_size: Number(url.searchParams.get('page_size') || 20),
        },
      }),
    });
  };

  await page.route('**/api/v2/admin/finance/renewal-orders**', async (route) => fulfillList(route, 'renewals'));
  await page.route('**/api/v2/admin/finance/upgrade-orders**', async (route) => fulfillList(route, 'upgrade'));
}

async function mockRecharges(page: import('@playwright/test').Page) {
  const rechargeRow = (variant: 'default' | 'filtered' | 'page2' = 'default'): Record<string, unknown> => ({
    id: variant === 'page2' ? 911 : 910,
    payment_no: variant === 'filtered' ? 'PAY-FILTERED-001' : variant === 'page2' ? 'PAY-PAGE-002' : 'PAY-RECHARGE-001',
    invoice_no: variant === 'filtered' ? 'RC-FILTERED-001' : variant === 'page2' ? 'RC-PAGE-002' : 'RC-20260606-001',
    invoice_id: variant === 'page2' ? 911 : 910,
    gateway: 'alipay',
    trade_no: 'TRADE-20260606',
    amount: variant === 'page2' ? 300 : 200,
    paid_amount: variant === 'page2' ? 300 : 200,
    status: variant === 'default' ? 1 : 0,
    status_label: variant === 'default' ? '已支付' : '待支付',
    created_at: '2026-06-06 10:00:00',
    paid_at: variant === 'default' ? '2026-06-06 10:02:00' : null,
    user: { id: 1, nickname: variant === 'filtered' ? '筛选充值用户' : '测试用户', email: '2908990438@qq.com' },
    payment: {
      id: 1,
      payment_no:
        variant === 'filtered' ? 'PAY-FILTERED-001' : variant === 'page2' ? 'PAY-PAGE-002' : 'PAY-RECHARGE-001',
      gateway: 'alipay',
      trade_no: 'TRADE-20260606',
    },
  });

  await page.route('**/api/v2/admin/finance/recharges**', async (route) => {
    const url = new URL(route.request().url());
    const keyword = url.searchParams.get('keyword') || '';
    const pageIndex = Number(url.searchParams.get('page') || 1);
    const variant = keyword ? 'filtered' : pageIndex === 2 ? 'page2' : 'default';

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [rechargeRow(variant)],
          total: 21,
          page: pageIndex,
          page_size: Number(url.searchParams.get('page_size') || 20),
        },
      }),
    });
  });
}

async function mockNewCustomers(page: import('@playwright/test').Page) {
  await page.route('**/api/v2/admin/finance/new-customer-daily-summary**', async (route) => {
    const url = new URL(route.request().url());
    const isFiltered = url.searchParams.get('start_date') === '2026-05-01';
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          summary: isFiltered
            ? {
                new_customers: 3,
                new_orders: 4,
                completed_orders: 2,
                new_tickets: 1,
                ticket_replies: 6,
                cancel_requests: 0,
              }
            : {
                new_customers: 12,
                new_orders: 18,
                completed_orders: 9,
                new_tickets: 5,
                ticket_replies: 21,
                cancel_requests: 2,
              },
          list: [
            isFiltered
              ? {
                  date: '2026-05-02',
                  new_customers: 3,
                  new_orders: 4,
                  completed_orders: 2,
                  new_tickets: 1,
                  ticket_replies: 6,
                  cancel_requests: 0,
                }
              : {
                  date: '2026-06-06',
                  new_customers: 12,
                  new_orders: 18,
                  completed_orders: 9,
                  new_tickets: 5,
                  ticket_replies: 21,
                  cancel_requests: 2,
                },
          ],
        },
      }),
    });
  });
}

async function mockServices(page: import('@playwright/test').Page) {
  const serviceRow = (variant: 'default' | 'filtered' | 'page2' = 'default'): Record<string, unknown> => ({
    id: variant === 'page2' ? 12 : 11,
    service_id: variant === 'filtered' ? 'SVC-FILTERED-001' : variant === 'page2' ? 'SVC-PAGE-002' : 'SVC-20260606-001',
    name: variant === 'filtered' ? '筛选服务' : '标准云服务器实例',
    domain: variant === 'page2' ? 'page2-vm' : 'vm-001',
    custom_hostname: variant === 'default' ? 'old-hostname' : '',
    product_id: 101,
    product_display_name: variant === 'filtered' ? '筛选云服务器 2C4G' : '标准云服务器 2C4G',
    status: variant === 'page2' ? 3 : 1,
    status_label: variant === 'page2' ? '已到期' : '已开通',
    amount: variant === 'page2' ? 188 : 88,
    billing_cycle: 'monthly',
    expires_at: '2026-07-06 10:00:00',
    created_at: '2026-06-06 10:00:00',
    upstream_host_id: 9001,
    upstream_host_id_text: 'MF-9001',
    host_ips: ['192.0.2.10'],
    host_username: 'root',
    connection: { username: 'root' },
    user: { id: 1, username: '测试用户', email: '2908990438@qq.com' },
    invoice: { id: 900, invoice_no: 'INV-SERVICE-001' },
  });

  await page.route('**/api/v2/admin/services**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname;

    if (pathname.endsWith('/custom-hostnames/batch')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '自定义主机名已更新', data: {} }),
      });
      return;
    }

    const keyword = url.searchParams.get('keyword') || '';
    const pageIndex = Number(url.searchParams.get('page') || 1);
    const variant = keyword ? 'filtered' : pageIndex === 2 ? 'page2' : 'default';

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [serviceRow(variant)],
          total: 21,
          page: pageIndex,
          page_size: Number(url.searchParams.get('page_size') || 20),
        },
      }),
    });
  });
}

async function mockUserDetail(page: import('@playwright/test').Page) {
  await mockNestedCouponProductTree(page);
  await page.route('**/api/v2/admin/product-groups**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: { tree: [{ id: 1, name: '云服务器', product_type_label: '云服务器' }] },
      }),
    });
  });
  await page.route(/\/api\/v2\/admin\/products(?:\/\d+)?(?:\?.*)?$/, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname;

    if (pathname.endsWith('/products/101')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            product: {
              id: 101,
              display: { display_name: '高宽云服务器 2C4G' },
              classification: { product_type: 'cloud_server', product_type_label: '云服务器' },
              pricing: {
                items: { monthly: 99 },
                primary_price: { cycle: 'monthly', amount: '99.00' },
              },
            },
          },
        }),
      });
      return;
    }

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [
            {
              id: 101,
              name: '测试云服务器套餐',
              display_name: '测试云服务器套餐',
              product_type_label: '云服务器',
              product_group_id: 1,
            },
          ],
          total: 1,
          page: Number(url.searchParams.get('page') || 1),
          page_size: Number(url.searchParams.get('page_size') || 100),
        },
      }),
    });
  });
  await page.route('**/api/v2/admin/suppliers**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [{ id: 3, name: 'ZJMF 财务', interface_type: 'zjmf_finance_api', interface_type_label: 'ZJMF 财务' }],
          total: 1,
        },
      }),
    });
  });
  await page.route('**/api/v2/admin/os-options**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: { groups: [{ label: 'Linux', value: 'linux', children: [{ label: 'Debian 12', value: 'debian-12' }] }] },
      }),
    });
  });
  await page.route('**/api/v2/admin/invoices/**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ code: 0, message: '操作成功', data: {} }),
    });
  });
  await page.route('**/api/v2/admin/users/1**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname;

    if (request.method() !== 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          message: pathname.endsWith('/login-as') ? 'OK' : '操作成功',
          data: pathname.endsWith('/login-as')
            ? { login_code: 'LOGIN-AS-CODE', target_url: `${url.origin}/client/login-as` }
            : {},
        }),
      });
      return;
    }

    const pageIndex = Number(url.searchParams.get('page') || 1);
    const pageSize = Number(url.searchParams.get('page_size') || 10);
    const paged = (list: Array<Record<string, unknown>>) => ({
      code: 0,
      data: { list, total: list.length, page: pageIndex, page_size: pageSize },
    });

    if (pathname.endsWith('/services/11/remote-status')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            service: {
              id: 11,
              runtime: { power_state: 'running', power_label: '运行中' },
              upstream: { status_label: '在线' },
            },
          },
        }),
      });
      return;
    }

    if (pathname.endsWith('/services/11/connection')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: { connection: { dedicated_ip: '192.0.2.10', username: 'root', port: 22 } },
        }),
      });
      return;
    }

    if (pathname.endsWith('/services/11')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            service: {
              id: 11,
              name: '测试云服务器',
              status: 1,
              status_label: '运行中',
              billing_cycle_label: '月付',
              amount: 88,
              expires_at: '2026-07-06 10:00:00',
              invoice: { id: 21, invoice_no: 'INV-USER-001', status: 1 },
              order: { id: 71, invoice_no: 'INV-USER-001', amount: 88 },
              upstream: { provider: 'zjmf_finance_api', supplier_id: 3, host_id: 9001, status_label: '在线' },
              runtime: { power_state: 'running', power_label: '运行中' },
              specs: [{ label: 'CPU', value: '2 核' }],
              renewal: { cycles: [{ billing_cycle: 'monthly', enabled: true, base_amount: 88, manual_amount: 88 }] },
              refund: { amount: 88, can_original: false, original_blocked_reason: '余额支付不支持原路退款' },
              actions: {
                password_reset: true,
                manual_provision: true,
                available: ['power:off', 'power:reboot', 'refund'],
              },
            },
          },
        }),
      });
      return;
    }

    if (pathname.endsWith('/services')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(
          paged([
            {
              id: 11,
              name: '测试云服务器',
              domain: 'vm-001',
              amount: 88,
              status: 1,
              status_label: '运行中',
              status_tone: 'success',
              billing_cycle_label: '月付',
              created_at: '2026-06-06 10:00:00',
              expires_at: '2026-07-06 10:00:00',
              product: { type_label: '云服务器', group_name: '计算' },
              order: { id: 71, invoice_no: 'INV-USER-001' },
              upstream: { dedicated_ip: '192.0.2.10' },
            },
          ]),
        ),
      });
      return;
    }

    if (pathname.endsWith('/invoices/21')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            invoice: {
              id: 21,
              invoice_no: 'INV-USER-001',
              amount: 88,
              paid_amount: 88,
              status: 1,
              status_label: '已支付',
              type: 'new',
              type_label: '新购',
              created_at: '2026-06-06 10:00:00',
              paid_at: '2026-06-06 10:01:00',
              order: { id: 71, order_no: 'ORD-USER-001', status: 1 },
              product: { display_name: '测试云服务器套餐' },
              payment_summary: { gateway_label: '余额' },
              scene: { items: [{ id: 'item-1', description: '测试云服务器套餐', amount: 88 }] },
            },
            payments: [
              { id: 91, payment_no: 'PAY-USER-001', gateway: 'balance', gateway_label: '余额', status: 1, amount: 88 },
            ],
            logs: [{ id: 1, action: 'paid', summary: '账单已支付', created_at: '2026-06-06 10:01:00' }],
          },
        }),
      });
      return;
    }

    if (pathname.endsWith('/invoices')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(
          paged([
            {
              id: 21,
              invoice_no: 'INV-USER-001',
              amount: 88,
              status: 1,
              status_label: '已支付',
              type: 'new',
              type_label: '新购',
              order: { id: 71, order_no: 'ORD-USER-001', status: 1 },
              payment_summary: { gateway_label: '余额' },
              created_at: '2026-06-06 10:00:00',
              paid_at: '2026-06-06 10:01:00',
              due_date: '2026-06-07',
            },
          ]),
        ),
      });
      return;
    }

    if (pathname.endsWith('/balance-logs')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(
          paged([
            {
              ledger_id: 31,
              event_type: 'manual_recharge',
              change_amount: 100,
              balance_after: 228.5,
              remark: '测试充值',
              operator: 'cerbo',
              occurred_at: '2026-06-06 10:00:00',
            },
          ]),
        ),
      });
      return;
    }

    if (pathname.endsWith('/tickets')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(
          paged([{ id: 41, subject: '测试工单', priority: 2, status: 0, created_at: '2026-06-06 10:00:00' }]),
        ),
      });
      return;
    }

    if (pathname.endsWith('/operation-logs')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(
          paged([
            {
              id: 51,
              action: 'admin.user.update',
              module: 'user',
              ip_address: '127.0.0.1',
              created_at: '2026-06-06 10:00:00',
            },
          ]),
        ),
      });
      return;
    }

    if (pathname.endsWith('/email-logs')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(
          paged([
            {
              id: 61,
              to_email: '2908990438@qq.com',
              subject: '测试邮件',
              status: 'success',
              sent_at: '2026-06-06 10:00:00',
            },
          ]),
        ),
      });
      return;
    }

    if (pathname.endsWith('/sms-logs')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(
          paged([
            {
              id: 62,
              phone: '13800000000',
              template_code: 'SMS_TEST',
              status: 'pending',
              created_at: '2026-06-06 10:00:00',
            },
          ]),
        ),
      });
      return;
    }

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          user: {
            id: 1,
            email: '2908990438@qq.com',
            phone: '13800000000',
            nickname: '测试用户',
            display_name: '测试用户',
            cash_balance: 128.5,
            credit_limit: 500,
            status: 1,
            is_verified: 1,
            real_name: '张三',
            id_card_masked: '110***********001',
            admin_note: '重点客户',
            created_at: '2026-06-06 10:00:00',
            last_login_at: '2026-06-06 11:00:00',
            last_login_ip: '127.0.0.1',
            member_level: { name: '黄金会员' },
          },
          stats: {
            ticket_open: 1,
            total_expense: 1888,
            direct_referral_count: 2,
            total_referral_reward: 66,
          },
          referral: {
            referral_code: 'REF001',
            referral_available_amount: 33,
            member_level: { name: '黄金会员' },
            recent_referrals: [
              { id: 2, nickname: '被推荐用户', email: 'ref@example.com', referred_at: '2026-06-06 12:00:00' },
            ],
          },
        },
      }),
    });
  });
}

async function mockVerifications(page: import('@playwright/test').Page) {
  await page.route(/\/api\/v2\/admin\/verifications(?:\/[^/?]+)?(?:\/history|\/summary)?(?:\?.*)?$/, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname;

    if (pathname.endsWith('/summary')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            stats: { total: 2, verified: 1, pending: 1, failed: 0, unbound: 0 },
            config: { verification_biz_code: 'FACE' },
          },
        }),
      });
      return;
    }

    if (pathname.endsWith('/history')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            user_name: '测试用户',
            list: [
              {
                id: 'history-1',
                real_name: '张三',
                id_card_masked: '110***********001',
                verification_status: 2,
                submitted_at: '2026-06-06 10:00:00',
              },
            ],
          },
        }),
      });
      return;
    }

    if (request.method() === 'POST') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '操作成功', data: {} }),
      });
      return;
    }

    const detailMatch = pathname.match(/\/api\/v2\/admin\/verifications\/([^/]+)$/);
    if (detailMatch) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            id: 1,
            display_name: '测试用户',
            email: 'user@example.com',
            phone: '13800000000',
            real_name: '张三',
            id_card_masked: '110***********001',
            verification_status: 2,
            verification_method_label: '人脸识别',
            verification_type_label: '个人认证',
            document_type_label: '居民身份证',
            identity_region_label: '大陆',
            verification_certify_id: 'CERT-001',
            verification_message: '',
            created_at: '2026-06-06 10:00:00',
          },
        }),
      });
      return;
    }

    const keyword = url.searchParams.get('keyword') || '';
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [
            {
              id: 1,
              display_name: keyword ? '筛选实名用户' : '测试用户',
              email: 'user@example.com',
              phone: '13800000000',
              real_name: keyword ? '李四' : '张三',
              id_card_masked: '110***********001',
              verification_status: 2,
              verification_message: '',
              created_at: '2026-06-06 10:00:00',
            },
          ],
          total: 1,
          page: Number(url.searchParams.get('page') || 1),
          page_size: Number(url.searchParams.get('page_size') || 20),
        },
      }),
    });
  });

  await page.route(/\/api\/v2\/admin\/settings(?:\?.*)?$/, async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '保存成功', data: {} }),
      });
      return;
    }
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          list: [
            { key: 'verification_api', value: 'api-id' },
            { key: 'verification_key', value: 'api-key' },
            { key: 'verification_biz_code', value: 'FACE' },
            { key: 'free_attempts', value: 3 },
            { key: 'retry_fee', value: 2 },
          ],
        },
      }),
    });
  });
}

async function mockLogin(page: import('@playwright/test').Page) {
  await page.route('**/api/v2/admin/login**', async (route) => {
    const body = route.request().postDataJSON() as { username?: string; password?: string };
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: body.username && body.password ? 0 : 422,
        message: body.username && body.password ? '登录成功' : '参数错误',
        data: {
          token: 'login-token',
          admin: {
            id: 1,
            username: 'cerbo',
            nickname: 'cerbo',
            email: 'admin@example.com',
            permissions: ['*'],
          },
        },
      }),
    });
  });
}

async function mockLogs(page: import('@playwright/test').Page) {
  await page.route(
    /\/api\/v2\/admin\/(?:logs(?:\/[^?]*)?|log-summaries\/[^/?]+|log-cleanups(?:\/[^?]*)?)(?:\?.*)?$/,
    async (route) => {
      const request = route.request();
      const url = new URL(request.url());
      const pathname = url.pathname;
      const pageIndex = Number(url.searchParams.get('page') || 1);
      const keyword = url.searchParams.get('keyword') || '';
      const status = url.searchParams.get('status') || '';
      const paginator = (row: Record<string, unknown>, total = 21) => ({
        list: [row],
        total,
        page: pageIndex,
        page_size: Number(url.searchParams.get('page_size') || 20),
        summary: {},
      });

      if (pathname.endsWith('/log-summaries/system')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({ code: 0, data: { total: 21, errors: 2, warnings: 3, infos: 16 } }),
        });
        return;
      }
      if (pathname.endsWith('/log-summaries/sms') || pathname.endsWith('/log-summaries/email')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({ code: 0, data: { total: 21, success: 18, failed: 2, pending: 1 } }),
        });
        return;
      }
      if (pathname.endsWith('/log-summaries/tasks')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({ code: 0, data: { total: 21, tasks: 8, errors: 1 } }),
        });
        return;
      }
      if (pathname.endsWith('/log-cleanups/overview') && request.method() === 'GET') {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            data: {
              database: { sms: 12, email: 8, api: 33, admin_login: 5 },
              file: {
                exists: true,
                size_bytes: 2048,
                task_log_count: 9,
                system_log_count: 18,
                path: 'storage/logs/laravel.log',
                updated_at: '2026-06-06 10:30:00',
              },
              supported_cleanup_types: [
                { label: '短信日志', value: 'sms' },
                { label: '邮件日志', value: 'email' },
                { label: 'API 日志', value: 'api' },
              ],
            },
          }),
        });
        return;
      }
      if (pathname.endsWith('/log-cleanups') && request.method() === 'POST') {
        const body = request.postDataJSON();
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            message: '日志清理完成',
            data: {
              id: 'cleanup-1',
              status: 'completed',
              detail: {
                cleanup: {
                  type: body.type,
                  keep_days: body.keep_days,
                  cutoff_at: '2026-05-07 00:00:00',
                  affected: { sms: 3 },
                },
              },
            },
          }),
        });
        return;
      }
      if (/\/api\/v2\/admin\/logs\/system\/[^/]+$/.test(pathname)) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            data: {
              log: {
                id: 1,
                channel: 'system',
                source: 'system',
                fields: { id: 1, level: 'WARNING', raw: '[INFO] Filtered system warning' },
                message: '[INFO] Filtered system warning',
                context: { keyword: 'filtered' },
                created_at: '2026-06-06 10:00:00',
              },
            },
          }),
        });
        return;
      }
      if (pathname.endsWith('/logs/system')) {
        const message = pageIndex === 2 ? 'System page 2' : keyword ? 'Filtered system warning' : 'System boot ok';
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            data: paginator({
              id: pageIndex,
              channel: 'system',
              source: 'system',
              level: keyword ? 'WARNING' : 'INFO',
              message_excerpt: message,
              error_excerpt: '',
              context_excerpt: `[INFO] ${message}`,
              created_at: '2026-06-06 10:00:00',
            }),
          }),
        });
        return;
      }
      if (pathname.endsWith('/logs/admin-logins')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            data: paginator({
              id: 101,
              channel: 'admin-logins',
              admin_username: 'cerbo',
              admin_nickname: '主账号',
              role_name: 'super_admin',
              ip_address: '127.0.0.1',
              source: 'operation_log',
              message_excerpt: '管理员登录成功',
              context_excerpt: 'Playwright',
              created_at: '2026-06-06 10:01:00',
            }),
          }),
        });
        return;
      }
      if (pathname.endsWith('/logs/api')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            data: paginator({
              id: 201,
              channel: 'api',
              method: 'GET',
              path: keyword ? '/api/v2/admin/logs/api' : '/api/v2/admin/users',
              status: 200,
              module: 'admin',
              actor_name: 'cerbo',
              user_type: 'admin',
              ip_address: '127.0.0.1',
              request_id: 'REQ-LOG-001',
              message_excerpt: 'API 请求完成',
              context_excerpt: 'admin.logs.api',
              created_at: '2026-06-06 10:02:00',
            }),
          }),
        });
        return;
      }
      if (pathname.endsWith('/logs/sms')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            data: paginator({
              id: 301,
              channel: 'sms',
              phone: '13800000000',
              template_code: 'SMS_001',
              provider: 'aliyun',
              status: status || 'success',
              request_id: 'SMS-REQ-001',
              message_excerpt: '短信验证码 482915',
              error_excerpt: status === 'failed' ? '通道失败' : '',
              context_excerpt: '验证码: 482915',
              sent_at: '2026-06-06 10:03:00',
              created_at: '2026-06-06 10:03:00',
            }),
          }),
        });
        return;
      }
      if (pathname.endsWith('/logs/email')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            data: paginator({
              id: 401,
              channel: 'email',
              to_email: 'notice@example.com',
              template_code: '100001',
              subject: '测试邮件',
              status: status || 'success',
              message_excerpt: '邮件正文 482915',
              error_excerpt: '',
              context_excerpt: '模板: 100001',
              sent_at: '2026-06-06 10:04:00',
              created_at: '2026-06-06 10:04:00',
            }),
          }),
        });
        return;
      }
      if (pathname.endsWith('/logs/tasks')) {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify({
            code: 0,
            data: paginator({
              id: 501,
              channel: 'tasks',
              time: '2026-06-06 10:05:00',
              task_key: 'service-auto-renew',
              task_title: '服务自动续费',
              level: 'INFO',
              message_excerpt: '服务自动续费完成',
              context_excerpt: '[INFO] service auto renew done',
              created_at: '2026-06-06 10:05:00',
            }),
          }),
        });
        return;
      }
      await route.fallback();
    },
  );

  await page.route(/\/api\/v2\/admin\/(?:schedules\/overview|schedule-triggers)(?:\?.*)?$/, async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;
    if (pathname.endsWith('/schedule-triggers')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: { id: 'schedule-1', status: 'completed', detail: { task: { execution_mode: 'sync' } } },
        }),
      });
      return;
    }
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          environment: {
            business_queue: 'provision,referral,notification,coupon,default',
            automation_queue: 'automation',
            queue_runtime_mode: 'database_queue_parallel_drained',
            schedule_mutex: { enabled: true, mode: 'without_overlapping' },
          },
          tasks: [
            {
              key: 'service-status-sync',
              title: '服务状态同步',
              category: '服务',
              expression: '*/5 * * * *',
              last_run_at: '2026-06-06 10:00:00',
              next_run_at: '2026-06-06 10:05:00',
              manual_triggerable: true,
              last_log: { level: 'INFO', time: '2026-06-06 10:00:00' },
            },
            {
              key: 'queue-backlog-drain',
              title: '队列积压消费',
              category: '队列',
              expression: '* * * * *',
              manual_triggerable: false,
              last_log: null,
            },
          ],
          recent_logs: [
            { id: 1, time: '2026-06-06 10:00:00', task_key: 'service-status-sync', message: '状态同步完成' },
          ],
        },
      }),
    });
  });
}

function visibleAccount(page: import('@playwright/test').Page, account: string) {
  return page
    .locator('button.users-account:visible, button.users-mobile-card__account:visible')
    .filter({ hasText: account });
}

function visibleVerificationName(page: import('@playwright/test').Page, name: string) {
  return page.locator('.verification-table:visible td, .verification-mobile-card:visible dd').filter({ hasText: name });
}

function userDetailTab(page: import('@playwright/test').Page, name: string) {
  return page.locator('.user-detail-page .t-tabs__nav-item-text-wrapper').filter({ hasText: name }).first();
}

async function clickVisibleDropdownItem(page: import('@playwright/test').Page, action: string) {
  const item = page.locator('.t-dropdown__menu:visible .t-dropdown__item').filter({ hasText: action }).last();
  await expect(item).toBeVisible();
  await item.click();
}

async function mockSettingsCenter(page: Page) {
  const settingGroups: Record<string, Record<string, string | number | boolean>> = {
    basic: {
      site_name: '图拉云',
      browser_title: '图拉云控制台',
      site_logo: '/branding/logo.svg',
      site_favicon: '/branding/logo1.svg',
      service_phone: '123456',
      support_group_qr: '/branding/group.png',
      support_group_link: 'https://example.com/group',
      terms_url: '/terms',
      privacy_url: '/privacy',
    },
  };

  const heroPayload = {
    slides: [
      {
        rail_title: '官网换新',
        title: '云服务新体验',
        desc: '稳定交付云服务器和 IDC 产品',
        primary_text: '立即购买',
        primary_path: '/products',
        secondary_text: '查看文档',
        secondary_path: '/docs',
      },
    ],
    features: [
      {
        kicker: '稳定',
        title: '分钟级开通',
        desc: '自动化交付服务实例',
        path: '/products',
      },
    ],
    defaults: {
      slides: [
        {
          rail_title: '默认轮播',
          title: '默认标题',
          desc: '默认描述',
          primary_text: '立即体验',
          primary_path: '/products',
          secondary_text: '了解更多',
          secondary_path: '/about',
        },
      ],
      features: [
        {
          kicker: '默认',
          title: '默认卡片',
          desc: '默认卡片描述',
          path: '/products',
        },
      ],
    },
  };

  await page.route(/\/api\/v2\/admin\/settings(?:\?.*)?$/, async (route) => {
    const request = route.request();
    if (request.method() !== 'GET') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '保存成功', data: {} }),
      });
      return;
    }

    const group = new URL(request.url()).searchParams.get('group') || 'system';
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: { list: Object.entries(settingGroups[group] || {}).map(([key, value]) => ({ key, value })) },
      }),
    });
  });

  await page.route('**/api/v2/admin/media-files**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        message: '上传成功',
        data: { id: 9901, filename: 'logo-new.png', url: '/uploads/site/logo-new.png' },
      }),
    });
  });

  await page.route('**/api/v2/admin/site/home-hero**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        message: route.request().method() === 'GET' ? '操作成功' : '保存成功',
        data: heroPayload,
      }),
    });
  });
}

test.describe('frontend-admin-v3 shell smoke', () => {
  test.beforeEach(async ({ page }) => {
    page.on('pageerror', (error) => {
      console.error('pageerror:', error.message);
    });
    page.on('console', (message) => {
      if (['error', 'warning'].includes(message.type())) {
        console.error(`console ${message.type()}:`, message.text());
      }
    });
  });

  test('opens admin login page', async ({ page }) => {
    await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/login/);
    await expect(page.getByRole('heading', { name: '管理后台' })).toBeVisible();
    await expect(page.getByRole('button', { name: /登录|sign in/i })).toBeVisible();
  });

  test('submits admin login and opens dashboard', async ({ page }) => {
    await mockLogin(page);
    await mockAdminInfo(page);
    await mockDashboard(page);

    await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
    await page.getByPlaceholder('请输入管理员账号').fill('cerbo');
    await page.getByPlaceholder('请输入密码').fill('Temp@123456');
    const loginRequest = page.waitForRequest('**/api/v2/admin/login');
    await page.getByRole('button', { name: '登录' }).click();
    await expect((await loginRequest).postDataJSON()).toMatchObject({
      username: 'cerbo',
      password: 'Temp@123456',
    });

    await expect(page).toHaveURL(/\/admin\/dashboard/);
    await expect(page.getByText('INV-TEST-001')).toBeVisible();
  });

  test('redirects protected dashboard to login without token', async ({ page }) => {
    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/login/);
  });

  test('opens dashboard with admin token and mocked profile', async ({ page }) => {
    await mockAdminInfo(page);
    await mockDashboard(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/dashboard/);
    await expect(page.locator('.recent-invoices-card .t-card__title')).toHaveText('最近账单');
    await expect(page.getByText('INV-TEST-001')).toBeVisible();
    await expect(page.getByText('Result')).toHaveCount(0);
    await expect(page.getByText('User Center')).toHaveCount(0);
  });

  test('groups sidebar navigation by business category', async ({ page }) => {
    await mockAdminInfo(page);
    await mockDashboard(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    const sidebar = page.locator('.tdesign-starter-side-nav');
    const menuText = (text: string) => sidebar.getByText(text, { exact: true }).first();

    await expect(page.locator('nav.tdesign-starter-sidebar-layout')).toHaveAttribute('aria-label', /.+/);

    for (const category of [
      '数据看板',
      '产品配置',
      '用户管理',
      '工单处理',
      '财务管理',
      '营销推广',
      '站点内容',
      '系统设置',
      '日志中心',
    ]) {
      await expect(menuText(category)).toBeVisible();
    }

    await menuText('产品配置').click();
    await expect(menuText('商品管理')).toBeVisible();
    await expect(menuText('上游供应')).toBeVisible();

    await menuText('财务管理').click();
    await expect(menuText('订单管理')).toBeVisible();
    await expect(menuText('账务管理')).toBeVisible();

    await menuText('系统设置').click();
    await expect(menuText('集成插件')).toBeVisible();
    await expect(menuText('自动化')).toBeVisible();
    await expect(menuText('权限管理')).toBeVisible();

    await expect(sidebar.getByText('User Detail', { exact: true })).toHaveCount(0);
    await expect(sidebar.getByText('Orders Redirect', { exact: true })).toHaveCount(0);
    await expect(sidebar.getByText('Email Template Detail', { exact: true })).toHaveCount(0);
  });

  test('shows not found page for unknown admin route with token', async ({ page }) => {
    await mockAdminInfo(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/missing-page', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/missing-page/);
    await expect(page.locator('.result-title')).toHaveText('页面不存在');
  });

  test('does not expose starter example routes with token', async ({ page }) => {
    await mockAdminInfo(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/result/success', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/result\/success/);
    await expect(page.locator('.result-title')).toHaveText('页面不存在');
    await expect(page.getByText('Success')).toHaveCount(0);

    await page.goto('/user/index', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/user\/index/);
    await expect(page.locator('.result-title')).toHaveText('页面不存在');
    await expect(page.getByText('User Center')).toHaveCount(0);
  });

  test('opens users list and handles primary controls', async ({ page }) => {
    await mockAdminInfo(page);
    await mockUsers(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/users', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/users/);
    await expect(page.getByText('用户管理').first()).toBeVisible();
    await expect(visibleAccount(page, '2908990438@qq.com')).toBeVisible();

    await page.getByPlaceholder('搜索邮箱/昵称/手机号').fill('filtered');
    await page.getByRole('button', { name: '搜索' }).click();
    await expect(visibleAccount(page, 'filtered@example.com')).toBeVisible();

    await page.getByRole('button', { name: '新增用户' }).click();
    await expect(page.locator('.t-dialog:visible').getByText('新增用户')).toBeVisible();
    await expect(page.locator('.t-dialog:visible').getByText('邮箱')).toBeVisible();
  });

  test('opens user recharge dialog', async ({ page }) => {
    await mockAdminInfo(page);
    await mockUserDetail(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/users/1', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/users\/1/);
    await page.getByRole('button', { name: '资金管理' }).first().click();
    await expect(page.getByText('操作类型')).toBeVisible();
  });

  test('opens tickets list and handles filters and navigation entries', async ({ page }) => {
    await mockAdminInfo(page);
    await mockTickets(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/tickets', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/tickets/);
    await expect(page.locator('.ticket-card h2').filter({ hasText: '网络无法连接' })).toBeVisible();
    await expect(page.getByText('网络无法连接')).toBeVisible();
    await expect(page.getByText('客户回复')).toBeVisible();

    await page.getByPlaceholder('搜索工单标题或 ID').fill('network');
    await page.getByPlaceholder('搜索工单标题或 ID').press('Enter');
    await expect(page.getByText('筛选工单')).toBeVisible();

    await page.getByPlaceholder('搜索工单标题或 ID').clear();
    await page.getByPlaceholder('搜索工单标题或 ID').press('Enter');
    await expect(page.getByText('网络无法连接')).toBeVisible();

    await page.getByRole('button', { name: '测试用户' }).click();
    await expect(page).toHaveURL(/\/admin\/users\/1/);

    await page.goto('/admin/tickets', { waitUntil: 'domcontentloaded' });
    await page.getByText('网络无法连接').click();
    await expect(page).toHaveURL(/\/admin\/ticket-conversations\/101/);
  });

  test('opens ticket conversation and handles core actions', async ({ page }) => {
    await mockAdminInfo(page);
    await mockTicketConversation(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/ticket-conversations/101', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/ticket-conversations\/101/);
    await expect(page.locator('.card-headline').getByText('沟通记录', { exact: true })).toBeVisible();
    await expect(page.getByText('客户反馈无法访问服务器')).toBeVisible();
    await expect(page.getByText('测试云服务器')).toBeVisible();
    await expect(page.getByText('192.0.2.10')).toBeVisible();

    await page.locator('.assign-box .t-select').click();
    await page.getByText('cerbo(ticket@example.com)').click();
    const assignRequest = page.waitForRequest('**/api/v2/admin/tickets/101/assignment');
    await page.getByRole('button', { name: '保存指派' }).click();
    await expect((await assignRequest).postDataJSON()).toMatchObject({ assignee_id: 1 });
    await expect(page.getByText('指派成功')).toBeVisible();

    await page.getByRole('button', { name: '引用' }).first().click();
    await expect(page.getByText('回复 测试用户')).toBeVisible();

    const uploadRequest = page.waitForRequest('**/api/v2/admin/tickets/upload-images');
    await page.locator('.reply-composer input[type="file"]').setInputFiles({
      name: 'admin.png',
      mimeType: 'image/png',
      buffer: Buffer.from('fake-image'),
    });
    await uploadRequest;

    await page.getByPlaceholder('输入回复内容，或只上传图片后发送').fill('后台回复内容');
    const replyRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/tickets/101/replies') && request.method() === 'POST',
    );
    await page.getByRole('button', { name: '发送' }).click();
    const replyPayload = (await replyRequest).postDataJSON();
    await expect(replyPayload).toMatchObject({
      content: '后台回复内容',
      quote_reply_id: 201,
    });
    expect(replyPayload.attachments).toContain('private/tickets/admin.png');
    await expect(page.getByText('后台回复内容')).toBeVisible();

    const recallRequest = page.waitForRequest('**/api/v2/admin/tickets/101/replies/202/recalls');
    await page.getByRole('button', { name: '撤回' }).first().click();
    await recallRequest;
    await expect(page.locator('.recalled-text').filter({ hasText: '消息已撤回' })).toBeVisible();

    const closeRequest = page.waitForRequest('**/api/v2/admin/tickets/101/closures');
    await page.getByRole('button', { name: '关闭工单' }).click();
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认关闭' }).click();
    await closeRequest;
    await expect(page.getByText('此工单已关闭，不能继续回复。')).toBeVisible();
  });

  test('opens products hub catalog and compatibility tabs', async ({ page }) => {
    test.slow();
    await mockAdminInfo(page);
    await mockProductsHub(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    const initialCategoryTreeRequest = page.waitForRequest((request) => {
      const url = new URL(request.url());
      return url.pathname === '/api/v2/admin/product-groups/tree';
    });
    await page.goto('/admin/products', { waitUntil: 'domcontentloaded' });
    expect(new URL((await initialCategoryTreeRequest).url()).searchParams.get('first_product_group_code')).toBe(
      'cloud_server',
    );
    await expect(page).toHaveURL(/\/admin\/products/);
    await expect(page.getByText('商品分类').first()).toBeVisible();
    await expect(page.getByText('标准云服务器')).toBeVisible();
    await expect(page.getByText('云服务器').first()).toBeVisible();

    await page.getByPlaceholder('搜索商品名称').fill('filtered');
    await page.getByPlaceholder('搜索商品名称').press('Enter');
    await expect(page.getByText('筛选云服务器')).toBeVisible();

    await page.locator('.product-table-card .t-table__body .t-checkbox').first().click();
    await expect(page.getByText('已选 1 个商品')).toBeVisible();
    await page.getByRole('button', { name: '批量归类' }).click();
    const batchCategoryDialog = page.locator('.t-dialog:visible');
    await expect(batchCategoryDialog.getByText('批量归类')).toBeVisible();
    await batchCategoryDialog.locator('.t-form__item').filter({ hasText: '目标分类' }).locator('.t-select').click();
    await page.locator('.t-popup:visible .t-select-option').filter({ hasText: '存储型' }).last().click();
    const batchCategoryRequest = page.waitForRequest('**/api/v2/admin/products/category-batches');
    await batchCategoryDialog.getByRole('button', { name: '确认归类' }).click();
    await expect((await batchCategoryRequest).postDataJSON()).toMatchObject({
      product_ids: [101],
      target_first_product_group_id: 11,
      target_second_product_group_id: 13,
      target_third_product_group_id: null,
    });

    await page.locator('.product-table-card .t-table__body .t-checkbox').first().click();
    const splitPreviewRequest = page.waitForRequest('**/api/v2/admin/products/split-previews');
    await page.getByRole('button', { name: '拆分商品' }).click();
    await expect((await splitPreviewRequest).postDataJSON()).toMatchObject({ product_ids: [101] });
    const splitDialog = page.locator('.t-dialog:visible');
    await expect(splitDialog.getByText('筛选云服务器 2C4G')).toBeVisible();
    await expect(splitDialog.getByText('预计处理 1 个规格')).toBeVisible();
    const splitSubmitRequest = page.waitForRequest('**/api/v2/admin/products/splits');
    await splitDialog.getByRole('button', { name: '确认拆分' }).click();
    await expect((await splitSubmitRequest).postDataJSON()).toMatchObject({ product_ids: [101] });

    await page.locator('.product-table-card .t-table__body .t-checkbox').first().click();
    await page.getByRole('button', { name: '批量主机名' }).click();
    const hostnameDialog = page.locator('.t-dialog:visible');
    await expect(hostnameDialog.getByText('设置商品开通主机名')).toBeVisible();
    await hostnameDialog.getByText('指定前缀').click();
    await hostnameDialog.getByPlaceholder('例如 hk / sg / us').fill('hk');
    const hostnameRequest = page.waitForRequest('**/api/v2/admin/products/provision-hostname-batches');
    await hostnameDialog.getByRole('button', { name: '保存规则' }).click();
    await expect((await hostnameRequest).postDataJSON()).toMatchObject({
      product_ids: [101],
      provision_hostname: { mode: 'prefix', value: 'hk', length: 12 },
    });

    await page.getByRole('button', { name: '管理一级分类' }).click();
    const typeDialog = page.locator('.t-dialog:visible');
    await expect(typeDialog.getByText('管理一级分类')).toBeVisible();
    await typeDialog
      .locator('.type-form .t-form__item')
      .filter({ hasText: '一级分类名称' })
      .locator('input')
      .fill('存储服务器');
    await typeDialog
      .locator('.type-form .t-form__item')
      .filter({ hasText: '图标名称' })
      .locator('input')
      .fill('ServerIcon');
    const createTypeRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/product-types') && request.method() === 'POST',
    );
    await typeDialog.getByRole('button', { name: '新增一级分类' }).click();
    await expect((await createTypeRequest).postDataJSON()).toMatchObject({ label: '存储服务器', icon: 'ServerIcon' });

    await typeDialog
      .locator('.type-manager-item')
      .filter({ hasText: '云服务器' })
      .getByRole('button', { name: '编辑' })
      .click();
    await typeDialog
      .locator('.type-form .t-form__item')
      .filter({ hasText: '一级分类名称' })
      .locator('input')
      .fill('云服务器编辑');
    const updateTypeRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/product-types/cloud_server') && request.method() === 'PUT',
    );
    await typeDialog.getByRole('button', { name: '保存一级分类' }).click();
    await expect((await updateTypeRequest).postDataJSON()).toMatchObject({ label: '云服务器编辑' });

    const reorderTypeRequest = page.waitForRequest('**/api/v2/admin/product-types/reorders');
    await typeDialog
      .locator('.type-manager-item')
      .filter({ hasText: '存储服务器' })
      .getByRole('button', { name: '上移' })
      .click();
    await expect((await reorderTypeRequest).postDataJSON()).toMatchObject({
      values: ['storage_server', 'cloud_server'],
    });

    const deleteTypeRequest = page.waitForRequest(
      (request) =>
        request.url().includes('/api/v2/admin/product-types/storage_server') && request.method() === 'DELETE',
    );
    await typeDialog
      .locator('.type-manager-item')
      .filter({ hasText: '存储服务器' })
      .getByRole('button', { name: '删除' })
      .click();
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认删除' }).click();
    await deleteTypeRequest;
    await typeDialog.getByRole('button', { name: '关闭' }).click();

    const reorderCategoryRequest = page.waitForRequest('**/api/v2/admin/product-groups/reorders');
    await page.locator('.category-tree-row').filter({ hasText: '存储型' }).locator('.category-menu-trigger').click();
    await page.locator('.t-popup:visible .t-dropdown__item').filter({ hasText: '上移' }).click();
    await expect((await reorderCategoryRequest).postDataJSON()).toMatchObject({
      effective_product_group_level: 2,
      first_product_group_id: 11,
      second_product_group_ids: [13, 12],
    });

    await page.getByRole('button', { name: '新增二级' }).click();
    const categoryCreateDialog = page.locator('.t-dialog:visible');
    await expect(categoryCreateDialog.getByText('新增二级分类')).toBeVisible();
    await categoryCreateDialog.locator('.t-form__item').filter({ hasText: '分类名称' }).locator('input').fill('存储型');
    const createCategoryRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/product-groups') && request.method() === 'POST',
    );
    await categoryCreateDialog.getByRole('button', { name: '保存' }).click();
    await expect((await createCategoryRequest).postDataJSON()).toMatchObject({
      name: '存储型',
      service_type_code: 'cloud_server',
      effective_product_group_level: 2,
      first_product_group_id: 11,
    });

    await page.locator('.category-tree-row').filter({ hasText: '通用型' }).locator('.category-menu-trigger').click();
    await page.locator('.t-popup:visible .t-dropdown__item').filter({ hasText: '新增三级分类' }).click();
    const thirdCategoryCreateDialog = page.locator('.t-dialog:visible');
    await expect(thirdCategoryCreateDialog.getByText('新增三级分类')).toBeVisible();
    await thirdCategoryCreateDialog
      .locator('.t-form__item')
      .filter({ hasText: '分类名称' })
      .locator('input')
      .fill('高性能');
    const createThirdCategoryRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/product-groups') && request.method() === 'POST',
    );
    await thirdCategoryCreateDialog.getByRole('button', { name: '保存' }).click();
    await expect((await createThirdCategoryRequest).postDataJSON()).toMatchObject({
      name: '高性能',
      service_type_code: 'cloud_server',
      effective_product_group_level: 3,
      second_product_group_id: 12,
    });

    await page.locator('.category-tree-row').filter({ hasText: '通用型' }).locator('.category-menu-trigger').click();
    await page.locator('.t-popup:visible .t-dropdown__item').filter({ hasText: '编辑' }).click();
    const categoryEditDialog = page.locator('.t-dialog:visible');
    await expect(categoryEditDialog.getByText('分类名称')).toBeVisible();
    await categoryEditDialog
      .locator('.t-form__item')
      .filter({ hasText: '分类名称' })
      .locator('input')
      .fill('通用型编辑');
    const updateCategoryRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/product-groups/') && request.method() === 'PUT',
    );
    await categoryEditDialog.getByRole('button', { name: '保存' }).click();
    await expect((await updateCategoryRequest).postDataJSON()).toMatchObject({
      name: '通用型编辑',
      service_type_code: 'cloud_server',
      effective_product_group_level: 2,
    });

    const deleteCategoryRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/product-groups/') && request.method() === 'DELETE',
    );
    await page.locator('.category-tree-row').filter({ hasText: '存储型' }).locator('.category-menu-trigger').click();
    await page.locator('.t-popup:visible .t-dropdown__item').filter({ hasText: '删除' }).click();
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认删除' }).click();
    await deleteCategoryRequest;

    await page.getByRole('button', { name: '新增商品' }).click();
    const newProductDrawer = page.locator('.t-drawer:visible');
    await expect(newProductDrawer.getByText('商品名称')).toBeVisible();
    await newProductDrawer.getByRole('button', { name: '取消' }).click();

    const productDetailRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/products/101') && request.method() === 'GET',
    );
    await page.locator('.product-table-card').getByRole('button', { name: '编辑' }).first().click();
    await productDetailRequest;
    const productDrawer = page.locator('.t-drawer:visible');
    await expect(productDrawer.getByRole('button', { name: '产品配置' })).toBeVisible();
    await productDrawer.getByRole('button', { name: '产品配置' }).click();
    await expect(productDrawer.locator('.config-option-list').getByText('CPU', { exact: true })).toBeVisible();
    const templateRequest = page.waitForRequest(
      (request) =>
        request.url().includes('/api/v2/admin/suppliers/3/products/300/config-template') && request.method() === 'GET',
    );
    await productDrawer.getByRole('button', { name: '拉取模板' }).click();
    await templateRequest;
    await expect(productDrawer.locator('.config-option-list').getByText('内存', { exact: true })).toBeVisible();

    await productDrawer.getByRole('button', { name: '新增配置' }).click();
    const configDialog = page.locator('.t-dialog:visible').filter({ hasText: '新增配置项' });
    await configDialog.locator('.t-form__item').filter({ hasText: '配置项名称' }).locator('input').fill('带宽');
    await configDialog.locator('.t-form__item').filter({ hasText: '配置标识' }).locator('input').fill('bandwidth');
    await configDialog.locator('.config-subitem-grid').nth(1).locator('input').nth(0).fill('10Mbps');
    await configDialog.locator('.config-subitem-grid').nth(1).locator('input').nth(1).fill('10');
    await configDialog.getByRole('button', { name: '保存配置' }).click();
    await expect(productDrawer.locator('.config-option-list').getByText('带宽', { exact: true })).toBeVisible();
    const updateProductRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/products/101') && request.method() === 'PUT',
    );
    await productDrawer.getByRole('button', { name: '保存更改' }).click();
    const updateProductPayload = (await updateProductRequest).postDataJSON();
    expect(updateProductPayload.config_options).toEqual(
      expect.arrayContaining([expect.objectContaining({ field: 'bandwidth', name: '带宽' })]),
    );

    const deleteProductRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/products/101') && request.method() === 'DELETE',
    );
    await page.locator('.product-table-card').getByRole('button', { name: '删除' }).first().click();
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认删除' }).click();
    await deleteProductRequest;

    await page.goto('/admin/products/traffic-packages', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/products\?tab=traffic-packages/);
    await expect(page.locator('.traffic-group-list').getByRole('button', { name: /基础流量包/ })).toBeVisible();
    await expect(page.locator('.traffic-row input').first()).toHaveValue('100GB');

    const pullTrafficRequest = page.waitForRequest('**/api/v2/admin/products/traffic-package-pulls');
    await page.getByRole('button', { name: '上游拉取' }).click();
    await expect((await pullTrafficRequest).postDataJSON()).toMatchObject({
      second_product_group_id: 12,
      product_type: 'cloud_server',
      source_product_id: 101,
    });
    await expect(page.locator('.traffic-row input').first()).toHaveValue('200GB');

    const saveTrafficRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/settings') && request.method() === 'POST',
    );
    await page.getByRole('button', { name: '保存当前分组' }).click();
    const saveTrafficPayload = (await saveTrafficRequest).postDataJSON();
    expect(saveTrafficPayload.group).toBe('traffic_package_catalog');
    expect(JSON.parse(saveTrafficPayload.settings.items)).toEqual(
      expect.arrayContaining([expect.objectContaining({ label: '200GB', target_value: 200, price: '29.90' })]),
    );

    await page.locator('.traffic-actions').getByRole('button', { name: '新增分组' }).click();
    const trafficGroupDialog = page.locator('.t-dialog:visible').filter({ hasText: '新增流量包分组' });
    await expect(trafficGroupDialog.getByText('绑定配置', { exact: true })).toBeVisible();
    await trafficGroupDialog
      .locator('.t-form__item')
      .filter({ hasText: '分组名称' })
      .locator('input')
      .fill('高防流量包');
    await trafficGroupDialog
      .locator('.t-form__item')
      .filter({ hasText: '绑定配置' })
      .locator('.binding-tree-select')
      .click();
    await page.locator('.t-popup:visible').getByText('标准云服务器', { exact: true }).last().click();
    await expect(trafficGroupDialog.getByText('襄阳 / 高宽')).toBeVisible();
    await trafficGroupDialog.getByText('分组名称', { exact: true }).click();
    const createTrafficGroupRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/settings') && request.method() === 'POST',
    );
    await trafficGroupDialog.getByRole('button', { name: '保存分组' }).click();
    const createTrafficGroupPayload = (await createTrafficGroupRequest).postDataJSON();
    expect(JSON.parse(createTrafficGroupPayload.settings.groups)).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ name: '高防流量包', product_type: 'cloud_server', product_ids: [101] }),
      ]),
    );
    await expect(page.locator('.traffic-group-list').getByRole('button', { name: /高防流量包/ })).toBeVisible();

    await page
      .locator('.traffic-group-item')
      .filter({ hasText: '高防流量包' })
      .getByRole('button', { name: '编辑' })
      .click();
    const editTrafficGroupDialog = page.locator('.t-dialog:visible').filter({ hasText: '编辑流量包分组' });
    await editTrafficGroupDialog
      .locator('.t-form__item')
      .filter({ hasText: '分组名称' })
      .locator('input')
      .fill('高防流量包编辑');
    const updateTrafficGroupRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/settings') && request.method() === 'POST',
    );
    await editTrafficGroupDialog.getByRole('button', { name: '保存分组' }).click();
    const updateTrafficGroupPayload = (await updateTrafficGroupRequest).postDataJSON();
    expect(JSON.parse(updateTrafficGroupPayload.settings.groups)).toEqual(
      expect.arrayContaining([expect.objectContaining({ name: '高防流量包编辑', product_ids: [101] })]),
    );

    const deleteTrafficGroupRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/settings') && request.method() === 'POST',
    );
    await page
      .locator('.traffic-group-item')
      .filter({ hasText: '高防流量包编辑' })
      .getByRole('button', { name: '删除' })
      .click();
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认删除' }).click();
    const deleteTrafficGroupPayload = (await deleteTrafficGroupRequest).postDataJSON();
    expect(JSON.parse(deleteTrafficGroupPayload.settings.groups)).not.toEqual(
      expect.arrayContaining([expect.objectContaining({ name: '高防流量包编辑' })]),
    );

    const supplierBalanceRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/suppliers/3/balance') && request.method() === 'GET',
    );
    await page.goto('/admin/products/suppliers', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/products\?tab=suppliers/);
    await expect(page.getByText('ZJMF 财务').first()).toBeVisible();
    await supplierBalanceRequest;
    await expect(page.getByText('¥ 123.45')).toBeVisible();

    await page.getByPlaceholder('搜索接口名称 / 用户名').fill('supplier');
    await page.getByRole('button', { name: '搜索' }).click();
    await expect(page.getByText('筛选供应商')).toBeVisible();

    await page.locator('.supplier-grid').getByRole('button', { name: '批量对接' }).first().click();
    const supplierBatchDialog = page.locator('.t-dialog:visible');
    await expect(supplierBatchDialog.getByText('上游云服务器 A')).toBeVisible();
    await supplierBatchDialog.locator('.t-form__item').filter({ hasText: '导入分类' }).locator('.t-select').click();
    await page.getByText('通用型').last().click();
    await supplierBatchDialog.getByRole('button', { name: '选择未对接' }).click();
    const supplierBatchRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/suppliers/3/tasks') && request.method() === 'POST',
    );
    await supplierBatchDialog.getByRole('button', { name: '执行对接' }).click();
    await expect((await supplierBatchRequest).postDataJSON()).toMatchObject({
      type: 'server.supplier.bulk_connect',
      payload: {
        first_product_group_code: 'cloud_server',
        first_product_group_id: 11,
        second_product_group_id: 12,
        third_product_group_id: null,
        product_ids: [9001],
        default_status: 1,
        default_auto_setup: 1,
        sync_config_options: 1,
      },
    });
    await supplierBatchDialog.getByRole('button', { name: /Cancel|取消/ }).click();

    await page.getByRole('button', { name: '新增供应商' }).click();
    const createSupplierDialog = page.locator('.t-dialog:visible').filter({ hasText: '新增供应商' });
    await expect(createSupplierDialog.getByText('接口名称')).toBeVisible();
    await createSupplierDialog
      .locator('.t-form__item')
      .filter({ hasText: '接口名称' })
      .locator('input')
      .fill('新供应商');
    await createSupplierDialog
      .locator('.t-form__item')
      .filter({ hasText: '接口地址' })
      .locator('input')
      .fill('https://new.example.test');
    await createSupplierDialog.locator('.t-form__item').filter({ hasText: '用户名' }).locator('input').fill('new-user');
    await createSupplierDialog
      .locator('.t-form__item')
      .filter({ hasText: 'API 密钥' })
      .locator('input')
      .fill('new-secret');
    const createSupplierRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/suppliers') && request.method() === 'POST',
    );
    await createSupplierDialog.getByRole('button', { name: '保存' }).click();
    await expect((await createSupplierRequest).postDataJSON()).toMatchObject({
      name: '新供应商',
      api_url: 'https://new.example.test',
      api_username: 'new-user',
      api_key: 'new-secret',
    });

    const toggleSupplierRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/suppliers/3/status') && request.method() === 'PATCH',
    );
    await page.locator('.supplier-grid').getByRole('button', { name: '停用' }).first().click();
    await expect((await toggleSupplierRequest).postDataJSON()).toMatchObject({ enabled: false });

    const supplierDetailRequest = page.waitForRequest(
      (request) => request.url().includes('/admin/suppliers/3') && request.method() === 'GET',
    );
    await page.locator('.supplier-grid').getByRole('button', { name: '编辑' }).first().click();
    await supplierDetailRequest;
    const supplierDialog = page.locator('.t-dialog:visible');
    await expect(supplierDialog.locator('.t-form__item').filter({ hasText: '接口名称' }).locator('input')).toHaveValue(
      'ZJMF 财务详情',
    );
    await supplierDialog
      .locator('.t-form__item')
      .filter({ hasText: '接口名称' })
      .locator('input')
      .fill('ZJMF 财务编辑');
    await supplierDialog.locator('.t-form__item').filter({ hasText: 'API 密钥' }).locator('input').fill('secret');
    const updateSupplierRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/suppliers/3') && request.method() === 'PUT',
    );
    await supplierDialog.getByRole('button', { name: '保存' }).click();
    await expect((await updateSupplierRequest).postDataJSON()).toMatchObject({
      name: 'ZJMF 财务编辑',
      api_key: 'secret',
    });

    const deleteSupplierRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/suppliers/3') && request.method() === 'DELETE',
    );
    await page.locator('.supplier-grid').getByRole('button', { name: '删除' }).first().click();
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认删除' }).click();
    await deleteSupplierRequest;
  });

  test('opens coupons and handles filters and core actions', async ({ page }) => {
    await mockAdminInfo(page);
    await mockCoupons(page);
    await mockUsers(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    const isMobileViewport = () => (page.viewportSize()?.width || 1440) <= 768;
    const clickRowAction = async (rowText: string, action: string) => {
      const row = page.locator('.t-table__body tr').filter({ hasText: rowText }).first();
      if (isMobileViewport()) {
        await row.getByRole('button', { name: '更多' }).click();
        await clickVisibleDropdownItem(page, action);
        return;
      }
      await row.getByRole('button', { name: action }).click();
    };

    await page.goto('/admin/coupons', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/coupons/);
    await expect(page.getByRole('heading', { name: '优惠券管理' })).toBeVisible();
    await expect(page.getByText('新客首单立减券')).toBeVisible();
    await expect(page.getByText('指定 1 个商品')).toBeVisible();

    await page.getByPlaceholder('搜索优惠券名称 / 描述').fill('filtered');
    await page.getByRole('button', { name: '搜索' }).click();
    await expect(page.getByText('筛选优惠券')).toBeVisible();

    await page.getByRole('button', { name: '重置' }).click();
    await expect(page.getByText('新客首单立减券')).toBeVisible();

    await page.getByRole('button', { name: '新增优惠券' }).click();
    let dialog = page.locator('.t-dialog:visible');
    await expect(dialog.getByText('新增优惠券')).toBeVisible();
    await dialog.locator('.t-form__item').filter({ hasText: '优惠券名称' }).locator('input').fill('自动化优惠券');
    await dialog.locator('.t-form__item').filter({ hasText: '优惠金额' }).locator('input').first().fill('50');
    const createRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/coupons') && request.method() === 'POST',
    );
    await dialog.getByRole('button', { name: '保存' }).click();
    await expect((await createRequest).postDataJSON()).toMatchObject({
      name: '自动化优惠券',
      distribution_type: 'public',
      discount_scope: 'first_month',
      discount_type: 'fixed',
      discount_value: 50,
      user_ids: [],
    });

    await clickRowAction('新客首单立减券', '编辑');
    dialog = page.locator('.t-dialog:visible');
    await expect(dialog.getByText('编辑优惠券')).toBeVisible();
    await dialog.locator('.t-form__item').filter({ hasText: '描述' }).locator('textarea').fill('更新说明');
    const updateRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/coupons/501') && request.method() === 'PUT',
    );
    await dialog.getByRole('button', { name: '保存' }).click();
    await expect((await updateRequest).postDataJSON()).toMatchObject({
      name: '新客首单立减券',
      description: '更新说明',
      product_ids: [101],
    });

    const toggleRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/coupons/501/status') && request.method() === 'PATCH',
    );
    await clickRowAction('新客首单立减券', '停用');
    await expect((await toggleRequest).postDataJSON()).toMatchObject({ enabled: false });

    const deleteRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/coupons/501') && request.method() === 'DELETE',
    );
    await clickRowAction('新客首单立减券', '删除');
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认删除' }).click();
    await deleteRequest;
  });

  test('opens coupon campaigns and handles schedule actions', async ({ page }) => {
    await mockAdminInfo(page);
    await mockCouponCampaigns(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    const isMobileViewport = () => (page.viewportSize()?.width || 1440) <= 768;
    const clickRowAction = async (rowText: string, action: string) => {
      const row = page.locator('.t-table__body tr').filter({ hasText: rowText }).first();
      if (isMobileViewport()) {
        await row.getByRole('button', { name: '更多' }).click();
        await clickVisibleDropdownItem(page, action);
        return;
      }
      await row.getByRole('button', { name: action }).click();
    };

    await page.goto('/admin/coupon-campaigns', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/coupon-campaigns/);
    await expect(page.getByRole('heading', { name: '优惠券活动' })).toBeVisible();
    await expect(page.getByText('周五特惠', { exact: true })).toBeVisible();
    await expect(page.getByText('每周五 18:00', { exact: true })).toBeVisible();

    await page.getByPlaceholder('搜索活动名称 / 描述 / 备注').fill('filtered');
    await page.getByRole('button', { name: '搜索' }).click();
    await expect(page.getByText('筛选活动')).toBeVisible();

    await page.getByRole('button', { name: '重置' }).click();
    await expect(page.getByText('周五特惠', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: '新增活动' }).click();
    let dialog = page.locator('.t-dialog:visible');
    await expect(dialog.getByText('新增优惠券活动')).toBeVisible();
    await dialog.locator('.t-form__item').filter({ hasText: '活动名称' }).locator('input').fill('自动化活动');
    const createRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/coupon-campaigns') && request.method() === 'POST',
    );
    await dialog.getByRole('button', { name: '保存' }).click();
    await expect((await createRequest).postDataJSON()).toMatchObject({
      name: '自动化活动',
      weekdays: [5],
      trigger_time: '18:00:00',
      issue_quantity: 20,
      discount_type: 'percentage',
      discount_value: 80,
    });

    await clickRowAction('周五特惠', '编辑');
    dialog = page.locator('.t-dialog:visible');
    await expect(dialog.getByText('编辑优惠券活动')).toBeVisible();
    await dialog.locator('.t-form__item').filter({ hasText: '描述' }).locator('textarea').fill('活动更新说明');
    const updateRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/coupon-campaigns/601') && request.method() === 'PUT',
    );
    await dialog.getByRole('button', { name: '保存' }).click();
    await expect((await updateRequest).postDataJSON()).toMatchObject({
      name: '周五特惠',
      description: '活动更新说明',
      product_ids: [101],
    });

    const triggerRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/coupon-campaigns/601/tasks') && request.method() === 'POST',
    );
    await clickRowAction('周五特惠', '立即发放');
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认发放' }).click();
    await expect((await triggerRequest).postDataJSON()).toEqual({ type: 'trigger', payload: {} });

    const toggleRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/coupon-campaigns/601/status') && request.method() === 'PATCH',
    );
    await clickRowAction('周五特惠', '停用');
    await expect((await toggleRequest).postDataJSON()).toMatchObject({ enabled: false });

    const deleteRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/coupon-campaigns/601') && request.method() === 'DELETE',
    );
    await clickRowAction('周五特惠', '删除');
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认删除' }).click();
    await deleteRequest;
  });

  test('opens referral and handles rewards and withdrawals', async ({ page }) => {
    await mockAdminInfo(page);
    await mockReferral(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    const isMobileViewport = () => (page.viewportSize()?.width || 1440) <= 768;
    const clickTab = async (name: string) => {
      await page.locator('.t-tabs__nav-item').filter({ hasText: name }).click();
    };
    const clickWithdrawalAction = async (rowText: string, action: string) => {
      if (isMobileViewport()) {
        await page
          .locator('.referral-mobile-card')
          .filter({ hasText: rowText })
          .getByRole('button', { name: action })
          .click();
        return;
      }
      await page
        .locator('.t-table__body tr')
        .filter({ hasText: rowText })
        .getByRole('button', { name: action })
        .click();
    };

    await page.goto('/admin/referral', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/referral/);
    await expect(page.getByRole('heading', { name: '推广返利' })).toBeVisible();
    await expect(page.getByText('推广用户').first()).toBeVisible();
    await expect(page.getByText('¥12880.00').first()).toBeVisible();

    await clickTab('奖励');
    await expect(page.getByText('ORD-REF-001')).toBeVisible();
    const rewardFilterRequest = page.waitForRequest(
      (request) =>
        request.url().includes('/api/v2/admin/referral/rewards') && request.url().includes('keyword=filtered'),
    );
    await page.getByPlaceholder('搜索推荐人 / 被推荐人 / 账单号').fill('filtered');
    await page.getByRole('button', { name: '搜索' }).click();
    await rewardFilterRequest;
    await expect(page.getByText('筛选推荐人')).toBeVisible();

    await page.getByRole('button', { name: '重置' }).click();
    await expect(page.getByText('ORD-REF-001')).toBeVisible();
    const rewardPageRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/referral/rewards') && request.url().includes('page=2'),
    );
    await page.locator('.t-pagination').getByText('2', { exact: true }).click();
    await rewardPageRequest;
    await expect(page.getByText('ORD-REF-PAGE-002')).toBeVisible();

    await clickTab('提现');
    await expect(page.getByText('提现用户')).toBeVisible();
    const withdrawalFilterRequest = page.waitForRequest(
      (request) =>
        request.url().includes('/api/v2/admin/referral-withdrawals') && request.url().includes('keyword=filtered'),
    );
    await page.getByPlaceholder('搜索用户 / 邮箱 / 账号 / 备注').fill('filtered');
    await page.getByRole('button', { name: '搜索' }).click();
    await withdrawalFilterRequest;
    await expect(page.getByText('筛选提现用户')).toBeVisible();

    await page.getByRole('button', { name: '重置' }).click();
    await expect(page.getByText('提现用户')).toBeVisible();

    const approveRequest = page.waitForRequest('**/api/v2/admin/referral-withdrawals/801/approvals');
    await clickWithdrawalAction('提现用户', '通过');
    let dialog = page.locator('.t-dialog:visible');
    await expect(dialog.getByText('通过提现申请')).toBeVisible();
    await dialog.locator('textarea').fill('审核通过');
    await dialog.getByRole('button', { name: '确认通过' }).click();
    await expect((await approveRequest).postDataJSON()).toMatchObject({ remark: '审核通过' });
    await expect(page.getByText('提现申请已通过')).toBeVisible();

    const rejectRequest = page.waitForRequest('**/api/v2/admin/referral-withdrawals/802/rejections');
    await clickWithdrawalAction('待拒绝用户', '拒绝');
    dialog = page.locator('.t-dialog:visible');
    await expect(dialog.getByText('拒绝提现申请')).toBeVisible();
    await dialog.locator('textarea').fill('资料不完整');
    await dialog.getByRole('button', { name: '确认拒绝' }).click();
    await expect((await rejectRequest).postDataJSON()).toMatchObject({ remark: '资料不完整' });
    await expect(page.getByText('提现申请已拒绝')).toBeVisible();
  });

  test('opens member levels and handles core actions', async ({ page }) => {
    await mockAdminInfo(page);
    await mockMemberLevels(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    const isMobileViewport = () => (page.viewportSize()?.width || 1440) <= 768;
    const clickLevelAction = async (rowText: string, action: string) => {
      if (isMobileViewport()) {
        await page
          .locator('.member-mobile-card')
          .filter({ hasText: rowText })
          .getByRole('button', { name: action })
          .click();
        return;
      }
      await page
        .locator('.t-table__body tr')
        .filter({ hasText: rowText })
        .getByRole('button', { name: action })
        .click();
    };

    await page.goto('/admin/member-levels', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/member-levels/);
    await expect(page.getByRole('heading', { name: '会员等级与返利档位' })).toBeVisible();
    await expect(page.getByText('黄金会员')).toBeVisible();
    await expect(page.getByText('8.00%')).toBeVisible();

    await page.getByRole('button', { name: '新增等级' }).click();
    let dialog = page.locator('.t-dialog:visible');
    await expect(dialog.getByText('新增会员等级')).toBeVisible();
    await dialog.locator('.t-form__item').filter({ hasText: '等级名称' }).locator('input').fill('钻石会员');
    await dialog.locator('.t-form__item').filter({ hasText: '等级编码' }).locator('input').fill('diamond');
    await dialog.locator('.t-form__item').filter({ hasText: '累计销售额下限' }).locator('input').fill('10000');
    await dialog.locator('.t-form__item').filter({ hasText: '累计销售额上限' }).locator('input').fill('50000');
    await dialog.locator('.t-form__item').filter({ hasText: '返利比例' }).locator('input').fill('12');
    await dialog.locator('.t-form__item').filter({ hasText: '排序值' }).locator('input').fill('1');
    await dialog.locator('textarea').fill('高阶推广等级');
    const createRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/member-levels') && request.method() === 'POST',
    );
    await dialog.getByRole('button', { name: '保存' }).click();
    await expect((await createRequest).postDataJSON()).toMatchObject({
      name: '钻石会员',
      code: 'diamond',
      sales_amount_min: 10000,
      sales_amount_max: 50000,
      reward_rate: 12,
      status: 1,
      sort_order: 1,
      remark: '高阶推广等级',
    });
    await expect(page.getByText('会员等级已创建')).toBeVisible();
    await expect(page.getByText('钻石会员')).toBeVisible();

    await clickLevelAction('黄金会员', '编辑');
    dialog = page.locator('.t-dialog:visible');
    await expect(dialog.getByText('编辑会员等级')).toBeVisible();
    await dialog.locator('.t-form__item').filter({ hasText: '等级名称' }).locator('input').fill('黄金会员编辑');
    await dialog.locator('textarea').fill('更新等级备注');
    const updateRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/member-levels/901') && request.method() === 'PUT',
    );
    await dialog.getByRole('button', { name: '保存' }).click();
    await expect((await updateRequest).postDataJSON()).toMatchObject({
      name: '黄金会员编辑',
      remark: '更新等级备注',
    });
    await expect(page.getByText('会员等级已更新')).toBeVisible();
    await expect(page.getByText('黄金会员编辑')).toBeVisible();

    const deleteRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/member-levels/902') && request.method() === 'DELETE',
    );
    await clickLevelAction('白银会员', '删除');
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认删除' }).click();
    await deleteRequest;
    await expect(page.getByText('会员等级已删除')).toBeVisible();
    await expect(page.getByText('白银会员')).not.toBeVisible();

    await page.goto('/admin/growth', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/member-levels/);
  });

  test('opens content center and handles notice actions', async ({ page }) => {
    await mockAdminInfo(page);
    await mockContent(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    const isMobileViewport = () => (page.viewportSize()?.width || 1440) <= 768;
    const clickArticleAction = async (rowText: string, action: string) => {
      if (isMobileViewport()) {
        await page
          .locator('.content-mobile-card')
          .filter({ hasText: rowText })
          .getByRole('button', { name: action })
          .click();
        return;
      }
      await page
        .locator('.t-table__body tr')
        .filter({ hasText: rowText })
        .getByRole('button', { name: action })
        .click();
    };

    await page.goto('/admin/content/notices', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/content\/notices/);
    await expect(page.getByRole('heading', { name: '系统公告' })).toBeVisible();
    await expect(page.getByText('平台维护公告')).toBeVisible();

    const filterRequest = page.waitForRequest(
      (request) =>
        request.url().includes('/api/v2/admin/content/articles') && request.url().includes('keyword=filtered'),
    );
    await page.getByPlaceholder('搜索公告标题 / 摘要 / 正文 / 别名').fill('filtered');
    await page.getByRole('button', { name: '搜索' }).click();
    await filterRequest;
    await expect(page.getByText('筛选公告')).toBeVisible();

    await page.getByRole('button', { name: '重置' }).click();
    await expect(page.getByText('平台维护公告')).toBeVisible();
    const pageRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/content/articles') && request.url().includes('page=2'),
    );
    await page.locator('.t-pagination').getByText('2', { exact: true }).click();
    await pageRequest;
    await expect(page.getByText('第二页公告')).toBeVisible();

    await page.goto('/admin/content/notices', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: '分类管理' }).click();
    let dialog = page.locator('.t-dialog:visible');
    await expect(dialog.getByText('分类管理')).toBeVisible();
    await dialog.locator('.t-form__item').filter({ hasText: '分类名称' }).locator('input').fill('运维公告');
    await dialog.locator('.t-form__item').filter({ hasText: '别名' }).locator('input').fill('ops');
    const createCategoryRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/content/categories') && request.method() === 'POST',
    );
    await dialog.getByRole('button', { name: '新增分类' }).click();
    await expect((await createCategoryRequest).postDataJSON()).toMatchObject({
      content_type: 'notice',
      name: '运维公告',
      slug: 'ops',
    });
    await expect(page.getByText('分类已创建')).toBeVisible();
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: '新增公告' }).click();
    dialog = page.locator('.t-dialog:visible');
    await expect(dialog.getByText('新增公告')).toBeVisible();
    await dialog.getByPlaceholder('请输入标题').fill('自动化公告');
    await dialog.locator('.t-form__item').filter({ hasText: '摘要' }).locator('textarea').fill('自动化摘要');
    await dialog.locator('.t-form__item').filter({ hasText: '正文内容' }).locator('textarea').fill('自动化正文');
    const createArticleRequest = page.waitForRequest(
      (request) => request.url().endsWith('/api/v2/admin/content/articles') && request.method() === 'POST',
    );
    await dialog.getByRole('button', { name: '保存' }).click();
    await expect((await createArticleRequest).postDataJSON()).toMatchObject({
      content_type: 'notice',
      title: '自动化公告',
      content: '自动化正文',
    });
    await expect(page.getByText('公告已创建')).toBeVisible();

    await clickArticleAction('平台维护公告', '编辑');
    dialog = page.locator('.t-dialog:visible');
    await expect(dialog.getByText('编辑公告')).toBeVisible();
    await dialog.getByPlaceholder('请输入标题').fill('平台维护公告编辑');
    const updateArticleRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/content/articles/1101') && request.method() === 'PUT',
    );
    await dialog.getByRole('button', { name: '保存' }).click();
    await expect((await updateArticleRequest).postDataJSON()).toMatchObject({
      content_type: 'notice',
      title: '平台维护公告编辑',
    });
    await expect(page.getByText('公告已更新')).toBeVisible();

    const deleteArticleRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/content/articles/1101') && request.method() === 'DELETE',
    );
    await clickArticleAction('平台维护公告编辑', '删除');
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认删除' }).click();
    await deleteArticleRequest;
    await expect(page.getByText('公告已删除')).toBeVisible();

    await page.goto('/admin/content/help', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: '帮助中心' })).toBeVisible();
    await expect(page.getByText('新手指南文章')).toBeVisible();

    await page.goto('/admin/content', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/content\/notices/);
  });

  test('opens logs hub and handles filters pagination and detail drawers', async ({ page }) => {
    await mockAdminInfo(page);
    await mockLogs(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    const clickLogTab = async (name: string) => {
      await page.locator('.t-tabs__nav-item-text-wrapper').filter({ hasText: name }).click();
    };

    await page.goto('/admin/logs', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/logs/);
    await expect(page.getByRole('heading', { name: '日志中心' })).toBeVisible();
    await expect(page.getByRole('heading', { name: '系统日志' })).toBeVisible();
    await expect(page.getByText('System boot ok')).toBeVisible();

    const filterRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/logs/system') && request.url().includes('keyword=filtered'),
    );
    await page.getByPlaceholder('日志内容关键词').fill('filtered');
    await page.getByRole('button', { name: '搜索' }).click();
    await filterRequest;
    await expect(page.getByText('Filtered system warning')).toBeVisible();

    await page
      .locator('.t-table__body tr')
      .filter({ hasText: 'Filtered system warning' })
      .getByRole('button', { name: '详情' })
      .click();
    await expect(page.locator('.t-drawer:visible').getByText('系统日志详情')).toBeVisible();
    await expect(page.locator('.t-drawer:visible').getByText('[INFO] Filtered system warning')).toBeVisible();
    await page.keyboard.press('Escape');

    const pageRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/logs/system') && request.url().includes('page=2'),
    );
    await page.locator('.t-pagination').getByText('2', { exact: true }).click();
    await pageRequest;
    await expect(page.getByText('System page 2')).toBeVisible();

    await clickLogTab('管理员登录');
    await expect(page.getByText('cerbo').first()).toBeVisible();

    await clickLogTab('API 日志');
    await expect(page.getByText('/api/v2/admin/users')).toBeVisible();

    await clickLogTab('短信日志');
    await expect(page.getByText('13800000000')).toBeVisible();
    await expect(page.getByText('短信验证码 482915')).toBeVisible();

    await clickLogTab('邮件日志');
    await expect(page.getByText('notice@example.com')).toBeVisible();
    await expect(page.getByText('测试邮件')).toBeVisible();

    await clickLogTab('任务日志');
    await expect(page.getByText('服务自动续费', { exact: true })).toBeVisible();
    await expect(page.getByText('服务自动续费完成')).toBeVisible();
  });

  test('handles log schedules and cleanup actions', async ({ page }) => {
    await mockAdminInfo(page);
    await mockLogs(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/logs?tab=schedules', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('业务与定时已分离')).toBeVisible();
    await expect(
      page.getByText('业务：provision,referral,notification,coupon,default；定时：automation'),
    ).toBeVisible();
    const triggerButton = page.getByRole('button', { name: '立即执行' });
    await expect(triggerButton).toBeVisible();

    const triggerRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/schedule-triggers') && request.method() === 'POST',
    );
    await triggerButton.click();
    await expect((await triggerRequest).postDataJSON()).toMatchObject({ task: 'service-status-sync' });

    await page.goto('/admin/logs?tab=cleanup', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('数据库日志概览')).toBeVisible();
    await expect(page.getByText('storage/logs/laravel.log')).toBeVisible();
    await page.getByPlaceholder('请输入 立即清理').fill('立即清理');
    const cleanupRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/log-cleanups') && request.method() === 'POST',
    );
    await page.getByRole('button', { name: '立即清理' }).click();
    await expect((await cleanupRequest).postDataJSON()).toMatchObject({
      type: 'sms',
      keep_days: 30,
      confirm_text: '立即清理',
    });
    await expect(page.getByText('最近一次清理结果')).toBeVisible();
  });

  test('redirects log compatibility routes', async ({ page }) => {
    await mockAdminInfo(page);
    await mockLogs(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/logs/api', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/logs\?tab=api/);
    await expect(page.getByText('/api/v2/admin/users')).toBeVisible();

    await page.goto('/admin/notifications/email-logs', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/logs\?tab=email/);
    await expect(page.getByText('notice@example.com')).toBeVisible();

    await page.goto('/admin/notifications/sms-logs', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/logs\?tab=sms/);
    await expect(page.getByText('13800000000')).toBeVisible();

    await page.goto('/admin/schedules', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/logs\?tab=schedules/);
    await expect(page.getByText('服务状态同步')).toBeVisible();
  });

  test('does not expose removed system settings page', async ({ page }) => {
    await mockAdminInfo(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/settings', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/settings/);
    await expect(page.locator('.result-title')).toHaveText('页面不存在');
  });

  test('uploads site image and saves site basic settings', async ({ page }) => {
    await mockAdminInfo(page);
    await mockSettingsCenter(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/settings/site/basic', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/settings\?tab=site_basic/);
    await expect(page.getByRole('heading', { name: '系统设置' })).toBeVisible();
    await expect(page.getByText('站点信息')).toBeVisible();

    await page.locator('.field-card').filter({ hasText: '站点名称' }).locator('input').fill('图拉云测试站');
    await page.locator('.field-card').filter({ hasText: '站点 Logo' }).locator('.upload-trigger').click();
    const uploadRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/media-files') && request.method() === 'POST',
    );
    await page.locator('.hidden-file-input').setInputFiles({
      name: 'logo-new.png',
      mimeType: 'image/png',
      buffer: Buffer.from('png'),
    });
    await uploadRequest;
    await expect(page.getByText('图片上传成功')).toBeVisible();
    await expect(page.locator('.field-card').filter({ hasText: '站点 Logo' }).locator('input')).toHaveValue(
      '/uploads/site/logo-new.png',
    );

    const saveRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/settings') && request.method() === 'POST',
    );
    await page.getByRole('button', { name: '保存设置' }).click();
    await expect((await saveRequest).postDataJSON()).toMatchObject({
      group: 'basic',
      settings: {
        site_name: '图拉云测试站',
        site_logo: '/uploads/site/logo-new.png',
      },
    });
    await expect(page.getByText('基础信息已保存')).toBeVisible();
  });

  test('edits home hero and saves independent payload', async ({ page }) => {
    await mockAdminInfo(page);
    await mockSettingsCenter(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/settings/site/hero', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/settings\?tab=site_hero/);
    await expect(page.getByText('轮播项（1 / 5）')).toBeVisible();
    await expect(page.getByText('底部特色卡片（1 / 5）')).toBeVisible();

    const firstSlide = page.locator('.slide-card').first();
    await firstSlide.locator('input').nth(1).fill('官网首页 Banner 测试标题');
    await expect(page.getByText('当前存在未保存修改')).toBeVisible();

    const saveHeroRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/site/home-hero') && request.method() === 'POST',
    );
    await page.getByRole('button', { name: '保存设置' }).click();
    await expect((await saveHeroRequest).postDataJSON()).toMatchObject({
      slides: [{ title: '官网首页 Banner 测试标题' }],
      features: [{ title: '分钟级开通' }],
    });
    await expect(page.getByText('首页 Banner 已保存')).toBeVisible();
  });

  test('opens notifications hub and handles configuration templates and api directory', async ({ page }) => {
    await mockAdminInfo(page);
    await mockNotifications(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/notifications', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/notifications/);
    await expect(page.getByRole('heading', { name: '通知管理' })).toBeVisible();
    await expect(page.getByText('邮件接口', { exact: true })).toBeVisible();
    await expect(page.getByText('短信接口', { exact: true })).toBeVisible();
    await expect(page.getByPlaceholder('例如 smtp.qq.com')).toHaveValue('smtp.example.test');

    await page.getByPlaceholder('例如 smtp.qq.com').fill('smtp.updated.test');
    const emailSaveRequest = page.waitForRequest(
      (request) => request.url().includes('/settings') && request.method() === 'POST',
    );
    await page.getByRole('button', { name: '保存邮件配置' }).click();
    await expect((await emailSaveRequest).postDataJSON()).toMatchObject({
      group: 'notification',
      settings: {
        email_enabled: 1,
        email_host: 'smtp.updated.test',
        email_port: '465',
      },
    });
    await expect(page.getByText('邮件配置已保存')).toBeVisible();

    await page.locator('.t-tabs__nav-item-text-wrapper').filter({ hasText: '邮件模板' }).click();
    await expect(page.getByText('测试验证码邮件')).toBeVisible();
    await expect(page.getByText('HTML')).toBeVisible();
    await expect(page.getByText('独立 CSS')).toHaveCount(0);
    await page.getByText(/管理员模板/).click();
    await expect(page.getByText('测试管理员新工单提示')).toBeVisible();
    await expect(page.getByRole('button', { name: '编辑' }).first()).toBeVisible();

    await page.locator('.t-tabs__nav-item-text-wrapper').filter({ hasText: 'API 接口' }).click();
    await expect(page.getByRole('heading', { name: 'API 接口页' })).toBeVisible();
    await expect(page.getByText('全部接口')).toBeVisible();
    await expect(page.getByText('/api/v2/admin/auth/info').first()).toBeVisible();
    await page.getByPlaceholder('搜索路径、权限码、控制器或源码文件').fill('auth/info');
    await expect(page.getByText('/api/v2/admin/auth/info').first()).toBeVisible();
    await page.getByRole('button', { name: '重置筛选' }).click();
    await expect(page.getByText('/api/v2/admin/auth/info').first()).toBeVisible();

    await page.goto('/admin/notifications/interfaces', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/notifications$/);
    await expect(page.getByText('邮件接口')).toBeVisible();

    await page.goto('/admin/notifications/email-templates', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/notifications\?tab=email-templates/);
    await expect(page.getByText('测试验证码邮件')).toBeVisible();

    await page.goto('/admin/api-directory', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/notifications\?tab=api-directory/);
    await expect(page.getByRole('heading', { name: 'API 接口页' })).toBeVisible();
  });

  test('opens notification email template detail and saves template', async ({ page }) => {
    await mockAdminInfo(page);
    await mockNotifications(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/notifications/email-templates/100001?tab=user', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/notifications\/email-templates\/100001\?tab=user/);
    await expect(page.getByRole('heading', { name: '验证码邮件' })).toBeVisible();
    await expect(page.locator('.token-list')).toContainText('code');
    await expect(page.getByText('482915')).toBeVisible();
    await expect(page.frameLocator('.preview-frame').locator('.email-logo')).toHaveAttribute('src', /logo-new\.png/);
    await expect(page.getByText('主题预览')).toBeVisible();
    await expect(page.getByPlaceholder('请输入邮件主题')).toHaveValue('测试验证码邮件');
    const htmlEditor = page.locator('.editor-pane').filter({ hasText: 'HTML 正文' }).locator('textarea');
    await expect(htmlEditor).toHaveValue(
      '<p>{{#site_logo}}<img class="email-logo" src="{{site_logo}}" alt="{{site_name}}">{{/site_logo}}验证码 {{code}}</p>',
    );
    await expect(page.getByText('模板 CSS')).toHaveCount(0);

    await page.getByPlaceholder('请输入邮件主题').fill('自动化验证码主题');
    await htmlEditor.fill('<style>.email-auto { color: #1f5eff; }</style><p>自动化正文 {{code}}</p>');
    const saveRequest = page.waitForRequest(
      (request) => request.url().includes('/settings') && request.method() === 'POST',
    );
    await page.getByRole('button', { name: '保存模板' }).click();
    await expect((await saveRequest).postDataJSON()).toMatchObject({
      group: 'notification',
      settings: {
        email_template_subject_100001: '自动化验证码主题',
        email_template_content_100001: '<style>.email-auto { color: #1f5eff; }</style><p>自动化正文 {{code}}</p>',
      },
    });
    await expect(page.getByText('模板已保存')).toBeVisible();

    await page.getByRole('button', { name: '恢复默认' }).click();
    await expect(page.getByPlaceholder('请输入邮件主题')).toHaveValue('验证码邮件');
    await expect(htmlEditor).toHaveValue(notificationEmailContentDefault);

    await page.getByRole('button', { name: '返回列表' }).click();
    await expect(page).toHaveURL(/\/admin\/notifications\?tab=user/);
    await expect(page.getByText('测试验证码邮件')).toBeVisible();
  });

  test('opens finance invoices and handles detail and cancel', async ({ page }) => {
    await mockAdminInfo(page);
    await mockInvoices(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    const isMobileViewport = () => (page.viewportSize()?.width || 1440) <= 768;
    const clickRowAction = async (rowText: string, action: string) => {
      const row = isMobileViewport()
        ? page.locator('.invoice-mobile-list .mobile-record-card').filter({ hasText: rowText }).first()
        : page.locator('.t-table__body tr').filter({ hasText: rowText }).first();
      if (isMobileViewport()) {
        await row.locator('.mobile-record-card__more').click();
        await page.getByText(action, { exact: true }).last().click();
        return;
      }
      await row.getByRole('button', { name: action }).click();
    };

    await page.goto('/admin/orders', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/finance\/invoices/);
    await expect(page.getByText('INV-20260606-001')).toBeVisible();
    await expect(page.getByText('标准云服务器 2C4G')).toBeVisible();

    const keywordInput = page.getByPlaceholder('搜索账单号 / 订单号 / 用户');
    const filterRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/invoices') && request.url().includes('keyword=filtered'),
    );
    await keywordInput.fill('filtered');
    await keywordInput.press('Enter');
    await filterRequest;
    await expect(page.getByText('INV-FILTERED-001')).toBeVisible();

    const resetRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/invoices') && !request.url().includes('keyword='),
    );
    await keywordInput.fill('');
    await keywordInput.press('Enter');
    await resetRequest;
    await expect(page.getByText('INV-20260606-001')).toBeVisible();

    const detailRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/invoices/900') && request.method() === 'GET',
    );
    await clickRowAction('INV-20260606-001', '详情');
    await detailRequest;
    const drawer = page.locator('.t-drawer:visible').filter({ hasText: '账单详情' }).first();
    await expect(drawer.locator('.t-drawer__header').filter({ hasText: /^账单详情$/ })).toHaveText('账单详情');
    await drawer.getByText('支付记录', { exact: true }).click();
    await expect(drawer.getByText('PAY-001')).toBeVisible();
    await drawer.getByText('操作日志', { exact: true }).click();
    await expect(drawer.getByText('账单已创建')).toBeVisible();

    const cancelRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/invoices/900/cancellations') && request.method() === 'POST',
    );
    await drawer.getByRole('button', { name: '取消账单' }).click();
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认取消' }).click();
    await cancelRequest;
    await expect(page.getByText('账单已取消')).toBeVisible();
  });

  test('opens finance orders and handles filters and pagination', async ({ page }) => {
    await mockAdminInfo(page);
    await mockOrders(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/finance/orders', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/finance\/orders$/);
    await expect(page.getByText('ORD-20260606-001')).toBeVisible();
    await expect(page.getByText('标准云服务器')).toBeVisible();
    await expect(page.getByText('INV-20260606-001')).toBeVisible();
    await expect(page.locator('.t-pagination')).toContainText('21');

    const filterRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/orders') && request.url().includes('keyword=filtered'),
    );
    const keywordInput = page.getByPlaceholder('搜索订单号 / 账单号 / 用户 / 服务');
    await keywordInput.fill('filtered');
    await keywordInput.press('Enter');
    await filterRequest;
    await expect(page.getByText('ORD-FILTERED-001')).toBeVisible();
    await expect(page.getByText('筛选云服务器')).toBeVisible();

    await keywordInput.fill('');
    await keywordInput.press('Enter');
    await expect(page.getByText('ORD-20260606-001')).toBeVisible();

    const pageRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/orders') && request.url().includes('page=2'),
    );
    await page.locator('.t-pagination').getByText('2', { exact: true }).click();
    await pageRequest;
    await expect(page.getByText('ORD-PAGE-002')).toBeVisible();
  });

  test('opens new purchase order detail and shows instance snapshot', async ({ page }) => {
    await mockAdminInfo(page);
    await mockOrderDetail(page, 'new');
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/finance/orders/800', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('ORD-DETAIL-001').first()).toBeVisible();

    await page.locator('.t-tabs__nav-item').getByText('产品配置', { exact: true }).click();
    await expect(page.getByText('标准云服务器')).toBeVisible();
    const instanceSection = page.locator('.order-detail-section').filter({ hasText: '实例快照' });
    await expect(instanceSection).toBeVisible();
    await expect(instanceSection.getByText('实例ID', { exact: true })).toBeVisible();
    await expect(instanceSection.getByText('11', { exact: true })).toBeVisible();
    await expect(instanceSection.getByText('主机名', { exact: true })).toBeVisible();
    await expect(instanceSection.getByText('vm-001.example.test')).toBeVisible();
  });

  test('hides instance snapshot on renewal order detail', async ({ page }) => {
    await mockAdminInfo(page);
    await mockOrderDetail(page, 'renew');
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/finance/orders/800', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('ORD-DETAIL-001').first()).toBeVisible();

    await page.locator('.t-tabs__nav-item').getByText('产品配置', { exact: true }).click();
    await expect(page.getByText('标准云服务器')).toBeVisible();
    await expect(page.locator('.order-detail-section').filter({ hasText: '实例快照' })).toHaveCount(0);
  });

  test('opens finance renewal orders and handles filters and pagination', async ({ page }) => {
    await mockAdminInfo(page);
    await mockFinanceModeOrders(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/finance/renewals', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/finance\/orders\/renewals/);
    await expect(page.getByText('REN-20260606-001')).toBeVisible();
    await expect(page.getByText('INV-RENEW-001')).toBeVisible();

    const filterRequest = page.waitForRequest(
      (request) =>
        request.url().includes('/api/v2/admin/finance/renewal-orders') && request.url().includes('keyword=filtered'),
    );
    const keywordInput = page.getByPlaceholder('搜索订单号 / 账单号 / 用户 / 服务');
    await keywordInput.fill('filtered');
    await keywordInput.press('Enter');
    await filterRequest;
    await expect(page.getByText('REN-FILTERED-001')).toBeVisible();

    await keywordInput.fill('');
    await keywordInput.press('Enter');
    await expect(page.getByText('REN-20260606-001')).toBeVisible();

    const pageRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/finance/renewal-orders') && request.url().includes('page=2'),
    );
    await page.locator('.t-pagination').getByText('2', { exact: true }).click();
    await pageRequest;
    await expect(page.getByText('REN-PAGE-002')).toBeVisible();
  });

  test('opens finance upgrade orders and handles filters and pagination', async ({ page }) => {
    await mockAdminInfo(page);
    await mockFinanceModeOrders(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/finance/upgrades', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/finance\/orders\/upgrades/);
    await expect(page.getByText('UPG-20260606-001')).toBeVisible();
    if ((page.viewportSize()?.width || 1440) > 768) {
      await expect(page.getByText('流量包', { exact: true })).toBeVisible();
      await expect(page.getByText('100GB 流量包')).toBeVisible();
    }

    const filterRequest = page.waitForRequest(
      (request) =>
        request.url().includes('/api/v2/admin/finance/upgrade-orders') && request.url().includes('keyword=filtered'),
    );
    const keywordInput = page.getByPlaceholder('搜索订单号 / 账单号 / 用户 / 服务');
    await keywordInput.fill('filtered');
    await keywordInput.press('Enter');
    await filterRequest;
    await expect(page.getByText('UPG-FILTERED-001')).toBeVisible();

    await keywordInput.fill('');
    await keywordInput.press('Enter');
    await expect(page.getByText('UPG-20260606-001')).toBeVisible();

    const pageRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/finance/upgrade-orders') && request.url().includes('page=2'),
    );
    await page.locator('.t-pagination').getByText('2', { exact: true }).click();
    await pageRequest;
    await expect(page.getByText('UPG-PAGE-002')).toBeVisible();
  });

  test('opens finance recharges and handles filters and pagination', async ({ page }) => {
    await mockAdminInfo(page);
    await mockRecharges(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/finance/recharges', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/finance\/recharges/);
    await expect(page.getByText('PAY-RECHARGE-001').first()).toBeVisible();
    await expect(page.getByText('¥200.00').first()).toBeVisible();
    await expect(page.locator('.t-pagination')).toContainText('21');

    const filterRequest = page.waitForRequest(
      (request) =>
        request.url().includes('/api/v2/admin/finance/recharges') && request.url().includes('keyword=filtered'),
    );
    const keywordInput = page.getByPlaceholder('搜索支付号 / 第三方单号 / 用户');
    await keywordInput.fill('filtered');
    await keywordInput.press('Enter');
    await filterRequest;
    await expect(page.getByText('PAY-FILTERED-001').first()).toBeVisible();

    await keywordInput.fill('');
    await keywordInput.press('Enter');
    await expect(page.getByText('PAY-RECHARGE-001').first()).toBeVisible();

    const pageRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/finance/recharges') && request.url().includes('page=2'),
    );
    await page.locator('.t-pagination').getByText('2', { exact: true }).click();
    await pageRequest;
    await expect(page.getByText('PAY-PAGE-002').first()).toBeVisible();
  });

  test('opens finance new customers and handles date range', async ({ page }) => {
    await mockAdminInfo(page);
    await mockNewCustomers(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/finance/new-customers', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/finance\/new-customers/);
    await expect(page.getByText('2026-06-06')).toBeVisible();

    const filterRequest = page.waitForRequest(
      (request) =>
        request.url().includes('/api/v2/admin/finance/new-customer-daily-summary') &&
        request.url().includes('start_date=2026-05-01') &&
        request.url().includes('end_date=2026-05-31'),
    );
    const startDateInput = page.getByPlaceholder('开始日期');
    const endDateInput = page.getByPlaceholder('结束日期');
    const selectMayDate = async (input: typeof startDateInput, day: '1' | '31') => {
      await input.click();
      const panel = page.locator('.t-date-picker__panel:visible').last();
      await expect(panel).toBeVisible();
      for (let month = 0; month < 3; month += 1) {
        await panel.locator('.t-pagination-mini__prev').click();
      }
      const dayCell = panel
        .locator('td.t-date-picker__cell:not(.t-date-picker__cell--additional)')
        .filter({ hasText: new RegExp(`^${day}$`) });
      await expect(dayCell).toHaveCount(1);
      await dayCell.click();
    };
    await selectMayDate(startDateInput, '1');
    await selectMayDate(endDateInput, '31');
    await filterRequest;
    await expect(page.getByText('2026-05-02')).toBeVisible();

    await page.getByRole('button', { name: '本月' }).click();
    await expect(page.getByText('2026-06-06')).toBeVisible();
  });

  test('opens services and handles filters pagination and hostnames', async ({ page }) => {
    await mockAdminInfo(page);
    await mockServices(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/services', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: '服务列表' })).toBeVisible();
    await expect(page.getByText('SVC-20260606-001')).toBeVisible();
    await expect(page.getByText('标准云服务器 2C4G')).toBeVisible();
    await expect(page.getByText('INV-SERVICE-001')).toBeVisible();

    const filterRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/services') && request.url().includes('keyword=filtered'),
    );
    await page.getByPlaceholder('搜索主机ID / 主机IP / 实例ID / 用户名 / 账单号').fill('filtered');
    await page.getByRole('button', { name: '搜索' }).click();
    await filterRequest;
    await expect(page.getByText('SVC-FILTERED-001')).toBeVisible();

    await page.getByPlaceholder('搜索主机ID / 主机IP / 实例ID / 用户名 / 账单号').clear();
    await page.getByRole('button', { name: '搜索' }).click();
    await expect(page.getByText('SVC-20260606-001')).toBeVisible();

    const pageRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/services') && request.url().includes('page=2'),
    );
    await page.locator('.t-pagination').getByText('2', { exact: true }).click();
    await pageRequest;
    await expect(page.getByText('SVC-PAGE-002')).toBeVisible();

    await page.goto('/admin/services', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('SVC-20260606-001')).toBeVisible();
    await page.locator('.services-page .t-table__body .t-checkbox').first().click();
    await page.getByRole('button', { name: /批量主机名/ }).click();
    const dialog = page.locator('.t-dialog:visible');
    await expect(dialog.getByText('设置自定义主机名')).toBeVisible();
    await dialog.getByPlaceholder('留空则清空自定义主机名').fill('custom-vm-001');
    const saveRequest = page.waitForRequest(
      (request) =>
        request.url().includes('/api/v2/admin/services/custom-hostnames/batch') && request.method() === 'POST',
    );
    await dialog.getByRole('button', { name: '保存' }).click();
    expect((await saveRequest).postDataJSON()).toMatchObject({
      items: [{ service_id: 11, hostname: 'custom-vm-001' }],
    });
  });

  test('redirects finance product income to services', async ({ page }) => {
    await mockAdminInfo(page);
    await mockServices(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/finance/product-income', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/services/);
    await expect(page.getByText('SVC-20260606-001')).toBeVisible();
  });

  test('opens instance specs and saves catalog bindings', async ({ page }) => {
    await mockAdminInfo(page);
    await mockInstanceSpecs(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/specs', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/specs/);
    await expect(page.getByRole('heading', { name: '实例规格管理' })).toBeVisible();
    await expect(page.getByText('ecs.g9i.2c2g')).toBeVisible();

    const filterRequest = page.waitForRequest(
      (request) =>
        request.url().includes('/api/v2/admin/instance-spec-catalog') && request.url().includes('keyword=filtered'),
    );
    await page.getByPlaceholder('搜索规格文本 / 别名 / 说明 / 绑定配置').fill('filtered');
    await page.getByRole('button', { name: '搜索' }).click();
    await filterRequest;
    await expect(page.getByText('筛选规格')).toBeVisible();

    await page.locator('.specs-page .t-table__body .t-select').first().click();
    await page.getByText('标准云服务器 · 2C4G · 云服务器 / 通用型').last().click();

    await page.getByRole('button', { name: '新增规格' }).click();
    const specDialog = page.locator('.t-dialog:visible').filter({ hasText: '新增实例规格' });
    await specDialog.locator('.t-form__item').filter({ hasText: '实例规格文本' }).locator('input').fill('ecs.g9i.4c8g');
    await specDialog.locator('.t-form__item').filter({ hasText: '别名' }).locator('input').fill('4 核 8G');
    await specDialog.getByRole('button', { name: '确认' }).click();
    await expect(page.getByText('ecs.g9i.4c8g')).toBeVisible();

    const createdSpecRow = page.getByRole('row', { name: /ecs\.g9i\.4c8g/ });
    await createdSpecRow.getByRole('button', { name: '删除' }).click();
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认删除' }).click();
    await expect(page.getByText('ecs.g9i.4c8g')).not.toBeVisible();

    const saveRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/instance-spec-catalog') && request.method() === 'POST',
    );
    await page.getByRole('button', { name: '保存目录' }).click();
    const savePayload = (await saveRequest).postDataJSON();
    expect(savePayload.list).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          text: '筛选规格',
          bindings: [expect.objectContaining({ product_id: 101, display_name: '标准云服务器' })],
        }),
      ]),
    );
  });

  test('opens CPU models and saves catalog bindings', async ({ page }) => {
    await mockAdminInfo(page);
    await mockCpuModels(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/cpu-models', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/cpu-models/);
    await expect(page.getByRole('heading', { name: 'CPU型号管理' })).toBeVisible();
    await expect(page.getByText('Intel Xeon Gold 6133')).toBeVisible();
    await expect(page.getByText('主频 2.50GHz')).toBeVisible();

    await page.locator('.cpu-models-page .t-table__body .t-select').first().click();
    await page.getByText('标准云服务器 · 2C4G · 云服务器 / 通用型').last().click();

    await page.getByRole('button', { name: '新增型号' }).click();
    const modelDialog = page.locator('.t-dialog:visible').filter({ hasText: '新增 CPU 型号' });
    await modelDialog
      .locator('.t-form__item')
      .filter({ hasText: '型号名称' })
      .locator('input')
      .fill('Intel Xeon Silver 4210');
    await modelDialog.locator('.t-form__item').filter({ hasText: '主频' }).locator('input').fill('2.20');
    await modelDialog.locator('.t-form__item').filter({ hasText: '睿频' }).locator('input').fill('3.20');
    await modelDialog.getByRole('button', { name: '确认' }).click();
    await expect(page.getByText('Intel Xeon Silver 4210')).toBeVisible();

    const createdModelRow = page.getByRole('row', { name: /Intel Xeon Silver 4210/ });
    await createdModelRow.getByRole('button', { name: '删除' }).click();
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认删除' }).click();
    await expect(page.getByText('Intel Xeon Silver 4210')).not.toBeVisible();

    await page.getByRole('button', { name: '新增分组' }).click();
    const groupDialog = page.locator('.t-dialog:visible').filter({ hasText: '新增 CPU 分组' });
    await groupDialog.locator('.t-form__item').filter({ hasText: '分组名称' }).locator('input').fill('AMD EPYC');
    await groupDialog.getByRole('button', { name: '确认' }).click();
    const createdGroup = page.locator('.cpu-group-item').filter({ hasText: 'AMD EPYC' });
    await expect(createdGroup).toBeVisible();
    await createdGroup.getByRole('button', { name: '删除' }).click();
    await page.locator('.t-dialog:visible').getByRole('button', { name: '确认删除' }).click();
    await expect(createdGroup).not.toBeVisible();

    const saveRequest = page.waitForRequest(
      (request) => request.url().includes('/api/v2/admin/cpu-model-catalog') && request.method() === 'POST',
    );
    await page.getByRole('button', { name: '保存目录' }).click();
    const savePayload = (await saveRequest).postDataJSON();
    expect(savePayload.list).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          name: 'Intel Xeon',
          models: expect.arrayContaining([
            expect.objectContaining({
              name: 'Intel Xeon Gold 6133',
              base_frequency: '2.50GHz',
              turbo_frequency: '3.20GHz',
              bindings: [expect.objectContaining({ product_id: 101, display_name: '标准云服务器' })],
            }),
          ]),
        }),
      ]),
    );
  });

  test('opens user detail and loads primary tabs', async ({ page }) => {
    await mockAdminInfo(page);
    await mockUserDetail(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/users/1', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/users\/1/);
    await expect(page.getByRole('heading', { name: '测试用户' })).toBeVisible();
    await expect(page.locator('.user-note').filter({ hasText: '重点客户' })).toBeVisible();

    await page.getByRole('button', { name: '编辑资料' }).click();
    await expect(page.locator('.t-dialog:visible').getByText('信用额度')).toBeVisible();
    await page
      .locator('.t-dialog:visible')
      .getByRole('button', { name: /Cancel|取消/ })
      .click();

    await userDetailTab(page, '产品/服务').click();
    await expect(page.getByText('测试云服务器').first()).toBeVisible();
    await page.locator('.table-scroll').getByRole('button', { name: '管理', exact: true }).first().click();
    await expect(page.locator('.t-drawer:visible').getByText('服务控制台')).toBeVisible();
    await page.keyboard.press('Escape');

    await userDetailTab(page, '账单').click();
    await expect(page.locator('td').filter({ hasText: 'INV-USER-001' }).first()).toBeVisible();

    await userDetailTab(page, '资金流水').click();
    await expect(page.getByText('测试充值')).toBeVisible();

    await userDetailTab(page, '工单').click();
    await expect(page.getByText('测试工单')).toBeVisible();

    await userDetailTab(page, '操作日志').click();
    await expect(page.getByText('admin.user.update')).toBeVisible();

    await userDetailTab(page, '通知记录').click();
    await expect(page.getByText('测试邮件')).toBeVisible();

    await page.getByRole('button', { name: '资金管理' }).click();
    await expect(page.locator('.t-dialog:visible').getByText('操作类型')).toBeVisible();
  });

  test('opens the client login page and hands over the login code without a URL query', async ({ page, context }) => {
    await mockAdminInfo(page);
    await mockUserDetail(page);
    await context.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
      window.addEventListener('message', (event) => {
        if (event.data?.type === 'turaidc:login-as-code') {
          (window as typeof window & { __loginAsCode?: unknown }).__loginAsCode = {
            code: event.data.code,
            origin: event.origin,
          };
        }
      });
    });

    await page.goto('/admin/users/1', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('button', { name: '代登录', exact: true })).toBeVisible();

    const popupPromise = page.waitForEvent('popup');
    await page.getByRole('button', { name: '代登录', exact: true }).click();
    const popup = await popupPromise;
    await popup.waitForURL(/\/client\/login-as$/);
    expect(new URL(popup.url()).searchParams.has('code')).toBe(false);
    await popup.evaluate((openerOrigin) => {
      window.opener?.postMessage({ type: 'turaidc:login-as-ready' }, openerOrigin);
    }, new URL(page.url()).origin);
    await expect(page.getByText('已打开客户端登录页')).toBeVisible();
    await expect
      .poll(() => popup.evaluate(() => (window as typeof window & { __loginAsCode?: unknown }).__loginAsCode ?? null))
      .toEqual({ code: 'LOGIN-AS-CODE', origin: new URL(page.url()).origin });
    await popup.close();
  });

  test('does not issue a login code when the browser blocks the login-as popup', async ({ page }) => {
    await mockAdminInfo(page);
    await mockUserDetail(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
      window.open = () => null;
    });

    let loginAsRequestCount = 0;
    page.on('request', (request) => {
      if (new URL(request.url()).pathname.endsWith('/api/v2/admin/users/1/login-as')) {
        loginAsRequestCount += 1;
      }
    });

    await page.goto('/admin/users/1', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: '代登录', exact: true }).click();

    await expect(page.getByText('浏览器拦截了代登录窗口，请允许弹窗后重试')).toBeVisible();
    expect(loginAsRequestCount).toBe(0);
  });

  test('opens user detail deep service and invoice actions', async ({ page }) => {
    await mockAdminInfo(page);
    await mockUserDetail(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    const productTreeRequest = page.waitForRequest(
      (request) =>
        request.url().includes('/api/v2/admin/coupon-product-groups/batch-products') && request.method() === 'POST',
    );
    await page.goto('/admin/users/1', { waitUntil: 'domcontentloaded' });
    expect((await productTreeRequest).postDataJSON()).toEqual({ groups: [{ id: 4, level: 3 }] });

    await userDetailTab(page, '产品/服务').click();
    await page.getByRole('button', { name: '添加实例' }).click();
    const addServiceDialog = page.locator('.t-dialog:visible');
    const selectedProductRequests: string[] = [];
    page.on('request', (request) => {
      if (new URL(request.url()).pathname.endsWith('/products/101')) {
        selectedProductRequests.push(request.url());
      }
    });
    await expect(addServiceDialog.getByText('选择商品')).toBeVisible();
    await expect(addServiceDialog.getByText('自动创建订单', { exact: true })).toBeVisible();
    await expect(addServiceDialog.getByText('自动创建账单', { exact: true })).toBeVisible();
    await expect(addServiceDialog.getByText('从余额扣款', { exact: true })).toBeVisible();
    await addServiceDialog.locator('.binding-tree-select').click();
    const productTreePopup = page.locator('.binding-tree-select-popup:visible');
    await productTreePopup.locator('[data-value="type:cloud_server"] .t-tree__icon').click();
    await productTreePopup.getByText('特价云服务器', { exact: true }).click();
    await expect(productTreePopup).toBeVisible();
    await productTreePopup.getByText('高宽', { exact: true }).click();
    await expect(productTreePopup).toBeVisible();
    expect(selectedProductRequests).toEqual([]);
    await expect(productTreePopup.getByText('高宽云服务器 2C4G', { exact: true })).toBeVisible();
    await productTreePopup.getByText('高宽云服务器 2C4G', { exact: true }).click();
    await expect.poll(() => selectedProductRequests).toHaveLength(1);
    await expect(
      addServiceDialog.locator('.t-form__item').filter({ hasText: '服务名称' }).locator('input'),
    ).toHaveValue('高宽云服务器 2C4G');
    await page
      .locator('.t-dialog:visible')
      .getByRole('button', { name: /Cancel|取消/ })
      .click();

    await page.locator('.table-scroll').getByRole('button', { name: '管理', exact: true }).first().click();
    const serviceDrawer = page.locator('.t-drawer:visible');
    await expect(serviceDrawer.getByText('服务控制台')).toBeVisible();
    await expect(serviceDrawer.getByRole('button', { name: '重置密码' })).toBeVisible();
    await expect(serviceDrawer.getByRole('button', { name: '上游绑定' })).toBeVisible();
    await expect(serviceDrawer.getByRole('button', { name: '调价' })).toBeVisible();
    await expect(serviceDrawer.getByRole('button', { name: '退款' })).toBeVisible();

    await serviceDrawer.getByRole('button', { name: '重置密码' }).click();
    await expect(page.locator('.t-dialog:visible').getByText('新密码')).toBeVisible();
    await page
      .locator('.t-dialog:visible')
      .getByRole('button', { name: /Cancel|取消/ })
      .click();

    await serviceDrawer.getByRole('button', { name: '退款' }).click();
    await expect(page.locator('.t-dialog:visible').getByText('退款方式')).toBeVisible();
    await page
      .locator('.t-dialog:visible')
      .getByRole('button', { name: /Cancel|取消/ })
      .click();
    await page.keyboard.press('Escape');

    await userDetailTab(page, '账单').click();
    await page.locator('.table-scroll').getByRole('button', { name: '详情', exact: true }).first().click();
    const invoiceDrawer = page.locator('.t-drawer:visible');
    await expect(invoiceDrawer.getByText('INV-USER-001')).toBeVisible();
    await expect(invoiceDrawer.getByText('支付 / 退款记录')).toBeVisible();
    await expect(invoiceDrawer.getByRole('button', { name: '退款' })).toBeVisible();
  });

  test('opens verification list detail and history', async ({ page }) => {
    await mockAdminInfo(page);
    await mockVerifications(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/users/verification', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/users\/verification/);
    await expect(page.getByText('实名列表')).toBeVisible();
    await expect(visibleVerificationName(page, '张三')).toBeVisible();

    await page.getByPlaceholder('输入关键字').fill('filter');
    await page.getByRole('button', { name: '搜索' }).click();
    await expect(visibleVerificationName(page, '李四')).toBeVisible();

    await page.getByRole('button', { name: '查看' }).first().click();
    await expect(page.getByText('实名认证详情')).toBeVisible();
    await expect(page.getByText('CERT-001')).toBeVisible();
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: '历史记录' }).first().click();
    await expect(page.getByText('历史记录(测试用户)')).toBeVisible();
  });

  test('opens verification route directly', async ({ page }) => {
    await mockAdminInfo(page);
    await mockVerifications(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/users/verification', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/admin\/users\/verification/);
    await expect(page.getByText('实名列表')).toBeVisible();
    await expect(visibleVerificationName(page, '张三')).toBeVisible();
  });

  test('saves verification settings', async ({ page }) => {
    await mockAdminInfo(page);
    await mockVerifications(page);
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.goto('/admin/users/verification', { waitUntil: 'domcontentloaded' });

    await page.locator('.verification-panel .t-tabs__nav-item').filter({ hasText: '实名管理' }).click();
    await expect(page.getByText('免费认证次数')).toBeVisible();
    await page.getByRole('button', { name: '保存费用设置' }).click();
    await expect(page.getByText('费用设置已保存')).toBeVisible();

    await page.locator('.verification-panel .t-tabs__nav-item').filter({ hasText: '实名接口' }).click();
    await expect(page.getByText('API ID')).toBeVisible();
    await page.getByRole('button', { name: '保存配置' }).click();
    await expect(page.getByText('保存成功')).toBeVisible();
  });
});
