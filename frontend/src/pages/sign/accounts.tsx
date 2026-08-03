import { Button } from '@heroui/button';
import { Card, CardBody, CardFooter, CardHeader } from '@heroui/card';
import { Input } from '@heroui/input';
import { Modal, ModalBody, ModalContent, ModalFooter, ModalHeader } from '@heroui/modal';
import { Select, SelectItem } from '@heroui/select';
import { Spinner } from '@heroui/spinner';
import { Switch } from '@heroui/switch';
import { motion } from 'motion/react';
import { QRCodeSVG } from 'qrcode.react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'react-hot-toast';
import {
  LuCirclePlus, LuClock3, LuCookie, LuKeyRound, LuMessageSquare, LuPencil, LuPlay,
  LuQrCode, LuRefreshCw, LuShieldCheck, LuSmartphone, LuTrash2, LuTriangleAlert, LuUserRound,
} from 'react-icons/lu';
import { useSearchParams } from 'react-router-dom';

import { apiRequest } from '@/api/client';
import { confirmCard } from '@/components/confirm_card';
import PlatformBrandIcon, { platformLogoByCode } from '@/components/platform_brand_icon';
import StatusChip from '@/components/sign/status_chip';
import TaskProgressModal from '@/components/sign/task_progress_modal';
import type {
  Platform, PlatformAuthResult, PlatformConnectionMethod, PlatformDetail, PlatformQrFlow,
  PluginAccount, SignTask,
} from '@/types/sign';
import { actionNames, errorMessage, formatDateTime, pluginNames } from '@/utils/sign';

interface AccountForm {
  platform_code: string;
  method: PlatformConnectionMethod;
  display_name: string;
  credentials: Record<string, string>;
  username: string;
  password: string;
  login_type: string;
  phone: string;
  sms_code: string;
  captcha: string;
  security_code: string;
  schedule_mode: 'auto' | 'fixed';
  schedule_time: string;
  scheduled_action: string;
  status: string;
  bili_watch_enabled: boolean;
  bili_share_enabled: boolean;
  bili_coin_enabled: boolean;
  bili_allow_coin_spend: boolean;
  bili_coin_count: string;
  bili_live_sign_enabled: boolean;
  bili_live_daily_bag_enabled: boolean;
  bili_live_heartbeat_enabled: boolean;
  bili_live_room_id: string;
  bili_capsule_enabled: boolean;
  bili_allow_capsule_spend: boolean;
  bili_capsule_count: string;
  bili_comic_sign_enabled: boolean;
  sport_step_mode: 'fixed' | 'random';
  sport_steps: string;
  sport_min_steps: string;
  sport_max_steps: string;
  sport_progressive_by_time: boolean;
}

const emptyForm: AccountForm = {
  platform_code: '', method: 'qr', display_name: '', credentials: {}, username: '', password: '', login_type: 'auto',
  phone: '', sms_code: '', captcha: '', security_code: '',
  schedule_mode: 'auto', schedule_time: '06:30', scheduled_action: '', status: 'active',
  bili_watch_enabled: true, bili_share_enabled: true, bili_coin_enabled: false,
  bili_allow_coin_spend: false, bili_coin_count: '1', bili_live_sign_enabled: true,
  bili_live_daily_bag_enabled: false, bili_live_heartbeat_enabled: false, bili_live_room_id: '',
  bili_capsule_enabled: false, bili_allow_capsule_spend: false, bili_capsule_count: '1',
  bili_comic_sign_enabled: false,
  sport_step_mode: 'fixed', sport_steps: '18000', sport_min_steps: '18000',
  sport_max_steps: '25000', sport_progressive_by_time: false,
};

const platformStyles: Record<string, { accent: string; surface: string; glyph: string; badge: string; option: string }> = {
  tieba: {
    accent: 'from-blue-600 to-cyan-400 shadow-blue-500/25',
    surface: '!bg-gradient-to-br !from-blue-50 !via-white !to-cyan-50/80 dark:!from-blue-950/35 dark:!via-content1 dark:!to-cyan-950/20',
    glyph: '贴', badge: 'bg-blue-500/10 text-blue-600 dark:text-blue-300',
    option: '!bg-gradient-to-r !from-blue-50 !to-cyan-50 data-[hover=true]:!from-blue-100 data-[hover=true]:!to-cyan-100 data-[selected=true]:!from-blue-100 data-[selected=true]:!to-cyan-100 dark:!from-blue-950/60 dark:!to-cyan-950/40',
  },
  bilibili: {
    accent: 'from-pink-500 to-rose-400 shadow-pink-500/25',
    surface: '!bg-gradient-to-br !from-pink-50 !via-white !to-rose-50/80 dark:!from-pink-950/35 dark:!via-content1 dark:!to-rose-950/20',
    glyph: 'B', badge: 'bg-pink-500/10 text-pink-600 dark:text-pink-300',
    option: '!bg-gradient-to-r !from-pink-50 !to-rose-50 data-[hover=true]:!from-pink-100 data-[hover=true]:!to-rose-100 data-[selected=true]:!from-pink-100 data-[selected=true]:!to-rose-100 dark:!from-pink-950/60 dark:!to-rose-950/40',
  },
  netease: {
    accent: 'from-red-600 to-orange-500 shadow-red-500/25',
    surface: '!bg-gradient-to-br !from-red-50 !via-white !to-orange-50/80 dark:!from-red-950/35 dark:!via-content1 dark:!to-orange-950/20',
    glyph: '音', badge: 'bg-red-500/10 text-red-600 dark:text-red-300',
    option: '!bg-gradient-to-r !from-red-50 !to-orange-50 data-[hover=true]:!from-red-100 data-[hover=true]:!to-orange-100 data-[selected=true]:!from-red-100 data-[selected=true]:!to-orange-100 dark:!from-red-950/60 dark:!to-orange-950/40',
  },
  iqiyi: {
    accent: 'from-lime-500 to-emerald-500 shadow-lime-500/25',
    surface: '!bg-gradient-to-br !from-lime-50 !via-white !to-emerald-50/80 dark:!from-lime-950/35 dark:!via-content1 dark:!to-emerald-950/20',
    glyph: '奇', badge: 'bg-lime-500/10 text-lime-700 dark:text-lime-300',
    option: '!bg-gradient-to-r !from-lime-50 !to-emerald-50 data-[hover=true]:!from-lime-100 data-[hover=true]:!to-emerald-100 data-[selected=true]:!from-lime-100 data-[selected=true]:!to-emerald-100 dark:!from-lime-950/60 dark:!to-emerald-950/40',
  },
  sport: {
    accent: 'from-orange-500 to-amber-400 shadow-orange-500/25',
    surface: '!bg-gradient-to-br !from-orange-50 !via-white !to-amber-50/80 dark:!from-orange-950/35 dark:!via-content1 dark:!to-amber-950/20',
    glyph: '动', badge: 'bg-orange-500/10 text-orange-700 dark:text-orange-300',
    option: '!bg-gradient-to-r !from-orange-50 !to-amber-50 data-[hover=true]:!from-orange-100 data-[hover=true]:!to-amber-100 data-[selected=true]:!from-orange-100 data-[selected=true]:!to-amber-100 dark:!from-orange-950/60 dark:!to-amber-950/40',
  },
  cloud189: {
    accent: 'from-sky-500 to-blue-600 shadow-sky-500/25',
    surface: '!bg-gradient-to-br !from-sky-50 !via-white !to-blue-50/80 dark:!from-sky-950/35 dark:!via-content1 dark:!to-blue-950/20',
    glyph: '翼', badge: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    option: '!bg-gradient-to-r !from-sky-50 !to-blue-50 data-[hover=true]:!from-sky-100 data-[hover=true]:!to-blue-100 data-[selected=true]:!from-sky-100 data-[selected=true]:!to-blue-100 dark:!from-sky-950/60 dark:!to-blue-950/40',
  },
  picacomic: {
    accent: 'from-fuchsia-500 to-violet-500 shadow-fuchsia-500/25',
    surface: '!bg-gradient-to-br !from-fuchsia-50 !via-white !to-violet-50/80 dark:!from-fuchsia-950/35 dark:!via-content1 dark:!to-violet-950/20',
    glyph: '咔', badge: 'bg-fuchsia-500/10 text-fuchsia-700 dark:text-fuchsia-300',
    option: '!bg-gradient-to-r !from-fuchsia-50 !to-violet-50 data-[hover=true]:!from-fuchsia-100 data-[hover=true]:!to-violet-100 data-[selected=true]:!from-fuchsia-100 data-[selected=true]:!to-violet-100 dark:!from-fuchsia-950/60 dark:!to-violet-950/40',
  },
};

