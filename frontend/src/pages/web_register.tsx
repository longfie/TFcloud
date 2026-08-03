import { Button } from '@heroui/button';
import { CardBody, CardHeader } from '@heroui/card';
import { Input } from '@heroui/input';
import { motion } from 'motion/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { toast } from 'react-hot-toast';
import { FaQq } from 'react-icons/fa';
import { IoKeyOutline, IoMailOutline, IoPersonOutline, IoShieldCheckmarkOutline } from 'react-icons/io5';
import { useNavigate } from 'react-router-dom';

import { apiRequest } from '@/api/client';
import HoverEffectCard from '@/components/effect_card';
import HumanVerification from '@/components/human_verification';
import PlatformLogo from '@/components/platform_logo';
import { title } from '@/components/primitives';
import { ThemeSwitch } from '@/components/theme-switch';
import { useSignAuth } from '@/contexts/auth';
import { useSite } from '@/contexts/site';
import PureLayout from '@/layouts/pure';
import type { QqLoginStart } from '@/types/sign';
import { errorMessage } from '@/utils/sign';

const inputClassNames = {
  input: 'bg-transparent text-black/90 dark:text-white/90',
  innerWrapper: 'bg-transparent',
  inputWrapper: 'bg-default-100/70 shadow-lg backdrop-blur-xl dark:bg-default/60',
};

