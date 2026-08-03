import { Card, CardBody, CardHeader } from '@heroui/card';
import { Spinner } from '@heroui/spinner';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'react-hot-toast';
import { LuCircleAlert, LuCircleUserRound, LuListChecks } from 'react-icons/lu';

import { apiRequest } from '@/api/client';
import StatusChip from '@/components/sign/status_chip';
import type { AdminOverview } from '@/types/sign';
import { errorMessage, pluginNames } from '@/utils/sign';

export default function AdminOverviewPage () {
  const [data, setData] = useState<AdminOverview | null>(null);
  useEffect(() => { apiRequest<AdminOverview>({ url: '/admin/overview' }).then(setData).catch((error) => toast.error(errorMessage(error))); }, []);
  const accountTotal = useMemo(() => data?.plugin_accounts.reduce((sum, item) => sum + item.total, 0) || 0, [data]);
  if (!data) return <div className='flex min-h-60 items-center justify-center'><Spinner label='正在加载管理概览' /></div>;
  const stats = [
    ['用户总数', data.users.total, `${data.users.active} 个活跃`, <LuCircleUserRound />],
    ['平台账号', accountTotal, `${data.plugin_accounts.length} 个状态分组`, <LuListChecks />],
    ['队列等待', data.queue.pending, `${data.queue.running} 个运行中`, <LuCircleAlert />],
  ] as const;
  return (
    <div className='flex flex-col gap-5'>
      <div className='grid grid-cols-2 gap-3 lg:grid-cols-4'>{stats.map(([label, value, hint, icon]) => <Card key={label} className='border border-default-200/60 shadow-sm'><CardBody className='gap-2 p-4'><div className='text-xl text-primary'>{icon}</div><p className='text-2xl font-semibold'>{value.toLocaleString()}</p><p className='text-sm'>{label}</p><p className='text-xs text-default-400'>{hint}</p></CardBody></Card>)}</div>
      <div className='grid gap-5 xl:grid-cols-2'>
        <Card className='border border-default-200/60 shadow-sm'><CardHeader className='px-5 pt-5'><h3 className='font-semibold'>账号分布</h3></CardHeader><CardBody className='gap-2 px-5'>{data.plugin_accounts.map((item) => <div key={`${item.plugin_code}-${item.status}`} className='flex items-center justify-between rounded-xl bg-default-100 px-3 py-2'><span className='text-sm'>{pluginNames[item.plugin_code] || item.plugin_code}</span><div className='flex items-center gap-2'><span className='text-sm font-medium'>{item.total}</span><StatusChip status={item.status} /></div></div>)}</CardBody></Card>
        <Card className='border border-default-200/60 shadow-sm'><CardHeader className='px-5 pt-5'><h3 className='font-semibold'>今日任务</h3></CardHeader><CardBody className='gap-2 px-5'>{Object.entries(data.tasks_today).length === 0 && <p className='py-10 text-center text-sm text-default-400'>今天还没有任务</p>}{Object.entries(data.tasks_today).map(([status, total]) => <div key={status} className='flex items-center justify-between rounded-xl bg-default-100 px-3 py-2'><StatusChip status={status} /><span className='text-sm font-semibold'>{total}</span></div>)}</CardBody></Card>
      </div>
    </div>
  );
}
