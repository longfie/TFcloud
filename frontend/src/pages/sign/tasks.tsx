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
import type { Paginated, Platform, SignTask } from '@/types/sign';
import { actionNames, errorMessage, formatDateTime, pluginNames, shortTaskNo } from '@/utils/sign';

const perPage = 15;

export default function TasksPage () {
  const [result, setResult] = useState<Paginated<SignTask> | null>(null);
  const [platforms, setPlatforms] = useState<Platform[]>([]);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState('all');
  const [pluginCode, setPluginCode] = useState('all');
  const [page, setPage] = useState(1);
  const [busy, setBusy] = useState('');
  const navigate = useNavigate();

  const load = useCallback(async () => {
    const taskPage = await apiRequest<Paginated<SignTask>>({
      url: '/sign-tasks',
      params: {
        page,
        per_page: perPage,
        status: status === 'all' ? undefined : status,
        plugin_code: pluginCode === 'all' ? undefined : pluginCode,
      },
    });
    setResult(taskPage);
  }, [page, pluginCode, status]);

  useEffect(() => {
    setLoading(true);
    load().catch((error) => toast.error(errorMessage(error))).finally(() => setLoading(false));
  }, [load]);
  useEffect(() => {
    apiRequest<Platform[]>({ url: '/platforms' }).then(setPlatforms).catch((error) => toast.error(errorMessage(error)));
  }, []);

  const operate = async (task: SignTask, action: 'retry' | 'cancel') => {
    setBusy(task.task_no);
    try {
      await apiRequest<SignTask>({ method: 'POST', url: `/sign-tasks/${task.task_no}/${action}` });
      toast.success(action === 'retry' ? '任务已重新排队' : '任务已取消');
      await load();
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setBusy('');
    }
  };

  const tasks = result?.items ?? [];
  const totalPages = result?.total_pages ?? Math.max(1, Math.ceil((result?.total ?? 0) / perPage));
  const platformOptions = [{ code: 'all', name: '全部平台' }, ...platforms];

  return (
    <div className='flex flex-col gap-5'>
      <div className='flex flex-col justify-between gap-3 lg:flex-row lg:items-end'>
        <div><h2 className='text-xl font-semibold'>签到任务</h2><p className='mt-1 text-sm text-default-500'>按平台和状态查看每次签到结果，共 {result?.total ?? 0} 条</p></div>
        <div className='flex flex-col gap-2 sm:flex-row'>
          <Select items={platformOptions} aria-label='平台筛选' className='w-full sm:w-44' selectedKeys={[pluginCode]} onSelectionChange={(keys) => { setPluginCode(String(Array.from(keys)[0] || 'all')); setPage(1); }}>
            {(platform) => <SelectItem key={platform.code}>{platform.name}</SelectItem>}
          </Select>
          <Select aria-label='任务状态' className='w-full sm:w-44' selectedKeys={[status]} onSelectionChange={(keys) => { setStatus(String(Array.from(keys)[0] || 'all')); setPage(1); }}>
            <SelectItem key='all'>全部状态</SelectItem>
            <SelectItem key='pending'>等待中</SelectItem>
            <SelectItem key='running'>执行中</SelectItem>
            <SelectItem key='completed'>任务完成</SelectItem>
            <SelectItem key='failed'>失败</SelectItem>
            <SelectItem key='cancelled'>已取消</SelectItem>
          </Select>
          <Tooltip content='刷新任务列表'><Button isIconOnly variant='flat' aria-label='刷新任务列表' onPress={() => void load()}><LuRefreshCw /></Button></Tooltip>
        </div>
      </div>
      <Card className='tf-glass-card tf-table-card'>
        <CardBody className='overflow-x-auto p-0'>
          {loading
            ? <div className='flex min-h-72 items-center justify-center'><Spinner label='正在加载签到任务' /></div>
            : <>
            <div className='flex flex-col gap-2 p-3 md:hidden'>
              {!tasks.length && <p className='py-10 text-center text-sm text-default-400'>暂无符合条件的任务</p>}
              {tasks.map((task) => (
                <div key={task.task_no} role='button' tabIndex={0} className='cursor-pointer rounded-2xl border border-divider bg-content1/60 p-4 text-left transition active:scale-[0.99]' onClick={() => navigate(`/TFYT/tasks/${task.task_no}`)} onKeyDown={(event) => { if (event.key === 'Enter') navigate(`/TFYT/tasks/${task.task_no}`); }}>
                  <div className='flex items-center justify-between gap-2'>
                    <p className='text-sm font-medium'>{pluginNames[task.plugin_code] || task.plugin_code} · {actionNames[task.action] || task.action}</p>
                    <StatusChip status={task.status} />
                  </div>
                  <p className='mt-2 text-xs text-default-500'>{task.counts.success + task.counts.already} 成功 / {task.counts.failed} 失败 · {formatDateTime(task.created_at)}</p>
                  <div className='mt-2 flex items-center justify-between gap-2'>
                    <span className='font-mono text-[11px] text-default-400'>{shortTaskNo(task.task_no)}</span>
                    <span className='flex items-center gap-0.5' onClick={(event) => event.stopPropagation()}>
                      {['failed', 'cancelled'].includes(task.status) && (
                        <Tooltip content='重新执行任务'>
                          <Button isIconOnly size='sm' variant='light' color='warning' className='h-7 w-7 min-w-7' aria-label='重新执行任务' isLoading={busy === task.task_no} onPress={() => void operate(task, 'retry')}>
                            <LuRefreshCw className='text-sm' />
                          </Button>
                        </Tooltip>
                      )}
                      {['pending', 'retrying'].includes(task.status) && (
                        <Tooltip content='取消等待中的任务'>
                          <Button isIconOnly size='sm' variant='light' color='danger' className='h-7 w-7 min-w-7' aria-label='取消等待中的任务' isLoading={busy === task.task_no} onPress={() => void operate(task, 'cancel')}>
                            <LuSquare className='text-sm' />
                          </Button>
                        </Tooltip>
                      )}
                    </span>
                  </div>
                </div>
              ))}
            </div>
            <div className='hidden md:block'>
            <Table aria-label='签到任务列表' removeWrapper>
              <TableHeader><TableColumn>任务</TableColumn><TableColumn>平台 / 动作</TableColumn><TableColumn>状态</TableColumn><TableColumn>结果</TableColumn><TableColumn>创建时间</TableColumn><TableColumn align='end'>操作</TableColumn></TableHeader>
              <TableBody emptyContent='暂无符合条件的任务'>
                {tasks.map((task) => (
                  <TableRow key={task.task_no}>
                    <TableCell><button className='font-mono text-xs text-primary hover:underline' onClick={() => navigate(`/TFYT/tasks/${task.task_no}`)}>{shortTaskNo(task.task_no)}</button></TableCell>
                    <TableCell><div><p className='text-sm'>{pluginNames[task.plugin_code] || task.plugin_code}</p><p className='text-xs text-default-400'>{actionNames[task.action] || task.action}</p></div></TableCell>
                    <TableCell><StatusChip status={task.status} /></TableCell>
                    <TableCell><span className='text-xs text-default-500'>{task.counts.success + task.counts.already} 成功 / {task.counts.failed} 失败</span></TableCell>
                    <TableCell><span className='whitespace-nowrap text-xs'>{formatDateTime(task.created_at)}</span></TableCell>
                    <TableCell><div className='flex justify-end gap-1'>
                      <Tooltip content='查看任务详情'><Button isIconOnly size='sm' variant='light' aria-label='查看任务详情' onPress={() => navigate(`/TFYT/tasks/${task.task_no}`)}><LuEye /></Button></Tooltip>
                      {['failed', 'cancelled'].includes(task.status) && <Tooltip content='重新执行任务'><Button isIconOnly size='sm' variant='light' color='warning' className='h-7 w-7 min-w-7' aria-label='重新执行任务' isLoading={busy === task.task_no} onPress={() => void operate(task, 'retry')}><LuRefreshCw className='text-sm' /></Button></Tooltip>}
                      {['pending', 'retrying'].includes(task.status) && <Tooltip content='取消等待中的任务'><Button isIconOnly size='sm' variant='light' color='danger' className='h-7 w-7 min-w-7' aria-label='取消等待中的任务' isLoading={busy === task.task_no} onPress={() => void operate(task, 'cancel')}><LuSquare className='text-sm' /></Button></Tooltip>}
                    </div></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            </div>
            </>}
        </CardBody>
      </Card>
      {totalPages > 1 && <div className='flex justify-center'><Pagination showControls page={page} total={totalPages} onChange={setPage} /></div>}
    </div>
  );
}
