import clsx from 'clsx';
import { LuGlobe } from 'react-icons/lu';

export const officialWebsite = 'https://www.yunsign.net';

type CopyrightVariant = 'default' | 'home' | 'backend';

export default function CopyrightNotice ({
  className,
  variant = 'default',
}: {
  className?: string;
  variant?: CopyrightVariant;
}) {
  return (
    <div className={clsx(
      'text-xs text-default-500',
      variant === 'default' && 'flex flex-col items-center gap-0.5 text-center leading-5',
      variant === 'home' && 'flex flex-col items-center gap-1.5 rounded-2xl border border-default-200/70 bg-default-100/55 px-4 py-2.5 text-center shadow-sm sm:flex-row sm:gap-3 sm:text-left',
      variant === 'backend' && 'flex flex-col items-center justify-center gap-1.5 border-t border-default-200/60 pb-1 pt-5 text-center sm:flex-row sm:gap-3',
      className
    )}>
      <span>© 2016–{new Date().getFullYear()} 天方云签 · 龙辉 版权所有</span>
      {variant !== 'default' && <span aria-hidden className='hidden h-3 w-px bg-divider sm:block' />}
      <a className='inline-flex items-center gap-1.5 font-medium text-default-600 transition-colors hover:text-primary dark:text-default-400' href={officialWebsite} target='_blank' rel='noreferrer'>
        <LuGlobe className='shrink-0' />
        <span>官方网站：www.yunsign.net</span>
      </a>
    </div>
  );
}
