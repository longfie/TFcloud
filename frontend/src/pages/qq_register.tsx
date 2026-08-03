import { Avatar } from '@heroui/avatar';
import { Button } from '@heroui/button';
import { Card, CardBody, CardHeader } from '@heroui/card';
import { Input } from '@heroui/input';
import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { toast } from 'react-hot-toast';
import { FaQq } from 'react-icons/fa';
import { IoMailOutline, IoPersonOutline, IoShieldCheckmarkOutline } from 'react-icons/io5';
import { Navigate, useNavigate } from 'react-router-dom';

import { apiRequest } from '@/api/client';
import { useSignAuth } from '@/contexts/auth';
import { useSite } from '@/contexts/site';
import type { QqOnboarding } from '@/types/sign';
import { errorMessage } from '@/utils/sign';

export default function QqRegisterPage () {
  const pending = useMemo<QqOnboarding | null>(() => {
    try {
      const value = JSON.parse(sessionStorage.getItem('tf-qq-onboarding') || 'null');
      return value?.requires_profile_completion && value?.onboarding_token ? value : null;
    } catch {
      return null;
    }
  }, []);
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [emailCode, setEmailCode] = useState('');
  const [sendingCode, setSendingCode] = useState(false);
  const [resendIn, setResendIn] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const { completeQqRegistration } = useSignAuth();
  const site = useSite();
  const navigate = useNavigate();
  const needEmailCode = Boolean(site.registration_email_verification);

  useEffect(() => {
    if (resendIn <= 0) return;
    const timer = window.setTimeout(() => setResendIn((value) => value - 1), 1000);
    return () => window.clearTimeout(timer);
  }, [resendIn]);

  if (!pending) return <Navigate to='/login' replace />;

  const sendEmailCode = async () => {
    if (!email.trim()) return toast.error('请先填写邮箱地址');
    setSendingCode(true);
    try {
      const result = await apiRequest<{ resend_after: number }>({
        method: 'POST',
        url: '/auth/register/email-code',
        data: { email: email.trim() },
      });
      toast.success('验证码已发送，请查收邮箱');
      setResendIn(result.resend_after || 60);
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSendingCode(false);
    }
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (username.trim().length < 2) return toast.error('用户名至少需要2个字符');
    if (!email.trim()) return toast.error('请输入有效邮箱');
    if (needEmailCode && !/^\d{6}$/.test(emailCode.trim())) return toast.error('请输入邮箱收到的6位验证码');
    setSubmitting(true);
    try {
      await completeQqRegistration(pending.onboarding_token, username.trim(), email.trim(), emailCode.trim() || undefined);
      sessionStorage.removeItem('tf-qq-onboarding');
      toast.success('QQ 快捷账号创建成功');
      navigate('/TFYT', { replace: true });
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className='grid min-h-screen place-items-center bg-gradient-to-br from-sky-50 via-background to-indigo-50 p-5 dark:from-slate-950 dark:via-background dark:to-indigo-950/40'>
      <Card className='w-full max-w-lg rounded-[28px] border border-sky-200/50 shadow-2xl shadow-sky-500/10'>
        <CardHeader className='flex-col gap-4 px-7 pt-8 text-center'>
          <div className='relative'>
            <Avatar size='lg' src={pending.qq_profile.avatar} name={pending.qq_profile.nickname || 'QQ'} />
            <span className='absolute -bottom-1 -right-1 grid h-7 w-7 place-items-center rounded-full bg-sky-500 text-sm text-white'><FaQq /></span>
          </div>
          <div><h1 className='text-2xl font-semibold'>完成账号创建</h1><p className='mt-1 text-sm text-default-500'>已通过 QQ 验证身份，请设置本站用户名和安全邮箱</p></div>
        </CardHeader>
        <CardBody className='gap-5 px-7 pb-8'>
          <div className='rounded-2xl bg-sky-500/10 px-4 py-3 text-sm text-sky-700 dark:text-sky-300'>QQ 昵称：{pending.qq_profile.nickname || 'QQ 用户'}。账号初始不设置密码，可继续使用 QQ 登录，或稍后通过邮箱设置密码。</div>
          <form className='flex flex-col gap-4' onSubmit={(event) => void submit(event)}>
            <Input autoFocus label='用户名' description='创建后不可自行修改' startContent={<IoPersonOutline />} value={username} onValueChange={setUsername} />
            <Input label='邮箱' type='email' autoComplete='email' description='用于安全通知和找回密码' startContent={<IoMailOutline />} value={email} onValueChange={setEmail} />
            {needEmailCode && (
              <div className='flex items-start gap-2'>
                <Input
                  autoComplete='one-time-code'
                  description='验证码将发送到上方填写的邮箱，10 分钟内有效'
                  inputMode='numeric'
                  label='邮箱验证码'
                  maxLength={6}
                  startContent={<IoShieldCheckmarkOutline />}
                  value={emailCode}
                  onValueChange={(value) => setEmailCode(value.replace(/\D/g, ''))}
                />
                <Button
                  className='mt-1 h-12 shrink-0'
                  isDisabled={resendIn > 0 || !email.trim()}
                  isLoading={sendingCode}
                  radius='lg'
                  variant='flat'
                  onPress={() => void sendEmailCode()}
                >
                  {resendIn > 0 ? `${resendIn} 秒后重发` : '发送验证码'}
                </Button>
              </div>
            )}
            <Button color='primary' size='lg' type='submit' isLoading={submitting}>创建账号并登录</Button>
            <Button variant='light' type='button' onPress={() => { sessionStorage.removeItem('tf-qq-onboarding'); navigate('/login'); }}>取消</Button>
          </form>
        </CardBody>
      </Card>
    </div>
  );
}
