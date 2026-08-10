import type { RouteRecordRaw } from 'vue-router';

export const productsRoutes: RouteRecordRaw[] = [
  {
    path: 'products',
    name: 'AdminProductCatalog',
    component: () => import('@/pages/products/index.vue'),
    meta: {
      title: {
        zh_CN: '商品目录',
        en_US: 'Product Catalog',
      },
      productTab: 'catalog',
      permission: 'product.list',
    },
  },
  {
    path: 'specs',
    name: 'AdminSpecs',
    component: () => import('@/pages/products/specs/index.vue'),
    meta: {
      title: {
        zh_CN: '规格管理',
        en_US: 'Specs',
      },
      permission: 'product.list',
    },
  },
  {
    path: 'cpu-models',
    name: 'AdminCpuModels',
    component: () => import('@/pages/products/cpu-models/index.vue'),
    meta: {
      title: {
        zh_CN: 'CPU型号管理',
        en_US: 'CPU Models',
      },
      permission: 'product.list',
    },
  },
  {
    path: 'products/create',
    name: 'AdminProductCreate',
    component: () => import('@/pages/products/edit.vue'),
    meta: {
      title: {
        zh_CN: '新增商品',
        en_US: 'Create Product',
      },
      permission: 'product.list',
      hidden: true,
    },
  },
  {
    path: 'products/:id/edit',
    name: 'AdminProductEdit',
    component: () => import('@/pages/products/edit.vue'),
    meta: {
      title: {
        zh_CN: '编辑商品',
        en_US: 'Edit Product',
      },
      permission: 'product.list',
      hidden: true,
    },
  },
  {
    path: 'products/traffic-packages',
    name: 'AdminTrafficPackages',
    component: () => import('@/pages/products/index.vue'),
    meta: {
      title: {
        zh_CN: '流量包管理',
        en_US: 'Traffic Packages',
      },
      productTab: 'traffic-packages',
      permission: 'product.list',
    },
  },
  {
    path: 'products/suppliers',
    name: 'AdminSuppliers',
    component: () => import('@/pages/products/index.vue'),
    meta: {
      title: {
        zh_CN: '上游提供商',
        en_US: 'Suppliers',
      },
      productTab: 'suppliers',
      permission: 'supplier.list',
    },
  },
];
