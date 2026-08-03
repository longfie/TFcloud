import { Card, CardProps } from '@heroui/card';
import clsx from 'clsx';
import React from 'react';

export interface HoverEffectCardProps extends CardProps {
  children: React.ReactNode
  maxXRotation?: number
  maxYRotation?: number
  lightClassName?: string
  lightStyle?: React.CSSProperties
}

const HoverEffectCard: React.FC<HoverEffectCardProps> = (props) => {
  const {
    children,
    maxXRotation = 5,
    maxYRotation = 5,
    className,
    style,
    lightClassName,
    lightStyle,
    ...cardProps
  } = props;
  const cardRef = React.useRef<HTMLDivElement | null>(null);
  const lightRef = React.useRef<HTMLDivElement | null>(null);
  const frameRef = React.useRef<number | null>(null);

  React.useEffect(() => () => {
    if (frameRef.current !== null) window.cancelAnimationFrame(frameRef.current);
  }, []);

  const reset = () => {
    if (frameRef.current !== null) window.cancelAnimationFrame(frameRef.current);
    frameRef.current = null;
    if (lightRef.current) lightRef.current.style.opacity = '0';
    if (cardRef.current) {
      cardRef.current.style.transition = 'transform 180ms ease-out';
      cardRef.current.style.transform = 'perspective(1000px) rotateX(0deg) rotateY(0deg)';
    }
  };

  return (
    <Card
      {...cardProps}
      ref={cardRef}
      className={clsx(
        'relative overflow-hidden bg-opacity-50',
        className
      )}
      style={{
        transform: 'perspective(1000px) rotateX(0deg) rotateY(0deg)',
        ...style,
      }}
      onPointerLeave={reset}
      onPointerMove={(event: React.PointerEvent<HTMLDivElement>) => {
        if (event.pointerType === 'touch' || !cardRef.current) return;
        const { clientX, clientY } = event;
        if (frameRef.current !== null) window.cancelAnimationFrame(frameRef.current);
        frameRef.current = window.requestAnimationFrame(() => {
          const card = cardRef.current;
          const light = lightRef.current;
          if (!card || !light) return;
          const rect = card.getBoundingClientRect();
          const offsetX = clientX - rect.left;
          const offsetY = clientY - rect.top;
          const lightWidth = Number.parseFloat(String(lightStyle?.width ?? 150)) || 150;
          const lightHeight = Number.parseFloat(String(lightStyle?.height ?? 150)) || 150;
          const rotateX = ((offsetY - rect.height / 2) / Math.max(1, rect.height / 2)) * maxXRotation;
          const rotateY = -((offsetX - rect.width / 2) / Math.max(1, rect.width / 2)) * maxYRotation;
          card.style.transition = 'transform 60ms linear';
          card.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
          light.style.opacity = '1';
          light.style.transform = `translate3d(${offsetX - lightWidth / 2}px, ${offsetY - lightHeight / 2}px, 0)`;
          frameRef.current = null;
        });
      }}
    >
      <div
        ref={lightRef}
        className={clsx(
          'pointer-events-none absolute left-0 top-0 h-[150px] w-[150px] rounded-full bg-gradient-to-r from-primary-400 to-secondary-400 opacity-0 blur-[80px] transition-opacity duration-200',
          lightClassName
        )}
        style={{
          ...lightStyle,
        }}
      />
      {children}
    </Card>
  );
};

export default HoverEffectCard;
