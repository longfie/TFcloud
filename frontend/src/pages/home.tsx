import { Button } from '@heroui/button';
import { motion } from 'motion/react';
import { useState } from 'react';
import {
  LuArrowRight,
  LuBellRing,
  LuBot,
  LuChartNoAxesCombined,
  LuCircleUserRound,
  LuClock3,
  LuCloudSun,
  LuGauge,
  LuLaptop,
  LuMail,
  LuMenu,
  LuMessageCircleQuestion,
  LuPackageCheck,
  LuQrCode,
  LuShieldCheck,
  LuSparkles,
  LuTimer,
  LuX,
} from 'react-icons/lu';
import { Link } from 'react-router-dom';

import legacyHero from '@/assets/home/legacy-hero.jpg';
import PlatformBrandIcon from '@/components/platform_brand_icon';
import PlatformLogo from '@/components/platform_logo';
import CopyrightNotice from '@/components/copyright_notice';
import { ThemeSwitch } from '@/components/theme-switch';
import { useSignAuth } from '@/contexts/auth';
import { useSite } from '@/contexts/site';

const classicFeatures = [
  {
    icon: <LuLaptop />,
    title: '全图形化',
    description: '无需安装 App，只需要一个浏览器，即可管理账号、计划和签到结果。',
  },
  {
    icon: <LuCloudSun />,
    title: '全自动化',
    description: '完成简单设置后，系统会按照自动计划或指定时间执行，无需挂机。',
  },
  {
    icon: <LuGauge />,
    title: '高速高效',
    description: '后台任务持续运行，执行过程和每条结果都可以清楚查看。',
  },
  {
    icon: <LuShieldCheck />,
    title: '稳定可靠',
    description: '签到前自动检查登录状态，账号失效后停止执行并及时提醒。',
  },
];

const newFeatures = [
  { icon: <LuTimer />, title: '定时签到', description: '可为不同账号分别指定每天的执行时间。' },
  { icon: <LuMail />, title: '邮件通知', description: '账号失效主动提醒，每日签到结果可按需接收。' },
  { icon: <LuQrCode />, title: '多种添加方式', description: '根据平台支持扫码、短信、密码或凭证导入。' },
  { icon: <LuBot />, title: '智能助手', description: '在站内直接咨询账号添加和使用问题。' },
  { icon: <LuChartNoAxesCombined />, title: '任务明细', description: '按平台、状态筛选任务，执行结果逐条可查。' },
  { icon: <LuPackageCheck />, title: '多平台管理', description: '账号按平台分类展示，登录方式随平台匹配。' },
];

const platforms = [
  { code: 'tieba', name: '百度贴吧' },
  { code: 'bilibili', name: '哔哩哔哩' },
  { code: 'iqiyi', name: '爱奇艺' },
  { code: 'cloud189', name: '天翼云盘' },
  { code: 'sport', name: '小米运动' },
  { code: 'picacomic', name: '哔咔漫画' },
];

