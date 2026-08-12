import { useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';

import { usePageLoading } from '@/hooks/use-page-loading';

const FINISH_DELAY_MS = 240;
const SHOW_DELAY_MS = 140;

export default function PageLoadingBar () {
  const location = useLocation();
  const [visible, setVisible] = useState(false);
  const [progress, setProgress] = useState(10);
  const finishTimer = useRef<number | null>(null);
  const showTimer = useRef<number | null>(null);
  const loading = usePageLoading();

  useEffect(() => {
    if (finishTimer.current !== null) {
      window.clearTimeout(finishTimer.current);
      finishTimer.current = null;
    }
    if (showTimer.current !== null) {
      window.clearTimeout(showTimer.current);
      showTimer.current = null;
    }

    if (!loading) {
      setProgress(100);
      finishTimer.current = window.setTimeout(() => {
        setVisible(false);
        setProgress(10);
        finishTimer.current = null;
      }, FINISH_DELAY_MS);
      return;
    }

    setProgress((current) => current >= 95 ? 10 : Math.max(10, current));
    showTimer.current = window.setTimeout(() => {
      setVisible(true);
      showTimer.current = null;
    }, SHOW_DELAY_MS);
    const timer = window.setInterval(() => {
      setProgress((current) => Math.min(92, current + Math.max(1.2, (92 - current) * 0.12)));
    }, 180);

    return () => {
      window.clearInterval(timer);
      if (showTimer.current !== null) {
        window.clearTimeout(showTimer.current);
        showTimer.current = null;
      }
    };
  }, [loading]);

  useEffect(() => () => {
    if (finishTimer.current !== null) window.clearTimeout(finishTimer.current);
    if (showTimer.current !== null) window.clearTimeout(showTimer.current);
  }, []);

  if (location.pathname === '/' || location.pathname === '/login') return null;

  return (
    <div
      role='progressbar'
      aria-label='页面加载进度'
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={Math.round(progress)}
      className={`pointer-events-none fixed inset-x-0 top-0 z-[120] h-[3px] overflow-hidden transition-opacity duration-200 motion-reduce:transition-none ${visible ? 'opacity-100' : 'opacity-0'}`}
    >
      <div
        className='h-full origin-left bg-gradient-to-r from-primary via-secondary to-pink-500 shadow-[0_0_8px_rgba(99,102,241,0.65)] transition-transform duration-200 ease-out motion-reduce:transition-none'
        style={{ transform: `scaleX(${progress / 100})` }}
      />
    </div>
  );
}
