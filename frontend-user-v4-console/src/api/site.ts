import request from '@/utils/request';

export default {
  config: (config?: Record<string, unknown>) => request.get('/v2/site/config', config),
};
