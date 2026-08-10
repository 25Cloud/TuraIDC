import { hasPermissionInList } from '@/constants/permissions';
import { useUserStore } from '@/store';

export function hasAdminPermission(permission: string) {
  const permissions = useUserStore().userInfo?.permissions || [];
  return hasPermissionInList(permissions, permission);
}
