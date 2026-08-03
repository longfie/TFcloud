export const pluginNames: Record<string, string> = {
  tieba: '百度贴吧',
  bilibili: '哔哩哔哩',
  netease: '网易云音乐',
  iqiyi: '爱奇艺',
  sport: '小米运动',
  cloud189: '天翼云盘',
  picacomic: '哔咔漫画',
};

export const actionNames: Record<string, string> = {
  daily_sign: '每日签到',
  daily_tasks: '每日任务',
  watch_video: '观看视频',
  share_video: '分享视频',
  give_coin: '投币任务',
  live_sign: '直播签到',
  live_daily_bag: '直播每日礼包',
  live_heartbeat: '直播心跳',
  capsule_open: '开启直播扭蛋',
  comic_sign: '漫画签到',
  vip_sign: '会员签到',
  score_sign: '积分签到',
  update_steps: '修改步数',
  forum_sign: '贴吧签到',
  daily_sign_summary: '签到汇总',
};

export function formatDateTime (value?: string | null): string {
  if (!value) return '—';
  const normalized = value.includes('T') ? value : value.replace(' ', 'T');
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('zh-CN', {
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(date);
}

export function formatDate (value?: string | null): string {
  if (!value) return '—';
  const normalized = value.includes('T') ? value : value.replace(' ', 'T');
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return value.slice(0, 10);
  return new Intl.DateTimeFormat('zh-CN', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(date);
}

export function formatFullDateTime (value?: string | null): string {
  if (!value) return '—';
  const normalized = value.includes('T') ? value : value.replace(' ', 'T');
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('zh-CN', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(date);
}

export function shortTaskNo (value: string): string {
  return value.length > 14 ? `${value.slice(0, 8)}…${value.slice(-4)}` : value;
}

export function errorMessage (error: unknown): string {
  return error instanceof Error ? error.message : '操作失败，请稍后重试';
}
