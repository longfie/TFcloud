import { Button } from '@heroui/button';
import { CardBody, CardHeader } from '@heroui/card';
import { motion } from 'motion/react';
import { useMemo } from 'react';
import {
  LuArrowLeft,
  LuCloudOff,
  LuHouse,
  LuLayoutDashboard,
  LuRefreshCw,
} from 'react-icons/lu';
import { Link, useLocation, useNavigate } from 'react-router-dom';

import HoverEffectCard from '@/components/effect_card';
import PlatformLogo from '@/components/platform_logo';
import { title } from '@/components/primitives';
import { ThemeSwitch } from '@/components/theme-switch';
import { useSignAuth } from '@/contexts/auth';
import { useSite } from '@/contexts/site';
import PureLayout from '@/layouts/pure';

const tips = [
  '今天的签到计划里，没有这一页的排班。',
  '任务列表翻到底了，还是没遇见它。',
  '这页可能翘班了，系统没法替它打卡。',
  '链接好像走过期，像失效的登录凭据一样。',
  '助手说：迷路不算失败，换条路继续签到。',
];

function pickTip (pathname: string) {
  let hash = 0;
  for (let index = 0; index < pathname.length; index += 1) {
    hash = (hash + pathname.charCodeAt(index) * (index + 1)) % tips.length;
  }
  return tips[hash] ?? tips[0];
}

export default function NotFoundPage () {
  const location = useLocation();
  const navigate = useNavigate();
  const site = useSite();
  const { isAuthenticated, loading } = useSignAuth();
  const embedded = location.pathname.startsWith('/TFYT');
  const tip = useMemo(() => pickTip(location.pathname), [location.pathname]);
  const consoleHref = isAuthenticated ? '/TFYT' : '/login';
  const consoleLabel = isAuthenticated ? '回到控制台' : '去登录';

  const content = (
    <motion.div
      initial={{ opacity: 0, y: 18, scale: 0.96 }}
      animate={{ opacity: 1, y: 0, scale: 1 }}
      transition={{ duration: 0.45, type: 'spring', stiffness: 120, damping: 18 }}
      className={embedded ? 'mx-auto w-full max-w-2xl' : 'w-[608px] max-w-full overflow-hidden px-2 py-8 md:px-8'}
    >
      <HoverEffectCard
        className='items-center gap-3 bg-default-50 pb-7 pt-0'
        maxXRotation={3}
        maxYRotation={3}
      >
        <CardHeader className='relative inline-block w-full justify-center text-center'>
          {!embedded && <ThemeSwitch className='absolute right-4 top-4' />}
          <div className='flex w-full flex-col items-center gap-4 pt-10'>
            <div className='relative'>
              <PlatformLogo size={embedded ? '4.5em' : '6em'} src={site.logo_url || undefined} />
              <motion.div
                aria-hidden
                className='absolute -bottom-1 -right-1 flex h-10 w-10 items-center justify-center rounded-2xl bg-warning-100 text-warning-600 shadow-md dark:bg-warning-500/20 dark:text-warning-300'
                animate={{ y: [0, -4, 0], rotate: [0, -6, 0] }}
                transition={{ duration: 2.4, repeat: Infinity, ease: 'easeInOut' }}
              >
                <LuCloudOff className='text-xl' />
              </motion.div>
            </div>
            <div>
              <div className='inline-block max-w-lg text-center'>
                <span className={title({ size: embedded ? 'sm' : 'md' })}>页面&nbsp;</span>
                <span className={title({ color: 'violet', size: embedded ? 'sm' : 'md' })}>走丢&nbsp;</span>
                <span className={title({ size: embedded ? 'sm' : 'md' })}>了</span>
              </div>
              <p className='mt-2 text-sm text-default-500'>这一页没有排进今日签到</p>
            </div>
          </div>
        </CardHeader>

        <CardBody className='flex flex-col gap-5 px-5 pb-2 pt-1 md:px-10'>
          <div className='rounded-2xl border border-default-200/70 bg-default-100/60 px-4 py-3 text-left dark:bg-default/40'>
            <div className='flex flex-wrap items-center gap-2 text-xs font-medium tracking-wide text-default-500'>
              <span className='rounded-full bg-danger-100 px-2.5 py-0.5 text-danger-600 dark:bg-danger-500/20 dark:text-danger-300'>
                404
              </span>
              <span>TASK_NOT_SCHEDULED</span>
            </div>
            <p className='mt-3 text-sm leading-relaxed text-default-700 dark:text-default-300'>{tip}</p>
            <p className='mt-2 break-all font-[JetBrains_Mono,ui-monospace,monospace] text-xs text-default-400'>
              {location.pathname}{location.search}
            </p>
          </div>

          <div className='flex flex-wrap justify-center gap-2'>
            <Button
              variant='flat'
              startContent={<LuArrowLeft />}
              onPress={() => {
                if (window.history.length > 1) navigate(-1);
                else navigate(embedded ? '/TFYT' : '/', { replace: true });
              }}
            >
              返回上一页
            </Button>
            {!embedded && (
              <Button as={Link} to='/' variant='flat' startContent={<LuHouse />}>
                回首页
              </Button>
            )}
            {!loading && (
              <Button
                as={Link}
                to={consoleHref}
                color='primary'
                startContent={isAuthenticated ? <LuLayoutDashboard /> : <LuRefreshCw />}
              >
                {consoleLabel}
              </Button>
            )}
          </div>
          <p className='text-center text-xs text-default-400'>
            {site.name || '天方云签'} · 找不到页面，不耽误明天继续签到
          </p>
        </CardBody>
      </HoverEffectCard>
    </motion.div>
  );

  if (embedded) {
    return (
      <>
        <title>页面未找到 - {site.name || '天方云签'}</title>
        <div className='flex min-h-[60vh] items-center justify-center py-6'>{content}</div>
      </>
    );
  }

  return (
    <>
      <title>页面未找到 - {site.name || '天方云签'}</title>
      <div className='relative isolate min-h-screen overflow-hidden'>
        <div className='pointer-events-none fixed inset-0 -z-10 h-full w-full overflow-hidden bg-gradient-to-br from-indigo-50 via-white to-pink-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900'>
          <div className='absolute left-[-10%] top-[-10%] h-[500px] w-[500px] rounded-full bg-primary-200/40 blur-[100px]' />
          <div className='absolute right-[-10%] top-[20%] h-[400px] w-[400px] rounded-full bg-secondary-200/40 blur-[90px]' />
          <div className='absolute bottom-[-10%] left-[20%] h-[600px] w-[600px] rounded-full bg-pink-200/30 blur-[110px]' />
        </div>
        <PureLayout>{content}</PureLayout>
      </div>
    </>
  );
}
