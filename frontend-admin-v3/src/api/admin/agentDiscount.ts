import { request } from '@/utils/request';

import type {
  AgentGroupDiscountMatrixRow,
  AgentGroupDiscountMatrixSavePayload,
  AgentGroupPayload,
  AgentGroupRecord,
  ProductDiscountGroupPayload,
  ProductDiscountGroupRecord,
} from './types';

interface AgentGroupListResponse {
  list?: AgentGroupRecord[];
}

interface ProductDiscountGroupListResponse {
  list?: ProductDiscountGroupRecord[];
}

interface MatrixResponse {
  rows?: AgentGroupDiscountMatrixRow[];
}

export const agentGroupsApi = {
  list: async () => {
    const response = await request.get<AgentGroupListResponse>({ url: '/v2/admin/agent-groups' });
    return response.list || [];
  },
  create: (data: AgentGroupPayload) => request.post<AgentGroupRecord>({ url: '/v2/admin/agent-groups', data }),
  update: (id: number | string, data: AgentGroupPayload) =>
    request.put<AgentGroupRecord>({ url: `/v2/admin/agent-groups/${id}`, data }),
  delete: (id: number | string) => request.delete({ url: `/v2/admin/agent-groups/${id}` }),
};

export const productDiscountGroupsApi = {
  list: async () => {
    const response = await request.get<ProductDiscountGroupListResponse>({ url: '/v2/admin/product-discount-groups' });
    return response.list || [];
  },
  create: (data: ProductDiscountGroupPayload) =>
    request.post<ProductDiscountGroupRecord>({ url: '/v2/admin/product-discount-groups', data }),
  update: (id: number | string, data: ProductDiscountGroupPayload) =>
    request.put<ProductDiscountGroupRecord>({ url: `/v2/admin/product-discount-groups/${id}`, data }),
  delete: (id: number | string) => request.delete({ url: `/v2/admin/product-discount-groups/${id}` }),
};

export const agentDiscountMatrixApi = {
  get: async () => {
    const response = await request.get<MatrixResponse>({ url: '/v2/admin/agent-group-discounts' });
    return response.rows || [];
  },
  save: (data: AgentGroupDiscountMatrixSavePayload) =>
    request.put<unknown>({ url: '/v2/admin/agent-group-discounts', data }),
};

export const agentDiscountApi = {
  agentGroups: agentGroupsApi,
  productDiscountGroups: productDiscountGroupsApi,
  matrix: agentDiscountMatrixApi,
};
