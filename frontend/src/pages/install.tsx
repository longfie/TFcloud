import { Button } from '@heroui/button';
import { CardBody, CardHeader } from '@heroui/card';
import { Checkbox } from '@heroui/checkbox';
import { Input } from '@heroui/input';
import { AnimatePresence, motion } from 'motion/react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'react-hot-toast';
import {
  LuArrowRight,
  LuCheck,
  LuCircleAlert,
  LuClock3,
  LuDatabase,
  LuKeyRound,
  LuRocket,
  LuRefreshCw,
  LuServer,
  LuShieldCheck,
  LuSparkles,
  LuTriangleAlert,
  LuUserRound,
  LuZap,
} from 'react-icons/lu';
import { Navigate, useNavigate } from 'react-router-dom';

import { apiRequest } from '@/api/client';
import HoverEffectCard from '@/components/effect_card';
import PlatformLogo from '@/components/platform_logo';
import { title } from '@/components/primitives';
import { ThemeSwitch } from '@/components/theme-switch';
import PureLayout from '@/layouts/pure';
import type {
  InstallChecksResult,
  InstallResult,
  InstallStatus,
} from '@/types/sign';
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

const steps = [
  { key: 'checks', label: '环境检测', short: '检测', icon: LuServer },
  { key: 'database', label: '数据库', short: '数据库', icon: LuDatabase },
  { key: 'site', label: '站点与管理员', short: '管理员', icon: LuShieldCheck },
  { key: 'done', label: '启动完成', short: '完成', icon: LuRocket },
] as const;

type StepKey = (typeof steps)[number]['key'];

const stepCopy: Record<Exclude<StepKey, 'done'>, { heading: string; urgency: string }> = {
  checks: {
    heading: '环境监测中，确保项目能够正常运行起来～',
    urgency: '缺扩展或目录不可写，后面的签到任务都会原地熄火...',
  },
  database: {
    heading: '数据库连接',
    urgency: '选择你的👖，把数据装进去',
  },
  site: {
    heading: '最后一步：请老哥输入管理员账号密码',
    urgency: '欢迎使用天方云签，简单、便捷、轻量的帮你完成云任务。使用过程中如果遇到问题请提交issue或者联系龙辉 qq：1790716272',
  },
};

