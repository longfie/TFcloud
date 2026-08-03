const PageBackground = () => {
  return (
    <div className='pointer-events-none fixed inset-0 -z-10 h-full w-full overflow-hidden bg-[#f7f8ff] dark:bg-[#090b12]'>
      <div className='tf-background-mesh absolute inset-0' />
      <div className='tf-background-grid absolute inset-0 opacity-45 dark:opacity-20' />
      <div className='absolute -left-32 -top-36 h-[30rem] w-[30rem] rounded-full bg-primary-300/30 blur-[80px] dark:bg-primary-500/15' />
      <div className='absolute right-[-12rem] top-[12%] h-[28rem] w-[28rem] rounded-full bg-secondary-300/25 blur-[80px] dark:bg-secondary-500/10' />
      <div className='absolute -bottom-48 left-[24%] h-[32rem] w-[32rem] rounded-full bg-pink-300/20 blur-[90px] dark:bg-pink-500/[0.08]' />
      <div className='absolute inset-x-0 top-0 h-36 bg-gradient-to-b from-white/30 to-transparent dark:from-white/[0.025]' />
    </div>
  );
};

export default PageBackground;
