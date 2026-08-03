import { Button } from '@heroui/button';
import { Card, CardBody, CardHeader } from '@heroui/card';
import { Spinner } from '@heroui/spinner';
import { Tooltip } from '@heroui/tooltip';
import { useEffect, useMemo, useState } from 'react';
import { LuChevronLeft, LuChevronRight } from 'react-icons/lu';

import { apiRequest } from '@/api/client';
import type { SignCalendar, SignCalendarDay } from '@/types/sign';

function monthKey (date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function shiftMonth (month: string, offset: number): string {
  const [year, monthIndex] = month.split('-').map(Number);
  return monthKey(new Date(year, monthIndex - 1 + offset, 1));
}

function dayTone (day: SignCalendarDay | undefined, isFuture: boolean): { className: string; label: string } {
  if (isFuture) return { className: 'bg-default-100/50 text-default-300', label: '未到' };
  if (!day || day.total === 0) return { className: 'bg-default-100 text-default-400', label: '无任务' };
  if (day.failed > 0 && day.completed > 0) return { className: 'bg-warning-400/90 text-white', label: '部分失败' };
  if (day.failed > 0) return { className: 'bg-danger-500/90 text-white', label: '有失败' };
  if (day.pending > 0) return { className: 'bg-primary-300/80 text-white', label: '待执行' };
  if (day.completed > 0) return { className: 'bg-success-500/90 text-white', label: '全部完成' };
  return { className: 'bg-default-200 text-default-500', label: '已取消' };
}

export default function SignCalendarCard () {
  const currentMonth = monthKey(new Date());
  const [month, setMonth] = useState(currentMonth);
  const [data, setData] = useState<SignCalendar | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let stale = false;
    setLoading(true);
    apiRequest<SignCalendar>({ url: '/sign-tasks/calendar', params: { month } })
      .then((result) => { if (!stale) setData(result); })
      .catch(() => { if (!stale) setData({ month, days: [] }); })
      .finally(() => { if (!stale) setLoading(false); });
    return () => { stale = true; };
  }, [month]);

  const { cells, dayMap } = useMemo(() => {
    const [year, monthIndex] = month.split('-').map(Number);
    const first = new Date(year, monthIndex - 1, 1);
    const totalDays = new Date(year, monthIndex, 0).getDate();
    // 周一为一周的第一天
    const leading = (first.getDay() + 6) % 7;
    const list: Array<number | null> = [...Array.from({ length: leading }, () => null)];
    for (let day = 1; day <= totalDays; day++) list.push(day);
    const map = new Map((data?.days ?? []).map((day) => [day.date, day]));
    return { cells: list, dayMap: map };
  }, [month, data]);

  const todayKey = new Date();
  const todayString = `${monthKey(todayKey)}-${String(todayKey.getDate()).padStart(2, '0')}`;

  return (
    <Card className='tf-glass-card h-full rounded-[26px]'>
      <CardHeader className='flex items-center justify-between px-5 pt-5'>
        <div>
          <h3 className='font-semibold'>签到日历</h3>
          <p className='text-xs text-default-500'>按天查看签到完成情况，一眼确认有没有断签</p>
        </div>
        <div className='flex items-center gap-1'>
          <Button isIconOnly size='sm' variant='light' aria-label='上一月' onPress={() => setMonth((value) => shiftMonth(value, -1))}><LuChevronLeft /></Button>
          <span className='min-w-20 text-center text-sm font-medium'>{month}</span>
          <Button isIconOnly size='sm' variant='light' aria-label='下一月' isDisabled={month >= currentMonth} onPress={() => setMonth((value) => shiftMonth(value, 1))}><LuChevronRight /></Button>
        </div>
      </CardHeader>
      <CardBody className='gap-3 px-5 pb-5'>
        {loading
          ? <div className='flex min-h-48 items-center justify-center'><Spinner size='sm' label='正在加载日历' /></div>
          : <>
            <div className='grid grid-cols-7 gap-1.5 text-center text-[11px] text-default-400'>
              {['一', '二', '三', '四', '五', '六', '日'].map((weekday) => <span key={weekday}>{weekday}</span>)}
            </div>
            <div className='grid grid-cols-7 gap-1.5'>
              {cells.map((day, index) => {
                if (day === null) return <span key={`empty-${index}`} />;
                const dateString = `${month}-${String(day).padStart(2, '0')}`;
                const info = dayMap.get(dateString);
                const isFuture = dateString > todayString;
                const tone = dayTone(info, isFuture);
                const tooltip = info && info.total > 0
                  ? `${dateString} · 完成 ${info.completed} / 失败 ${info.failed}${info.pending ? ` / 待执行 ${info.pending}` : ''}`
                  : `${dateString} · ${tone.label}`;
                return (
                  <Tooltip key={dateString} content={tooltip} closeDelay={0}>
                    <div className={`flex aspect-square items-center justify-center rounded-lg text-xs font-medium transition ${tone.className} ${dateString === todayString ? 'ring-2 ring-primary ring-offset-1 ring-offset-background' : ''}`}>
                      {day}
                    </div>
                  </Tooltip>
                );
              })}
            </div>
            <div className='flex flex-wrap items-center gap-3 pt-1 text-[11px] text-default-500'>
              <span className='flex items-center gap-1.5'><span className='h-2.5 w-2.5 rounded bg-success-500/90' />全部完成</span>
              <span className='flex items-center gap-1.5'><span className='h-2.5 w-2.5 rounded bg-warning-400/90' />部分失败</span>
              <span className='flex items-center gap-1.5'><span className='h-2.5 w-2.5 rounded bg-danger-500/90' />有失败</span>
              <span className='flex items-center gap-1.5'><span className='h-2.5 w-2.5 rounded bg-primary-300/80' />待执行</span>
              <span className='flex items-center gap-1.5'><span className='h-2.5 w-2.5 rounded bg-default-100 ring-1 ring-default-200' />无任务</span>
            </div>
          </>}
      </CardBody>
    </Card>
  );
}
