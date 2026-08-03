import { Button } from '@heroui/button';
import { Card, CardBody, CardHeader } from '@heroui/card';
import { Input } from '@heroui/input';
import { type FormEvent, useEffect, useState } from 'react';
import { toast } from 'react-hot-toast';
import { LuKeyRound, LuMail, LuShieldCheck } from 'react-icons/lu';
import { useNavigate } from 'react-router-dom';

import { apiRequest } from '@/api/client';
import type { PasswordCodeResponse } from '@/types/sign';
import { errorMessage } from '@/utils/sign';

export default function ForgotPasswordPage () {
  const [form, setForm] = useState({ email: '', verification_code: '', new_password: '', confirm_password: '' });
  const [sent, setSent] = useState(false);
  const [sending, setSending] = useState(false);
  const [resetting, setResetting] = useState(false);
  const [cooldown, setCooldown] = useState(0);
  const navigate = useNavigate();

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = window.setInterval(() => setCooldown((value) => Math.max(0, value - 1)), 1000);
    return () => window.clearInterval(timer);
  }, [cooldown]);

  const sendCode = async () => {
    if (!form.email.trim()) return toast.error('请输入邮箱地址');
    setSending(true);
    try {
      const result = await apiRequest<PasswordCodeResponse>({ method: 'POST', url: '/auth/password/forgot', data: { email: form.email.trim() } });
      setSent(true);
      setCooldown(result.resend_after);
      toast.success('如果该邮箱已绑定账号，验证码将发送至邮箱');
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSending(false);
    }
  };

  const reset = async (event: FormEvent) => {
    event.preventDefault();
    if (form.new_password.length < 8) return toast.error('新密码至少需要8个字符');
    if (form.new_password !== form.confirm_password) return toast.error('两次输入的密码不一致');
    setResetting(true);
    try {
      await apiRequest<null>({
        method: 'POST',
        url: '/auth/password/reset',
        data: { email: form.email.trim(), verification_code: form.verification_code, new_password: form.new_password },
      });
      toast.success('密码已重置，请使用新密码登录');
      navigate('/login', { replace: true });
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setResetting(false);
    }
  };

  return (
    <div className='grid min-h-screen place-items-center bg-gradient-to-br from-indigo-50 via-background to-pink-50 p-5 dark:from-slate-950 dark:via-background dark:to-indigo-950/40'>
      <Card className='w-full max-w-lg rounded-[28px] shadow-2xl shadow-primary/10'>
        <CardHeader className='flex-col items-start gap-2 px-7 pt-8'>
          <div className='grid h-12 w-12 place-items-center rounded-2xl bg-primary/10 text-2xl text-primary'><LuKeyRound /></div>
          <h1 className='mt-2 text-2xl font-semibold'>找回登录密码</h1>
          <p className='text-sm text-default-500'>通过账号绑定邮箱接收验证码并设置新密码</p>
        </CardHeader>
        <CardBody className='gap-5 px-7 pb-8'>
          <div className='flex gap-2 rounded-2xl bg-default-100 p-4 text-xs leading-5 text-default-500'><LuShieldCheck className='mt-0.5 shrink-0 text-success' />为了避免泄露账号信息，无论邮箱是否存在，发送结果都会显示相同提示。</div>
          <form className='flex flex-col gap-4' onSubmit={(event) => void reset(event)}>
            <Input label='绑定邮箱' type='email' autoComplete='email' startContent={<LuMail />} value={form.email} onValueChange={(value) => setForm((current) => ({ ...current, email: value }))} />
            <Input label='邮箱验证码' inputMode='numeric' maxLength={6} value={form.verification_code} onValueChange={(value) => setForm((current) => ({ ...current, verification_code: value.replace(/\D/g, '').slice(0, 6) }))} endContent={<Button size='sm' variant='flat' isDisabled={cooldown > 0} isLoading={sending} onPress={() => void sendCode()}>{cooldown > 0 ? `${cooldown}s` : sent ? '重新发送' : '获取验证码'}</Button>} />
            <Input label='新密码' type='password' autoComplete='new-password' description='至少8个字符' value={form.new_password} onValueChange={(value) => setForm((current) => ({ ...current, new_password: value }))} />
            <Input label='确认新密码' type='password' autoComplete='new-password' value={form.confirm_password} onValueChange={(value) => setForm((current) => ({ ...current, confirm_password: value }))} />
            <Button color='primary' size='lg' type='submit' isLoading={resetting} isDisabled={form.verification_code.length !== 6 || form.new_password.length < 8}>重置密码</Button>
            <Button variant='light' type='button' onPress={() => navigate('/login')}>返回登录</Button>
          </form>
        </CardBody>
      </Card>
    </div>
  );
}
