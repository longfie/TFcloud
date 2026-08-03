import { Button } from '@heroui/button';
import { Card, CardBody } from '@heroui/card';
import { Dropdown, DropdownItem, DropdownMenu, DropdownTrigger } from '@heroui/dropdown';
import { Input } from '@heroui/input';
import { Pagination } from '@heroui/pagination';
import { Select, SelectItem } from '@heroui/select';
import { Spinner } from '@heroui/spinner';
import { Table, TableBody, TableCell, TableColumn, TableHeader, TableRow } from '@heroui/table';
import { useEffect, useState } from 'react';
import { toast } from 'react-hot-toast';
import { LuEllipsis, LuPlay, LuSearch, LuTrash2 } from 'react-icons/lu';

import { apiRequest } from '@/api/client';
import { confirmCard } from '@/components/confirm_card';
import PlatformBrandIcon from '@/components/platform_brand_icon';
import StatusChip from '@/components/sign/status_chip';
import type { Paginated, Plugin, PluginAccount, SignTask } from '@/types/sign';
import { actionNames, errorMessage, formatDateTime, pluginNames } from '@/utils/sign';

export default function AdminAccountsPage () {
  const [result, setResult] = useState<Paginated<PluginAccount> | null>(null);
  const [plugins, setPlugins] = useState<Plugin[]>([]);
  const [page, setPage] = useState(1);
  const [keyword, setKeyword] = useState('');
  const [query, setQuery] = useState('');
  const [pluginCode, setPluginCode] = useState('all');
  const [status, setStatus] = useState('all');
  const [busy, setBusy] = useState<number | null>(null);
  const limit = 20;

  const load = () => Promise.all([
    apiRequest<Paginated<PluginAccount>>({ url: '/admin/plugin-accounts', params: { limit, offset: (page - 1) * limit, keyword: query || undefined, plugin_code: pluginCode === 'all' ? undefined : pluginCode, status: status === 'all' ? undefined : status } }),
    plugins.length ? Promise.resolve(plugins) : apiRequest<Plugin[]>({ url: '/plugins' }),
  ]).then(([accountRows, pluginRows]) => { setResult(accountRows); setPlugins(pluginRows); });
  useEffect(() => { load().catch((error) => toast.error(errorMessage(error))); }, [page, query, pluginCode, status]);

  const operate = async (row: PluginAccount, action: 'toggle' | 'delete') => {
    if (action === 'delete' && !(await confirmCard({ title: `删除 ${row.username} 的平台账号？`, description: '该用户的这个平台账号将停止自动签到，历史记录会保留。', confirmText: '删除账号' }))) return;
    setBusy(row.id);
    try {
      if (action === 'toggle') {
        if (row.login_status === 'expired' || row.status === 'credential_expired' || ['PLUGIN_CREDENTIAL_EXPIRED', 'PLUGIN_CREDENTIAL_INVALID'].includes(row.last_error?.code || '')) {
          toast.error('登录凭据已经失效，不能直接启用');
          return;
        }
        await apiRequest<PluginAccount>({ method: 'PATCH', url: `/admin/plugin-accounts/${row.id}`, data: { status: row.status === 'active' ? 'disabled' : 'active' } });
      }
      else await apiRequest<null>({ method: 'DELETE', url: `/admin/plugin-accounts/${row.id}` });
      toast.success(action === 'toggle' ? '账号状态已更新' : '账号已删除');
      await load();
    } catch (error) { toast.error(errorMessage(error)); } finally { setBusy(null); }
  };

  const run = async (row: PluginAccount, action: string) => {
    setBusy(row.id);
    try { const task = await apiRequest<SignTask>({ method: 'POST', url: `/admin/plugin-accounts/${row.id}/actions/${action}` }); toast.success(`任务 ${task.task_no.slice(0, 8)} 已创建`); } catch (error) { toast.error(errorMessage(error)); } finally { setBusy(null); }
  };

  if (!result) return <div className='flex min-h-60 items-center justify-center'><Spinner label='正在加载平台账号' /></div>;
  const pages = Math.max(1, Math.ceil(result.total / limit));
  const pluginOptions = [{ code: 'all', name: '全部插件' }, ...plugins];
  return (
    <div className='flex flex-col gap-4'>
      <div className='grid gap-2 lg:grid-cols-[1fr_180px_160px_auto]'><Input placeholder='搜索账号名称、平台ID或所属用户' startContent={<LuSearch />} value={keyword} onValueChange={setKeyword} onKeyDown={(event) => { if (event.key === 'Enter') { setPage(1); setQuery(keyword); } }} /><Select items={pluginOptions} aria-label='插件' selectedKeys={[pluginCode]} onSelectionChange={(keys) => { setPage(1); setPluginCode(String(Array.from(keys)[0] || 'all')); }}>{(plugin) => <SelectItem key={plugin.code} startContent={plugin.code === 'all' ? undefined : <PlatformBrandIcon code={plugin.code} name={plugin.name} fallback={plugin.name.slice(0, 1)} className='h-6 w-6 rounded-lg' fallbackClassName='bg-default-100 text-xs font-semibold' />}>{plugin.name}</SelectItem>}</Select><Select aria-label='状态' selectedKeys={[status]} onSelectionChange={(keys) => { setPage(1); setStatus(String(Array.from(keys)[0] || 'all')); }}><SelectItem key='all'>全部状态</SelectItem><SelectItem key='active'>启用</SelectItem><SelectItem key='disabled'>停用</SelectItem><SelectItem key='pending_verification'>等待首次执行</SelectItem><SelectItem key='credential_expired'>登录失效</SelectItem></Select><Button variant='flat' onPress={() => { setPage(1); setQuery(keyword); }}>搜索</Button></div>
      <Card className='border border-default-200/60 shadow-sm'>
        <CardBody className='p-0'>
          <Table aria-label='全局平台账号' removeWrapper>
            <TableHeader><TableColumn>账号</TableColumn><TableColumn>所属用户</TableColumn><TableColumn>状态</TableColumn><TableColumn>调度计划</TableColumn><TableColumn>最近运行</TableColumn><TableColumn align='end'>操作</TableColumn></TableHeader>
            <TableBody emptyContent='暂无平台账号'>
              {result.items.map((row) => {
                const plugin = plugins.find((item) => item.code === row.plugin_code);
                const expired = row.login_status === 'expired' || row.status === 'credential_expired' || ['PLUGIN_CREDENTIAL_EXPIRED', 'PLUGIN_CREDENTIAL_INVALID'].includes(row.last_error?.code || '');
                const menuItems = [
                  ...(plugin?.actions || []).map((action) => ({ key: `run:${action}`, label: actionNames[action] || action, kind: 'run' })),
                  { key: 'toggle', label: expired ? '登录凭据已失效' : row.status === 'active' ? '停用账号' : '启用账号', kind: 'toggle' },
                  { key: 'delete', label: '删除账号', kind: 'delete' },
                ];
                return <TableRow key={row.id}><TableCell><div className='flex items-center gap-3'><PlatformBrandIcon code={row.plugin_code} name={pluginNames[row.plugin_code]} avatarUrl={row.avatar_url} fallback={(pluginNames[row.plugin_code] || row.plugin_code).slice(0, 1)} className='h-9 w-9 rounded-xl shadow-sm' fallbackClassName='bg-default-100 text-xs font-semibold' /><div><p className='text-sm font-medium'>{row.display_name || `账号 #${row.id}`}</p><p className='text-xs text-default-400'>{pluginNames[row.plugin_code] || row.plugin_code} · {row.external_user_id || '无平台ID'}</p></div></div></TableCell><TableCell><p className='text-sm'>{row.username}</p><p className='text-xs text-default-400'>UID {row.user_id}</p></TableCell><TableCell><StatusChip status={expired ? 'expired' : 'normal'} /></TableCell><TableCell><p className='text-xs'>下次 {formatDateTime(row.next_run_at)}</p><p className='text-xs text-default-400'>{row.settings.schedule_mode === 'fixed' ? `定时计划 · ${String(row.settings.schedule_time || '—')}` : '自动计划'}</p></TableCell><TableCell><span className='text-xs'>{formatDateTime(row.last_run_at)}</span></TableCell><TableCell><div className='flex justify-end'><Dropdown><DropdownTrigger><Button isIconOnly size='sm' variant='light' aria-label='账号操作' isDisabled={busy === row.id}><LuEllipsis /></Button></DropdownTrigger><DropdownMenu items={menuItems} disabledKeys={expired ? ['toggle'] : []} aria-label='账号操作' onAction={(key) => { const value = String(key); if (value.startsWith('run:')) void run(row, value.slice(4)); else void operate(row, value as 'toggle' | 'delete'); }}>{(item) => <DropdownItem key={item.key} color={item.kind === 'delete' ? 'danger' : 'default'} startContent={item.kind === 'run' ? <LuPlay /> : item.kind === 'delete' ? <LuTrash2 /> : undefined}>{item.label}</DropdownItem>}</DropdownMenu></Dropdown></div></TableCell></TableRow>;
              })}
            </TableBody>
          </Table>
        </CardBody>
      </Card>
      <div className='flex items-center justify-between'><p className='text-xs text-default-400'>共 {result.total} 个账号</p><Pagination page={page} total={pages} onChange={setPage} showControls /></div>
    </div>
  );
}
