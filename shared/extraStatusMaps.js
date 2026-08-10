export const CONTENT_STATUS_MAP = {
  0: { label: '草稿', color: '#909399', tagType: 'info', icon: 'EditPen' },
  1: { label: '已发布', color: '#67C23A', tagType: 'success', icon: 'Select' },
  2: { label: '已下线', color: '#E6A23C', tagType: 'warning', icon: 'RemoveFilled' },
}

export const COUPON_DISPLAY_STATUS_MAP = {
  pending: { label: '待生效', color: '#E6A23C', tagType: 'warning', icon: 'Clock' },
  active: { label: '生效中', color: '#67C23A', tagType: 'success', icon: 'CircleCheck' },
  disabled: { label: '已停用', color: '#909399', tagType: 'info', icon: 'SwitchButton' },
  expired: { label: '已过期', color: '#F56C6C', tagType: 'danger', icon: 'Timer' },
}
