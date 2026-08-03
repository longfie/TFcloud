import CopyrightNotice from '@/components/copyright_notice';

export default function PureLayout ({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <div className='relative flex min-h-screen flex-col'>
      <main className='flex w-full flex-grow flex-col items-center justify-center py-8'>
        {children}
      </main>
      <CopyrightNotice className='relative z-10 px-4 pb-6' />
    </div>
  );
}
