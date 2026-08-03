import { Avatar } from '@heroui/avatar';
import { Button } from '@heroui/button';
import { Dropdown, DropdownItem, DropdownMenu, DropdownTrigger } from '@heroui/dropdown';
import { Spinner } from '@heroui/spinner';
import clsx from 'clsx';
import { AnimatePresence, motion } from 'motion/react';
import { Suspense, useMemo, useState } from 'react';
import {
  LuBookOpenCheck,
  LuCircleUserRound,
  LuClipboardList,
  LuBot,
  LuLayoutDashboard,
  LuLogOut,
  LuMenu,
  LuShieldCheck,
  LuX,
} from 'react-icons/lu';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';

import logo from '@/assets/platform_logo';
import CopyrightNotice from '@/components/copyright_notice';
import PlatformLogo from '@/components/platform_logo';
import { ThemeSwitch } from '@/components/theme-switch';
import { useSignAuth } from '@/contexts/auth';
import { useSite } from '@/contexts/site';

interface NavItem {
  label: string;
  href: string;
  icon: React.ReactNode;
  admin?: boolean;
  assistant?: boolean;
}

const navItems: NavItem[] = [
  { label: '控制台', href: '/TFYT', icon: <LuLayoutDashboard /> },
  { label: '平台账号', href: '/TFYT/accounts', icon: <LuCircleUserRound /> },
  { label: '签到任务', href: '/TFYT/tasks', icon: <LuClipboardList /> },
  { label: '智能助手', href: '/TFYT/assistant', icon: <LuBot />, assistant: true },
  { label: '个人设置', href: '/TFYT/profile', icon: <LuShieldCheck /> },
  { label: '管理后台', href: '/TFYT/admin', icon: <LuBookOpenCheck />, admin: true },
];

const titles: Record<string, string> = {
  '/TFYT': '控制台',
  '/TFYT/accounts': '平台账号',
  '/TFYT/tasks': '签到任务',
  '/TFYT/assistant': '智能助手',
  '/TFYT/profile': '个人设置',
  '/TFYT/admin': '管理后台',
};

