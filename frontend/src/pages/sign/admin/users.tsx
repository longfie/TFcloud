import { Button } from '@heroui/button';
import { Card, CardBody } from '@heroui/card';
import { Input } from '@heroui/input';
import { Modal, ModalBody, ModalContent, ModalFooter, ModalHeader } from '@heroui/modal';
import { Pagination } from '@heroui/pagination';
import { Select, SelectItem } from '@heroui/select';
import { Spinner } from '@heroui/spinner';
import { Table, TableBody, TableCell, TableColumn, TableHeader, TableRow } from '@heroui/table';
import { useEffect, useState } from 'react';
import { toast } from 'react-hot-toast';
import { LuKeyRound, LuPencil, LuPlus, LuSearch, LuShieldOff, LuTrash2 } from 'react-icons/lu';

import { apiRequest } from '@/api/client';
import { confirmCard } from '@/components/confirm_card';
import StatusChip from '@/components/sign/status_chip';
import { useSignAuth } from '@/contexts/auth';
import type { Paginated, User } from '@/types/sign';
import { errorMessage, formatDateTime } from '@/utils/sign';

interface UserForm {
  username: string; password: string; display_name: string; email: string; qq: string;
  role: string; status: string; quota: string;
}
const blank: UserForm = { username: '', password: '', display_name: '', email: '', qq: '', role: 'user', status: 'active', quota: '2' };