export default function WebRegisterPage () {
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [challengeId, setChallengeId] = useState<string | null>(null);
  const [challengeVersion, setChallengeVersion] = useState(0);
  const [emailCode, setEmailCode] = useState('');
  const [sendingCode, setSendingCode] = useState(false);
  const [resendIn, setResendIn] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [qqSubmitting, setQqSubmitting] = useState(false);
  const qqCallbackHandled = useRef(false);
  const { registerAccount, loginWithQqExchange, isAuthenticated } = useSignAuth();
  const site = useSite();
  const navigate = useNavigate();

  useEffect(() => {
    if (isAuthenticated) navigate('/TFYT', { replace: true });
  }, [isAuthenticated, navigate]);

  useEffect(() => {
    if (qqCallbackHandled.current) return;
    const parameters = new URLSearchParams(window.location.search);
    const exchangeCode = parameters.get('qq_code');
    const qqError = parameters.get('qq_error');
    if (!exchangeCode && !qqError) return;
    qqCallbackHandled.current = true;
    window.history.replaceState({}, '', window.location.pathname);
    if (qqError) {
      const messages: Record<string, string> = {
        QQ_LOGIN_CANCELLED: '已取消 QQ 授权',
        QQ_LOGIN_STATE_INVALID: 'QQ 授权已过期，请重新尝试',
        QQ_LOGIN_NOT_LINKED: '该 QQ 尚未绑定，且当前未开放 QQ 创建账号',
        QQ_LOGIN_UNAVAILABLE: 'QQ 快捷登录暂不可用',
      };
      toast.error(messages[qqError] || 'QQ 授权失败，请重试');
      return;
    }
    setQqSubmitting(true);
    loginWithQqExchange(exchangeCode as string)
      .then((result) => {
        if ('requires_profile_completion' in result) {
          sessionStorage.setItem('tf-qq-onboarding', JSON.stringify(result));
          navigate('/qq-register', { replace: true });
          return;
        }
        toast.success('该 QQ 已有账号，已为你直接登录');
        navigate('/TFYT', { replace: true });
      })
      .catch((error) => toast.error(errorMessage(error)))
      .finally(() => setQqSubmitting(false));
  }, [loginWithQqExchange, navigate]);

  useEffect(() => {
    if (resendIn <= 0) return;
    const timer = window.setTimeout(() => setResendIn((value) => value - 1), 1000);
    return () => window.clearTimeout(timer);
  }, [resendIn]);

  const sendEmailCode = async () => {
    if (!email.trim()) return toast.error('请先填写邮箱地址');
    setSendingCode(true);
    try {
      const result = await apiRequest<{ resend_after: number }>({ method: 'POST', url: '/auth/register/email-code', data: { email: email.trim() } });
      toast.success('验证码已发送，请查收邮箱');
      setResendIn(result.resend_after || 60);
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSendingCode(false);
    }
  };

  const startQqRegistration = async () => {
    setQqSubmitting(true);
    try {
      const result = await apiRequest<QqLoginStart>({
        method: 'POST',
        url: '/auth/qq/start',
        data: { return_url: `${window.location.origin}${window.location.pathname}` },
      });
      window.location.assign(result.authorization_url);
    } catch (error) {
      toast.error(errorMessage(error));
      setQqSubmitting(false);
    }
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!site.registration_enabled) return toast.error('当前未开放用户注册');
    if (username.trim().length < 2) return toast.error('用户名至少需要2个字符');
    if (!email.trim()) return toast.error('请输入邮箱地址');
    if (password.length < 8) return toast.error('密码至少需要8个字符');
    if (password !== confirmation) return toast.error('两次输入的密码不一致');
    if (site.registration_email_verification && !/^\d{6}$/.test(emailCode.trim())) return toast.error('请输入邮箱收到的6位验证码');
    if (site.registration_challenge_enabled && !challengeId) return toast.error('请先完成人机验证');
    setSubmitting(true);
    try {
      await registerAccount(username.trim(), email.trim(), password, challengeId || undefined, emailCode.trim() || undefined);
      toast.success('注册成功');
      navigate('/TFYT', { replace: true });
    } catch (error) {
      toast.error(errorMessage(error));
      setChallengeId(null);
      setChallengeVersion((value) => value + 1);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className='relative isolate min-h-screen overflow-hidden'>
      <div className='pointer-events-none fixed inset-0 -z-10 bg-gradient-to-br from-indigo-50 via-white to-pink-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900' />
      <PureLayout>
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className='w-[608px] max-w-full overflow-hidden px-2 py-8 md:px-8'>
          <HoverEffectCard className='items-center gap-3 bg-default-50 pb-6 pt-0' maxXRotation={3} maxYRotation={3}>
            <CardHeader className='inline-block max-w-lg justify-center text-center'>
              <div className='flex w-full items-center justify-center gap-2 pt-8'>
                <PlatformLogo size='6em' />
                <div><span className={title()}>创建&nbsp;</span><span className={title({ color: 'violet' })}>账号</span></div>
              </div>
              <ThemeSwitch className='absolute right-4 top-4' />
            </CardHeader>
            <CardBody className='px-5 pb-6 md:px-10'>
              <form className='flex flex-col gap-4' onSubmit={(event) => void submit(event)}>
                <Input autoFocus autoComplete='username' classNames={inputClassNames} label='用户名' startContent={<IoPersonOutline />} value={username} onValueChange={setUsername} />
                <Input autoComplete='email' classNames={inputClassNames} label='邮箱' type='email' startContent={<IoMailOutline />} value={email} onValueChange={setEmail} />
                {site.registration_enabled && site.registration_email_verification && (
                  <div className='flex items-start gap-2'>
                    <Input
                      autoComplete='one-time-code'
                      classNames={inputClassNames}
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
                <div className='grid gap-4 sm:grid-cols-2'>
                  <Input autoComplete='new-password' classNames={inputClassNames} label='密码' type='password' startContent={<IoKeyOutline />} value={password} onValueChange={setPassword} />
                  <Input autoComplete='new-password' classNames={inputClassNames} label='确认密码' type='password' startContent={<IoKeyOutline />} value={confirmation} onValueChange={setConfirmation} />
                </div>
                {site.registration_enabled && site.registration_challenge_enabled && <HumanVerification key={challengeVersion} purpose='register' disabled={submitting} onVerified={setChallengeId} />}
                {!site.registration_enabled && (
                  <div className='rounded-2xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning-700 dark:text-warning-300'>
                    {site.qq_login_enabled && site.qq_auto_register !== false
                      ? '当前未开放账号密码注册，可在下方使用 QQ 创建账号。'
                      : '当前未开放用户注册，请联系管理员。'}
                  </div>
                )}
                <Button color='primary' isLoading={submitting} isDisabled={!site.registration_enabled || (site.registration_challenge_enabled && !challengeId)} radius='full' size='lg' type='submit' variant='shadow'>注册并登录</Button>
                {site.qq_login_enabled && site.qq_auto_register !== false && (
                  <>
                    <div className='flex items-center gap-3 text-xs text-default-400'>
                      <span className='h-px flex-1 bg-default-200' />
                      <span>或使用快捷方式</span>
                      <span className='h-px flex-1 bg-default-200' />
                    </div>
                    <Button
                      className='border-sky-200 bg-sky-50/70 text-sky-600 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300'
                      isLoading={qqSubmitting}
                      radius='full'
                      size='lg'
                      startContent={!qqSubmitting ? <FaQq /> : undefined}
                      type='button'
                      variant='bordered'
                      onPress={() => void startQqRegistration()}
                    >
                      使用 QQ 创建账号
                    </Button>
                  </>
                )}
                <Button radius='full' type='button' variant='light' onPress={() => navigate('/login')}>已有账号，返回登录</Button>
              </form>
            </CardBody>
          </HoverEffectCard>
        </motion.div>
      </PureLayout>
    </div>
  );
}