export default function SignLayout () {
  const [mobileOpen, setMobileOpen] = useState(false);
  const { user, logout } = useSignAuth();
  const site = useSite();
  const location = useLocation();
  const navigate = useNavigate();
  const visibleNav = useMemo(
    () => navItems.filter((item) => (
      (!item.admin || user?.role === 'admin')
      && (!item.assistant || site.assistant_enabled || user?.role === 'admin')
    )),
    [site.assistant_enabled, user?.role]
  );
  const currentTitle = location.pathname.startsWith('/TFYT/tasks/')
    ? '任务详情'
    : location.pathname.startsWith('/TFYT/admin')
      ? '管理后台'
      : (titles[location.pathname] || '天方云签');
  const userQq = String(user?.qq || '').trim();
  const userAvatar = /^\d{5,12}$/.test(userQq)
    ? `https://q1.qlogo.cn/g?b=qq&nk=${encodeURIComponent(userQq)}&s=640`
    : undefined;

  const doLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
  };

  const sidebar = (
    <div className='flex h-full flex-col px-3 py-4'>
      <div className='mb-6 flex items-center gap-3 px-2.5 pt-1'>
        <div className='relative flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-primary/20 to-secondary/15 shadow-inner'>
          <span className='absolute inset-0 rounded-2xl ring-1 ring-inset ring-white/70 dark:ring-white/10' />
          <PlatformLogo size={32} src={site.logo_url || logo} />
        </div>
        <div>
          <p className='max-w-40 truncate text-lg font-semibold leading-tight'>{site.name}</p>
          <p className='max-w-40 truncate text-xs text-default-500'>{site.description}</p>
        </div>
      </div>
      <nav className='flex flex-1 flex-col gap-1.5'>
        {visibleNav.map((item) => (
          <NavLink
            key={item.href}
            to={item.href}
            end={item.href === '/TFYT'}
            onClick={() => setMobileOpen(false)}
            className={({ isActive }) => clsx(
              'group relative flex items-center gap-3 overflow-hidden rounded-2xl px-3 py-3 text-sm font-medium transition-all duration-200',
              isActive
                ? 'bg-gradient-to-r from-primary to-secondary text-white shadow-lg shadow-primary/20'
                : 'text-default-600 hover:bg-white/60 hover:text-foreground dark:hover:bg-white/[0.06]'
            )}
          >
            <span className='relative z-10 text-lg transition-transform duration-200 group-hover:scale-110'>{item.icon}</span>
            <span className='relative z-10'>{item.label}</span>
          </NavLink>
        ))}
      </nav>
    </div>
  );

  return (
    <div className='min-h-screen text-foreground'>
      <aside className='tf-glass fixed bottom-3 left-3 top-3 z-40 hidden w-[252px] overflow-hidden rounded-[28px] lg:block'>
        {sidebar}
      </aside>
      <AnimatePresence>
        {mobileOpen && (
          <motion.div className='fixed inset-0 z-50 lg:hidden' initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
            <button aria-label='关闭导航' className='absolute inset-0 bg-slate-950/45 backdrop-blur-sm' onClick={() => setMobileOpen(false)} />
            <motion.aside
              initial={{ x: -320, opacity: 0.6 }}
              animate={{ x: 0, opacity: 1 }}
              exit={{ x: -320, opacity: 0.6 }}
              transition={{ type: 'spring', stiffness: 330, damping: 34 }}
              className='tf-glass absolute bottom-3 left-3 top-3 w-[min(19rem,calc(100vw-1.5rem))] overflow-hidden rounded-[28px] shadow-2xl'
            >
              <Button isIconOnly variant='light' className='absolute right-3 top-3 z-10' onPress={() => setMobileOpen(false)}><LuX /></Button>
              {sidebar}
            </motion.aside>
          </motion.div>
        )}
      </AnimatePresence>

      <div className='lg:pl-[276px]'>
        <header className='sticky top-0 z-30 p-3 pb-0 md:px-5'>
          <div className='tf-glass flex h-16 items-center justify-between rounded-[22px] px-3 md:px-5'>
          <div className='flex items-center gap-3'>
            <Button isIconOnly variant='flat' className='rounded-xl lg:hidden' onPress={() => setMobileOpen(true)}>
              <LuMenu className='text-xl' />
            </Button>
            <div>
              <h1 className='font-semibold'>{currentTitle}</h1>
              <p className='hidden text-xs text-default-500 sm:block'>统一管理平台账号与签到任务</p>
            </div>
          </div>
          <div className='flex items-center gap-2'>
            <ThemeSwitch />
            <Dropdown placement='bottom-end'>
              <DropdownTrigger>
                <Button variant='light' className='h-11 min-w-0 gap-2 rounded-2xl px-2 sm:px-3'>
                  <Avatar size='sm' src={userAvatar} name={user?.username || 'U'} />
                  <span className='hidden max-w-28 truncate text-sm sm:block'>{user?.username}</span>
                </Button>
              </DropdownTrigger>
              <DropdownMenu aria-label='用户菜单'>
                <DropdownItem key='profile' onPress={() => navigate('/TFYT/profile')}>个人设置</DropdownItem>
                <DropdownItem key='logout' color='danger' startContent={<LuLogOut />} onPress={() => void doLogout()}>
                  退出登录
                </DropdownItem>
              </DropdownMenu>
            </Dropdown>
          </div>
          </div>
        </header>
        <main className='mx-auto flex min-h-[calc(100vh-5rem)] w-full max-w-[1500px] flex-col p-4 md:p-6 lg:p-7'>
          <Suspense fallback={<div className='flex flex-1 items-center justify-center'><Spinner label='正在加载页面' /></div>}>
            <div className='flex flex-1 flex-col'>
              <Outlet />
              <CopyrightNotice variant='backend' className='mt-auto' />
            </div>
          </Suspense>
        </main>
      </div>
    </div>
  );
}
