import { Button } from '@heroui/button';
import { Card, CardBody } from '@heroui/card';
import { Spinner } from '@heroui/spinner';
import { Table, TableBody, TableCell, TableColumn, TableHeader, TableRow } from '@heroui/table';
import { useEffect, useState } from 'react';
import { toast } from 'react-hot-toast';

import { apiRequest } from '@/api/client';
import type { AuditLog } from '@/types/sign';
import { errorMessage, formatDateTime } from '@/utils/sign';

export default function AdminAuditPage () {
  const [rows, setRows] = useState<AuditLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const load = async (append = false) => {
    const beforeId = append ? rows[rows.length - 1]?.id : undefined;
    const data = await apiRequest<AuditLog[]>({ url: '/admin/audit-logs', params: { limit: 100, before_id: beforeId } });
    setRows((current) => append ? [...current, ...data] : data);
  };
  useEffect(() => { load().catch((error) => toast.error(errorMessage(error))).finally(() => setLoading(false)); }, []);
  if (loading) return <div className='flex min-h-60 items-center justify-center'><Spinner label='正在加载审计日志' /></div>;
  return (
    <div className='flex flex-col gap-4'><Card className='border border-default-200/60 shadow-sm'><CardBody className='p-0'><Table aria-label='审计日志' removeWrapper><TableHeader><TableColumn>ID</TableColumn><TableColumn>操作</TableColumn><TableColumn>操作者</TableColumn><TableColumn>资源</TableColumn><TableColumn>IP</TableColumn><TableColumn>时间</TableColumn></TableHeader><TableBody emptyContent='暂无审计日志'>{rows.map((row) => <TableRow key={row.id}><TableCell>{row.id}</TableCell><TableCell><div><p className='text-sm font-medium'>{row.action}</p><p className='max-w-64 truncate text-xs text-default-400'>{row.request_id || '无请求ID'}</p></div></TableCell><TableCell>{row.user_id ? `UID ${row.user_id}` : '系统'}</TableCell><TableCell><p className='text-xs'>{row.resource_type || '—'}</p><p className='text-xs text-default-400'>{row.resource_id || '—'}</p></TableCell><TableCell><span className='text-xs'>{row.ip_address || '—'}</span></TableCell><TableCell><span className='whitespace-nowrap text-xs'>{formatDateTime(row.created_at)}</span></TableCell></TableRow>)}</TableBody></Table></CardBody></Card>{rows.length >= 100 && <Button variant='flat' isLoading={loadingMore} onPress={() => { setLoadingMore(true); load(true).catch((error) => toast.error(errorMessage(error))).finally(() => setLoadingMore(false)); }}>加载更早日志</Button>}</div>
  );
}