function isVideoBackground (url: string, type: 'auto' | 'image' | 'video') {
  if (type === 'video') return true;
  if (type === 'image') return false;
  return /\.(mp4|webm|ogg|ogv|mov|m4v|m3u8)(?:[?#].*)?$/i.test(url);
}

export default function HomePage () {
  const [mobileOpen, setMobileOpen] = useState(false);
  const { isAuthenticated } = useSignAuth();
  const site = useSite();
  const primaryHref = isAuthenticated ? '/TFYT' : '/login';
  const primaryLabel = isAuthenticated ? '欢迎回来，进入控制台' : '登录';
  const customBackground = site.home_background_url.trim();
  const backgroundIsVideo = customBackground !== '' && isVideoBackground(customBackground, site.home_background_type);

  return (
    <div className='min-h-screen bg-background text-foreground'>
      <header className='absolute inset-x-0 top-0 z-50'>
        <div className='mx-auto flex h-20 max-w-7xl items-center justify-between px-5 lg:px-8'>
          <Link to='/' className='flex items-center gap-3 text-white' aria-label={`${site.name} 首页`}>
            <PlatformLogo size={40} src={site.logo_url || undefined} className='ring-2 ring-white/25' />
            <span className='text-lg font-semibold tracking-wide'>{site.name}</span>
          </Link>

          <nav className='hidden items-center gap-1 lg:flex' aria-label='首页导航'>
            <Button as='a' href='#features' variant='light' className='text-white data-[hover=true]:bg-white/10'>功能特点</Button>
            <Button as='a' href='#new-features' variant='light' className='text-white data-[hover=true]:bg-white/10'>新特性</Button>
            <Button as='a' href='#platforms' variant='light' className='text-white data-[hover=true]:bg-white/10'>支持平台</Button>
            <Button
              as='a'
              href='http://wpa.qq.com/msgrd?v=3&uin=1790716272&site=qq&menu=yes'
              target='_blank'
              rel='noreferrer'
              variant='light'
              className='text-white data-[hover=true]:bg-white/10'
            >
              联系站长
            </Button>
          </nav>

          <div className='flex items-center gap-1'>
            <ThemeSwitch className='text-white' />
            <Button as={Link} to={primaryHref} variant='bordered' className='hidden rounded-full border-white/65 px-5 text-white sm:flex'>
              {isAuthenticated ? '用户中心' : '登录'}
            </Button>
            {!isAuthenticated && site.registration_enabled && (
              <Button as={Link} to='/register' variant='light' className='hidden rounded-full text-white md:flex'>注册</Button>
            )}
            <Button
              isIconOnly
              variant='light'
              className='text-white lg:hidden'
              aria-label={mobileOpen ? '关闭导航' : '打开导航'}
              onPress={() => setMobileOpen((value) => !value)}
            >
              {mobileOpen ? <LuX /> : <LuMenu />}
            </Button>
          </div>
        </div>

        {mobileOpen && (
          <motion.nav
            initial={{ opacity: 0, y: -8 }}
            animate={{ opacity: 1, y: 0 }}
            className='mx-4 flex flex-col gap-1 rounded-2xl border border-white/15 bg-black/55 p-2 shadow-2xl backdrop-blur-xl lg:hidden'
            aria-label='移动端首页导航'
          >
            {[
              ['功能特点', '#features'],
              ['新特性', '#new-features'],
              ['支持平台', '#platforms'],
            ].map(([label, href]) => (
              <Button key={href} as='a' href={href} variant='light' className='justify-start text-white' onPress={() => setMobileOpen(false)}>
                {label}
              </Button>
            ))}
            <Button as={Link} to={primaryHref} variant='bordered' className='mt-1 border-white/50 text-white'>{primaryLabel}</Button>
            {!isAuthenticated && site.registration_enabled && <Button as={Link} to='/register' color='danger'>注册</Button>}
          </motion.nav>
        )}
      </header>

      <main>
        <section
          className='relative isolate flex min-h-[720px] items-center justify-center overflow-hidden bg-slate-950 px-5 py-28 text-center sm:min-h-screen'
        >
          <div className='absolute inset-0 -z-20 overflow-hidden'>
            <img src={legacyHero} alt='' aria-hidden className='h-full w-full object-cover object-center' />
            {customBackground && (backgroundIsVideo
              ? <video key={customBackground} src={customBackground} poster={legacyHero} autoPlay muted loop playsInline preload='metadata' disablePictureInPicture className='absolute inset-0 h-full w-full object-cover object-center' onError={(event) => { event.currentTarget.hidden = true; }} />
              : <img key={customBackground} src={customBackground} alt='' aria-hidden className='absolute inset-0 h-full w-full object-cover object-center' onError={(event) => { event.currentTarget.hidden = true; }} />)}
          </div>
          <div className='absolute inset-0 -z-10 bg-gradient-to-b from-black/55 via-black/30 to-black/45' />
          <div className='absolute inset-0 -z-10 bg-[radial-gradient(circle_at_center,transparent_0%,rgba(0,0,0,0.18)_70%)]' />

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.7, ease: [0.22, 1, 0.36, 1] }}
            className='relative z-10 mx-auto max-w-4xl'
          >
            <PlatformLogo size={88} src={site.logo_url || undefined} className='mx-auto mb-6 shadow-2xl ring-4 ring-white/20' />
            <h1 className='!text-white text-5xl font-semibold tracking-[-0.04em] drop-shadow-lg sm:text-7xl'>{site.name}</h1>
            <p className='mt-5 text-xl font-light tracking-wide text-white/90 sm:text-2xl'>更懂你的云签到</p>
            <p className='mx-auto mt-5 max-w-2xl text-sm leading-7 text-white/75 sm:text-base'>
              高效、多平台、无需挂机。把每天重复的签到任务交给云端，账号状态与执行结果随时可查。
            </p>

            {site.home_announcement?.trim() && (
              <div className='mx-auto mt-6 flex max-w-xl items-start justify-center gap-2 rounded-full border border-white/20 bg-black/15 px-5 py-2.5 text-sm text-white/85 backdrop-blur-md'>
                <LuBellRing className='mt-0.5 shrink-0' />
                <span className='line-clamp-1'>{site.home_announcement}</span>
              </div>
            )}

            <div className='mt-8 flex flex-wrap justify-center gap-3'>
              <Button
                as={Link}
                to={primaryHref}
                size='lg'
                variant='bordered'
                className='h-12 rounded-full border-2 border-white/80 px-8 font-medium text-white data-[hover=true]:bg-white data-[hover=true]:text-black'
              >
                {primaryLabel}
              </Button>
              {!isAuthenticated && site.registration_enabled && (
                <Button
                  as={Link}
                  to='/register'
                  size='lg'
                  variant='bordered'
                  className='h-12 rounded-full border-2 border-white/80 px-8 font-medium text-white data-[hover=true]:bg-white data-[hover=true]:text-black'
                >
                  注册
                </Button>
              )}
            </div>
          </motion.div>

        </section>

        <section id='features' className='scroll-mt-16 bg-background px-5 pb-24 pt-16 text-center'>
          <div className='mx-auto max-w-7xl'>
            <div className='mx-auto max-w-4xl'>
              <p className='text-sm font-semibold uppercase tracking-[0.2em] text-danger'>WHY TIANFANG</p>
              <h2 className='mt-3 text-3xl font-semibold sm:text-4xl'>以下是我们的特点</h2>
              <p className='mt-5 text-sm leading-7 text-default-500 sm:text-base'>
                高效、多功能、无需挂机。无人值守自动执行，支持多个平台账号分类管理，真正把每天的重复操作交给系统。
              </p>
            </div>

            <div className='mt-14 grid gap-10 sm:grid-cols-2 lg:grid-cols-4'>
              {classicFeatures.map((feature, index) => (
                <motion.article
                  key={feature.title}
                  initial={{ opacity: 0, y: 14 }}
                  whileInView={{ opacity: 1, y: 0 }}
                  viewport={{ once: true, amount: 0.25 }}
                  transition={{ delay: index * 0.06 }}
                  className='px-4'
                >
                  <div className='mx-auto grid h-16 w-16 place-items-center text-5xl text-danger'>{feature.icon}</div>
                  <h3 className='mt-5 text-xl font-semibold'>{feature.title}</h3>
                  <p className='mt-3 text-sm leading-7 text-default-500'>{feature.description}</p>
                </motion.article>
              ))}
            </div>
          </div>
        </section>

        <section id='new-features' className='scroll-mt-16 bg-default-50 px-5 py-24 dark:bg-default-100/25'>
          <div className='mx-auto max-w-7xl'>
            <div className='flex flex-col justify-between gap-6 md:flex-row md:items-end'>
              <div className='max-w-2xl'>
                <p className='text-sm font-semibold uppercase tracking-[0.2em] text-danger'>NEW FEATURES</p>
                <h2 className='mt-3 text-3xl font-semibold sm:text-4xl'>熟悉的云签，现在更完整</h2>
                <p className='mt-4 text-sm leading-7 text-default-500'>保留原来简单直接的使用方式，同时补齐账号安全、任务计划、状态通知与智能帮助。</p>
              </div>
              <Button as={Link} to={primaryHref} color='danger' variant='flat' className='w-fit rounded-full px-6' endContent={<LuArrowRight />}>
                {isAuthenticated ? '进入用户中心' : '登录体验'}
              </Button>
            </div>

            <div className='mt-10 grid gap-px overflow-hidden rounded-3xl border border-default-200 bg-default-200 sm:grid-cols-2 lg:grid-cols-3 dark:bg-default-100'>
              {newFeatures.map((feature) => (
                <article key={feature.title} className='group bg-background p-6 transition-colors hover:bg-danger-50/50 dark:hover:bg-danger-500/5 sm:p-7'>
                  <div className='grid h-11 w-11 place-items-center rounded-full bg-danger/10 text-xl text-danger transition-transform group-hover:-translate-y-0.5'>
                    {feature.icon}
                  </div>
                  <h3 className='mt-5 text-lg font-semibold'>{feature.title}</h3>
                  <p className='mt-2 text-sm leading-7 text-default-500'>{feature.description}</p>
                </article>
              ))}
            </div>
          </div>
        </section>

        <section id='platforms' className='scroll-mt-16 bg-background px-5 py-24 text-center'>
          <div className='mx-auto max-w-6xl'>
            <p className='text-sm font-semibold uppercase tracking-[0.2em] text-danger'>SUPPORTED PLATFORMS</p>
            <h2 className='mt-3 text-3xl font-semibold sm:text-4xl'>多个平台，一个账号中心</h2>
            <p className='mx-auto mt-4 max-w-2xl text-sm leading-7 text-default-500'>不同平台会展示对应的添加方式和主题风格，扫码、短信、密码与凭证导入按实际能力提供。</p>
            <div className='mt-10 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6'>
              {platforms.map((platform) => (
                <div key={platform.name} className='rounded-2xl border border-default-200 bg-default-50 p-5 transition-transform hover:-translate-y-1 dark:bg-default-100/30'>
                  <PlatformBrandIcon code={platform.code} name={platform.name} className='mx-auto h-12 w-12 rounded-[15px] shadow-lg ring-1 ring-black/5' />
                  <p className='mt-3 text-sm font-medium'>{platform.name}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        <section className='bg-[#282d36] px-5 py-16 text-center text-white'>
          <div className='mx-auto grid max-w-6xl gap-10 sm:grid-cols-3'>
            {[
              { icon: <LuCircleUserRound />, label: '使用人数', value: site.user_count },
              { icon: <LuSparkles />, label: '今日签到账号数', value: site.today_signed_accounts ?? site.today_signed_users },
              { icon: <LuClock3 />, label: '持续运行天数', value: site.running_days },
            ].map((stat) => (
              <div key={stat.label}>
                <div className='mx-auto mb-3 grid h-10 w-10 place-items-center text-2xl text-danger-300'>{stat.icon}</div>
                <p className='text-sm tracking-wide text-white/60'>{stat.label}</p>
                <p className='mt-2 text-4xl font-light text-white'>{stat.value ?? '—'}</p>
              </div>
            ))}
          </div>
        </section>

        <section className='bg-background px-5 py-20 text-center'>
          <div className='mx-auto max-w-3xl'>
            <LuMessageCircleQuestion className='mx-auto text-5xl text-danger' />
            <h2 className='mt-5 text-3xl font-semibold'>准备好解放双手了吗？</h2>
            <p className='mt-4 text-sm leading-7 text-default-500'>添加平台账号并选择计划，剩下的交给天方云签。</p>
            <div className='mt-7 flex flex-wrap justify-center gap-3'>
              <Button as={Link} to={primaryHref} color='danger' size='lg' className='rounded-full px-8' endContent={<LuArrowRight />}>
                {isAuthenticated ? '进入用户中心' : '立即登录'}
              </Button>
              {!isAuthenticated && site.registration_enabled && (
                <Button as={Link} to='/register' size='lg' variant='bordered' className='rounded-full px-8'>创建账号</Button>
              )}
            </div>
          </div>
        </section>
      </main>

      <footer className='border-t border-default-200 bg-background px-5 py-8'>
        <div className='mx-auto grid max-w-7xl items-center gap-5 text-center text-xs text-default-500 md:grid-cols-[1fr_auto_1fr] md:text-left'>
          <div className='flex items-center justify-center gap-3 md:justify-self-start'>
            <PlatformLogo size={36} src={site.logo_url || undefined} />
            <div>
              <p className='text-sm font-medium text-foreground'>{site.name}</p>
              <p className='mt-0.5'>{site.description}</p>
            </div>
          </div>
          {site.icp_number
            ? <a href='https://beian.miit.gov.cn/' target='_blank' rel='noreferrer' className='justify-self-center transition-colors hover:text-danger'>{site.icp_number}</a>
            : <span aria-hidden />}
          <CopyrightNotice variant='home' className='md:justify-self-end' />
        </div>
      </footer>
    </div>
  );
}