export default function AdminUsersPage () {
  const [result, setResult] = useState<Paginated<User> | null>(null);
  const [page, setPage] = useState(1);
  const [keyword, setKeyword] = useState('');
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('all');
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<User | null>(null);
  const [form, setForm] = useState<UserForm>(blank);
  const [resetTarget, setResetTarget] = useState<User | null>(null);
  const [resetPassword, setResetPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const { user: currentUser } = useSignAuth();
  const limit = 20;

  const load = () => apiRequest<Paginated<User>>({ url: '/admin/users', params: { limit, offset: (page - 1) * limit, keyword: query || undefined, status: status === 'all' ? undefined : status } }).then(setResult);
  useEffect(() => { load().catch((error) => toast.error(errorMessage(error))); }, [page, query, status]);

  const openCreate = () => { setEditing(null); setForm(blank); setModalOpen(true); };
  const openEdit = (row: User) => {
    setEditing(row);
    setForm({ username: row.username, password: '', display_name: row.display_name || '', email: row.email || '', qq: row.qq || '', role: row.role, status: row.status, quota: String(row.quota) });
    setModalOpen(true);
  };
  const setField = (field: keyof UserForm, value: string) => setForm((current) => ({ ...current, [field]: value }));

  const submit = async () => {
    setSubmitting(true);
    try {
      const payload = { ...form, quota: Number(form.quota), ...(editing ? {} : { password: form.password }) };
      if (editing) await apiRequest<User>({ method: 'PATCH', url: `/admin/users/${editing.id}`, data: payload });
      else await apiRequest<User>({ method: 'POST', url: '/admin/users', data: payload });
      toast.success(editing ? '用户已更新' : '用户已创建');
      setModalOpen(false); await load();
    } catch (error) { toast.error(errorMessage(error)); } finally { setSubmitting(false); }
  };

  const doResetPassword = async () => {
    if (!resetTarget) return;
    setSubmitting(true);
    try {
      await apiRequest<null>({ method: 'POST', url: `/admin/users/${resetTarget.id}/reset-password`, data: { password: resetPassword } });
      toast.success('密码已重置，原会话已撤销'); setResetTarget(null); setResetPassword('');
    } catch (error) { toast.error(errorMessage(error)); } finally { setSubmitting(false); }
  };

  const revoke = async (row: User) => {
    if (!(await confirmCard({ title: `撤销 ${row.username} 的登录会话？`, description: '该用户将在所有设备上被强制下线，需要重新登录。', confirmText: '全部撤销', tone: 'warning' }))) return;
    try { const data = await apiRequest<{ revoked_count: number }>({ method: 'POST', url: `/admin/users/${row.id}/revoke-sessions` }); toast.success(`已撤销 ${data.revoked_count} 个会话`); } catch (error) { toast.error(errorMessage(error)); }
  };

  const remove = async (row: User) => {
    if (!(await confirmCard({ title: `删除用户 ${row.username}？`, description: '该用户的平台账号、签到任务和登录会话将一并删除，且无法恢复。', confirmText: '永久删除' }))) return;
    try {
      await apiRequest<null>({ method: 'DELETE', url: `/admin/users/${row.id}` });
      toast.success('用户已删除');
      await load();
    } catch (error) { toast.error(errorMessage(error)); }
  };

  if (!result) return <div className='flex min-h-60 items-center justify-center'><Spinner label='正在加载用户' /></div>;
  const pages = Math.max(1, Math.ceil(result.total / limit));

  return (
    <div className='flex flex-col gap-4'>
      <div className='flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between'>
        <div className='flex flex-1 gap-2'><Input placeholder='搜索用户名、昵称、邮箱或QQ' startContent={<LuSearch />} value={keyword} onValueChange={setKeyword} onKeyDown={(event) => { if (event.key === 'Enter') { setPage(1); setQuery(keyword); } }} /><Button variant='flat' onPress={() => { setPage(1); setQuery(keyword); }}>搜索</Button></div>
        <div className='flex gap-2'><Select aria-label='用户状态' className='w-36' selectedKeys={[status]} onSelectionChange={(keys) => { setPage(1); setStatus(String(Array.from(keys)[0] || 'all')); }}><SelectItem key='all'>全部状态</SelectItem><SelectItem key='active'>启用</SelectItem><SelectItem key='disabled'>禁用</SelectItem></Select><Button color='primary' startContent={<LuPlus />} onPress={openCreate}>创建用户</Button></div>
      </div>
      <Card className='border border-default-200/60 shadow-sm'><CardBody className='p-0'><Table aria-label='用户列表' removeWrapper><TableHeader><TableColumn>用户</TableColumn><TableColumn>角色</TableColumn><TableColumn>状态</TableColumn><TableColumn>账号配额</TableColumn><TableColumn>最近登录</TableColumn><TableColumn align='end'>操作</TableColumn></TableHeader><TableBody emptyContent='暂无用户'>{result.items.map((row) => <TableRow key={row.id}><TableCell><div><p className='text-sm font-medium'>{row.display_name || row.username}</p><p className='text-xs text-default-400'>#{row.id} · {row.username}</p></div></TableCell><TableCell><StatusChip status={row.role} /></TableCell><TableCell><StatusChip status={row.status} /></TableCell><TableCell><p className='text-sm'>{row.quota} 个账号</p></TableCell><TableCell><p className='text-xs'>{formatDateTime(row.last_login_at)}</p><p className='text-xs text-default-400'>{row.last_login_ip || '—'}</p></TableCell><TableCell><div className='flex justify-end gap-1'><Button isIconOnly size='sm' variant='light' aria-label='编辑用户' onPress={() => openEdit(row)}><LuPencil /></Button><Button isIconOnly size='sm' variant='light' color='warning' aria-label='重置密码' onPress={() => { setResetTarget(row); setResetPassword(''); }}><LuKeyRound /></Button><Button isIconOnly size='sm' variant='light' color='danger' aria-label='撤销会话' isDisabled={row.id === currentUser?.id} onPress={() => void revoke(row)}><LuShieldOff /></Button><Button isIconOnly size='sm' variant='light' color='danger' aria-label='删除用户' isDisabled={row.id === currentUser?.id || row.role === 'admin'} onPress={() => void remove(row)}><LuTrash2 /></Button></div></TableCell></TableRow>)}</TableBody></Table></CardBody></Card>
      <div className='flex items-center justify-between'><p className='text-xs text-default-400'>共 {result.total} 个用户</p><Pagination page={page} total={pages} onChange={setPage} showControls /></div>

      <Modal isOpen={modalOpen} onOpenChange={setModalOpen} size='2xl' scrollBehavior='inside'><ModalContent><ModalHeader>{editing ? '编辑用户' : '创建用户'}</ModalHeader><ModalBody className='grid gap-4 sm:grid-cols-2'><Input label='用户名' isRequired value={form.username} onValueChange={(value) => setField('username', value)} />{!editing && <Input label='初始密码' type='password' isRequired description='至少8个字符' value={form.password} onValueChange={(value) => setField('password', value)} />}<Input label='显示名称' value={form.display_name} onValueChange={(value) => setField('display_name', value)} /><Input label='邮箱' type='email' value={form.email} onValueChange={(value) => setField('email', value)} /><Input label='QQ号' value={form.qq} onValueChange={(value) => setField('qq', value)} /><Select label='角色' selectedKeys={[form.role]} onSelectionChange={(keys) => setField('role', String(Array.from(keys)[0] || 'user'))}><SelectItem key='user'>普通用户</SelectItem><SelectItem key='admin'>管理员</SelectItem></Select><Select label='状态' selectedKeys={[form.status]} onSelectionChange={(keys) => setField('status', String(Array.from(keys)[0] || 'active'))}><SelectItem key='active'>启用</SelectItem><SelectItem key='disabled'>禁用</SelectItem></Select><Input label='账号配额' type='number' min='0' value={form.quota} onValueChange={(value) => setField('quota', value)} /></ModalBody><ModalFooter><Button variant='light' onPress={() => setModalOpen(false)}>取消</Button><Button color='primary' isLoading={submitting} onPress={() => void submit()}>保存</Button></ModalFooter></ModalContent></Modal>
      <Modal isOpen={Boolean(resetTarget)} onOpenChange={(open) => { if (!open) setResetTarget(null); }}><ModalContent><ModalHeader>重置 {resetTarget?.username} 的密码</ModalHeader><ModalBody><Input autoFocus label='新密码' type='password' description='至少8个字符，提交后撤销其全部会话' value={resetPassword} onValueChange={setResetPassword} /></ModalBody><ModalFooter><Button variant='light' onPress={() => setResetTarget(null)}>取消</Button><Button color='warning' isLoading={submitting} onPress={() => void doResetPassword()}>确认重置</Button></ModalFooter></ModalContent></Modal>
    </div>
  );
}