export default function InstallPage () {
  const navigate = useNavigate();
  const [bootLoading, setBootLoading] = useState(true);
  const [status, setStatus] = useState<InstallStatus | null>(null);
  const [step, setStep] = useState<StepKey>('checks');
  const [checks, setChecks] = useState<InstallChecksResult | null>(null);
  const [checking, setChecking] = useState(false);
  const [testingDb, setTestingDb] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [installResult, setInstallResult] = useState<InstallResult | null>(null);

  const [dbHost, setDbHost] = useState('127.0.0.1');
  const [dbPort, setDbPort] = useState('3306');
  const [dbName, setDbName] = useState('tf_sign');
  const [dbUser, setDbUser] = useState('tf_sign');
  const [dbPassword, setDbPassword] = useState('');
  const [dbSocket, setDbSocket] = useState('');
  const [siteUrl, setSiteUrl] = useState(() => window.location.origin);
  const [sessionSecure, setSessionSecure] = useState(() => window.location.protocol === 'https:');
  const [adminUsername, setAdminUsername] = useState('admin');
  const [adminPassword, setAdminPassword] = useState('');
  const [adminPasswordConfirm, setAdminPasswordConfirm] = useState('');
  const [adminDisplayName, setAdminDisplayName] = useState('系统管理员');

  const stepIndex = useMemo(() => steps.findIndex((item) => item.key === step), [step]);
  const remaining = Math.max(0, steps.length - 1 - stepIndex);
  const failedCheckItems = useMemo(
    () => (checks?.checks || []).filter((item) => !item.ok),
    [checks]
  );
  const failedChecks = failedCheckItems.length;
  const totalChecks = checks?.checks.length || 0;
  const passedChecks = Math.max(0, totalChecks - failedChecks);
  const progress = ((stepIndex + 1) / steps.length) * 100;

  const loadStatus = async () => {
    const current = await apiRequest<InstallStatus>({ url: '/install/status' });
    setStatus(current);
    return current;
  };

  const loadChecks = async () => {
    setChecking(true);
    try {
      const result = await apiRequest<InstallChecksResult>({ url: '/install/checks' });
      setChecks(result);
      return result;
    } finally {
      setChecking(false);
    }
  };

  useEffect(() => {
    void (async () => {
      try {
        const current = await loadStatus();
        if (!current.installed) {
          await loadChecks();
        }
      } catch (error) {
        toast.error(errorMessage(error));
      } finally {
        setBootLoading(false);
      }
    })();
  }, []);

  if (!bootLoading && status?.installed && step !== 'done') {
    return <Navigate to='/login' replace />;
  }

  const databasePayload = {
    host: dbHost.trim(),
    port: Number(dbPort) || 3306,
    name: dbName.trim(),
    user: dbUser.trim(),
    password: dbPassword,
    socket: dbSocket.trim(),
  };

  const testDatabase = async () => {
    setTestingDb(true);
    try {
      const result = await apiRequest<{ ok: boolean; server_version?: string; message: string }>({
        method: 'POST',
        url: '/install/test-database',
        data: databasePayload,
      });
      toast.success(result.server_version ? `${result.message}（${result.server_version}）` : result.message);
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setTestingDb(false);
    }
  };

  const runInstall = async () => {
    if (adminPassword !== adminPasswordConfirm) {
      toast.error('两次输入的管理员密码不一致');
      return;
    }
    setSubmitting(true);
    try {
      const result = await apiRequest<InstallResult>({
        method: 'POST',
        url: '/install',
        data: {
          site_url: siteUrl.trim(),
          session_secure: sessionSecure,
          database: databasePayload,
          admin: {
            username: adminUsername.trim(),
            password: adminPassword,
            display_name: adminDisplayName.trim(),
          },
        },
      });
      setInstallResult(result);
      setStep('done');
      toast.success('安装完成，天方云签已点亮');
      const latest = await loadStatus();
      setStatus(latest);
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSubmitting(false);
    }
  };

  const currentCopy = step === 'done' ? null : stepCopy[step];

  return (
    <>
      <title>立即安装 - 天方云签</title>
      <div className='relative isolate min-h-screen'>
        <div className='pointer-events-none fixed inset-0 -z-10 overflow-hidden bg-gradient-to-br from-rose-50 via-white to-indigo-50 dark:from-gray-950 dark:via-gray-900 dark:to-slate-950'>
          <motion.div
            className='absolute left-[-12%] top-[-14%] h-[520px] w-[520px] rounded-full bg-danger-300/30 blur-[110px] dark:bg-danger-500/20'
            animate={{ scale: [1, 1.08, 1], opacity: [0.45, 0.7, 0.45] }}
            transition={{ duration: 7, repeat: Infinity, ease: 'easeInOut' }}
          />
          <motion.div
            className='absolute right-[-10%] top-[18%] h-[420px] w-[420px] rounded-full bg-secondary-300/35 blur-[100px] dark:bg-secondary-500/20'
            animate={{ scale: [1.05, 0.95, 1.05], opacity: [0.4, 0.65, 0.4] }}
            transition={{ duration: 8, repeat: Infinity, ease: 'easeInOut' }}
          />
          <motion.div
            className='absolute bottom-[-12%] left-[18%] h-[560px] w-[560px] rounded-full bg-primary-200/35 blur-[120px] dark:bg-primary-500/15'
            animate={{ y: [0, -18, 0] }}
            transition={{ duration: 9, repeat: Infinity, ease: 'easeInOut' }}
          />
        </div>

        <PureLayout>
          <motion.div
            initial={{ opacity: 0, y: 22, scale: 0.97 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            transition={{ duration: 0.5, type: 'spring', stiffness: 120, damping: 18 }}
            className='w-[760px] max-w-full px-2 md:px-6'
          >
            <HoverEffectCard className='items-stretch gap-0 overflow-hidden bg-default-50/95 pb-0 pt-0 shadow-2xl shadow-danger-500/10' maxXRotation={2} maxYRotation={2}>
              <div className='relative overflow-hidden border-b border-danger-200/60 bg-gradient-to-r from-danger-500 via-warning-500 to-secondary-500 px-5 py-3 text-white dark:border-danger-500/30'>
                <div className='absolute inset-0 bg-[linear-gradient(110deg,transparent,rgba(255,255,255,0.18),transparent)] bg-[length:200%_100%] animate-[shine_3.5s_linear_infinite]' />
                <div className='relative flex flex-wrap items-center justify-between gap-2 text-sm font-medium'>
                  <span className='inline-flex items-center gap-2'>
                    <motion.span
                      className='inline-flex h-2.5 w-2.5 rounded-full bg-white'
                      animate={{ scale: [1, 1.35, 1], opacity: [1, 0.55, 1] }}
                      transition={{ duration: 1.2, repeat: Infinity }}
                    />
                    系统尚未就绪 · 签到服务停在起点
                  </span>
                  <span className='inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-0.5 text-xs backdrop-blur'>
                    <LuClock3 />
                    {step === 'done' ? '即将上线' : `还差 ${remaining} 步启动`}
                  </span>
                </div>
              </div>

              <CardHeader className='relative block w-full px-5 pb-2 pt-8 text-center md:px-10'>
                <ThemeSwitch className='absolute right-4 top-4' />
                <div className='flex flex-col items-center gap-4'>
                  <div className='relative'>
                    <motion.div
                      className='absolute -inset-3 rounded-[2rem] bg-gradient-to-br from-danger-400/30 via-secondary-400/20 to-primary-400/30 blur-md'
                      animate={{ opacity: [0.35, 0.75, 0.35], rotate: [0, 4, 0] }}
                      transition={{ duration: 4.5, repeat: Infinity, ease: 'easeInOut' }}
                    />
                    <div className='relative rounded-[1.75rem] bg-white/80 p-2 shadow-lg ring-1 ring-white/60 dark:bg-default/40 dark:ring-white/10'>
                      <PlatformLogo size='4.5em' />
                    </div>
                    <motion.div
                      className='absolute -bottom-1 -right-1 flex h-9 w-9 items-center justify-center rounded-2xl bg-danger-500 text-white shadow-lg shadow-danger-500/40'
                      animate={{ y: [0, -3, 0] }}
                      transition={{ duration: 1.8, repeat: Infinity, ease: 'easeInOut' }}
                    >
                      <LuZap className='text-lg' />
                    </motion.div>
                  </div>

                  <div>
                    <div className='inline-flex items-center gap-1.5 rounded-full border border-danger-200/80 bg-danger-50 px-3 py-1 text-[11px] font-semibold tracking-wide text-danger-600 dark:border-danger-500/30 dark:bg-danger-500/15 dark:text-danger-300'>
                      <LuTriangleAlert />
                      未安装
                    </div>
                    <div className='mt-3'>
                      <span className={title({ size: 'sm' })}>开启&nbsp;</span>
                      <span className={title({ color: 'violet', size: 'sm' })}>天方&nbsp;</span>
                      <span className={title({ size: 'sm' })}>云签</span>
                    </div>
                    <p className='mx-auto mt-2 max-w-md text-sm leading-relaxed text-default-500'>
                      现在完成引导，几分钟后就能开始托管多平台签到。拖下去，站点会一直停在不可用状态。
                    </p>
                  </div>
                </div>
              </CardHeader>

              <CardBody className='flex flex-col gap-5 px-5 pb-8 pt-2 md:px-10'>
                <div>
                  <div className='mb-2 flex items-center justify-between text-xs text-default-500'>
                    <span>启动进度</span>
                    <span className='font-semibold text-danger-500'>{Math.round(progress)}%</span>
                  </div>
                  <div className='h-2.5 w-full overflow-hidden rounded-full bg-default-200/80'>
                    <motion.div
                      className='h-full rounded-full bg-gradient-to-r from-danger-500 via-warning-500 to-secondary-500'
                      initial={false}
                      animate={{ width: `${progress}%` }}
                      transition={{ type: 'spring', stiffness: 120, damping: 18 }}
                    />
                  </div>
                </div>

                <div className='grid grid-cols-4 gap-2'>
                  {steps.map((item, index) => {
                    const Icon = item.icon;
                    const active = index === stepIndex;
                    const done = index < stepIndex;
                    return (
                      <div
                        key={item.key}
                        className={`relative rounded-2xl border px-2 py-3 text-center transition-all ${
                          active
                            ? 'border-danger-300 bg-danger-50 shadow-md shadow-danger-500/10 dark:border-danger-500/40 dark:bg-danger-500/15'
                            : done
                              ? 'border-success-200 bg-success-50/80 dark:border-success-500/30 dark:bg-success-500/10'
                              : 'border-default-200/80 bg-default-100/40 dark:bg-default/20'
                        }`}
                      >
                        <div className={`mx-auto mb-1.5 flex h-8 w-8 items-center justify-center rounded-xl text-sm ${
                          active
                            ? 'bg-danger-500 text-white'
                            : done
                              ? 'bg-success-500 text-white'
                              : 'bg-default-200 text-default-500'
                        }`}
                        >
                          {done ? <LuCheck /> : <Icon />}
                        </div>
                        <p className={`text-[11px] font-medium ${active ? 'text-danger-700 dark:text-danger-200' : 'text-default-500'}`}>
                          {item.short}
                        </p>
                        {active && (
                          <motion.span
                            className='absolute inset-x-3 -bottom-0.5 h-0.5 rounded-full bg-danger-500'
                            layoutId='install-step-underline'
                          />
                        )}
                      </div>
                    );
                  })}
                </div>

                {bootLoading && (
                  <div className='rounded-2xl border border-default-200/70 bg-default-100/50 px-4 py-6 text-center text-sm text-default-500'>
                    正在探查安装状态…
                  </div>
                )}

                <AnimatePresence mode='wait'>
                  {!bootLoading && step !== 'done' && currentCopy && (
                    <motion.div
                      key={step + '-copy'}
                      initial={{ opacity: 0, y: 10 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0, y: -8 }}
                      transition={{ duration: 0.22 }}
                      className='rounded-2xl border border-warning-200/80 bg-gradient-to-br from-warning-50 to-danger-50/60 px-4 py-3 dark:border-warning-500/20 dark:from-warning-500/10 dark:to-danger-500/10'
                    >
                      <p className='inline-flex items-center gap-1.5 text-sm font-semibold text-warning-800 dark:text-warning-200'>
                        <LuSparkles />
                        {currentCopy.heading}
                      </p>
                      <p className='mt-1 text-xs leading-relaxed text-warning-700/90 dark:text-warning-200/80'>
                        {currentCopy.urgency}
                      </p>
                    </motion.div>
                  )}
                </AnimatePresence>

                <AnimatePresence mode='wait'>
                  {!bootLoading && step === 'checks' && (
                    <motion.div
                      key='checks'
                      initial={{ opacity: 0, x: 16 }}
                      animate={{ opacity: 1, x: 0 }}
                      exit={{ opacity: 0, x: -16 }}
                      className='flex flex-col gap-4'
                    >
                      <div className={`rounded-[24px] border p-4 ${
                        checks?.passed
                          ? 'border-success-200/80 bg-success-50/70 dark:border-success-500/20 dark:bg-success-500/10'
                          : 'border-danger-300/80 bg-danger-50/70 dark:border-danger-500/30 dark:bg-danger-500/10'
                      }`}>
                        <div className='flex items-center gap-3'>
                          <div className={`grid h-11 w-11 shrink-0 place-items-center rounded-2xl text-xl text-white ${checks?.passed ? 'bg-success' : 'bg-danger'}`}>
                            {checks?.passed ? <LuCheck /> : <LuCircleAlert />}
                          </div>
                          <div className='min-w-0 flex-1'>
                            <p className='text-sm font-semibold'>{checks?.passed ? '运行环境已就绪' : '运行环境需要处理'}</p>
                            <p className='mt-0.5 text-xs text-default-500'>已通过 {passedChecks}/{totalChecks} 项检测{failedChecks > 0 ? `，还有 ${failedChecks} 项异常` : ''}</p>
                          </div>
                          <span className={`rounded-full px-3 py-1 text-xs font-semibold ${checks?.passed ? 'bg-success/15 text-success-700 dark:text-success-300' : 'bg-danger/15 text-danger-700 dark:text-danger-300'}`}>
                            {checks?.passed ? '全部通过' : '未通过'}
                          </span>
                        </div>
                        <div className='mt-4 flex flex-wrap gap-2'>
                          {(checks?.checks || []).map((item, index) => (
                            <motion.div
                              key={item.key}
                              title={item.detail}
                              initial={{ opacity: 0, scale: 0.94 }}
                              animate={{ opacity: 1, scale: 1 }}
                              transition={{ delay: index * 0.025 }}
                              className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs ${
                                item.ok
                                  ? 'border-success-200 bg-success-50 text-success-700 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300'
                                  : 'border-danger-300 bg-danger-100 text-danger-700 dark:border-danger-500/40 dark:bg-danger-500/15 dark:text-danger-300'
                              }`}
                            >
                              {item.ok ? <LuCheck className='shrink-0' /> : <LuCircleAlert className='shrink-0' />}
                              <span>{item.label}</span>
                            </motion.div>
                          ))}
                        </div>
                      </div>

                      {failedCheckItems.length > 0 && (
                        <div className='rounded-2xl border border-danger-200/80 bg-danger-50/50 p-3 dark:border-danger-500/20 dark:bg-danger-500/5'>
                          <p className='mb-2 text-xs font-semibold text-danger'>需要处理</p>
                          <div className='grid gap-2 sm:grid-cols-2'>
                            {failedCheckItems.map((item) => (
                              <div key={item.key} className='rounded-xl bg-background/70 px-3 py-2'>
                                <p className='text-xs font-medium'>{item.label}</p>
                                <p className='mt-0.5 break-all text-[11px] leading-relaxed text-default-500'>{item.detail}</p>
                              </div>
                            ))}
                          </div>
                        </div>
                      )}

                      <div className='flex flex-wrap justify-center gap-2'>
                        <Button
                          variant='flat'
                          startContent={<LuRefreshCw />}
                          isLoading={checking}
                          onPress={() => void loadChecks()}
                        >
                          重新检测
                        </Button>
                        <Button
                          color='danger'
                          variant='shadow'
                          className='font-semibold'
                          endContent={<LuArrowRight />}
                          isDisabled={!checks?.passed}
                          onPress={() => setStep('database')}
                        >
                          环境已就绪，去接通数据库
                        </Button>
                      </div>
                    </motion.div>
                  )}

                  {!bootLoading && step === 'database' && (
                    <motion.div
                      key='database'
                      initial={{ opacity: 0, x: 16 }}
                      animate={{ opacity: 1, x: 0 }}
                      exit={{ opacity: 0, x: -16 }}
                      className='flex flex-col gap-4'
                    >
                      <div className='grid gap-3 md:grid-cols-2'>
                        <Input classNames={inputClassNames} label='数据库主机' value={dbHost} onValueChange={setDbHost} />
                        <Input classNames={inputClassNames} label='端口' value={dbPort} onValueChange={setDbPort} />
                        <Input classNames={inputClassNames} label='数据库名' value={dbName} onValueChange={setDbName} />
                        <Input classNames={inputClassNames} label='用户名' value={dbUser} onValueChange={setDbUser} />
                        <Input
                          classNames={inputClassNames}
                          label='密码'
                          type='password'
                          value={dbPassword}
                          onValueChange={setDbPassword}
                        />
                        <Input
                          classNames={inputClassNames}
                          label='Unix Socket（可选）'
                          placeholder='例如 /tmp/mysql.sock'
                          value={dbSocket}
                          onValueChange={setDbSocket}
                        />
                      </div>
                      <p className='text-xs text-default-400'>
                        请准备空库。填写 Socket 时优先走 Socket。需要 MySQL 8（utf8mb4_0900_ai_ci）。
                      </p>
                      <div className='flex flex-wrap justify-center gap-2'>
                        <Button variant='flat' onPress={() => setStep('checks')}>上一步</Button>
                        <Button variant='flat' isLoading={testingDb} startContent={<LuDatabase />} onPress={() => void testDatabase()}>
                          测试连接
                        </Button>
                        <Button color='danger' variant='shadow' className='font-semibold' endContent={<LuArrowRight />} onPress={() => setStep('site')}>
                          数据库已通，去创建管理员
                        </Button>
                      </div>
                    </motion.div>
                  )}

                  {!bootLoading && step === 'site' && (
                    <motion.div
                      key='site'
                      initial={{ opacity: 0, x: 16 }}
                      animate={{ opacity: 1, x: 0 }}
                      exit={{ opacity: 0, x: -16 }}
                      className='flex flex-col gap-4'
                    >
                      <Input
                        classNames={inputClassNames}
                        label='站点地址'
                        description='写入 CORS 白名单，建议填写正式访问域名'
                        startContent={<LuServer className='text-default-400' />}
                        value={siteUrl}
                        onValueChange={setSiteUrl}
                      />
                      <Checkbox isSelected={sessionSecure} onValueChange={setSessionSecure}>
                        启用安全 Cookie（HTTPS 环境请保持开启）
                      </Checkbox>
                      <div className='grid gap-3 md:grid-cols-2'>
                        <Input
                          classNames={inputClassNames}
                          label='管理员用户名'
                          startContent={<LuUserRound className='text-default-400' />}
                          value={adminUsername}
                          onValueChange={setAdminUsername}
                        />
                        <Input
                          classNames={inputClassNames}
                          label='显示名称'
                          value={adminDisplayName}
                          onValueChange={setAdminDisplayName}
                        />
                        <Input
                          classNames={inputClassNames}
                          label='管理员密码'
                          type='password'
                          startContent={<LuKeyRound className='text-default-400' />}
                          value={adminPassword}
                          onValueChange={setAdminPassword}
                        />
                        <Input
                          classNames={inputClassNames}
                          label='确认密码'
                          type='password'
                          value={adminPasswordConfirm}
                          onValueChange={setAdminPasswordConfirm}
                        />
                      </div>
                      <div className='rounded-2xl border border-danger-300/80 bg-danger-50 px-4 py-3 text-sm text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-200'>
                        <p className='font-semibold'>如果后续需要修改数据库、web进程等，请手动更改.env</p>
                        <p className='mt-1 text-xs leading-relaxed opacity-90'>
                          确认后将生成密钥、写入配置、导入表结构并创建管理员。请确认目标库为空，否则会出现未知错误。
                        </p>
                      </div>
                      <div className='flex flex-wrap justify-center gap-2'>
                        <Button variant='flat' onPress={() => setStep('database')}>上一步</Button>
                        <Button
                          color='danger'
                          variant='shadow'
                          size='lg'
                          className='min-w-[220px] font-semibold'
                          isLoading={submitting}
                          startContent={!submitting ? <LuRocket /> : undefined}
                          onPress={() => void runInstall()}
                        >
                          启动天方云签
                        </Button>
                      </div>
                    </motion.div>
                  )}

                  {!bootLoading && step === 'done' && (
                    <motion.div
                      key='done'
                      initial={{ opacity: 0, scale: 0.96 }}
                      animate={{ opacity: 1, scale: 1 }}
                      className='flex flex-col gap-5 text-center'
                    >
                      <motion.div
                        className='mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-gradient-to-br from-success-400 to-primary-500 text-white shadow-xl shadow-success-500/30'
                        animate={{ scale: [1, 1.06, 1] }}
                        transition={{ duration: 1.8, repeat: Infinity, ease: 'easeInOut' }}
                      >
                        <LuRocket className='text-3xl' />
                      </motion.div>
                      <div>
                        <h2 className='text-2xl font-semibold'>云签已点亮</h2>
                        <p className='mt-2 text-sm text-default-500'>
                          {installResult?.message || '配置已写入。还差最后一下：重启后端，正式开张。'}
                        </p>
                      </div>
                      <div className='rounded-2xl border border-success-200/80 bg-success-50/80 px-4 py-4 text-left text-sm dark:border-success-500/20 dark:bg-success-500/10'>
                        <p className='font-medium'>管理员：{installResult?.admin_username || adminUsername}</p>
                        <p className='mt-2 text-default-600 dark:text-default-300'>
                          请立即执行 <code className='rounded bg-default-100 px-1.5 py-0.5 text-xs dark:bg-default/50'>php start.php restart</code>
                          ，或在面板 / Docker 中重启后端。不重启的话，密钥与 Worker 可能仍未生效。
                        </p>
                      </div>
                      <Button
                        color='primary'
                        variant='shadow'
                        size='lg'
                        className='self-center font-semibold'
                        endContent={<LuArrowRight />}
                        onPress={() => navigate('/login', { replace: true })}
                      >
                        重启后去登录
                      </Button>
                    </motion.div>
                  )}
                </AnimatePresence>
              </CardBody>
            </HoverEffectCard>
          </motion.div>
        </PureLayout>
      </div>

      <style>{`
        @keyframes shine {
          0% { background-position: 200% 0; }
          100% { background-position: -200% 0; }
        }
      `}</style>
    </>
  );
}
