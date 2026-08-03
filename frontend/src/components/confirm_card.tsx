import { Button } from '@heroui/button';
import { Modal, ModalContent } from '@heroui/modal';
import { useEffect, useState, type ReactNode } from 'react';
import { LuCircleHelp, LuOctagonAlert, LuTriangleAlert } from 'react-icons/lu';

type Tone = 'danger' | 'warning' | 'primary';

export interface ConfirmCardOptions {
  title: string;
  description?: ReactNode;
  confirmText?: string;
  cancelText?: string;
  tone?: Tone;
}

interface PendingConfirm extends ConfirmCardOptions {
  key: number;
  resolve: (confirmed: boolean) => void;
}

const toneStyles: Record<Tone, { icon: ReactNode; iconWrap: string; button: 'danger' | 'warning' | 'primary' }> = {
  danger: { icon: <LuOctagonAlert />, iconWrap: 'bg-danger-50 text-danger dark:bg-danger-500/15', button: 'danger' },
  warning: { icon: <LuTriangleAlert />, iconWrap: 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400', button: 'warning' },
  primary: { icon: <LuCircleHelp />, iconWrap: 'bg-primary-50 text-primary dark:bg-primary-500/15', button: 'primary' },
};

let enqueue: ((item: PendingConfirm) => void) | null = null;
let nextKey = 0;

export function confirmCard (options: ConfirmCardOptions): Promise<boolean> {
  return new Promise((resolve) => {
    if (!enqueue) {
      resolve(window.confirm(options.title));
      return;
    }
    enqueue({ ...options, key: nextKey++, resolve });
  });
}

export function ConfirmCardHost () {
  const [queue, setQueue] = useState<PendingConfirm[]>([]);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    enqueue = (item) => {
      setQueue((items) => [...items, item]);
      setOpen(true);
    };
    return () => { enqueue = null; };
  }, []);

  const current = queue[0];
  const settle = (confirmed: boolean) => {
    if (!current) return;
    current.resolve(confirmed);
    setOpen(false);
    // 等退出动画播完再出队，避免卡片内容闪变
    window.setTimeout(() => {
      setQueue((items) => {
        const rest = items.slice(1);
        if (rest.length) setOpen(true);
        return rest;
      });
    }, 220);
  };

  const tone = toneStyles[current?.tone || 'danger'];

  return (
    <Modal
      hideCloseButton
      backdrop='blur'
      classNames={{ backdrop: 'z-[99] backdrop-blur-sm', wrapper: 'z-[99]', base: 'max-w-[21rem] rounded-[26px]' }}
      isOpen={Boolean(current) && open}
      placement='center'
      onClose={() => settle(false)}
    >
      <ModalContent>
        <div className='flex flex-col items-center gap-3 px-6 pb-6 pt-8 text-center'>
          <div className={`grid h-14 w-14 place-items-center rounded-2xl text-2xl ${tone.iconWrap}`}>{tone.icon}</div>
          <p className='text-base font-semibold'>{current?.title}</p>
          {current?.description && <p className='text-sm leading-6 text-default-500'>{current.description}</p>}
          <div className='mt-2 grid w-full grid-cols-2 gap-2'>
            <Button radius='full' variant='flat' onPress={() => settle(false)}>{current?.cancelText || '再想想'}</Button>
            <Button color={tone.button} radius='full' onPress={() => settle(true)}>{current?.confirmText || '确定'}</Button>
          </div>
        </div>
      </ModalContent>
    </Modal>
  );
}
