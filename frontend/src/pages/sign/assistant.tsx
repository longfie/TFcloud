import { Avatar } from '@heroui/avatar';
import { Button } from '@heroui/button';
import { Card, CardBody, CardHeader } from '@heroui/card';
import { Chip } from '@heroui/chip';
import { Spinner } from '@heroui/spinner';
import { Textarea } from '@heroui/input';
import { Tooltip } from '@heroui/tooltip';
import { motion } from 'motion/react';
import { type KeyboardEvent, useEffect, useRef, useState } from 'react';
import { toast } from 'react-hot-toast';
import {
  LuCircleAlert,
  LuClock3,
  LuMessageCircleQuestion,
  LuRotateCcw,
  LuSend,
  LuShieldCheck,
  LuSparkles,
} from 'react-icons/lu';
import ReactMarkdown from 'react-markdown';
import { useNavigate } from 'react-router-dom';
import remarkGfm from 'remark-gfm';

import { apiRequest, apiStream } from '@/api/client';
import { confirmCard } from '@/components/confirm_card';
import PlatformLogo from '@/components/platform_logo';
import { useSignAuth } from '@/contexts/auth';
import type {
  AstrBotMessage,
  AstrBotStatus,
  AstrBotStreamEvent,
  Paginated,
} from '@/types/sign';
import { errorMessage, formatDateTime } from '@/utils/sign';

const DEFAULT_WELCOME = '你好，我是天方云签智能助手。你可以问我如何添加平台账号、查看签到结果或设置定时计划。';

const starterQuestions = [
  {
    icon: <LuMessageCircleQuestion />,
    title: '添加贴吧账号',
    description: '了解扫码、短信等登录方式',
    question: '如何添加百度贴吧账号？',
  },
  {
    icon: <LuClock3 />,
    title: '设置签到计划',
    description: '自动计划与定时计划的区别',
    question: '自动计划和定时计划有什么区别？',
  },
  {
    icon: <LuShieldCheck />,
    title: '处理账号失效',
    description: '登录状态失效后的恢复方法',
    question: '平台账号失效后应该怎么处理？',
  },
];

function MessageContent ({ content }: { content: string }) {
  return (
    <ReactMarkdown
      remarkPlugins={[remarkGfm]}
      className='max-w-none break-words text-[15px] leading-7 [&_a]:font-medium [&_a]:text-primary [&_a]:underline [&_blockquote]:my-3 [&_blockquote]:border-l-3 [&_blockquote]:border-primary/30 [&_blockquote]:pl-3 [&_code]:rounded-md [&_code]:bg-default-100 [&_code]:px-1.5 [&_code]:py-0.5 [&_li]:ml-5 [&_ol]:list-decimal [&_p+p]:mt-3 [&_pre]:my-3 [&_pre]:overflow-x-auto [&_pre]:rounded-2xl [&_pre]:bg-slate-950 [&_pre]:p-4 [&_pre]:text-slate-100 [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_ul]:list-disc'
    >
      {content}
    </ReactMarkdown>
  );
}

function TypingDots () {
  return (
    <div className='flex h-7 items-center gap-1.5 px-1'>
      {[0, 1, 2].map((index) => (
        <motion.span
          key={index}
          className='h-1.5 w-1.5 rounded-full bg-primary/65'
          animate={{ opacity: [0.35, 1, 0.35], y: [0, -3, 0] }}
          transition={{ duration: 0.9, repeat: Infinity, delay: index * 0.14 }}
        />
      ))}
    </div>
  );
}

