/**
 * 图标一律来自 TDesign Icons，不手绘 SVG。
 * 数据层只存语义 key，组件按 key 取组件，避免在文案文件里塞组件引用。
 */
import {
  CertificateIcon,
  ChartIcon,
  ChatBubbleIcon,
  CheckCircleFilledIcon,
  CloseCircleFilledIcon,
  ControlPlatformIcon,
  ForkIcon,
  LayersIcon,
  LogoGithubIcon,
  MailIcon,
  MoneyIcon,
  NotificationIcon,
  RefreshIcon,
  RocketIcon,
  ServerIcon,
  ShopIcon,
  StarIcon,
  SwapIcon,
  TaskIcon,
  TerminalIcon,
  TicketIcon,
  TimeIcon,
  UserIcon,
  WalletIcon,
} from "tdesign-icons-vue-next";

export const ICONS = {
  chart: ChartIcon,
  check: CheckCircleFilledIcon,
  close: CloseCircleFilledIcon,
  control: ControlPlatformIcon,
  fork: ForkIcon,
  github: LogoGithubIcon,
  issue: ChatBubbleIcon,
  layers: LayersIcon,
  license: CertificateIcon,
  mail: MailIcon,
  money: MoneyIcon,
  notification: NotificationIcon,
  refresh: RefreshIcon,
  rocket: RocketIcon,
  server: ServerIcon,
  shop: ShopIcon,
  star: StarIcon,
  swap: SwapIcon,
  task: TaskIcon,
  terminal: TerminalIcon,
  ticket: TicketIcon,
  time: TimeIcon,
  user: UserIcon,
  wallet: WalletIcon,
} as const;

export type IconKey = keyof typeof ICONS;