const fallbackStyle = {
  accent: 'from-indigo-500 to-violet-500 shadow-indigo-500/25',
  surface: '!bg-gradient-to-br !from-indigo-50 !via-white !to-violet-50/80 dark:!from-indigo-950/35 dark:!via-content1 dark:!to-violet-950/20',
  glyph: '账', badge: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-300',
  option: '!bg-gradient-to-r !from-indigo-50 !to-violet-50 data-[hover=true]:!from-indigo-100 data-[hover=true]:!to-violet-100 dark:!from-indigo-950/60 dark:!to-violet-950/40',
};

const methodCardThemes: Record<string, { selected: string; idle: string }> = {
  tieba: {
    selected: 'border-blue-400 bg-blue-50/90 shadow-sm shadow-blue-500/10 dark:border-blue-500/50 dark:bg-blue-950/35',
    idle: 'border-divider bg-content1/50 hover:border-blue-400/60 hover:bg-blue-50/55 dark:hover:bg-blue-950/20',
  },
  bilibili: {
    selected: 'border-pink-400 bg-pink-50/90 shadow-sm shadow-pink-500/10 dark:border-pink-500/50 dark:bg-pink-950/35',
    idle: 'border-divider bg-content1/50 hover:border-pink-400/60 hover:bg-pink-50/55 dark:hover:bg-pink-950/20',
  },
  netease: {
    selected: 'border-red-400 bg-red-50/90 shadow-sm shadow-red-500/10 dark:border-red-500/50 dark:bg-red-950/35',
    idle: 'border-divider bg-content1/50 hover:border-red-400/60 hover:bg-red-50/55 dark:hover:bg-red-950/20',
  },
  iqiyi: {
    selected: 'border-lime-500 bg-lime-50/90 shadow-sm shadow-lime-500/10 dark:border-lime-500/50 dark:bg-lime-950/35',
    idle: 'border-divider bg-content1/50 hover:border-lime-500/60 hover:bg-lime-50/55 dark:hover:bg-lime-950/20',
  },
  sport: {
    selected: 'border-orange-400 bg-orange-50/90 shadow-sm shadow-orange-500/10 dark:border-orange-500/50 dark:bg-orange-950/35',
    idle: 'border-divider bg-content1/50 hover:border-orange-400/60 hover:bg-orange-50/55 dark:hover:bg-orange-950/20',
  },
  cloud189: {
    selected: 'border-sky-400 bg-sky-50/90 shadow-sm shadow-sky-500/10 dark:border-sky-500/50 dark:bg-sky-950/35',
    idle: 'border-divider bg-content1/50 hover:border-sky-400/60 hover:bg-sky-50/55 dark:hover:bg-sky-950/20',
  },
  picacomic: {
    selected: 'border-fuchsia-400 bg-fuchsia-50/90 shadow-sm shadow-fuchsia-500/10 dark:border-fuchsia-500/50 dark:bg-fuchsia-950/35',
    idle: 'border-divider bg-content1/50 hover:border-fuchsia-400/60 hover:bg-fuchsia-50/55 dark:hover:bg-fuchsia-950/20',
  },
};

const fallbackMethodTheme = {
  selected: 'border-indigo-400 bg-indigo-50/90 shadow-sm shadow-indigo-500/10 dark:border-indigo-500/50 dark:bg-indigo-950/35',
  idle: 'border-divider bg-content1/50 hover:border-indigo-400/60 hover:bg-indigo-50/55 dark:hover:bg-indigo-950/20',
};

const methodLabels: Record<PlatformConnectionMethod, { label: string; tip: string; icon: React.ReactNode }> = {
  sms: { label: '短信验证码', tip: '手机号快捷登录', icon: <LuMessageSquare /> },
  qr: { label: '平台扫码', tip: '使用对应平台客户端', icon: <LuQrCode /> },
  password: { label: '账号密码', tip: '由服务器安全换取登录状态', icon: <LuKeyRound /> },
  qq_qr: { label: 'QQ 扫码', tip: '使用关联 QQ 登录', icon: <LuUserRound /> },
  wechat_qr: { label: '微信扫码', tip: '使用关联微信登录', icon: <LuSmartphone /> },
  bduss: { label: '导入 BDUSS', tip: '手动填写登录凭据', icon: <LuCookie /> },
  cookie: { label: 'Cookie 导入', tip: '适合高级用户', icon: <LuCookie /> },
};

const qrPlatformMeta: Record<string, { name: string; client: string }> = {
  tieba: { name: '百度', client: '百度客户端' },
  bilibili: { name: '哔哩哔哩', client: '哔哩哔哩客户端' },
  iqiyi: { name: '爱奇艺', client: '爱奇艺客户端' },
};

const passwordFieldMeta: Record<string, { username: string; password: string; description: string }> = {
  sport: {
    username: 'Zepp Life 手机号或邮箱',
    password: 'Zepp Life 密码',
    description: '仅支持 Zepp Life 账号，不支持小米账号；凭据会加密保存。',
  },
  cloud189: {
    username: '天翼云盘手机号或邮箱',
    password: '天翼云盘密码',
    description: '支持天翼云盘手机号或邮箱登录；如触发设备锁，需要先到网页端完成验证。',
  },
  picacomic: {
    username: '哔咔登录邮箱',
    password: '哔咔密码',
    description: '使用哔咔漫画账号登录；密码与登录令牌均由服务器加密保存。',
  },
};