export default function AssistantPage () {
  const [status, setStatus] = useState<AstrBotStatus | null>(null);
  const [messages, setMessages] = useState<AstrBotMessage[]>([]);
  const [draft, setDraft] = useState('');
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(false);
  const [resetting, setResetting] = useState(false);
  const scrollRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const { user } = useSignAuth();
  const navigate = useNavigate();

  const load = async () => {
    const [assistantStatus, history] = await Promise.all([
      apiRequest<AstrBotStatus>({ url: '/assistant/status' }),
      apiRequest<Paginated<AstrBotMessage>>({
        url: '/assistant/messages',
        params: { page: 1, per_page: 100 },
      }),
    ]);
    setStatus(assistantStatus);
    setMessages(history.items);
  };

  useEffect(() => {
    load()
      .catch((error) => toast.error(errorMessage(error)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    const frame = requestAnimationFrame(() => {
      scrollRef.current?.scrollTo({
        top: scrollRef.current.scrollHeight,
        behavior: messages.length > 1 ? 'smooth' : 'auto',
      });
    });
    return () => cancelAnimationFrame(frame);
  }, [messages, sending]);

  const send = async (suggestedContent?: string) => {
    const content = (suggestedContent ?? draft).trim();
    if (!content || sending || !status?.ready) return;

    const createdAt = new Date().toISOString();
    const optimisticUser: AstrBotMessage = {
      id: -Date.now(),
      role: 'user',
      content,
      status: 'completed',
      error: null,
      created_at: createdAt,
      updated_at: createdAt,
    };
    const optimisticAssistant: AstrBotMessage = {
      id: optimisticUser.id - 1,
      role: 'assistant',
      content: '',
      status: 'pending',
      error: null,
      created_at: createdAt,
      updated_at: createdAt,
    };

    let currentUserId = optimisticUser.id;
    let currentAssistantId = optimisticAssistant.id;
    let streamError = '';
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 200000);

    setDraft('');
    setMessages((current) => [...current, optimisticUser, optimisticAssistant]);
    setSending(true);

    try {
      await apiStream<AstrBotStreamEvent>(
        '/assistant/messages/stream',
        { message: content },
        (event) => {
          if (event.event === 'started') {
            const previousUserId = currentUserId;
            const previousAssistantId = currentAssistantId;
            currentUserId = event.data.user_message.id;
            currentAssistantId = event.data.assistant_message.id;
            setMessages((current) => current.map((item) => {
              if (item.id === previousUserId) return event.data.user_message;
              if (item.id === previousAssistantId) return event.data.assistant_message;
              return item;
            }));
            return;
          }

          if (event.event === 'delta' || event.event === 'replace') {
            setMessages((current) => current.map((item) => item.id === currentAssistantId
              ? {
                  ...item,
                  content: event.event === 'replace'
                    ? event.data.content
                    : item.content + event.data.content,
                  status: 'pending',
                }
              : item));
            return;
          }

          if (event.event === 'done') {
            setMessages((current) => current.map((item) => {
              if (item.id === currentUserId) return event.data.user_message;
              if (item.id === currentAssistantId) return event.data.assistant_message;
              return item;
            }));
            return;
          }

          if (event.event === 'error') {
            streamError = event.data.message || '智能助手回复失败';
            setMessages((current) => current.map((item) => item.id === currentAssistantId
              ? {
                  ...item,
                  status: 'failed',
                  error: { code: event.data.code, message: streamError },
                }
              : item));
          }
        },
        controller.signal
      );
      if (streamError) throw new Error(streamError);
    } catch (error) {
      const message = error instanceof DOMException && error.name === 'AbortError'
        ? '智能助手响应超时，请稍后重试'
        : errorMessage(error);
      toast.error(message);
      try {
        const history = await apiRequest<Paginated<AstrBotMessage>>({
          url: '/assistant/messages',
          params: { page: 1, per_page: 100 },
        });
        setMessages(history.items);
      } catch {
        setMessages((current) => current.map((item) => item.id === currentAssistantId
          ? { ...item, status: 'failed', error: { code: 'STREAM_FAILED', message } }
          : item));
      }
    } finally {
      window.clearTimeout(timeout);
      setSending(false);
      window.setTimeout(() => inputRef.current?.focus(), 50);
    }
  };

  const reset = async () => {
    if (sending || resetting || messages.length === 0) return;
    if (!(await confirmCard({ title: '开始新对话？', description: '当前对话记录会被清空，无法恢复。', confirmText: '开始新对话', tone: 'primary' }))) return;
    setResetting(true);
    try {
      await apiRequest<{ reset: boolean }>({ method: 'DELETE', url: '/assistant/messages' });
      setMessages([]);
      setDraft('');
      toast.success('已经开始新对话');
      window.setTimeout(() => inputRef.current?.focus(), 50);
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setResetting(false);
    }
  };

  const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      void send();
    }
  };

  if (loading) {
    return <div className='flex min-h-72 items-center justify-center'><Spinner label='正在连接智能助手' /></div>;
  }

  const ready = Boolean(status?.ready);
  const assistantName = status?.name || '天方助手';
  const welcomeMessage = status?.welcome_message?.trim() || DEFAULT_WELCOME;

  return (
    <Card className='tf-glass-card mx-auto min-h-[700px] max-w-6xl overflow-hidden rounded-[30px] border border-white/40 shadow-[0_24px_70px_-36px_rgba(37,99,235,0.35)] lg:h-[calc(100vh-8.7rem)]'>
      <CardHeader className='relative flex min-h-[76px] items-center justify-between overflow-hidden border-b border-divider/50 bg-content1/70 px-5 py-4 backdrop-blur-xl md:px-7'>
        <div className='pointer-events-none absolute -left-16 -top-24 h-44 w-44 rounded-full bg-primary/10 blur-3xl' />
        <div className='relative flex min-w-0 items-center gap-3.5'>
          <div className='relative'>
            <div className='grid h-12 w-12 place-items-center overflow-hidden rounded-[18px] border border-white/80 bg-white shadow-sm dark:border-white/10'>
              <PlatformLogo size={42} />
            </div>
            <span className={`absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-[3px] border-content1 ${ready ? 'bg-success' : 'bg-default-300'}`} />
          </div>
          <div className='min-w-0'>
            <div className='flex items-center gap-2'>
              <h2 className='truncate text-[17px] font-semibold tracking-tight'>{assistantName}</h2>
              <Chip size='sm' variant='flat' color={ready ? 'success' : 'default'} className='h-5 text-[11px]'>{ready ? '在线' : '未启用'}</Chip>
            </div>
            <p className='mt-0.5 truncate text-xs text-default-500'>随时为你解答账号与签到问题</p>
          </div>
        </div>
        <Tooltip content='清空当前记录，开始一段新对话'>
          <Button
            size='sm'
            variant='flat'
            startContent={<LuRotateCcw />}
            isLoading={resetting}
            isDisabled={messages.length === 0 || sending}
            onPress={() => void reset()}
            className='relative rounded-xl'
          >
            <span className='hidden sm:inline'>新对话</span>
          </Button>
        </Tooltip>
      </CardHeader>

      <CardBody className='relative min-h-0 gap-0 bg-gradient-to-b from-default-50/60 via-content1/35 to-content1/70 p-0 dark:from-content2/35'>
        <div className='pointer-events-none absolute right-[-8rem] top-12 h-72 w-72 rounded-full bg-secondary/5 blur-3xl' />
        <div className='pointer-events-none absolute bottom-12 left-[-8rem] h-72 w-72 rounded-full bg-primary/5 blur-3xl' />

        {!ready ? (
          <div className='relative flex flex-1 flex-col items-center justify-center px-6 py-20 text-center'>
            <div className='grid h-20 w-20 place-items-center rounded-[28px] bg-warning/10 text-4xl text-warning'><LuCircleAlert /></div>
            <h3 className='mt-5 text-lg font-semibold'>智能助手尚未准备好</h3>
            <p className='mt-2 max-w-md text-sm leading-6 text-default-500'>需要管理员完成智能助手服务配置后才能开始对话。</p>
            {user?.role === 'admin' && <Button color='primary' variant='flat' className='mt-5' onPress={() => navigate('/TFYT/admin/settings')}>前往配置</Button>}
          </div>
        ) : (
          <>
            <div ref={scrollRef} className='relative flex-1 overflow-y-auto overscroll-contain px-4 py-6 md:px-8 md:py-8'>
              <div className='mx-auto max-w-4xl space-y-6'>
                <motion.div
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  className='flex items-start gap-3.5'
                >
                  <div className='grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-[13px] border border-white/80 bg-white shadow-sm dark:border-white/10'>
                    <PlatformLogo size={34} />
                  </div>
                  <div className='min-w-0 max-w-[88%] md:max-w-[78%]'>
                    <div className='mb-1.5 flex items-center gap-2 px-1'>
                      <span className='text-xs font-medium text-default-600'>{assistantName}</span>
                      <LuSparkles className='text-xs text-warning' />
                    </div>
                    <div className='rounded-[22px] rounded-tl-md border border-divider/60 bg-content1/90 px-4 py-3.5 shadow-[0_8px_28px_-20px_rgba(15,23,42,0.35)] backdrop-blur'>
                      <MessageContent content={welcomeMessage} />
                    </div>
                  </div>
                </motion.div>

                {messages.length === 0 && (
                  <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.08 }}
                    className='ml-0 grid gap-2.5 pt-1 sm:ml-[3.25rem] sm:grid-cols-3'
                  >
                    {starterQuestions.map((item) => (
                      <button
                        key={item.title}
                        type='button'
                        disabled={sending}
                        onClick={() => void send(item.question)}
                        className='group rounded-[18px] border border-divider/60 bg-content1/65 p-3.5 text-left shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-primary/30 hover:bg-content1 hover:shadow-md disabled:pointer-events-none disabled:opacity-50'
                      >
                        <span className='mb-3 grid h-8 w-8 place-items-center rounded-xl bg-primary/10 text-primary transition group-hover:bg-primary group-hover:text-white'>{item.icon}</span>
                        <span className='block text-sm font-medium'>{item.title}</span>
                        <span className='mt-1 block text-[11px] leading-5 text-default-400'>{item.description}</span>
                      </button>
                    ))}
                  </motion.div>
                )}

                {messages.map((message) => {
                  const mine = message.role === 'user';
                  return (
                    <motion.div
                      key={message.id}
                      initial={{ opacity: 0, y: 7 }}
                      animate={{ opacity: 1, y: 0 }}
                      className={`flex items-start gap-3.5 ${mine ? 'flex-row-reverse' : ''}`}
                    >
                      {mine
                        ? <Avatar size='sm' name={user?.username || 'U'} className='mt-0.5 shrink-0 shadow-sm' />
                        : <div className='grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-[13px] border border-white/80 bg-white shadow-sm dark:border-white/10'><PlatformLogo size={34} /></div>}
                      <div className={`flex max-w-[88%] flex-col md:max-w-[78%] ${mine ? 'items-end' : 'items-start'}`}>
                        <span className='mb-1.5 px-1 text-xs font-medium text-default-500'>{mine ? (user?.username || '你') : assistantName}</span>
                        <div className={mine
                          ? 'rounded-[22px] rounded-tr-md bg-gradient-to-br from-primary to-blue-500 px-4 py-3 text-white shadow-[0_12px_28px_-18px_rgba(37,99,235,0.8)]'
                          : `rounded-[22px] rounded-tl-md border px-4 py-3.5 shadow-[0_8px_28px_-20px_rgba(15,23,42,0.35)] backdrop-blur ${message.status === 'failed' ? 'border-danger/20 bg-danger/5' : 'border-divider/60 bg-content1/90'}`}>
                          {message.content
                            ? (
                                <>
                                  <MessageContent content={message.content} />
                                  {message.status === 'pending' && <motion.span className='ml-1 inline-block h-4 w-0.5 translate-y-0.5 rounded-full bg-primary' animate={{ opacity: [1, 0.15, 1] }} transition={{ duration: 0.8, repeat: Infinity }} />}
                                </>
                              )
                            : message.status === 'pending'
                              ? <TypingDots />
                              : <p className='text-sm text-danger'>{message.error?.message || '回复生成失败'}</p>}
                        </div>
                        <span className='mt-1.5 px-1 text-[10px] text-default-400'>{formatDateTime(message.created_at)}</span>
                      </div>
                    </motion.div>
                  );
                })}
              </div>
            </div>

            <div className='relative border-t border-divider/50 bg-content1/75 p-3 backdrop-blur-xl md:px-6 md:py-4'>
              <div className='mx-auto flex max-w-4xl items-end gap-2 rounded-[22px] border border-divider/80 bg-content1 p-2 shadow-[0_12px_40px_-28px_rgba(15,23,42,0.6)] transition duration-200 focus-within:border-primary/40 focus-within:shadow-[0_14px_44px_-26px_rgba(37,99,235,0.45)]'>
                <Textarea
                  ref={inputRef}
                  aria-label='发送给智能助手'
                  minRows={1}
                  maxRows={5}
                  placeholder='输入你想了解的问题…'
                  value={draft}
                  isDisabled={sending}
                  maxLength={status?.max_message_length || 4000}
                  onValueChange={setDraft}
                  onKeyDown={handleKeyDown}
                  classNames={{
                    inputWrapper: 'bg-transparent shadow-none data-[hover=true]:bg-transparent group-data-[focus=true]:bg-transparent',
                    input: 'text-[15px] leading-6',
                  }}
                />
                <Button
                  isIconOnly
                  color='primary'
                  className='mb-1 h-10 w-10 shrink-0 rounded-[14px] shadow-sm'
                  aria-label='发送消息'
                  isLoading={sending}
                  isDisabled={!draft.trim()}
                  onPress={() => void send()}
                >
                  <LuSend className='text-lg' />
                </Button>
              </div>
              <p className='mt-2 text-center text-[10px] tracking-wide text-default-400'>Enter 发送 · Shift + Enter 换行 · 重要信息请以实际页面为准</p>
            </div>
          </>
        )}
      </CardBody>
    </Card>
  );
}
