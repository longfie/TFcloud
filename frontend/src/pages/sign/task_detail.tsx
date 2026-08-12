import { Button } from '@heroui/button';
import { Card, CardBody, CardHeader } from '@heroui/card';
import { Pagination } from '@heroui/pagination';
import { Select, SelectItem } from '@heroui/select';
import { Spinner } from '@heroui/spinner';
import { Table, TableBody, TableCell, TableColumn, TableHeader, TableRow } from '@heroui/table';
import { Tab, Tabs } from '@heroui/tabs';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-hot-toast';
import { LuArrowLeft, LuRefreshCw, LuSquare } from 'react-icons/lu';
import { useNavigate, useParams } from 'react-router-dom';

import { apiRequest, type LoadingScope } from '@/api/client';
import StatusChip from '@/components/sign/status_chip';
import type { Paginated, SignRecord, SignRun, SignTask } from '@/types/sign';
import { actionNames, errorMessage, formatDateTime, pluginNames } from '@/utils/sign';

const pageSize = 15;

export default function TaskDetailPage ({ admin = false }: { admin?: boolean }) {
  const { taskNo = '' } = useParams();
  const [task, setTask] = useState<SignTask | null>(null);
  const [runs, setRuns] = useState<Paginated<SignRun> | null>(null);
  const [records, setRecords] = useState<Paginated<SignRecord> | null>(null);
  const [runPage, setRunPage] = useState(1);
  const [recordPage, setRecordPage] = useState(1);
  const [recordStatus, setRecordStatus] = useState('all');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const navigate = useNavigate();
  const apiBase = admin ? '/admin/sign-tasks' : '/sign-tasks';
  const backPath = admin ? '/TFYT/admin/tasks' : '/TFYT/tasks';

  const load = useCallback(async (loadingScope: LoadingScope = 'local') => {
    const [taskData, runRows, recordRows] = await Promise.all([
      apiRequest<SignTask>({ url: `${apiBase}/${taskNo}`, loadingScope }),
      apiRequest<Paginated<SignRun>>({ url: `${apiBase}/${taskNo}/runs`, params: { page: runPage, per_page: pageSize }, loadingScope }),
      apiRequest<Paginated<SignRecord>>({
        url: `${apiBase}/${taskNo}/records`,
        loadingScope,
        params: {
          page: recordPage,
          per_page: pageSize,
          status: recordStatus === 'all' ? undefined : recordStatus,
        },
      }),
    ]);
    setTask(taskData);
    setRuns(runRows);
    setRecords(recordRows);
  }, [apiBase, recordPage, recordStatus, runPage, taskNo]);

  useEffect(() => {
    setLoading(true);
    load().catch((error) => toast.error(errorMessage(error))).finally(() => setLoading(false));
  }, [load]);

  useEffect(() => {
    if (!task || !['pending', 'retrying', 'running'].includes(task.status)) return;
    const timer = window.setInterval(() => {
      void load('silent').catch(() => undefined);
    }, 1500);
    return () => window.clearInterval(timer);
  }, [load, task?.status]);

  const operate = async (action: 'retry' | 'cancel') => {
    setBusy(true);
    try {
      const result = await apiRequest<SignTask>({ method: 'POST', url: `${apiBase}/${taskNo}/${action}` });
      toast.success(action === 'retry'
        ? (admin
            ? (task?.plugin_code === 'tieba' ? '已创建任务，将重新获取当前关注的贴吧后执行' : '已创建完整重跑任务')
            : '任务已重新排队')
        : '任务已取消');
      if (admin && action === 'retry' && result.task_no !== taskNo) {
        navigate(`/TFYT/admin/tasks/${result.task_no}`, { replace: true });
        return;
      }
      await load();
    } catch (error) { toast.error(errorMessage(error)); } finally { setBusy(false); }
  };

  if (loading && !task) return <div className='flex min-h-72 items-center justify-center'><Spinner label='正在加载任务详情' /></div>;
  if (!task) return <p className='py-20 text-center text-default-500'>任务不存在或无权访问</p>;

  const recordPages = records?.total_pages ?? Math.max(1, Math.ceil((records?.total ?? 0) / pageSize));
  const runPages = runs?.total_pages ?? Math.max(1, Math.ceil((runs?.total ?? 0) / pageSize));
  const canRetry = admin
    ? ['succeeded', 'completed', 'failed', 'cancelled'].includes(task.status)
    : ['failed', 'cancelled'].includes(task.status);

  return (
    <div className='flex flex-col gap-5'>
      <div className='flex flex-wrap items-center justify-between gap-3'>
        <Button variant='light' startContent={<LuArrowLeft />} onPress={() => navigate(backPath)}>返回任务列表</Button>
        <div className='flex gap-1.5'>
          {canRetry && <Button size='sm' color='warning' variant='light' startContent={<LuRefreshCw className='text-sm' />} isLoading={busy} onPress={() => void operate('retry')}>{admin ? (task.plugin_code === 'tieba' ? '重新获取贴吧并执行' : '完整重跑') : '重试'}</Button>}
          {['pending', 'retrying'].includes(task.status) && <Button size='sm' color='danger' variant='light' startContent={<LuSquare className='text-sm' />} isLoading={busy} onPress={() => void operate('cancel')}>取消</Button>}
        </div>
      </div>
      <Card className='border border-default-200/60 shadow-sm'>
        <CardHeader className='flex flex-col items-start gap-2 px-6 pt-6 sm:flex-row sm:items-center sm:justify-between'><div><p className='break-all font-mono text-xs text-default-400'>{task.task_no}</p><h2 className='mt-2 text-xl font-semibold'>{pluginNames[task.plugin_code] || task.plugin_code} · {actionNames[task.action] || task.action}</h2></div><StatusChip status={task.status} size='md' /></CardHeader>
        <CardBody className='grid gap-3 px-6 pb-6 sm:grid-cols-2 lg:grid-cols-4'>
          <Info label='账号 ID' value={`#${task.account_id}`} /><Info label='触发方式' value={task.trigger_type} /><Info label='创建时间' value={formatDateTime(task.created_at)} />
          <Info label='成功' value={String(task.counts.success)} /><Info label='已完成' value={String(task.counts.already)} /><Info label='跳过' value={String(task.counts.skipped)} /><Info label='失败' value={String(task.counts.failed)} />
          {task.last_error && <div className='rounded-2xl bg-danger/10 p-3 text-sm text-danger sm:col-span-2 lg:col-span-4'>{task.last_error.message || task.last_error.code}</div>}
        </CardBody>
      </Card>
      <Tabs aria-label='任务明细' variant='underlined'>
        <Tab key='records' title={`执行明细 (${records?.total ?? 0})`}>
          <Card className='border border-default-200/60 shadow-sm'>
            <CardBody className='gap-4 p-4'>
              <div className='flex justify-end'>
                <Select aria-label='明细状态' className='w-40' selectedKeys={[recordStatus]} onSelectionChange={(keys) => { setRecordStatus(String(Array.from(keys)[0] || 'all')); setRecordPage(1); }}><SelectItem key='all'>全部状态</SelectItem><SelectItem key='succeeded'>成功</SelectItem><SelectItem key='already_done'>已完成</SelectItem><SelectItem key='skipped'>已跳过</SelectItem><SelectItem key='failed'>失败</SelectItem></Select>
              </div>
              <Table aria-label='执行明细' removeWrapper><TableHeader><TableColumn>目标</TableColumn><TableColumn>动作</TableColumn><TableColumn>状态</TableColumn><TableColumn>结果</TableColumn><TableColumn>时间</TableColumn></TableHeader><TableBody emptyContent='没有符合筛选条件的执行明细'>{(records?.items ?? []).map((record) => <TableRow key={record.id}><TableCell><div><p className='text-sm'>{record.target_name || '汇总记录'}</p><p className='text-xs text-default-400'>{record.target_type || '—'}</p></div></TableCell><TableCell>{actionNames[record.action] || record.action}</TableCell><TableCell><StatusChip status={record.status} /></TableCell><TableCell><p className='max-w-md text-xs text-default-500'>{record.message || record.result_code || '—'}</p></TableCell><TableCell><span className='whitespace-nowrap text-xs'>{formatDateTime(record.created_at)}</span></TableCell></TableRow>)}</TableBody></Table>
              {recordPages > 1 && <div className='flex justify-center'><Pagination showControls size='sm' page={recordPage} total={recordPages} onChange={setRecordPage} /></div>}
            </CardBody>
          </Card>
        </Tab>
        <Tab key='runs' title={`执行记录 (${runs?.total ?? 0})`}>
          <Card className='border border-default-200/60 shadow-sm'><CardBody className='gap-4 p-0 pb-4'><Table aria-label='执行记录' removeWrapper><TableHeader><TableColumn>执行编号</TableColumn><TableColumn>状态</TableColumn><TableColumn>开始</TableColumn><TableColumn>结束</TableColumn></TableHeader><TableBody emptyContent='暂无执行记录'>{(runs?.items ?? []).map((run) => <TableRow key={run.id}><TableCell><span className='font-mono text-xs'>{run.run_no}</span></TableCell><TableCell><StatusChip status={run.status} /></TableCell><TableCell>{formatDateTime(run.started_at)}</TableCell><TableCell>{formatDateTime(run.finished_at)}</TableCell></TableRow>)}</TableBody></Table>{runPages > 1 && <div className='flex justify-center'><Pagination showControls size='sm' page={runPage} total={runPages} onChange={setRunPage} /></div>}</CardBody></Card>
        </Tab>
      </Tabs>
    </div>
  );
}

function Info ({ label, value }: { label: string; value: string }) {
  return <div className='rounded-2xl bg-default-100 p-3'><p className='text-xs text-default-400'>{label}</p><p className='mt-1 text-sm font-medium'>{value}</p></div>;
}
