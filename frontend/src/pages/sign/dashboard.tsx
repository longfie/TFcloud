import { Button } from '@heroui/button';
import { Card, CardBody, CardHeader } from '@heroui/card';
import { Spinner } from '@heroui/spinner';
import { motion } from 'motion/react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'react-hot-toast';
import { LuArrowRight, LuCircleCheck, LuCircleCheckBig, LuCircleUserRound, LuClock3, LuMegaphone, LuPlugZap, LuRocket, LuTriangleAlert } from 'react-icons/lu';
import { useNavigate } from 'react-router-dom';

import { apiRequest } from '@/api/client';
import HoverEffectCard from '@/components/effect_card';
import PlatformBrandIcon from '@/components/platform_brand_icon';
import SignCalendarCard from '@/components/sign/sign_calendar';
import StatusChip from '@/components/sign/status_chip';
import { useSignAuth } from '@/contexts/auth';
import { useSite } from '@/contexts/site';
import type { Paginated, Platform, PluginAccount, SignTask } from '@/types/sign';
import { actionNames, errorMessage, formatDateTime, pluginNames, shortTaskNo } from '@/utils/sign';

export default function DashboardPage () {
  const [accounts, setAccounts] = useState<PluginAccount[]>([]);
  const [tasks, setTasks] = useState<SignTask[]>([]);
  const [platforms, setPlatforms] = useState<Platform[]>([]);
  const [loading, setLoading] = useState(true);
  const { user } = useSignAuth();
  const site = useSite();
  const navigate = useNavigate();

  useEffect(() => {
    Promise.all([
      apiRequest<PluginAccount[]>({ url: '/plugin-accounts' }),
      apiRequest<Paginated<SignTask>>({ url: '/sign-tasks', params: { page: 1, per_page: 8 } }),
      apiRequest<Platform[]>({ url: '/platforms' }),
    ]).then(([accountRows, taskRows, platformRows]) => {
      setAccounts(accountRows);
      setTasks(taskRows.items);
      setPlatforms(platformRows);
    }).catch((error) => toast.error(errorMessage(error))).finally(() => setLoading(false));
  }, []);

  const todayDone = useMemo(
    () => tasks.filter((task) => ['succeeded', 'completed', 'already_done'].includes(task.status)).length,
    [tasks]
  );
  const expiredAccounts = accounts.filter((account) => account.login_status === 'expired' || account.status === 'credential_expired'
    || ['PLUGIN_CREDENTIAL_EXPIRED', 'PLUGIN_CREDENTIAL_INVALID'].includes(account.last_error?.code || ''));

  if (loading) return <div className='flex min-h-72 items-center justify-center'><Spinner label='正在加载控制台' /></div>;

  const stats = [
    { label: '平台账号', value: accounts.length, hint: expiredAccounts.length ? `${expiredAccounts.length} 个登录失效` : '未发现登录失效', icon: <LuCircleUserRound />, color: expiredAccounts.length ? 'text-danger bg-danger/10' : 'text-primary bg-primary/10' },
    { label: '近期任务', value: tasks.length, hint: `${todayDone} 个已完成`, icon: <LuCircleCheckBig />, color: 'text-success bg-success/10' },
    { label: '可用平台', value: platforms.length, hint: `共 ${platforms.length} 个平台`, icon: <LuPlugZap />, color: 'text-secondary bg-secondary/10' },
    { label: '待执行任务', value: tasks.filter((task) => ['pending', 'retrying', 'running'].includes(task.status)).length, hint: '队列实时状态', icon: <LuClock3 />, color: 'text-warning bg-warning/10' },
  ];

  return (
    <div className='flex flex-col gap-6'>
      <motion.section
        initial={{ opacity: 0, y: 14 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1] }}
        className='relative isolate overflow-hidden rounded-[30px] bg-gradient-to-br from-indigo-600 via-primary-500 to-fuchsia-500 p-6 text-white shadow-[0_28px_70px_rgba(79,70,229,0.25)] md:p-9'
      >
        <div className='absolute -right-14 -top-24 -z-10 h-72 w-72 rounded-full border-[42px] border-white/10' />
        <div className='absolute -bottom-24 right-[24%] -z-10 h-56 w-56 rounded-full bg-white/10 blur-2xl' />
        <div className='absolute inset-0 -z-10 bg-[radial-gradient(circle_at_25%_0%,rgba(255,255,255,0.22),transparent_34%)]' />
        <p className='mb-2 inline-flex rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs text-white/85 backdrop-blur-md'>欢迎回来 · 系统已持续运行 {site.running_days ?? 1} 天</p>
        <h2 className='mt-1 !text-white text-2xl font-semibold md:text-4xl'>{user?.username}</h2>
        <p className='mt-3 max-w-2xl text-sm leading-6 text-white/80'>平台账号会按照自动计划或你设置的定时计划执行签到，登录状态失效时会停止执行并及时提示。</p>
        <Button className='tf-soft-button mt-6 bg-white font-medium text-primary' endContent={<LuArrowRight />} onPress={() => navigate('/TFYT/accounts')}>
          管理平台账号
        </Button>
      </motion.section>

      {site.dashboard_announcement?.trim() && <Card className='rounded-[22px] border border-primary/20 bg-primary/5'><CardBody className='flex-row items-start gap-3 p-4'><div className='grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary'><LuMegaphone /></div><div><p className='text-sm font-medium'>系统公告</p><p className='mt-1 whitespace-pre-wrap text-sm leading-6 text-default-500'>{site.dashboard_announcement}</p></div></CardBody></Card>}

      {(!accounts.length || !user?.email || !tasks.length) && (
        <Card className='rounded-[24px] border border-primary/20 bg-gradient-to-r from-primary/8 via-content1 to-secondary/8'>
          <CardBody className='gap-4 p-5 md:p-6'>
            <div className='flex items-center gap-2'>
              <LuRocket className='text-lg text-primary' />
              <h3 className='font-semibold'>快速开始</h3>
              <span className='text-xs text-default-400'>完成以下 3 步，签到就能全自动运行</span>
            </div>
            <div className='grid gap-3 md:grid-cols-3'>
              {[
                { done: accounts.length > 0, title: '绑定第一个平台账号', hint: '扫码或账号密码即可添加', action: '去绑定', href: '/TFYT/accounts' },
                { done: Boolean(user?.email), title: '设置通知邮箱', hint: '登录失效、签到失败时及时提醒你', action: '去设置', href: '/TFYT/profile' },
                { done: tasks.length > 0, title: '完成首次签到', hint: '手动执行一次，或等待自动计划触发', action: '去执行', href: '/TFYT/accounts' },
              ].map((step, index) => (
                <div key={step.title} className={`flex items-start justify-between gap-3 rounded-2xl border p-4 ${step.done ? 'border-success-300/50 bg-success-50/60 dark:border-success-500/25 dark:bg-success-500/10' : 'border-divider bg-content1/60'}`}>
                  <div className='flex items-start gap-2.5'>
                    <span className={`mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-bold ${step.done ? 'bg-success text-white' : 'bg-primary/10 text-primary'}`}>
                      {step.done ? <LuCircleCheck /> : index + 1}
                    </span>
                    <div>
                      <p className={`text-sm font-medium ${step.done ? 'text-success-700 line-through decoration-success-400/60 dark:text-success-300' : ''}`}>{step.title}</p>
                      <p className='mt-0.5 text-xs text-default-400'>{step.hint}</p>
                    </div>
                  </div>
                  {!step.done && <Button size='sm' variant='flat' color='primary' className='shrink-0' onPress={() => navigate(step.href)}>{step.action}</Button>}
                </div>
              ))}
            </div>
          </CardBody>
        </Card>
      )}

      <section className='grid grid-cols-2 gap-3 lg:grid-cols-4'>
        {stats.map((item, index) => (
          <motion.div
            key={item.label}
            initial={{ opacity: 0, y: 14 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.06 + index * 0.045, duration: 0.34 }}
          >
          <HoverEffectCard className='tf-glass-card h-full' maxXRotation={2.2} maxYRotation={2.2} lightStyle={{ width: 180, height: 180 }}>
            <CardBody className='gap-3 p-4 md:p-5'>
              <div className={`flex h-11 w-11 items-center justify-center rounded-2xl text-xl shadow-inner ${item.color}`}>{item.icon}</div>
              <div><p className='text-2xl font-semibold tracking-tight md:text-3xl'>{item.value}</p><p className='text-sm text-default-500'>{item.label}</p></div>
              <p className='text-xs text-default-400'>{item.hint}</p>
            </CardBody>
          </HoverEffectCard>
          </motion.div>
        ))}
      </section>

      <section className='grid gap-6 xl:grid-cols-2'>
        <Card className='tf-glass-card h-full rounded-[26px]'>
          <CardHeader className='flex justify-between px-5 pt-5'>
            <div><h3 className='font-semibold'>最近任务</h3><p className='text-xs text-default-500'>最新签到执行与处理结果</p></div>
            <Button size='sm' variant='light' onPress={() => navigate('/TFYT/tasks')}>查看全部</Button>
          </CardHeader>
          <CardBody className='gap-1 px-3 pb-4'>
            {tasks.length === 0 && <p className='py-12 text-center text-sm text-default-400'>还没有签到任务</p>}
            {tasks.slice(0, 7).map((task) => (
              <button key={task.task_no} className='group flex items-center gap-3 rounded-xl px-3 py-2 text-left transition-all duration-200 hover:translate-x-1 hover:bg-white/60 dark:hover:bg-white/[0.045]' onClick={() => navigate(`/TFYT/tasks/${task.task_no}`)}>
                <div className='min-w-0 flex-1'>
                  <p className='truncate text-sm font-medium'>{pluginNames[task.plugin_code] || task.plugin_code} · {actionNames[task.action] || task.action}</p>
                  <p className='mt-0.5 truncate text-xs text-default-400'>{shortTaskNo(task.task_no)} · {formatDateTime(task.created_at)}</p>
                </div>
                <StatusChip status={task.status} />
              </button>
            ))}
          </CardBody>
        </Card>

        <SignCalendarCard />
      </section>

      <Card className='tf-glass-card rounded-[26px]'>
        <CardHeader className='flex flex-wrap items-center justify-between gap-3 px-5 pt-5'>
          <div><h3 className='font-semibold'>账号登录状态</h3><p className='text-xs text-default-500'>只展示正常与失效两种状态</p></div>
          <div className='flex min-w-56 flex-1 items-center gap-3 md:max-w-xs md:flex-none'>
            <span className='flex shrink-0 items-center gap-1.5 text-sm'><LuTriangleAlert className={expiredAccounts.length ? 'text-danger' : 'text-success'} />失效 {expiredAccounts.length} / {accounts.length}</span>
            <div className='h-2.5 flex-1 overflow-hidden rounded-full bg-success-100/70 p-0.5 dark:bg-success-500/10'><motion.div initial={{ width: 0 }} animate={{ width: `${accounts.length ? (expiredAccounts.length / accounts.length) * 100 : 0}%` }} transition={{ duration: 0.7, delay: 0.15 }} className='h-full rounded-full bg-gradient-to-r from-danger-400 to-danger shadow-[0_0_12px_rgba(239,68,68,0.28)]' /></div>
          </div>
        </CardHeader>
        <CardBody className='px-5 pb-5'>
          <div className='grid gap-2 sm:grid-cols-2 xl:grid-cols-3'>
            {platforms.map((platform) => {
              const count = accounts.filter((account) => account.plugin_code === platform.code).length;
              const expired = expiredAccounts.filter((account) => account.plugin_code === platform.code).length;
              return <div key={platform.code} className='flex items-center justify-between gap-2 rounded-xl border border-divider/60 px-3 py-2 transition hover:bg-white/50 dark:hover:bg-white/[0.035]'><span className='flex min-w-0 items-center gap-2 text-sm'><PlatformBrandIcon code={platform.code} name={platform.name} fallback={platform.name.slice(0, 1)} className='h-7 w-7 shrink-0 rounded-lg shadow-sm' fallbackClassName='bg-default-100 text-[10px] font-semibold' /><span className='truncate'>{platform.name}</span></span><div className='flex shrink-0 items-center gap-2'><span className='text-xs text-default-400'>{count} 个账号</span><span className={`rounded-full px-2 py-1 text-[11px] font-medium ${expired ? 'bg-danger-500/10 text-danger-700 dark:text-danger-300' : 'bg-success-500/10 text-success-700 dark:text-success-300'}`}>{expired ? `${expired} 个失效` : '无失效'}</span></div></div>;
            })}
          </div>
        </CardBody>
      </Card>
    </div>
  );
}
