import { request } from '@/utils/request';

export interface RedisConnectionItem {
  name: string;
  database: number;
  connected: boolean;
  message: string;
}

export interface RedisKeyspaceItem {
  database: string;
  keys: number;
  expires: number;
  avg_ttl: number;
}

export interface RedisStatus {
  available: boolean;
  client: string;
  prefix: string;
  cache_store: string;
  connections: RedisConnectionItem[];
  server: Record<string, string>;
  keyspace: RedisKeyspaceItem[];
  memory: Record<string, string | number>;
  stats: Record<string, string | number>;
}

export interface RedisActionResult {
  id?: string;
  status?: string;
  message?: string;
}

export const redisApi = {
  status: () => request.get<RedisStatus>({ url: '/v2/admin/redis/status' }),
  flushCache: () => request.post<RedisActionResult>({ url: '/v2/admin/redis/cache/flush', data: {} }),
};
