import clsx from 'clsx';

import logo from '@/assets/platform_logo';

interface PlatformLogoProps {
  alt?: string;
  className?: string;
  size?: number | string;
  src?: string;
}

export default function PlatformLogo ({
  alt = '天方云签',
  className,
  size = 40,
  src = logo,
}: PlatformLogoProps) {
  return (
    <span
      className={clsx('inline-flex shrink-0 overflow-hidden rounded-full bg-white align-middle', className)}
      style={{ width: size, height: size }}
    >
      <img
        alt={alt}
        className='h-full w-full object-cover'
        src={src}
      />
    </span>
  );
}