export default function AccountsPage () {
  const [accounts, setAccounts] = useState<PluginAccount[]>([]);
  const [platforms, setPlatforms] = useState<Platform[]>([]);
  const [details, setDetails] = useState<Record<string, PlatformDetail>>({});
  const [loading, setLoading] = useState(true);
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<PluginAccount | null>(null);
  const [renewing, setRenewing] = useState<PluginAccount | null>(null);
  const [watchingTask, setWatchingTask] = useState<SignTask | null>(null);
  const [form, setForm] = useState<AccountForm>(emptyForm);
  const [submitting, setSubmitting] = useState(false);
  const [qrFlow, setQrFlow] = useState<PlatformQrFlow | null>(null);
  const [authResult, setAuthResult] = useState<PlatformAuthResult | null>(null);
  const [platformFilter, setPlatformFilter] = useState('all');
  const [searchParams, setSearchParams] = useSearchParams();

  const load = async () => {
    const [accountRows, platformRows] = await Promise.all([
      apiRequest<PluginAccount[]>({ url: '/plugin-accounts' }),
      apiRequest<Platform[]>({ url: '/platforms' }),
    ]);
    setAccounts(accountRows);
    setPlatforms(platformRows);
    const platformDetails = await Promise.all(platformRows.map((platform) => apiRequest<PlatformDetail>({ url: `/platforms/${platform.code}` })));
    setDetails(Object.fromEntries(platformDetails.map((detail) => [detail.code, detail])));
  };

  useEffect(() => { load().catch((error) => toast.error(errorMessage(error))).finally(() => setLoading(false)); }, []);

  // 支持通知邮件中的“一键更新凭据”深链：/TFYT/accounts?renew={accountId}
  const renewHandled = useMemo(() => ({ current: false }), []);
  useEffect(() => {
    const renewId = Number(searchParams.get('renew') || 0);
    if (!renewId || loading || renewHandled.current || !Object.keys(details).length) return;
    renewHandled.current = true;
    const target = accounts.find((row) => row.id === renewId);
    const next = new URLSearchParams(searchParams);
    next.delete('renew');
    setSearchParams(next, { replace: true });
    if (target) openRenew(target);
    else toast.error('没有找到需要更新凭据的账号，可能已被删除');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loading, accounts, details, searchParams]);

  const selectedPlatform = details[form.platform_code];
  const selectedPasswordMeta = passwordFieldMeta[form.platform_code] || {
    username: '平台账号',
    password: '平台密码',
    description: '密码只在加密认证会话中短暂使用，成功或过期后立即销毁',
  };
  const selectedQrMeta = qrPlatformMeta[form.platform_code] || {
    name: selectedPlatform?.name || '平台',
    client: `${selectedPlatform?.name || '对应平台'}客户端`,
  };
  const selectedPlatformStyle = platformStyles[form.platform_code] || fallbackStyle;
  const selectedMethodTheme = methodCardThemes[form.platform_code] || fallbackMethodTheme;
  const grouped = useMemo(() => {
    const known = platforms.map((platform) => ({
      platform,
      accounts: accounts.filter((account) => account.plugin_code === platform.code),
    })).filter((group) => group.accounts.length > 0);
    const knownCodes = new Set(platforms.map((platform) => platform.code));
    const legacyCodes = [...new Set(accounts.map((account) => account.plugin_code).filter((code) => !knownCodes.has(code)))];
    return [...known, ...legacyCodes.map((code) => ({
      platform: { code, name: pluginNames[code] || code, description: '保留的平台账号', connection_methods: [] as PlatformConnectionMethod[], actions: [] },
      accounts: accounts.filter((account) => account.plugin_code === code),
    }))];
  }, [accounts, platforms]);
  const visibleGroups = useMemo(
    () => platformFilter === 'all' ? grouped : grouped.filter(({ platform }) => platform.code === platformFilter),
    [grouped, platformFilter],
  );

  useEffect(() => {
    if (platformFilter !== 'all' && !grouped.some(({ platform }) => platform.code === platformFilter)) {
      setPlatformFilter('all');
    }
  }, [grouped, platformFilter]);

  useEffect(() => {
    if (!modalOpen || !qrFlow?.flow_no || ['expired', 'succeeded'].includes(qrFlow.status)) return;
    let stopped = false;
    const timer = window.setInterval(async () => {
      try {
        const result = await apiRequest<PlatformQrFlow>({ url: `/platform-auth/${form.platform_code}/qr/${qrFlow.flow_no}` });
        if (stopped) return;
        setQrFlow((current) => ({ ...current, ...result }));
        if (result.status === 'succeeded' && result.account) {
          stopped = true;
          window.clearInterval(timer);
          if (renewing) {
            toast.success(`${result.account.display_name} 登录凭据已更新，自动签到已恢复`);
          } else {
            await applySchedule(result.account);
            toast.success(`${result.account.display_name} 已添加`);
          }
          setModalOpen(false);
          await load();
        }
      } catch (error) {
        if (!stopped) {
          stopped = true;
          window.clearInterval(timer);
          toast.error(errorMessage(error));
        }
      }
    }, 2000);
    return () => { stopped = true; window.clearInterval(timer); };
  }, [modalOpen, qrFlow?.flow_no, qrFlow?.status, form.platform_code]);

  const openCreate = () => {
    const first = platforms[0];
    const method = first?.connection_methods[0] || 'cookie';
    setEditing(null);
    setRenewing(null);
    setQrFlow(null);
    setAuthResult(null);
    setForm({ ...emptyForm, platform_code: first?.code || '', method, scheduled_action: first?.actions[0] || '' });
    setModalOpen(true);
  };

  const openRenew = (account: PluginAccount) => {
    const platform = details[account.plugin_code];
    const method = platform?.connection_methods[0] || 'cookie';
    setEditing(null);
    setRenewing(account);
    setQrFlow(null);
    setAuthResult(null);
    setForm({ ...emptyForm, platform_code: account.plugin_code, method, display_name: account.display_name });
    setModalOpen(true);
  };

  const openEdit = (account: PluginAccount) => {
    const platform = details[account.plugin_code];
    setEditing(account);
    setQrFlow(null);
    setAuthResult(null);
    setForm({
      ...emptyForm,
      platform_code: account.plugin_code,
      method: 'cookie',
      display_name: account.display_name,
      schedule_mode: account.settings.schedule_mode === 'fixed' ? 'fixed' : 'auto',
      schedule_time: String(account.settings.schedule_time || '06:30'),
      scheduled_action: String(account.settings.scheduled_action || platform?.actions[0] || ''),
      status: account.status,
      bili_watch_enabled: account.settings.watch_enabled !== false,
      bili_share_enabled: account.settings.share_enabled !== false,
      bili_coin_enabled: account.settings.coin_enabled === true,
      bili_allow_coin_spend: account.settings.allow_coin_spend === true,
      bili_coin_count: String(account.settings.coin_count || '1'),
      bili_live_sign_enabled: account.settings.live_sign_enabled !== false,
      bili_live_daily_bag_enabled: account.settings.live_daily_bag_enabled === true,
      bili_live_heartbeat_enabled: account.settings.live_heartbeat_enabled === true,
      bili_live_room_id: String(account.settings.live_room_id || ''),
      bili_capsule_enabled: account.settings.capsule_enabled === true,
      bili_allow_capsule_spend: account.settings.allow_capsule_spend === true,
      bili_capsule_count: String(account.settings.capsule_count || '1'),
      bili_comic_sign_enabled: account.settings.comic_sign_enabled === true,
      sport_step_mode: account.settings.step_mode === 'random' ? 'random' : 'fixed',
      sport_steps: String(account.settings.steps || '18000'),
      sport_min_steps: String(account.settings.min_steps || '18000'),
      sport_max_steps: String(account.settings.max_steps || '25000'),
      sport_progressive_by_time: account.settings.progressive_by_time === true,
    });
    setModalOpen(true);
  };

  const scheduleSettings = () => {
    const schedule = {
      schedule_enabled: true,
      schedule_mode: form.schedule_mode,
      schedule_time: form.schedule_mode === 'fixed' ? form.schedule_time : undefined,
      scheduled_action: form.scheduled_action || undefined,
    };
    if (form.platform_code === 'sport') {
      return {
        ...schedule,
        step_mode: form.sport_step_mode,
        steps: Math.max(1, Math.min(98800, Number(form.sport_steps) || 18000)),
        min_steps: Math.max(1, Math.min(98800, Number(form.sport_min_steps) || 18000)),
        max_steps: Math.max(1, Math.min(98800, Number(form.sport_max_steps) || 25000)),
        progressive_by_time: form.sport_progressive_by_time,
      };
    }
    if (form.platform_code !== 'bilibili') return schedule;
    return {
      ...schedule,
      watch_enabled: form.bili_watch_enabled,
      share_enabled: form.bili_share_enabled,
      coin_enabled: form.bili_coin_enabled,
      allow_coin_spend: form.bili_allow_coin_spend,
      coin_count: Math.max(1, Math.min(2, Number(form.bili_coin_count) || 1)),
      live_sign_enabled: form.bili_live_sign_enabled,
      live_daily_bag_enabled: form.bili_live_daily_bag_enabled,
      live_heartbeat_enabled: form.bili_live_heartbeat_enabled,
      live_room_id: form.bili_live_room_id.trim(),
      capsule_enabled: form.bili_capsule_enabled,
      allow_capsule_spend: form.bili_allow_capsule_spend,
      capsule_count: Math.max(1, Math.min(100, Number(form.bili_capsule_count) || 1)),
      comic_sign_enabled: form.bili_comic_sign_enabled,
    };
  };

  const applySchedule = async (account: PluginAccount) => {
    await apiRequest<PluginAccount>({ method: 'PATCH', url: `/plugin-accounts/${account.id}`, data: { settings: scheduleSettings() } });
  };

  const startQr = async () => {
    setSubmitting(true);
    try {
      const flow = await apiRequest<PlatformQrFlow>({ method: 'POST', url: `/platform-auth/${form.platform_code}/qr/start`, data: { method: form.method } });
      setQrFlow(flow);
    } catch (error) { toast.error(errorMessage(error)); } finally { setSubmitting(false); }
  };

  const sendSms = async () => {
    setSubmitting(true);
    try {
      const result = await apiRequest<PlatformAuthResult>({
        method: 'POST', url: `/platform-auth/${form.platform_code}/sms/send`,
        data: { phone: form.phone, flow_no: authResult?.flow_no, captcha: form.captcha },
      });
      setAuthResult(result);
      toast.success(result.message || '验证码已发送');
    } catch (error) { toast.error(errorMessage(error)); } finally { setSubmitting(false); }
  };

  const completeSms = async () => {
    if (!authResult?.flow_no) { toast.error('请先获取短信验证码'); return; }
    setSubmitting(true);
    try {
      const result = await apiRequest<PlatformAuthResult>({
        method: 'POST', url: `/platform-auth/${form.platform_code}/sms/complete`,
        data: { flow_no: authResult.flow_no, sms_code: form.sms_code },
      });
      if (!result.account) { setAuthResult(result); return; }
      if (renewing) {
        toast.success(`${result.account.display_name} 登录凭据已更新，自动签到已恢复`);
      } else {
        await applySchedule(result.account);
        toast.success(`${result.account.display_name} 已添加`);
      }
      setModalOpen(false);
      await load();
    } catch (error) { toast.error(errorMessage(error)); } finally { setSubmitting(false); }
  };

  const sendSecurityCode = async () => {
    if (!authResult?.flow_no) return;
    setSubmitting(true);
    try {
      const result = await apiRequest<PlatformAuthResult>({
        method: 'POST', url: `/platform-auth/${form.platform_code}/password`,
        data: { flow_no: authResult.flow_no, send_security_code: true },
      });
      setAuthResult(result);
      toast.success(result.message || '安全验证码已发送');
    } catch (error) { toast.error(errorMessage(error)); } finally { setSubmitting(false); }
  };

  const submit = async () => {
    if (!form.platform_code) return;
    setSubmitting(true);
    try {
      if (editing) {
        const credentials = Object.fromEntries(Object.entries(form.credentials).filter(([, value]) => value.trim() !== ''));
        await apiRequest<PluginAccount>({
          method: 'PATCH', url: `/plugin-accounts/${editing.id}`,
          data: { display_name: form.display_name, settings: scheduleSettings(), status: form.status, ...(Object.keys(credentials).length ? { credentials } : {}) },
        });
        toast.success('账号设置已更新');
      } else if (form.method === 'password') {
        const result = await apiRequest<PlatformAuthResult>({
          method: 'POST', url: `/platform-auth/${form.platform_code}/password`,
          data: {
            username: form.username, password: form.password, login_type: form.login_type,
            flow_no: authResult?.flow_no, captcha: form.captcha, security_code: form.security_code,
          },
        });
        if (result.status !== 'succeeded' || !result.account) { setAuthResult(result); return; }
        if (renewing) {
          toast.success(`${result.account.display_name} 登录凭据已更新，自动签到已恢复`);
        } else {
          await applySchedule(result.account);
          toast.success(`${result.account.display_name} 已添加`);
        }
      } else if (form.method === 'cookie' || form.method === 'bduss') {
        const credentials = Object.fromEntries(Object.entries(form.credentials).filter(([, value]) => value.trim() !== ''));
        const missing = (selectedPlatform?.credential_fields || []).some((field) => field.required && !credentials[field.key]);
        if (missing) { toast.error('请填写全部必填 Cookie 字段'); return; }
        if (renewing) {
          await apiRequest<PluginAccount>({ method: 'PATCH', url: `/plugin-accounts/${renewing.id}`, data: { credentials } });
          toast.success('登录凭据已更新，自动签到已恢复');
        } else {
          await apiRequest<PluginAccount>({ method: 'POST', url: '/plugin-accounts', data: { plugin_code: form.platform_code, credentials, settings: scheduleSettings() } });
          toast.success('平台账号已添加');
        }
      } else if (['qr', 'qq_qr', 'wechat_qr'].includes(form.method)) {
        await startQr();
        return;
      }
      setModalOpen(false);
      await load();
    } catch (error) { toast.error(errorMessage(error)); } finally { setSubmitting(false); }
  };

  const operate = async (account: PluginAccount, operation: 'toggle' | 'delete') => {
    if (operation === 'delete' && !(await confirmCard({ title: `删除“${account.display_name || pluginNames[account.plugin_code]}”？`, description: '删除后将停止自动签到，历史签到记录会保留。', confirmText: '删除账号' }))) return;
    if (operation === 'toggle' && (account.login_status === 'expired' || account.status === 'credential_expired' || ['PLUGIN_CREDENTIAL_EXPIRED', 'PLUGIN_CREDENTIAL_INVALID'].includes(account.last_error?.code || ''))) {
      toast.error('登录凭据已经失效，请先更新登录凭据');
      return;
    }
    setBusyKey(`${account.id}:${operation}`);
    try {
      if (operation === 'toggle') await apiRequest<PluginAccount>({ method: 'PATCH', url: `/plugin-accounts/${account.id}`, data: { status: account.status === 'active' ? 'disabled' : 'active' } });
      if (operation === 'delete') await apiRequest<null>({ method: 'DELETE', url: `/plugin-accounts/${account.id}` });
      toast.success(operation === 'delete' ? '账号已删除' : '操作成功');
      await load();
    } catch (error) { toast.error(errorMessage(error)); } finally { setBusyKey(null); }
  };

  const runAction = async (account: PluginAccount, action: string) => {
    setBusyKey(`${account.id}:${action}`);
    try {
      const task = await apiRequest<SignTask>({ method: 'POST', url: `/plugin-accounts/${account.id}/actions/${action}` });
      setWatchingTask(task);
    } catch (error) { toast.error(errorMessage(error)); } finally { setBusyKey(null); }
  };

  const accountCard = (account: PluginAccount, platform: Platform, index: number) => {
    const style = platformStyles[platform.code] || fallbackStyle;
    const expired = account.login_status === 'expired' || account.status === 'credential_expired' || ['PLUGIN_CREDENTIAL_EXPIRED', 'PLUGIN_CREDENTIAL_INVALID'].includes(account.last_error?.code || '');
    const cardSurface = expired
      ? '!border-danger-400/70 !bg-danger-50 shadow-danger-500/10 dark:!border-danger-400/35 dark:!bg-danger-50/10'
      : `border-white/70 ${style.surface}`;
    return (
      <motion.div key={account.id} initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: Math.min(index * 0.04, 0.2) }} whileHover={{ y: -3 }}>
        <Card className={`tf-glass-card relative h-full overflow-hidden rounded-[26px] border ${cardSurface}`}>
          <div className={`h-1.5 bg-gradient-to-r ${expired ? 'from-danger-500 to-rose-400' : style.accent}`} />
          {platformLogoByCode[platform.code]
            ? <img src={platformLogoByCode[platform.code]} alt='' aria-hidden className='pointer-events-none absolute -bottom-8 -right-6 h-36 w-36 select-none rounded-[32px] object-cover opacity-[0.055] grayscale' />
            : <span className={`pointer-events-none absolute -bottom-8 -right-3 select-none text-[112px] font-black leading-none opacity-[0.045] ${expired ? 'text-danger-700' : ''}`}>{style.glyph}</span>}
          <CardHeader className='relative flex items-start justify-between px-5 pt-5'>
            <div className='flex min-w-0 items-center gap-3'><PlatformBrandIcon code={platform.code} name={platform.name} avatarUrl={account.avatar_url} fallback={style.glyph} className={`h-12 w-12 rounded-2xl shadow-lg ${expired ? 'ring-2 ring-danger-400 ring-offset-2 ring-offset-background' : ''}`} fallbackClassName={`bg-gradient-to-br text-lg font-black text-white ${expired ? 'from-danger-500 to-rose-400' : style.accent}`} /><div className='min-w-0'><div className={`mb-1 inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${expired ? 'bg-danger-500/15 text-danger-700 dark:text-danger-300' : style.badge}`}>{platform.name}</div><h3 className='truncate font-semibold'>{account.display_name || '未命名账号'}</h3><p className='truncate text-xs text-default-400'>{account.external_user_id || `账号 #${account.id}`}</p></div></div>
            <StatusChip status={expired ? 'expired' : 'normal'} />
          </CardHeader>
          <CardBody className='relative gap-4 px-5'>
            {expired && <div className='flex items-start gap-2 rounded-2xl border border-danger-400/30 bg-danger-500/10 px-3 py-2.5 text-xs text-danger-800 dark:text-danger-200'><LuTriangleAlert className='mt-0.5 shrink-0 text-base' /><span>{account.last_error?.message || '登录状态已经失效，请更新登录凭据后重新启用。'}</span></div>}
            <div className='grid grid-cols-2 gap-3 rounded-2xl border border-white/60 bg-white/45 p-3.5 text-xs shadow-inner dark:border-white/[0.04] dark:bg-white/[0.025]'>
              <div><p className='text-default-400'>上次执行时间</p><p className='mt-1'>{formatDateTime(account.last_run_at)}</p></div>
              <div><p className='text-default-400'>下次执行</p><p className='mt-1'>{formatDateTime(account.next_run_at)}</p></div>
              <div><p className='text-default-400'>执行计划</p><p className='mt-1'>{account.settings.schedule_mode === 'fixed' ? `定时计划 · ${String(account.settings.schedule_time || '—')}` : '自动计划'}</p></div>
              <div><p className='text-default-400'>登录状态</p><p className={`mt-1 ${expired ? 'font-medium text-danger-700 dark:text-danger-300' : 'font-medium text-success-700 dark:text-success-300'}`}>{expired ? '失效' : '正常'}</p></div>
            </div>
            <div className='flex flex-wrap gap-2'>{platform.actions.map((action) => <Button key={action} size='sm' variant='flat' color='primary' startContent={<LuPlay />} isDisabled={!['active', 'pending_verification'].includes(account.status) || Boolean(busyKey && busyKey.startsWith(`${account.id}:`))} isLoading={busyKey === `${account.id}:${action}`} onPress={() => void runAction(account, action)}>{actionNames[action] || action}</Button>)}</div>
          </CardBody>
          <CardFooter className='relative flex flex-wrap gap-2 px-5 pb-5 pt-0'>
            <Button size='sm' variant='light' startContent={<LuPencil />} onPress={() => openEdit(account)}>设置</Button>
            {expired
              ? <Button size='sm' color='danger' variant='flat' className='font-medium' startContent={<LuRefreshCw />} onPress={() => openRenew(account)}>更新凭据</Button>
              : <Button size='sm' variant='light' startContent={<LuRefreshCw />} isLoading={busyKey === `${account.id}:toggle`} isDisabled={Boolean(busyKey && busyKey.startsWith(`${account.id}:`))} onPress={() => void operate(account, 'toggle')}>{account.status === 'active' ? '停用' : '启用'}</Button>}
            <Button size='sm' variant='light' color='danger' isIconOnly aria-label='删除账号' isLoading={busyKey === `${account.id}:delete`} isDisabled={Boolean(busyKey && busyKey.startsWith(`${account.id}:`))} onPress={() => void operate(account, 'delete')}><LuTrash2 /></Button>
          </CardFooter>
        </Card>
      </motion.div>
    );
  };

  if (loading) return <div className='flex min-h-72 items-center justify-center'><Spinner label='正在加载平台账号' /></div>;

  return (
    <div className='flex flex-col gap-6'>
      <div className='flex flex-col justify-between gap-3 sm:flex-row sm:items-end'><div><h2 className='text-xl font-semibold'>我的平台账号</h2><p className='mt-1 text-sm text-default-500'>按平台分类管理，登录状态均加密存储（就算黑客偷了都没办法的那种）</p></div><Button color='primary' className='tf-soft-button' startContent={<LuCirclePlus />} onPress={openCreate}>添加账号</Button></div>
      {!accounts.length && <Card className='tf-glass-card rounded-[28px] border-dashed'><CardBody className='items-center gap-3 py-16 text-center'><div className='flex h-16 w-16 items-center justify-center rounded-3xl bg-primary/10'><LuCirclePlus className='text-3xl text-primary' /></div><h3 className='font-medium'>还没有平台账号</h3><p className='text-sm text-default-500'>选择平台，通过扫码或账号密码即可添加</p><Button color='primary' variant='flat' onPress={openCreate}>立即添加</Button></CardBody></Card>}
      {!!accounts.length && <div className='flex gap-2 overflow-x-auto rounded-2xl border border-divider bg-content1/55 p-2 shadow-sm'><button type='button' onClick={() => setPlatformFilter('all')} className={`shrink-0 rounded-xl px-4 py-2 text-sm font-medium transition ${platformFilter === 'all' ? 'bg-primary text-white shadow-md shadow-primary/20' : 'text-default-500 hover:bg-default-100'}`}>全部 <span className='ml-1 opacity-75'>{accounts.length}</span></button>{grouped.map(({ platform, accounts: rows }) => { const style = platformStyles[platform.code] || fallbackStyle; return <button key={platform.code} type='button' onClick={() => setPlatformFilter(platform.code)} className={`flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium transition ${platformFilter === platform.code ? 'bg-content1 text-foreground shadow-md ring-1 ring-divider' : 'text-default-500 hover:bg-default-100'}`}><PlatformBrandIcon code={platform.code} name={platform.name} fallback={style.glyph} className='h-6 w-6 rounded-lg' fallbackClassName={`text-xs font-black ${style.badge}`} />{platform.name}<span className='text-xs opacity-60'>{rows.length}</span></button>; })}</div>}
      {visibleGroups.map(({ platform, accounts: rows }) => <section key={platform.code} className='flex flex-col gap-3'><div className='flex items-center gap-3'><h3 className='font-semibold'>{platform.name}</h3><span className='rounded-full bg-default-100 px-2.5 py-1 text-xs text-default-500'>{rows.length} 个账号</span><div className='h-px flex-1 bg-divider' /></div><div className='grid gap-4 lg:grid-cols-2 2xl:grid-cols-3'>{rows.map((account, index) => accountCard(account, platform, index))}</div></section>)}

      <Modal isOpen={modalOpen} onOpenChange={(open) => { setModalOpen(open); if (!open) { setQrFlow(null); setAuthResult(null); setRenewing(null); } }} size='2xl' scrollBehavior='inside'>
        <ModalContent className='tf-glass rounded-[26px]'>
          <ModalHeader>{editing ? '账号设置' : renewing ? `更新登录凭据 · ${renewing.display_name || pluginNames[renewing.plugin_code] || renewing.plugin_code}` : '添加平台账号'}</ModalHeader>
          <ModalBody className='gap-4'>
            {renewing && <div className='flex items-start gap-2 rounded-2xl border border-danger-400/30 bg-danger-500/10 px-4 py-3 text-xs leading-5 text-danger-800 dark:text-danger-200'><LuTriangleAlert className='mt-0.5 shrink-0 text-base' /><span>该账号登录状态已失效。选择任意方式重新登录同一个平台账号，凭据会自动替换并恢复原有签到计划，无需重新配置。</span></div>}
            <Select label='选择平台' isDisabled={Boolean(editing) || Boolean(renewing)} selectedKeys={form.platform_code ? [form.platform_code] : []} onSelectionChange={(keys) => { const code = String(Array.from(keys)[0] || ''); const platform = details[code]; setQrFlow(null); setAuthResult(null); setForm((current) => ({ ...current, platform_code: code, method: platform?.connection_methods[0] || 'cookie', credentials: {}, scheduled_action: platform?.actions[0] || '' })); }}>{platforms.map((platform) => { const style = platformStyles[platform.code] || fallbackStyle; return <SelectItem key={platform.code} description={platform.description} className={`mb-1 last:mb-0 ${style.option}`} startContent={<PlatformBrandIcon code={platform.code} name={platform.name} fallback={style.glyph} className='h-8 w-8 rounded-lg' fallbackClassName={`text-xs font-black ${style.badge}`} />}>{platform.name}</SelectItem>; })}</Select>

            {!editing && selectedPlatform && <div><p className='mb-2 text-sm font-medium'>添加方式</p><div className='grid gap-2 sm:grid-cols-3'>{selectedPlatform.connection_methods.map((method) => { const baseMeta = methodLabels[method]; const meta = method === 'qr' ? { ...baseMeta, label: `${selectedQrMeta.name}扫码`, tip: `${selectedQrMeta.client}扫码登录` } : baseMeta; const selected = form.method === method; return <button key={method} type='button' onClick={() => { setQrFlow(null); setAuthResult(null); setForm((current) => ({ ...current, method, captcha: '', security_code: '', sms_code: '' })); }} className={`rounded-2xl border p-3 text-left transition ${selected ? selectedMethodTheme.selected : selectedMethodTheme.idle}`}><span className={`mb-2 flex h-9 w-9 items-center justify-center rounded-xl transition ${selected ? `bg-gradient-to-br text-white ${selectedPlatformStyle.accent}` : selectedPlatformStyle.badge}`}>{meta.icon}</span><span className='block text-sm font-medium'>{meta.label}</span><span className='text-xs text-default-400'>{meta.tip}</span></button>; })}</div></div>}

            {!editing && ['qr', 'qq_qr', 'wechat_qr'].includes(form.method) && <div className='flex min-h-72 flex-col items-center justify-center gap-4 rounded-3xl border border-divider bg-content1/45 p-6 text-center'>{(qrFlow?.qr_url || qrFlow?.qr_image) ? <>{qrFlow.qr_image ? <div className='rounded-2xl bg-white p-4 shadow-lg'><img src={qrFlow.qr_image} alt={`${selectedQrMeta.name}登录二维码`} className='h-[190px] w-[190px] object-contain' /></div> : <div className='rounded-2xl bg-white p-4 shadow-lg'><QRCodeSVG value={qrFlow.qr_url || ''} size={190} level='M' title={`${selectedQrMeta.name}登录二维码`} /></div>}<div><p className='font-medium'>{qrFlow.status === 'scanned' ? `已扫码，请在${selectedQrMeta.name}客户端确认` : `请使用${form.method === 'qq_qr' ? 'QQ' : form.method === 'wechat_qr' ? '微信' : selectedQrMeta.client}扫码`}</p><p className='mt-1 text-xs text-default-400'>{qrFlow.message || '确认后账号将自动添加，无需复制 Cookie'}</p></div>{qrFlow.status === 'expired' && <Button color='primary' variant='flat' onPress={() => void startQr()}>重新获取二维码</Button>}</> : <><div className='flex h-16 w-16 items-center justify-center rounded-3xl bg-primary/10 text-3xl text-primary'><LuSmartphone /></div><div><p className='font-medium'>使用{form.method === 'qq_qr' ? 'QQ' : form.method === 'wechat_qr' ? '微信' : selectedQrMeta.client}扫码登录</p><p className='mt-1 text-sm text-default-400'>登录完成后，本页会自动识别并安全保存账号</p></div><Button color='primary' isLoading={submitting} startContent={<LuQrCode />} onPress={() => void startQr()}>生成{form.method === 'qr' ? selectedQrMeta.name : form.method === 'qq_qr' ? 'QQ' : '微信'}登录二维码</Button></>}</div>}

            {!editing && form.method === 'password' && <div className='grid gap-3 rounded-2xl border border-divider bg-content1/45 p-4 sm:grid-cols-2'>{form.platform_code === 'netease' && <Select label='账号类型' selectedKeys={[form.login_type]} onSelectionChange={(keys) => setForm((current) => ({ ...current, login_type: String(Array.from(keys)[0] || 'auto') }))}><SelectItem key='auto'>自动识别</SelectItem><SelectItem key='phone'>手机号</SelectItem><SelectItem key='email'>邮箱</SelectItem></Select>}<Input className={form.platform_code !== 'netease' ? 'sm:col-span-2' : ''} label={selectedPasswordMeta.username} autoComplete='username' isDisabled={Boolean(authResult?.flow_no)} value={form.username} onValueChange={(value) => setForm((current) => ({ ...current, username: value }))} /><Input className='sm:col-span-2' label={selectedPasswordMeta.password} type='password' autoComplete='current-password' isDisabled={Boolean(authResult?.flow_no)} description={selectedPasswordMeta.description} value={form.password} onValueChange={(value) => setForm((current) => ({ ...current, password: value }))} />{authResult?.status === 'captcha_required' && <><div className='flex items-center justify-center rounded-xl bg-white p-2'><img src={authResult.captcha_image} alt='百度验证码' className='max-h-16' /></div><Input label='图片验证码' value={form.captcha} onValueChange={(value) => setForm((current) => ({ ...current, captcha: value }))} /></>}{authResult?.status === 'verification_required' && <><Input label='安全验证码' description={`发送至：${authResult.verification_target || '绑定设备'}`} value={form.security_code} onValueChange={(value) => setForm((current) => ({ ...current, security_code: value }))} /><Button variant='flat' color='primary' isLoading={submitting} onPress={() => void sendSecurityCode()}>发送安全验证码</Button></>} {authResult?.message && <p className='sm:col-span-2 text-xs text-warning'>{authResult.message}</p>}</div>}

            {!editing && form.method === 'sms' && <div className='grid gap-3 rounded-2xl border border-divider bg-content1/45 p-4 sm:grid-cols-2'><Input className='sm:col-span-2' label='百度绑定手机号' value={form.phone} isDisabled={Boolean(authResult?.flow_no)} onValueChange={(value) => setForm((current) => ({ ...current, phone: value }))} />{authResult?.status === 'captcha_required' && <><div className='flex items-center justify-center rounded-xl bg-white p-2'><img src={authResult.captcha_image} alt='百度验证码' className='max-h-16' /></div><Input label='图片验证码' value={form.captcha} onValueChange={(value) => setForm((current) => ({ ...current, captcha: value }))} /></>}{authResult?.status === 'code_sent' && <Input className='sm:col-span-2' label='短信验证码' value={form.sms_code} onValueChange={(value) => setForm((current) => ({ ...current, sms_code: value }))} />}{authResult?.message && <p className='sm:col-span-2 text-xs text-default-500'>{authResult.message}</p>}<Button className='sm:col-span-2' variant='flat' color='primary' isLoading={submitting} onPress={() => void sendSms()}>{authResult?.status === 'captcha_required' ? '验证并发送短信' : authResult?.status === 'code_sent' ? '重新发送短信' : '发送短信验证码'}</Button></div>}

            {(editing || form.method === 'cookie' || form.method === 'bduss') && <div className='rounded-2xl border border-divider bg-content1/45 p-4'><div className='mb-3'><h4 className='text-sm font-medium'>{editing ? '更新登录状态（可选）' : form.method === 'bduss' ? '手动导入 BDUSS' : '导入 Cookie'}</h4><p className='text-xs text-default-400'>{editing ? '留空表示继续使用现有登录状态' : '各字段会在提交后立即加密，页面不再回显'}</p></div><div className='grid gap-3 sm:grid-cols-2'>{(selectedPlatform?.credential_fields || []).map((field) => <Input key={field.key} label={field.label} isRequired={!editing && field.required} value={form.credentials[field.key] || ''} onValueChange={(value) => setForm((current) => ({ ...current, credentials: { ...current.credentials, [field.key]: value } }))} />)}</div></div>}

            {editing && <Input label='显示名称' value={form.display_name} onValueChange={(value) => setForm((current) => ({ ...current, display_name: value }))} />}
            {!renewing && form.platform_code === 'sport' && <div className='rounded-2xl border border-orange-200/70 bg-gradient-to-br from-orange-50/80 to-content1/60 p-4 dark:border-orange-500/20 dark:from-orange-950/20'>
              <div className='mb-4'><h4 className='text-sm font-medium'>小米运动步数设置</h4><p className='mt-1 text-xs text-default-500'>任务会将步数提交到 Zepp Life。第三方平台是否同步由账号绑定状态决定。</p></div>
              <Select label='步数生成方式' selectedKeys={[form.sport_step_mode]} onSelectionChange={(keys) => setForm((current) => ({ ...current, sport_step_mode: String(Array.from(keys)[0] || 'fixed') as 'fixed' | 'random' }))}>
                <SelectItem key='fixed'>固定步数</SelectItem>
                <SelectItem key='random'>随机范围</SelectItem>
              </Select>
              {form.sport_step_mode === 'fixed'
                ? <Input className='mt-3' label='目标步数' type='number' min={1} max={98800} description='允许范围：1～98800' value={form.sport_steps} onValueChange={(value) => setForm((current) => ({ ...current, sport_steps: value }))} />
                : <div className='mt-3 grid gap-3 sm:grid-cols-2'><Input label='最小步数' type='number' min={1} max={98800} value={form.sport_min_steps} onValueChange={(value) => setForm((current) => ({ ...current, sport_min_steps: value }))} /><Input label='最大步数' type='number' min={1} max={98800} value={form.sport_max_steps} onValueChange={(value) => setForm((current) => ({ ...current, sport_max_steps: value }))} /><Switch className='sm:col-span-2' size='sm' isSelected={form.sport_progressive_by_time} onValueChange={(value) => setForm((current) => ({ ...current, sport_progressive_by_time: value }))}>按当天时间逐步增加范围（22:00 达到完整范围）</Switch></div>}
              <div className='mt-4 rounded-xl border border-warning-300/50 bg-warning-50/70 px-3 py-2 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-200'>请控制合理步数。Zepp 对同一服务器 IP 的频繁登录可能限流，系统会复用加密保存的令牌并对重试保持同一天相同步数。</div>
            </div>}
            {!renewing && form.platform_code === 'bilibili' && <div className='rounded-2xl border border-pink-200/70 bg-gradient-to-br from-pink-50/80 to-content1/60 p-4 dark:border-pink-500/20 dark:from-pink-950/20'>
              <div className='mb-4'><h4 className='text-sm font-medium'>B 站任务设置</h4><p className='mt-1 text-xs text-default-500'>这些开关控制“每日任务”的组成；单独选择某个动作时只执行该动作。</p></div>
              <div className='grid gap-3 sm:grid-cols-2'>
                <Switch size='sm' isSelected={form.bili_watch_enabled} onValueChange={(value) => setForm((current) => ({ ...current, bili_watch_enabled: value }))}>观看视频</Switch>
                <Switch size='sm' isSelected={form.bili_share_enabled} onValueChange={(value) => setForm((current) => ({ ...current, bili_share_enabled: value }))}>分享视频</Switch>
                <Switch size='sm' isSelected={form.bili_live_sign_enabled} onValueChange={(value) => setForm((current) => ({ ...current, bili_live_sign_enabled: value }))}>直播签到</Switch>
                <Switch size='sm' isSelected={form.bili_live_daily_bag_enabled} onValueChange={(value) => setForm((current) => ({ ...current, bili_live_daily_bag_enabled: value }))}>领取直播每日礼包</Switch>
                <Switch size='sm' isSelected={form.bili_comic_sign_enabled} onValueChange={(value) => setForm((current) => ({ ...current, bili_comic_sign_enabled: value }))}>漫画签到</Switch>
                <Switch size='sm' isSelected={form.bili_live_heartbeat_enabled} onValueChange={(value) => setForm((current) => ({ ...current, bili_live_heartbeat_enabled: value }))}>直播心跳</Switch>
                <Input className='sm:col-span-2' label='直播间 ID' description='开启直播心跳后必填；每次任务提交一次有限心跳，不会常驻挂机。' value={form.bili_live_room_id} onValueChange={(value) => setForm((current) => ({ ...current, bili_live_room_id: value.replace(/\D/g, '') }))} />
              </div>
              <div className='my-4 h-px bg-divider' />
              <div className='mb-3 rounded-xl border border-warning-300/50 bg-warning-50/70 px-3 py-2 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-200'>投币和开启扭蛋会消耗账号资产，必须同时开启任务和消耗授权；默认始终关闭。</div>
              <div className='grid gap-3 sm:grid-cols-2'>
                <Switch size='sm' isSelected={form.bili_coin_enabled} onValueChange={(value) => setForm((current) => ({ ...current, bili_coin_enabled: value }))}>每日任务包含投币</Switch>
                <Switch size='sm' color='warning' isSelected={form.bili_allow_coin_spend} onValueChange={(value) => setForm((current) => ({ ...current, bili_allow_coin_spend: value }))}>允许实际消耗硬币</Switch>
                <Input label='单次投币数量' type='number' min={1} max={2} isDisabled={!form.bili_coin_enabled} value={form.bili_coin_count} onValueChange={(value) => setForm((current) => ({ ...current, bili_coin_count: value }))} />
                <div />
                <Switch size='sm' isSelected={form.bili_capsule_enabled} onValueChange={(value) => setForm((current) => ({ ...current, bili_capsule_enabled: value }))}>每日任务包含开启扭蛋</Switch>
                <Switch size='sm' color='warning' isSelected={form.bili_allow_capsule_spend} onValueChange={(value) => setForm((current) => ({ ...current, bili_allow_capsule_spend: value }))}>允许实际使用扭蛋币</Switch>
                <Input label='单次使用扭蛋币' type='number' min={1} max={100} isDisabled={!form.bili_capsule_enabled} value={form.bili_capsule_count} onValueChange={(value) => setForm((current) => ({ ...current, bili_capsule_count: value }))} />
              </div>
            </div>}
            {!renewing && <div className='rounded-2xl border border-divider bg-content1/45 p-4'><div className='mb-3'><h4 className='text-sm font-medium'>签到计划</h4><p className='text-xs text-default-400'>自动计划会为账号分配稳定的错峰时间；定时计划按指定时间执行</p></div><div className='grid gap-3 sm:grid-cols-2'><Select label='计划类型' selectedKeys={[form.schedule_mode]} onSelectionChange={(keys) => setForm((current) => ({ ...current, schedule_mode: String(Array.from(keys)[0] || 'auto') as 'auto' | 'fixed' }))}><SelectItem key='auto'>自动计划</SelectItem><SelectItem key='fixed'>定时计划</SelectItem></Select><Input label='执行时间' type='time' startContent={<LuClock3 />} isDisabled={form.schedule_mode !== 'fixed'} value={form.schedule_time} onValueChange={(value) => setForm((current) => ({ ...current, schedule_time: value }))} /><Select className='sm:col-span-2' label='签到动作' selectedKeys={form.scheduled_action ? [form.scheduled_action] : []} onSelectionChange={(keys) => setForm((current) => ({ ...current, scheduled_action: String(Array.from(keys)[0] || '') }))}>{(selectedPlatform?.actions || []).map((action) => <SelectItem key={action}>{actionNames[action] || action}</SelectItem>)}</Select></div></div>}
            {editing && <Select label='账号状态' isDisabled={editing.login_status === 'expired' || editing.status === 'credential_expired' || ['PLUGIN_CREDENTIAL_EXPIRED', 'PLUGIN_CREDENTIAL_INVALID'].includes(editing.last_error?.code || '')} description={editing.login_status === 'expired' || editing.status === 'credential_expired' || ['PLUGIN_CREDENTIAL_EXPIRED', 'PLUGIN_CREDENTIAL_INVALID'].includes(editing.last_error?.code || '') ? '登录状态失效，更新登录凭据后才能重新启用' : undefined} selectedKeys={[form.status]} onSelectionChange={(keys) => setForm((current) => ({ ...current, status: String(Array.from(keys)[0] || 'active') }))}><SelectItem key='active'>启用</SelectItem><SelectItem key='disabled'>停用</SelectItem><SelectItem key='credential_expired'>登录失效</SelectItem></Select>}
            <div className='flex items-start gap-2 rounded-2xl bg-default-100/70 px-4 py-3 text-xs leading-5 text-default-500'>
              <LuShieldCheck className='mt-0.5 shrink-0 text-success' />
              <span>所有登录凭据均在服务端使用 XChaCha20-Poly1305 算法加密存储，密钥不落库，仅在执行签到任务时解密使用，页面不会回显任何明文凭据。</span>
            </div>
          </ModalBody>
          <ModalFooter><Button variant='light' onPress={() => setModalOpen(false)}>取消</Button>{form.method === 'sms' && authResult?.status === 'code_sent' && <Button color='primary' isLoading={submitting} onPress={() => void completeSms()}>{renewing ? '验证并更新' : '验证并添加'}</Button>}{(editing || ['password', 'cookie', 'bduss'].includes(form.method)) && <Button color='primary' isLoading={submitting} onPress={() => void submit()}>{editing ? '保存修改' : form.method === 'password' ? (renewing ? '安全登录并更新' : '安全登录并添加') : (renewing ? '保存并更新' : '保存并添加')}</Button>}</ModalFooter>
        </ModalContent>
      </Modal>

      <TaskProgressModal task={watchingTask} onClose={() => setWatchingTask(null)} />
    </div>
  );
}
