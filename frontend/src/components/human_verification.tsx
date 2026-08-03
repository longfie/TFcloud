import { Button } from '@heroui/button';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { IoCheckmark, IoRefresh, IoShieldCheckmarkOutline } from 'react-icons/io5';

import { ApiError, apiRequest } from '@/api/client';
import type { HumanChallenge, HumanChallengeVerification } from '@/types/sign';

interface HumanVerificationProps {
  disabled?: boolean;
  purpose?: 'login' | 'register';
  onVerified: (challengeId: string | null) => void;
}

export default function HumanVerification ({ disabled = false, purpose = 'login', onVerified }: HumanVerificationProps) {
  const [challenge, setChallenge] = useState<HumanChallenge | null>(null);
  const [progress, setProgress] = useState(0);
  const [loading, setLoading] = useState(true);
  const [verifying, setVerifying] = useState(false);
  const [verified, setVerified] = useState(false);
  const [error, setError] = useState('');
  const progressRef = useRef(0);
  const startedAtRef = useRef<number | null>(null);
  const interactionCountRef = useRef(0);
  const draggingRef = useRef(false);
  const trackRef = useRef<HTMLDivElement>(null);

  const issueChallenge = useCallback(async () => {
    setLoading(true);
    setVerified(false);
    setProgress(0);
    progressRef.current = 0;
    startedAtRef.current = null;
    interactionCountRef.current = 0;
    setError('');
    onVerified(null);
    try {
      const result = await apiRequest<HumanChallenge>({
        method: 'POST',
        url: '/auth/human-challenge',
        data: { purpose },
      });
      setChallenge(result);
    } catch (requestError) {
      setChallenge(null);
      setError(requestError instanceof Error ? requestError.message : '验证服务暂时不可用');
    } finally {
      setLoading(false);
    }
  }, [onVerified, purpose]);

  useEffect(() => { void issueChallenge(); }, [issueChallenge]);

  const beginInteraction = () => {
    if (disabled || loading || verifying || verified || !challenge) return;
    if (startedAtRef.current === null) {
      startedAtRef.current = Date.now();
      interactionCountRef.current = 1;
    }
    setError('');
  };

  const changeProgress = (value: number) => {
    if (disabled || loading || verifying || verified || !challenge) return;
    if (startedAtRef.current === null) startedAtRef.current = Date.now();
    interactionCountRef.current += 1;
    progressRef.current = value;
    setProgress(value);
  };

  const finishInteraction = async (resetIncomplete = true) => {
    if (disabled || loading || verifying || verified || !challenge) return;
    if (progressRef.current < 98 || startedAtRef.current === null) {
      if (!resetIncomplete) return;
      progressRef.current = 0;
      setProgress(0);
      startedAtRef.current = null;
      interactionCountRef.current = 0;
      return;
    }

    setVerifying(true);
    try {
      const elapsed = Date.now() - startedAtRef.current;
      if (elapsed < 320) {
        await new Promise((resolve) => window.setTimeout(resolve, 320 - elapsed));
      }
      const result = await apiRequest<HumanChallengeVerification>({
        method: 'POST',
        url: '/auth/human-challenge/verify',
        data: {
          challenge_id: challenge.challenge_id,
          elapsed_ms: Date.now() - startedAtRef.current,
          interaction_count: interactionCountRef.current,
        },
      });
      setVerified(true);
      setProgress(100);
      progressRef.current = 100;
      setError('');
      onVerified(result.challenge_id);
    } catch (requestError) {
      const message = requestError instanceof Error ? requestError.message : '验证未完成，请重试';
      setError(message);
      setProgress(0);
      progressRef.current = 0;
      startedAtRef.current = null;
      interactionCountRef.current = 0;
      if (requestError instanceof ApiError && requestError.code === 'HUMAN_CHALLENGE_EXPIRED') {
        await issueChallenge();
      }
    } finally {
      setVerifying(false);
    }
  };

  const updateProgressFromPointer = (event: PointerEvent<HTMLDivElement>) => {
    const track = trackRef.current;
    if (!track) return;
    const bounds = track.getBoundingClientRect();
    const value = Math.max(0, Math.min(100, ((event.clientX - bounds.left) / bounds.width) * 100));
    changeProgress(Math.round(value));
  };

  const handlePointerDown = (event: PointerEvent<HTMLDivElement>) => {
    if (disabled || loading || verifying || verified || !challenge) return;
    draggingRef.current = true;
    event.currentTarget.setPointerCapture(event.pointerId);
    beginInteraction();
    updateProgressFromPointer(event);
  };

  const handlePointerMove = (event: PointerEvent<HTMLDivElement>) => {
    if (!draggingRef.current) return;
    updateProgressFromPointer(event);
  };

  const handlePointerUp = (event: PointerEvent<HTMLDivElement>) => {
    if (!draggingRef.current) return;
    updateProgressFromPointer(event);
    draggingRef.current = false;
    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId);
    }
    void finishInteraction(true);
  };

  const handlePointerCancel = () => {
    draggingRef.current = false;
    progressRef.current = 0;
    setProgress(0);
    startedAtRef.current = null;
    interactionCountRef.current = 0;
  };

  const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (disabled || loading || verifying || verified || !challenge) return;
    let nextProgress: number | null = null;
    if (event.key === 'ArrowRight' || event.key === 'ArrowUp') nextProgress = Math.min(100, progressRef.current + 10);
    if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') nextProgress = Math.max(0, progressRef.current - 10);
    if (event.key === 'Home') nextProgress = 0;
    if (event.key === 'End') nextProgress = 100;
    if (nextProgress === null) return;
    event.preventDefault();
    beginInteraction();
    changeProgress(nextProgress);
  };

  const handleKeyUp = (event: KeyboardEvent<HTMLDivElement>) => {
    if (!['ArrowRight', 'ArrowUp', 'ArrowLeft', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    void finishInteraction(false);
  };

  const knobOffset = `calc(${progress}% - ${(progress * 0.4).toFixed(2)}px)`;

  return (
    <div className='space-y-1.5'>
      <div
        ref={trackRef}
        aria-disabled={disabled || loading || verifying || verified || !challenge}
        aria-label={verified ? '人机验证已通过' : '向右滑动完成人机验证'}
        aria-valuemax={100}
        aria-valuemin={0}
        aria-valuenow={progress}
        aria-valuetext={verified ? '验证通过' : `${progress}%`}
        className={`relative h-12 touch-none select-none overflow-hidden rounded-full border transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 ${disabled || loading || verifying || verified || !challenge ? 'cursor-not-allowed' : 'cursor-grab active:cursor-grabbing'} ${verified ? 'border-success-300 bg-success-50 dark:border-success-500/30 dark:bg-success-500/10' : 'border-default-200 bg-default-100/70 dark:border-default-100/20 dark:bg-default/60'}`}
        role='slider'
        tabIndex={disabled || loading || verifying || verified || !challenge ? -1 : 0}
        onKeyDown={handleKeyDown}
        onKeyUp={handleKeyUp}
        onPointerCancel={handlePointerCancel}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={handlePointerUp}
      >
        <div className={`absolute inset-y-0 left-0 transition-[width] duration-100 ${verified ? 'bg-success-100 dark:bg-success-500/15' : 'bg-primary-100/70 dark:bg-primary-500/15'}`} style={{ width: `${progress}%` }} />
        <div className={`pointer-events-none absolute left-12 right-4 top-1/2 -translate-y-1/2 text-center text-sm ${verified ? 'font-medium text-success-700 dark:text-success-300' : 'text-default-500'}`}>
          {loading ? '正在准备验证…' : verifying ? '正在确认…' : verified ? '验证通过' : '向右滑动完成验证'}
        </div>
        <div
          className={`pointer-events-none absolute top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full shadow-md transition-[left,background-color] duration-100 ${verified ? 'bg-success text-white' : 'bg-white text-primary dark:bg-default-100'}`}
          style={{ left: knobOffset }}
        >
          {verified ? <IoCheckmark className='text-xl' /> : <IoShieldCheckmarkOutline className='text-xl' />}
        </div>
      </div>
      <div className='flex min-h-5 items-center justify-between px-2 text-xs'>
        <span className={error ? 'text-danger' : 'text-default-400'}>{error || (verified ? `本次验证仅可用于一次${purpose === 'register' ? '注册' : '登录'}` : '支持鼠标、触屏和键盘')}</span>
        {!loading && !verified && !challenge && (
          <Button isIconOnly aria-label='重新加载人机验证' size='sm' variant='light' onPress={() => void issueChallenge()}><IoRefresh /></Button>
        )}
      </div>
    </div>
  );
}
