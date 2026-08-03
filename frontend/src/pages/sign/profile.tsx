import { Button } from '@heroui/button';
import { Card, CardBody, CardHeader } from '@heroui/card';
import { Chip } from '@heroui/chip';
import { Input } from '@heroui/input';
import { Select, SelectItem } from '@heroui/select';
import { Switch } from '@heroui/switch';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { toast } from 'react-hot-toast';
import { FaQq } from 'react-icons/fa';
import {
  LuBellRing, LuCalendarDays, LuClock3, LuKeyRound, LuMail, LuShieldCheck, LuSparkles,
  LuUserRound,
} from 'react-icons/lu';
import { useNavigate } from 'react-router-dom';

import { apiRequest } from '@/api/client';
import { useSignAuth } from '@/contexts/auth';
import { useSite } from '@/contexts/site';
import type { PasswordCodeResponse, QqLoginStart, User } from '@/types/sign';
import { errorMessage, formatFullDateTime } from '@/utils/sign';

export default function ProfilePage () {
  const { user, refreshUser, logout } = useSignAuth();
  const [profile, setProfile] = useState({ email: '', qq: '', daily_sign_email: false });
  const [push, setPush] = useState({ push_channel: 'none', push_token: '' });
  const [passwords, setPasswords] = useState({ verification_code: '', new_password: '', confirm_password: '' });
  const [currentEmailCode, setCurrentEmailCode] = useState('');
  const [saving, setSaving] = useState(false);
  const [savingPush, setSavingPush] = useState(false);
  const [testingPush, setTestingPush] = useState(false);
  const [changing, setChanging] = useState(false);
  const [sendingCode, setSendingCode] = useState(false);
  const [sendingEmailCode, setSendingEmailCode] = useState(false);
  const [bindingQq, setBindingQq] = useState(false);
  const [cooldown, setCooldown] = useState(0);
  const [emailCooldown, setEmailCooldown] = useState(0);
  const [codeTarget, setCodeTarget] = useState('');
  const [emailCodeTarget, setEmailCodeTarget] = useState('');
  const qqCallbackHandled = useRef(false);
  const site = useSite();
  const navigate = useNavigate();

  const emailChanged = Boolean(user?.email)
    && profile.email.trim().toLowerCase() !== (user?.email || '').trim().toLowerCase();

  useEffect(() => {
    if (!user) return;
    setProfile({ email: user.email || '', qq: user.qq || '', daily_sign_email: Boolean(user.daily_sign_email) });
    setPush({ push_channel: user.push_channel || 'none', push_token: user.push_token || '' });
    setCurrentEmailCode('');
    setEmailCodeTarget('');
  }, [user]);

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = window.setInterval(() => setCooldown((value) => Math.max(0, value - 1)), 1000);
    return () => window.clearInterval(timer);
  }, [cooldown]);

  useEffect(() => {
    if (emailCooldown <= 0) return;
    const timer = window.setInterval(() => setEmailCooldown((value) => Math.max(0, value - 1)), 1000);
    return () => window.clearInterval(timer);
  }, [emailCooldown]);

  useEffect(() => {
    if (qqCallbackHandled.current) return;
    const parameters = new URLSearchParams(window.location.search);
    const bound = parameters.get('qq_bound');
    const failure = parameters.get('qq_error');
    if (!bound && !failure) return;
    qqCallbackHandled.current = true;
    window.history.replaceState({}, '', window.location.pathname);
    if (bound === '1') {
      void refreshUser();
      toast.success('QQ 快捷登录已绑定');
      return;
    }
    const messages: Record<string, string> = {
      QQ_LOGIN_CANCELLED: '已取消 QQ 绑定',
      QQ_LOGIN_STATE_INVALID: 'QQ 绑定请求已过期，请重新尝试',
      QQ_LOGIN_UNAVAILABLE: 'QQ 快捷登录暂不可用',
      QQ_ALREADY_LINKED: '该 QQ 已绑定其他平台账号',
    };
    toast.error(messages[failure || ''] || 'QQ 绑定失败，请重试');
  }, [refreshUser]);

  const bindQq = async () => {
    setBindingQq(true);
    try {
      const result = await apiRequest<QqLoginStart>({
        method: 'POST',
        url: '/me/qq/start',
        data: { return_url: `${window.location.origin}${window.location.pathname}` },
      });
      window.location.assign(result.authorization_url);
    } catch (error) {
      toast.error(errorMessage(error));
      setBindingQq(false);
    }
  };

  const saveProfile = async (event: FormEvent) => {
    event.preventDefault();
    if (emailChanged && !/^\d{6}$/.test(currentEmailCode.trim())) {
      toast.error('更换邮箱前，请输入原邮箱收到的6位验证码');
      return;
    }
    setSaving(true);
    try {
      await apiRequest<User>({
        method: 'PATCH',
        url: '/me',
        data: {
          ...profile,
          ...(emailChanged ? { current_email_code: currentEmailCode.trim() } : {}),
        },
      });
      await refreshUser();
      setCurrentEmailCode('');
      setEmailCodeTarget('');
      toast.success('联系方式已更新');
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSaving(false);
    }
  };

  const sendChangeEmailCode = async () => {
    if (!user?.email) {
      toast.error('当前账号尚未绑定邮箱');
      return;
    }
    setSendingEmailCode(true);
    try {
      const result = await apiRequest<PasswordCodeResponse>({ method: 'POST', url: '/me/email/code' });
      setEmailCodeTarget(result.email_masked);
      setEmailCooldown(result.resend_after);
      toast.success(`验证码已发送至 ${result.email_masked}`);
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSendingEmailCode(false);
    }
  };

  const savePush = async () => {
    setSavingPush(true);
    try {
      await apiRequest<User>({ method: 'PATCH', url: '/me', data: push });
      await refreshUser();
      toast.success('推送设置已保存');
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSavingPush(false);
    }
  };

  const testPush = async () => {
    setTestingPush(true);
    try {
      await apiRequest<null>({ method: 'POST', url: '/me/push/test' });
      toast.success('测试推送已发送，请在对应渠道查收');
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setTestingPush(false);
    }
  };

  const sendCode = async () => {
    if (!user?.email) {
      toast.error('请先保存一个有效邮箱');
      return;
    }
    setSendingCode(true);
    try {
      const result = await apiRequest<PasswordCodeResponse>({ method: 'POST', url: '/me/password/code' });
      setCodeTarget(result.email_masked);
      setCooldown(result.resend_after);
      toast.success(`验证码已发送至 ${result.email_masked}`);
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSendingCode(false);
    }
  };

  const changePassword = async (event: FormEvent) => {
    event.preventDefault();
    if (passwords.new_password !== passwords.confirm_password) {
      toast.error('两次输入的新密码不一致');
      return;
    }
    setChanging(true);
    try {
      await apiRequest<null>({
        method: 'POST',
        url: '/me/password',
        data: {
          verification_code: passwords.verification_code,
          new_password: passwords.new_password,
        },
      });
      try { await logout(); } catch { /* 服务端已经撤销全部会话 */ }
      toast.success('密码已修改，请重新登录');
      navigate('/login', { replace: true });
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setChanging(false);
    }
  };

  const facts = [
    { label: 'UID', value: user ? String(user.id) : '—', icon: <LuUserRound /> },
    { label: '账号配额', value: `${user?.quota ?? 0} 个`, icon: <LuSparkles /> },
    { label: '注册时间', value: formatFullDateTime(user?.created_at), icon: <LuCalendarDays /> },
    { label: '上次登录', value: formatFullDateTime(user?.last_login_at), icon: <LuClock3 /> },
  ];

  return (
    <div className='flex flex-col gap-6'>
      <Card className='overflow-hidden rounded-[30px] border border-primary/15 bg-gradient-to-br from-primary/12 via-content1 to-secondary/10 shadow-[0_24px_60px_rgba(79,70,229,0.12)]'>
        <CardBody className='relative gap-6 p-6 md:p-8'>
          <div className='pointer-events-none absolute -right-14 -top-20 h-56 w-56 rounded-full border-[34px] border-primary/10' />
          <div className='relative flex flex-col justify-between gap-5 md:flex-row md:items-center'>
            <div className='flex items-center gap-4'>
              <div className='grid h-16 w-16 place-items-center rounded-[22px] bg-gradient-to-br from-primary to-secondary text-2xl font-bold text-white shadow-lg shadow-primary/25'>
                {user?.username?.slice(0, 1).toUpperCase() || 'U'}
              </div>
              <div>
                <div className='flex flex-wrap items-center gap-2'>
                  <h2 className='text-2xl font-semibold'>{user?.username}</h2>
                  <Chip size='sm' color={user?.role === 'admin' ? 'primary' : 'default'} variant='flat'>
                    {user?.role === 'admin' ? '管理员' : '用户'}
                  </Chip>
                </div>
              </div>
            </div>
            <div className='grid grid-cols-2 gap-2 md:grid-cols-4'>
              {facts.map((fact) => (
                <div key={fact.label} className='min-w-28 rounded-2xl border border-white/50 bg-background/55 p-3 backdrop-blur'>
                  <div className='flex items-center gap-1.5 text-xs text-default-400'>{fact.icon}{fact.label}</div>
                  <p className='mt-1 whitespace-nowrap text-sm font-medium'>{fact.value}</p>
                </div>
              ))}
            </div>
          </div>
        </CardBody>
      </Card>

      <Card className='overflow-hidden rounded-[26px] border border-sky-200/70 bg-gradient-to-r from-sky-50/90 via-content1 to-cyan-50/80 dark:border-sky-500/20 dark:from-sky-500/10 dark:via-content1 dark:to-cyan-500/10'>
        <CardBody className='flex flex-col items-start justify-between gap-5 p-6 md:flex-row md:items-center'>
          <div className='flex items-start gap-4'>
            <div className='grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-sky-500 text-xl text-white shadow-lg shadow-sky-500/20'><FaQq /></div>
            <div>
              <div className='flex flex-wrap items-center gap-2'>
                <h2 className='font-semibold'>QQ 快捷登录</h2>
                <Chip size='sm' color={user?.qq_binding?.bound ? 'success' : 'warning'} variant='flat'>
                  {user?.qq_binding?.requires_rebind ? '需更新绑定' : user?.qq_binding?.bound ? '已绑定' : '未绑定'}
                </Chip>
              </div>
              {user?.qq_binding?.requires_rebind ? (
                <p className='mt-1 text-sm text-warning'>站点已升级为独立 AppID，请重新授权一次以继续使用 QQ 登录。</p>
              ) : user?.qq_binding?.bound ? (
                <p className='mt-1 text-sm text-default-500'>
                  已绑定 {user.qq_binding.display_name || 'QQ 账号'}
                  {user.qq_binding.linked_at ? ` · ${formatFullDateTime(user.qq_binding.linked_at)}` : ''}
                </p>
              ) : (
                <p className='mt-1 text-sm text-default-500'>绑定后可直接使用 QQ 登录；即使尚未设置密码，也不会影响账号使用。</p>
              )}
            </div>
          </div>
          {(!user?.qq_binding?.bound || user?.qq_binding?.requires_rebind) && (
            <Button
              className='w-full bg-sky-500 text-white shadow-md shadow-sky-500/20 md:w-auto'
              isDisabled={!site.qq_login_enabled}
              isLoading={bindingQq}
              startContent={!bindingQq ? <FaQq /> : undefined}
              onPress={() => void bindQq()}
            >
              {site.qq_login_enabled
                ? user?.qq_binding?.requires_rebind ? '更新 QQ 绑定' : '绑定 QQ 快捷登录'
                : 'QQ 快捷登录未启用'}
            </Button>
          )}
        </CardBody>
      </Card>

      <Card className='tf-glass-card rounded-[26px]'>
        <CardHeader className='flex-col items-start px-6 pt-6'>
          <div className='flex items-center gap-2'><LuBellRing className='text-primary' /><h2 className='text-lg font-semibold'>即时推送通知</h2></div>
          <p className='mt-1 text-sm text-default-500'>账号登录失效、签到失败、会员到期等重要事件会实时推送到你的微信或手机，比邮件更及时</p>
        </CardHeader>
        <CardBody className='gap-4 px-6 pb-6'>
          <div className='grid gap-4 md:grid-cols-[220px_1fr]'>
            <Select
              label='推送渠道'
              selectedKeys={[push.push_channel]}
              onSelectionChange={(keys) => setPush((current) => ({ ...current, push_channel: String(Array.from(keys)[0] || 'none') }))}
            >
              <SelectItem key='none'>关闭推送</SelectItem>
              <SelectItem key='pushplus' description='微信公众号推送，pushplus.plus 免费申请'>PushPlus（微信）</SelectItem>
              <SelectItem key='serverchan' description='微信推送，sct.ftqq.com 获取 SendKey'>Server酱（微信）</SelectItem>
              <SelectItem key='bark' description='iPhone 通知推送，填写 Bark Key 或完整推送地址'>Bark（iOS）</SelectItem>
            </Select>
            <Input
              label='推送令牌'
              isDisabled={push.push_channel === 'none'}
              placeholder={push.push_channel === 'pushplus' ? 'PushPlus Token' : push.push_channel === 'serverchan' ? 'SendKey（SCT 或 sctp 开头）' : push.push_channel === 'bark' ? 'Bark Key 或 https://api.day.app/xxxx' : '请先选择推送渠道'}
              value={push.push_token}
              onValueChange={(value) => setPush((current) => ({ ...current, push_token: value }))}
            />
          </div>
          <div className='flex flex-wrap gap-3'>
            <Button color='primary' isLoading={savingPush} onPress={() => void savePush()}>保存推送设置</Button>
            <Button variant='flat' isLoading={testingPush} isDisabled={(user?.push_channel || 'none') === 'none'} onPress={() => void testPush()}>发送测试推送</Button>
          </div>
        </CardBody>
      </Card>

      <div className='grid gap-6 xl:grid-cols-2'>
        <Card className='tf-glass-card rounded-[26px]'>
          <CardHeader className='flex-col items-start px-6 pt-6'>
            <div className='flex items-center gap-2'><LuMail className='text-primary' /><h2 className='text-lg font-semibold'>联系方式</h2></div>
            <p className='mt-1 text-sm text-default-500'>邮箱用于安全验证和获取通知</p>
          </CardHeader>
          <CardBody className='px-6 pb-6'>
            <form className='flex flex-col gap-4' onSubmit={(event) => void saveProfile(event)}>
              <Input
                label='邮箱'
                type='email'
                autoComplete='email'
                description={user?.email ? '更换邮箱前需验证当前绑定邮箱' : '用于安全验证和获取通知'}
                value={profile.email}
                onValueChange={(value) => {
                  setProfile((current) => ({ ...current, email: value }));
                  setCurrentEmailCode('');
                }}
              />
              {emailChanged && (
                <div className='rounded-2xl border border-warning/30 bg-warning/10 p-4'>
                  <p className='text-sm font-medium text-warning-700 dark:text-warning-300'>更换邮箱需验证原邮箱</p>
                  <p className='mt-1 text-xs leading-5 text-default-500'>
                    {emailCodeTarget
                      ? `验证码已发送至 ${emailCodeTarget}，10 分钟内有效。`
                      : `请向当前邮箱 ${user?.email} 发送验证码，确认本人操作后再保存。`}
                  </p>
                  <Input
                    className='mt-3'
                    label='原邮箱验证码'
                    inputMode='numeric'
                    maxLength={6}
                    value={currentEmailCode}
                    onValueChange={(value) => setCurrentEmailCode(value.replace(/\D/g, '').slice(0, 6))}
                    endContent={(
                      <Button
                        size='sm'
                        variant='flat'
                        color='warning'
                        isDisabled={emailCooldown > 0}
                        isLoading={sendingEmailCode}
                        onPress={() => void sendChangeEmailCode()}
                      >
                        {emailCooldown > 0 ? `${emailCooldown}s` : '获取验证码'}
                      </Button>
                    )}
                  />
                </div>
              )}
              <Input label='联系 QQ 号' description='仅作为联系方式，不等同于上方的 QQ 快捷登录绑定' inputMode='numeric' value={profile.qq} onValueChange={(value) => setProfile((current) => ({ ...current, qq: value }))} />
              <div className='flex items-center justify-between gap-4 rounded-2xl border border-divider bg-content1/50 p-4'>
                <div><p className='text-sm font-medium'>每日签到邮件</p><p className='mt-1 text-xs leading-5 text-default-400'>每天汇总当天任务完成、失败和等待状态，默认关闭</p></div>
                <Switch aria-label='每日签到邮件' isSelected={profile.daily_sign_email} onValueChange={(value) => setProfile((current) => ({ ...current, daily_sign_email: value }))} />
              </div>
              <Button type='submit' color='primary' isLoading={saving} isDisabled={emailChanged && currentEmailCode.length !== 6}>保存联系方式</Button>
            </form>
          </CardBody>
        </Card>

        <Card className='tf-glass-card rounded-[26px]'>
          <CardHeader className='flex-col items-start px-6 pt-6'>
            <div className='flex items-center gap-2'><LuKeyRound className='text-primary' /><h2 className='text-lg font-semibold'>修改登录密码</h2></div>
            <p className='mt-1 text-sm text-default-500'>{user?.has_password === false ? '当前尚未设置密码，可通过邮箱验证码设置登录密码' : '无需输入原密码，验证码将发送至当前绑定邮箱'}</p>
          </CardHeader>
          <CardBody className='gap-4 px-6 pb-6'>
            <div className='flex items-start gap-2 rounded-2xl bg-primary/10 p-3 text-xs leading-5 text-default-500'>
              <LuShieldCheck className='mt-0.5 shrink-0 text-primary' />
              <span>{codeTarget ? `验证码已发送至 ${codeTarget}，10 分钟内有效。` : '修改成功后会自动退出所有设备，保护账号安全。'}</span>
            </div>
            <form className='flex flex-col gap-4' onSubmit={(event) => void changePassword(event)}>
              <Input
                label='邮箱验证码'
                inputMode='numeric'
                maxLength={6}
                value={passwords.verification_code}
                onValueChange={(value) => setPasswords((current) => ({ ...current, verification_code: value.replace(/\D/g, '').slice(0, 6) }))}
                endContent={<Button size='sm' variant='flat' color='primary' isDisabled={cooldown > 0} isLoading={sendingCode} onPress={() => void sendCode()}>{cooldown > 0 ? `${cooldown}s` : '获取验证码'}</Button>}
              />
              <Input label='新密码' type='password' autoComplete='new-password' description='至少 8 个字符' value={passwords.new_password} onValueChange={(value) => setPasswords((current) => ({ ...current, new_password: value }))} />
              <Input label='确认新密码' type='password' autoComplete='new-password' value={passwords.confirm_password} onValueChange={(value) => setPasswords((current) => ({ ...current, confirm_password: value }))} />
              <Button type='submit' color='danger' variant='flat' isLoading={changing} isDisabled={passwords.verification_code.length !== 6 || passwords.new_password.length < 8}>修改密码并退出</Button>
            </form>
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
