import { Tab, Tabs } from '@heroui/tabs';
import { useMemo } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';

const sections = [
  { key: '/TFYT/admin', label: '概览' },
  { key: '/TFYT/admin/users', label: '用户' },
  { key: '/TFYT/admin/accounts', label: '平台账号' },
  { key: '/TFYT/admin/plugins', label: '插件中心' },
  { key: '/TFYT/admin/tasks', label: '任务' },
  { key: '/TFYT/admin/settings', label: '网站设置' },
  { key: '/TFYT/admin/version', label: '版本信息' },
  { key: '/TFYT/admin/mail', label: '邮件中心' },
  { key: '/TFYT/admin/audit', label: '审计日志' },
];

export default function AdminLayoutPage () {
  const location = useLocation();
  const navigate = useNavigate();
  const selected = useMemo(() => sections.find((section) => section.key === location.pathname)?.key
    || (location.pathname.startsWith('/TFYT/admin/tasks/') ? '/TFYT/admin/tasks' : '/TFYT/admin'), [location.pathname]);
  return (
    <div className='flex flex-col gap-5'>
      <div><h2 className='text-xl font-semibold'>系统管理</h2><p className='mt-1 text-sm text-default-500'>管理用户、平台账号和全局签到运行状态</p></div>
      <Tabs
        aria-label='管理后台导航'
        selectedKey={selected}
        onSelectionChange={(key) => navigate(String(key))}
        variant='solid'
        classNames={{ tabList: 'tf-glass gap-1 rounded-2xl p-1.5', cursor: 'bg-gradient-to-r from-primary to-secondary shadow-md shadow-primary/20', tab: 'h-9 px-4', tabContent: 'group-data-[selected=true]:text-white' }}
      >
        {sections.map((section) => <Tab key={section.key} title={section.label} />)}
      </Tabs>
      <Outlet />
    </div>
  );
}
