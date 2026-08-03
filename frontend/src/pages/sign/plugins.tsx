import { Button } from '@heroui/button';
import { Card, CardBody, CardFooter, CardHeader } from '@heroui/card';
import { Spinner } from '@heroui/spinner';
import { useEffect, useState } from 'react';
import { toast } from 'react-hot-toast';
import { LuActivity, LuBadgeCheck, LuBoxes } from 'react-icons/lu';
import { useNavigate } from 'react-router-dom';

import { apiRequest } from '@/api/client';
import PlatformBrandIcon from '@/components/platform_brand_icon';
import StatusChip from '@/components/sign/status_chip';
import type { Plugin } from '@/types/sign';
import { actionNames, errorMessage } from '@/utils/sign';

export default function PluginsPage () {
  const [plugins, setPlugins] = useState<Plugin[]>([]);
  const [loading, setLoading] = useState(true);
  const navigate = useNavigate();

  useEffect(() => {
    apiRequest<Plugin[]>({ url: '/plugins' })
      .then(setPlugins)
      .catch((error) => toast.error(errorMessage(error)))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <div className='flex min-h-72 items-center justify-center'><Spinner label='正在加载插件' /></div>;

  return (
    <div className='flex flex-col gap-5'>
      <div className='flex flex-col justify-between gap-3 sm:flex-row sm:items-end'>
        <div><h2 className='text-xl font-semibold'>插件中心</h2><p className='mt-1 text-sm text-default-500'>仅管理员可见，用于维护和扩展各平台的签到能力</p></div>
        <Button color='primary' className='tf-soft-button' onPress={() => navigate('/TFYT/admin/accounts')}>查看平台账号</Button>
      </div>
      <div className='grid gap-4 md:grid-cols-2 xl:grid-cols-3'>
        {plugins.map((plugin) => (
          <Card key={plugin.code} className='tf-glass-card rounded-[26px]'>
            <CardHeader className='flex items-start justify-between px-5 pt-5'>
              <div className='flex gap-3'>
                <PlatformBrandIcon code={plugin.code} name={plugin.name} fallback={<LuBoxes />} className='h-11 w-11 rounded-2xl shadow-sm' fallbackClassName='bg-primary/10 text-xl text-primary' />
                <div><h3 className='font-semibold'>{plugin.name}</h3><p className='text-xs text-default-400'>v{plugin.version} · {plugin.code}</p></div>
              </div>
              <StatusChip status={plugin.implementation_status} />
            </CardHeader>
            <CardBody className='gap-4 px-5'>
              <p className='min-h-10 text-sm leading-6 text-default-600'>{plugin.description}</p>
              <div className='flex items-center gap-2 text-xs text-default-500'>
                {plugin.healthy ? <LuBadgeCheck className='text-success' /> : <LuActivity className='text-warning' />}
                {plugin.health_message || (plugin.healthy ? '插件运行正常' : '插件当前不可用')}
              </div>
              <div className='flex flex-wrap gap-2'>
                {plugin.actions.map((action) => <span key={action} className='rounded-lg bg-default-100 px-2 py-1 text-xs'>{actionNames[action] || action}</span>)}
                {plugin.actions.length === 0 && <span className='text-xs text-default-400'>暂无可执行动作</span>}
              </div>
            </CardBody>
            <CardFooter className='px-5 pb-5 pt-0'>
              <Button fullWidth variant='flat' color='primary' isDisabled={plugin.implementation_status !== 'ready'} onPress={() => navigate('/TFYT/admin/accounts')}>
                {plugin.implementation_status === 'ready' ? '查看关联账号' : '等待后端接入'}
              </Button>
            </CardFooter>
          </Card>
        ))}
      </div>
    </div>
  );
}
