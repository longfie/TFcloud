import { Button } from '@heroui/button';
import { Modal, ModalBody, ModalContent, ModalFooter, ModalHeader } from '@heroui/modal';
import { Spinner } from '@heroui/spinner';
import { useEffect, useRef, useState } from 'react';
import { LuCircleCheck, LuCircleCheckBig, LuCircleDashed, LuCircleX, LuEye, LuSkipForward } from 'react-icons/lu';
import { useNavigate } from 'react-router-dom';

import { apiRequest } from '@/api/client';
import StatusChip from '@/components/sign/status_chip';
import type { Paginated, SignRecord, SignTask } from '@/types/sign';
import { actionNames, pluginNames } from '@/utils/sign';

const finalStatuses = ['succeeded', 'completed', 'partial', 'failed', 'cancelled'];

interface TaskProgressModalProps {
  task: SignTask | null;
  onClose: () => void;
}

function recordIcon (status: string) {
  if (status === 'succeeded' || status === 'already_done') return <LuCircleCheck className='text-success' />;
  if (status === 'skipped') return <LuSkipForward className='text-default-400' />;
  if (status === 'failed') return <LuCircleX className='text-danger' />;
  return <LuCircleDashed className='text-primary' />;
}

/** 手动触发签到后的实时过程反馈：排队 → 逐条明细 → 结果。 */
export default function TaskProgressModal ({ task, onClose }: TaskProgressModalProps) {
  const [current, setCurrent] = useState<SignTask | null>(task);
  const [records, setRecords] = useState<SignRecord[]>([]);
  const listRef = useRef<HTMLDivElement>(null);
  const navigate = useNavigate();

  useEffect(() => {
    setCurrent(task);
    setRecords([]);
  }, [task]);

  useEffect(() => {
    if (!task) return;
    let cancelled = false;
    let timer: number | undefined;

    const tick = async () => {
      try {
        const [updated, recordPage] = await Promise.all([
          apiRequest<SignTask>({ url: `/sign-tasks/${task.task_no}` }),
          apiRequest<Paginated<SignRecord>>({
            url: `/sign-tasks/${task.task_no}/records`,
            params: { page: 1, per_page: 500 },
          }),
        ]);
        if (cancelled) return;
        setCurrent(updated);
        setRecords(recordPage.items);
        if (!finalStatuses.includes(updated.status)) {
          timer = window.setTimeout(() => { void tick(); }, 1000);
        }
      } catch {
        if (!cancelled) timer = window.setTimeout(() => { void tick(); }, 2000);
      }
    };

    void tick();
    return () => {
      cancelled = true;
      if (timer !== undefined) window.clearTimeout(timer);
    };
  }, [task]);

  useEffect(() => {
    const el = listRef.current;
    if (!el) return;
    el.scrollTop = el.scrollHeight;
  }, [records.length]);

  const status = current?.status || 'pending';
  const done = finalStatuses.includes(status);
  const succeeded = ['succeeded', 'completed', 'partial'].includes(status);
  const successCount = current ? current.counts.success + current.counts.already : 0;

  return (
    <Modal isOpen={Boolean(task)} size='lg' scrollBehavior='inside' onOpenChange={(open) => { if (!open) onClose(); }}>
      <ModalContent className='tf-glass rounded-[26px]'>
        <ModalHeader className='flex flex-col gap-1'>
          <span>{current ? `${pluginNames[current.plugin_code] || current.plugin_code} · ${actionNames[current.action] || current.action}` : '手动签到'}</span>
          <span className='text-xs font-normal text-default-400'>执行过程会实时刷新到下方列表</span>
        </ModalHeader>
        <ModalBody className='gap-4 pb-2'>
          <div className='flex items-center justify-between gap-3 rounded-2xl bg-default-100/70 px-4 py-3 dark:bg-default-50/5'>
            <div className='flex items-center gap-3'>
              {!done ? <Spinner size='sm' /> : succeeded ? <LuCircleCheckBig className='text-2xl text-success' /> : <LuCircleX className='text-2xl text-danger' />}
              <div>
                <p className='text-sm font-medium'>
                  {!done && (status === 'running' ? '正在执行签到…' : status === 'retrying' ? '自动重试中…' : '已进入队列，等待执行…')}
                  {done && (succeeded ? '执行完成' : '执行结束')}
                </p>
                <p className='mt-0.5 text-xs text-default-400'>
                  {successCount} 成功 / {current?.counts.failed ?? 0} 失败
                  {current?.counts.skipped ? ` / ${current.counts.skipped} 跳过` : ''}
                  {records.length ? ` · 已产出 ${records.length} 条明细` : ''}
                </p>
              </div>
            </div>
            <StatusChip status={status} />
          </div>

          <div ref={listRef} className='max-h-72 space-y-1.5 overflow-y-auto rounded-2xl border border-divider/70 bg-content1/40 p-2'>
            {!records.length && !done && (
              <div className='flex flex-col items-center gap-2 py-10 text-xs text-default-400'>
                <Spinner size='sm' />
                <p>正在等待执行明细…</p>
              </div>
            )}
            {!records.length && done && (
              <p className='py-10 text-center text-xs text-default-400'>本次没有产出明细记录</p>
            )}
            {records.map((record) => (
              <div key={record.id} className='flex items-start gap-2.5 rounded-xl px-2.5 py-2 transition hover:bg-default-100/70'>
                <span className='mt-0.5 text-base'>{recordIcon(record.status)}</span>
                <div className='min-w-0 flex-1'>
                  <div className='flex items-center justify-between gap-2'>
                    <p className='truncate text-sm font-medium'>
                      {record.target_name || actionNames[record.action] || record.action}
                    </p>
                    <StatusChip status={record.status} />
                  </div>
                  <p className='mt-0.5 break-all text-xs leading-5 text-default-400'>
                    {record.message || record.result_code || '—'}
                  </p>
                </div>
              </div>
            ))}
          </div>

          {!succeeded && done && current?.last_error?.message && (
            <div className='rounded-2xl bg-danger-50/80 px-4 py-3 text-left text-xs leading-5 text-danger-600 dark:bg-danger-500/10 dark:text-danger-300'>
              <p className='font-medium'>失败原因</p>
              <p className='mt-1 break-all'>{current.last_error.message}</p>
            </div>
          )}
        </ModalBody>
        <ModalFooter>
          <Button variant='light' onPress={onClose}>关闭</Button>
          {current && (
            <Button color='primary' variant='flat' startContent={<LuEye />} onPress={() => { onClose(); navigate(`/TFYT/tasks/${current.task_no}`); }}>
              查看完整明细
            </Button>
          )}
        </ModalFooter>
      </ModalContent>
    </Modal>
  );
}
