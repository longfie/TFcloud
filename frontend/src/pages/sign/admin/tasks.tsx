import { Button } from '@heroui/button';
import { Card, CardBody } from '@heroui/card';
import { Pagination } from '@heroui/pagination';
import { Select, SelectItem } from '@heroui/select';
import { Spinner } from '@heroui/spinner';
import { Table, TableBody, TableCell, TableColumn, TableHeader, TableRow } from '@heroui/table';
import { Tooltip } from '@heroui/tooltip';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-hot-toast';
import { LuEye, LuRefreshCw, LuSquare } from 'react-icons/lu';
import { useNavigate } from 'react-router-dom';

import { apiRequest } from '@/api/client';
import StatusChip from '@/components/sign/status_chip';
import type { Paginated, Plugin, SignTask } from '@/types/sign';
import { actionNames, errorMessage, formatDateTime, pluginNames, shortTaskNo } from '@/utils/sign';

type AdminTask = SignTask & { user_id: number };
const perPage = 20;

export default function AdminTasksPage () {
  const [result, setResult] = useState<Paginated<AdminTask> | null>(null);
  const [plugins, setPlugins] = useState<Plugin[]>([]);
  const [status, setStatus] = useState('all');
  const [pluginCode, setPluginCode] = useState('all');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [busy, setBusy] = useState('');
  const navigate = useNavigate();

  const load = useCallback(async () => {
    setResult(await apiRequest<Paginated<AdminTask>>({
      url: '/admin/sign-tasks',
      params: {
        page,
        per_page: perPage,
        status: status === 'all' ? undefined : status,
        plugin_code: pluginCode === 'all' ? undefined : pluginCode,
      },
    }));
  }, [page, pluginCode, status]);

  useEffect(() => {
    setLoading(true);
    load().catch((error) => toast.error(errorMessage(error))).finally(() => setLoading(false));
  }, [load]);
  useEffect(() => {
    apiRequest<Plugin[]>({ url: '/plugins' }).then(setPlugins).catch((error) => toast.error(errorMessage(error)));
  }, []);

  const operate = async (task: AdminTask, action: 'retry' | 'cancel') => {
    setBusy(task.task_no);
    try {
      await apiRequest<SignTask>({ method: 'POST', url: `/admin/sign-tasks/${task.task_no}/${action}` });
      toast.success(action === 'retry'
        ? (task.plugin_code === 'tieba' ? '已创建任务，将重新获取当前关注的贴吧后执行' : '已创建完整重跑任务')
        : '任务已取消');
      await load();
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setBusy('');
    }
  };

  const refresh = async () => {
    setRefreshing(true);
    try {
      await load();
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setRefreshing(false);
    }
  };

  const totalPages = result?.total_pages ?? Math.max(1, Math.ceil((result?.total ?? 0) / perPage));
  const pluginOptions = [{ code: 'all', name: '全部插件' }, ...plugins];

  return (
    <div className='flex flex-col gap-4'>
      <div className='flex flex-wrap items-center justify-between gap-2'>
        <p className='text-sm text-default-500'>共 {result?.total ?? 0} 条任务</p>
        <div className='flex flex-wrap gap-2'><Select items={pluginOptions} aria-label='插件筛选' className='w-44' selectedKeys={[pluginCode]} onSelectionChange={(keys) => { setPluginCode(String(Array.from(keys)[0] || 'all')); setPage(1); }}>{(plugin) => <SelectItem key={plugin.code}>{plugin.name}</SelectItem>}</Select><Select aria-label='状态筛选' className='w-40' selectedKeys={[status]} onSelectionChange={(keys) => { setStatus(String(Array.from(keys)[0] || 'all')); setPage(1); }}><SelectItem key='all'>全部状态</SelectItem><SelectItem key='pending'>等待中</SelectItem><SelectItem key='running'>运行中</SelectItem><SelectItem key='completed'>任务完成</SelectItem><SelectItem key='failed'>失败</SelectItem><SelectItem key='cancelled'>已取消</SelectItem></Select><Tooltip content='刷新任务列表'><Button isIconOnly variant='flat' aria-label='刷新任务列表' isLoading={refreshing} onPress={() => void refresh()}><LuRefreshCw /></Button></Tooltip></div>
      </div>
      <Card className='border border-default-200/60 shadow-sm'><CardBody className='p-0'>
        {loading
          ? <div className='flex min-h-60 items-center justify-center'><Spinner label='正在加载全局任务' /></div>
          : <Table aria-label='全局任务列表' removeWrapper><TableHeader><TableColumn>任务</TableColumn><TableColumn>用户 / 账号</TableColumn><TableColumn>平台 / 动作</TableColumn><TableColumn>状态</TableColumn><TableColumn>错误</TableColumn><TableColumn>更新时间</TableColumn><TableColumn align='end'>操作</TableColumn></TableHeader><TableBody emptyContent='暂无任务'>{(result?.items ?? []).map((task) => { const rerunLabel = task.plugin_code === 'tieba' ? '重新获取关注贴吧并执行' : '重新完整执行任务'; return <TableRow key={task.task_no}><TableCell><span className='font-mono text-xs'>{shortTaskNo(task.task_no)}</span></TableCell><TableCell><p className='text-xs'>UID {task.user_id}</p><p className='text-xs text-default-400'>账号 #{task.account_id}</p></TableCell><TableCell><p className='text-sm'>{pluginNames[task.plugin_code] || task.plugin_code}</p><p className='text-xs text-default-400'>{actionNames[task.action] || task.action}</p></TableCell><TableCell><StatusChip status={task.status} /></TableCell><TableCell><p className='max-w-64 truncate text-xs text-danger'>{task.last_error?.message || '—'}</p></TableCell><TableCell><span className='whitespace-nowrap text-xs'>{formatDateTime(task.updated_at)}</span></TableCell><TableCell><div className='flex justify-end gap-1'><Tooltip content='查看任务详情'><Button isIconOnly size='sm' variant='light' aria-label='查看任务详情' onPress={() => navigate(`/TFYT/admin/tasks/${task.task_no}`)}><LuEye /></Button></Tooltip>{['succeeded', 'completed', 'failed', 'cancelled'].includes(task.status) && <Tooltip content={rerunLabel}><Button isIconOnly size='sm' variant='light' color='warning' isLoading={busy === task.task_no} aria-label={rerunLabel} onPress={() => void operate(task, 'retry')}><LuRefreshCw /></Button></Tooltip>}{['pending', 'retrying'].includes(task.status) && <Tooltip content='取消等待中的任务'><Button isIconOnly size='sm' variant='light' color='danger' isLoading={busy === task.task_no} aria-label='取消等待中的任务' onPress={() => void operate(task, 'cancel')}><LuSquare /></Button></Tooltip>}</div></TableCell></TableRow>; })}</TableBody></Table>}
      </CardBody></Card>
      {totalPages > 1 && <div className='flex justify-center'><Pagination showControls page={page} total={totalPages} onChange={setPage} /></div>}
    </div>
  );
}
