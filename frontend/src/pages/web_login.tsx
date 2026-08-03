import { Button } from '@heroui/button';
import { CardBody, CardHeader } from '@heroui/card';
import { Input } from '@heroui/input';
import { Tab, Tabs } from '@heroui/tabs';
import { motion } from 'motion/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { toast } from 'react-hot-toast';
import { FaQq } from 'react-icons/fa';
import { IoKeyOutline, IoMailOutline, IoPersonOutline } from 'react-icons/io5';
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
  label: 'text-black/50 dark:text-white/90',
  input: [
    'bg-transparent',
    'text-black/90 dark:text-white/90',
    'placeholder:text-default-700/50 dark:placeholder:text-white/60',
  ],
  innerWrapper: 'bg-transparent',
  inputWrapper: [
    'shadow-xl',
    'bg-default-100/70',
    'dark:bg-default/60',
    'backdrop-blur-xl',
    'backdrop-saturate-200',
    'hover:bg-default-0/70',
    'dark:hover:bg-default/70',
    'group-data-[focus=true]:bg-default-100/50',
    'dark:group-data-[focus=true]:bg-default/60',
    '!cursor-text',
  ],
};

export default function WebLoginPage () {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [emailCode, setEmailCode] = useState('');
  const [loginMode, setLoginMode] = useState<'password' | 'email'>('password');
  const [sendingCode, setSendingCode] = useState(false);
  const [emailCodeSent, setEmailCodeSent] = useState(false);
  const [cooldown, setCooldown] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [challengeId, setChallengeId] = useState<string | null>(null);
  const [challengeVersion, setChallengeVersion] = useState(0);
  const [qqSubmitting, setQqSubmitting] = useState(false);
  const qqCallbackHandled = useRef(false);
  const { login, loginWithEmailCode, loginWithQqExchange, isAuthenticated } = useSignAuth();
  const site = useSite();
  const navigate = useNavigate();

  useEffect(() => {
    if (isAuthenticated) navigate('/TFYT', { replace: true });
  }, [isAuthenticated, navigate]);

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = window.setInterval(() => setCooldown((value) => Math.max(0, value - 1)), 1000);
    return () => window.clearInterval(timer);
  }, [cooldown]);

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
        QQ_LOGIN_CANCELLED: '已取消 QQ 登录',
        QQ_LOGIN_STATE_INVALID: 'QQ 登录已过期，请重新尝试',
        QQ_LOGIN_NOT_LINKED: '该 QQ 尚未绑定平台账号',
        QQ_LOGIN_UNAVAILABLE: 'QQ 快捷登录暂不可用',
        QQ_ALREADY_LINKED: '该 QQ 已绑定其他账号',
      };
      toast.error(messages[qqError] || 'QQ 登录失败，请重试');
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
        toast.success('QQ 登录成功');
        navigate('/TFYT', { replace: true });
      })
      .catch((error) => toast.error(errorMessage(error)))
      .finally(() => setQqSubmitting(false));
  }, [loginWithQqExchange, navigate]);

  const startQqLogin = async () => {
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
    if (!username.trim() || (loginMode === 'password' ? !password : !emailCode)) {
      toast.error(loginMode === 'password' ? '请输入用户名/邮箱和密码' : '请输入邮箱和验证码');
      return;
    }
    if (loginMode === 'password' && site.login_challenge_enabled && !challengeId) {
      toast.error('请先完成人机验证');
      return;
    }
    setSubmitting(true);
    try {
      if (loginMode === 'password') {
        await login(username.trim(), password, challengeId || undefined);
      } else {
        await loginWithEmailCode(username.trim(), emailCode.trim());
      }
      toast.success('登录成功');
      navigate('/TFYT', { replace: true });
    } catch (error) {
      toast.error(errorMessage(error));
      setChallengeId(null);
      setChallengeVersion((current) => current + 1);
    } finally {
      setSubmitting(false);
    }
  };

  const sendEmailCode = async () => {
    const email = username.trim();
    if (!/^\S+@\S+\.\S+$/.test(email)) {
      toast.error('请输入有效的登录邮箱');
      return;
    }
    if (site.login_challenge_enabled && !challengeId) {
      toast.error('发送验证码前请完成人机验证');
      return;
    }
    setSendingCode(true);
    try {
      const result = await apiRequest<{ expires_in: number; resend_after: number }>({
        method: 'POST',
        url: '/auth/login/email-code',
        data: { email, challenge_id: challengeId || '' },
      });
      setEmailCodeSent(true);
      setCooldown(result.resend_after || 60);
      setChallengeId(null);
      setChallengeVersion((current) => current + 1);
      toast.success('如果邮箱已绑定账号，验证码将发送至邮箱');
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSendingCode(false);
    }
  };

  return (
    <>
      <title>登录 - {site.name || '天方云签'}</title>
      <div className='relative isolate min-h-screen overflow-hidden'>
        <div className='pointer-events-none fixed inset-0 -z-10 h-full w-full overflow-hidden bg-gradient-to-br from-indigo-50 via-white to-pink-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900'>
          <div className='absolute left-[-10%] top-[-10%] h-[500px] w-[500px] rounded-full bg-primary-200/40 blur-[100px]' />
          <div className='absolute right-[-10%] top-[20%] h-[400px] w-[400px] rounded-full bg-secondary-200/40 blur-[90px]' />
          <div className='absolute bottom-[-10%] left-[20%] h-[600px] w-[600px] rounded-full bg-pink-200/30 blur-[110px]' />
        </div>
        <PureLayout>
          <motion.div
            initial={{ opacity: 0, y: 20, scale: 0.95 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            transition={{ duration: 0.5, type: 'spring', stiffness: 120, damping: 20 }}
            className='w-[608px] max-w-full overflow-hidden px-2 py-8 md:px-8'
          >
            <HoverEffectCard
              className='items-center gap-4 bg-default-50 pb-6 pt-0'
              maxXRotation={3}
              maxYRotation={3}
            >
              <CardHeader className='inline-block max-w-lg justify-center text-center'>
                <div className='flex w-full items-center justify-center gap-2 pt-10'>
                  <PlatformLogo size='7em' />
                  <div>
                    <span className={title()}>天方&nbsp;</span>
                    <span className={title({ color: 'violet' })}>云签&nbsp;</span>
                  </div>
                </div>
                <ThemeSwitch className='absolute right-4 top-4' />
              </CardHeader>

              <CardBody className='flex gap-5 px-5 py-5 md:px-10'>
                <form className='flex flex-col gap-5' onSubmit={(event) => void submit(event)}>
                  <Tabs
                    fullWidth
                    aria-label='登录方式'
                    color='primary'
                    selectedKey={loginMode}
                    variant='underlined'
                    onSelectionChange={(key) => {
                      setLoginMode(String(key) === 'email' ? 'email' : 'password');
                      setChallengeId(null);
                      setChallengeVersion((current) => current + 1);
                    }}
                  >
                    <Tab key='password' title='密码登录' />
                    <Tab key='email' title='邮箱验证码登录' />
                  </Tabs>
                  <Input
                    autoFocus
                    autoComplete='username'
                    classNames={inputClassNames}
                    isDisabled={submitting}
                    label={loginMode === 'password' ? '用户名或邮箱' : '登录邮箱'}
                    name='username'
                    placeholder={loginMode === 'password' ? '请输入用户名或邮箱' : '请输入绑定邮箱'}
                    radius='lg'
                    size='lg'
                    startContent={loginMode === 'password' ? <IoPersonOutline className='pointer-events-none mb-0.5 shrink-0 text-slate-400' /> : <IoMailOutline className='pointer-events-none mb-0.5 shrink-0 text-slate-400' />}
                    value={username}
                    onValueChange={setUsername}
                  />
                  {loginMode === 'password' ? <Input
                    autoComplete='current-password'
                    classNames={inputClassNames}
                    isDisabled={submitting}
                    label='密码'
                    name='password'
                    placeholder='请输入密码'
                    radius='lg'
                    size='lg'
                    startContent={<IoKeyOutline className='pointer-events-none mb-0.5 shrink-0 text-slate-400' />}
                    type='password'
                    value={password}
                    onValueChange={setPassword}
                  /> : <Input
                    autoComplete='one-time-code'
                    classNames={inputClassNames}
                    endContent={<Button type='button' size='sm' variant='light' color='primary' isLoading={sendingCode} isDisabled={cooldown > 0} onPress={() => void sendEmailCode()}>{cooldown > 0 ? `${cooldown}s` : emailCodeSent ? '重新发送' : '发送验证码'}</Button>}
                    isDisabled={submitting}
                    label='邮箱验证码'
                    name='email_code'
                    placeholder='请输入6位验证码'
                    radius='lg'
                    size='lg'
                    startContent={<IoKeyOutline className='pointer-events-none mb-0.5 shrink-0 text-slate-400' />}
                    value={emailCode}
                    onValueChange={setEmailCode}
                  />}
                  {site.login_challenge_enabled && <HumanVerification key={challengeVersion} disabled={submitting} onVerified={setChallengeId} />}
                  <Button
                    className='mx-10 mt-5 py-7 text-lg'
                    color='primary'
                    isLoading={submitting}
                    isDisabled={loginMode === 'password' && site.login_challenge_enabled && !challengeId}
                    radius='full'
                    size='lg'
                    type='submit'
                    variant='shadow'
                  >
                    {!submitting && (
                      <PlatformLogo className='-ml-8' size='2em' />
                    )}
                    {loginMode === 'password' ? '登录' : '邮箱验证码登录'}
                  </Button>
                  {site.qq_login_enabled && (
                    <div className='space-y-4 pt-1'>
                      <div className='flex items-center gap-3 text-xs text-default-400'>
                        <span className='h-px flex-1 bg-default-200' />
                        <span>其他登录方式</span>
                        <span className='h-px flex-1 bg-default-200' />
                      </div>
                      <Button
                        className='w-full border-sky-200 bg-sky-50/70 text-sky-600 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300'
                        isDisabled={submitting}
                        isLoading={qqSubmitting}
                        radius='full'
                        size='lg'
                        startContent={!qqSubmitting ? <FaQq className='text-lg' /> : undefined}
                        type='button'
                        variant='bordered'
                        onPress={() => void startQqLogin()}
                      >
                        QQ 快捷登录 / 创建账号
                      </Button>
                    </div>
                  )}
                  {site.registration_enabled && (
                    <Button
                      className='w-full'
                      isDisabled={submitting || qqSubmitting}
                      radius='full'
                      type='button'
                      variant='light'
                      onPress={() => navigate('/register')}
                    >
                      没有账号？立即注册
                    </Button>
                  )}
                  <Button
                    className='w-full'
                    isDisabled={submitting || qqSubmitting}
                    radius='full'
                    type='button'
                    variant='light'
                    onPress={() => navigate('/forgot-password')}
                  >
                    忘记密码
                  </Button>
                </form>
              </CardBody>
            </HoverEffectCard>
          </motion.div>
        </PureLayout>
      </div>
    </>
  );
}
