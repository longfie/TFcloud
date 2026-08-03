import { Button } from "@heroui/button";
import { Card, CardBody, CardHeader } from "@heroui/card";
import { Input, Textarea } from "@heroui/input";
import {
  Modal,
  ModalBody,
  ModalContent,
  ModalFooter,
  ModalHeader,
} from "@heroui/modal";
import { Select, SelectItem } from "@heroui/select";
import { Spinner } from "@heroui/spinner";
import { Switch } from "@heroui/switch";
import { Pagination } from "@heroui/pagination";
import {
  Table,
  TableBody,
  TableCell,
  TableColumn,
  TableHeader,
  TableRow,
} from "@heroui/table";
import { Tab, Tabs } from "@heroui/tabs";
import { useEffect, useState } from "react";
import { toast } from "react-hot-toast";
import { LuMailCheck, LuRefreshCw, LuSave, LuSearch, LuSend, LuTrash2 } from "react-icons/lu";

import { apiRequest } from "@/api/client";
import { confirmCard } from "@/components/confirm_card";
import StatusChip from "@/components/sign/status_chip";
import type {
  MailRecord,
  MailSettings,
  MailSummary,
  MailTask,
  Paginated,
} from "@/types/sign";
import { errorMessage, formatDateTime } from "@/utils/sign";

const defaults: MailSettings = {
  enabled: false,
  smtp_host: "",
  smtp_port: 465,
  smtp_encryption: "ssl",
  smtp_username: "",
  smtp_password: "",
  smtp_password_configured: false,
  from_email: "",
  from_name: "天方云签",
  reply_to: "",
  batch_limit: 100,
  daily_summary_hour: 23,
};

const templateLabels: Record<string, string> = {
  admin_custom: "后台邮件",
  password_change_code: "修改密码验证码",
  credential_expired: "账号失效提醒",
  daily_sign_summary: "每日签到汇总",
  mail_test: "SMTP 测试",
  transactional: "系统通知",
};
const templateOptions = [
  { code: "all", label: "全部类型" },
  ...Object.entries(templateLabels).map(([code, label]) => ({ code, label })),
];

export default function AdminMailPage() {
  const [summary, setSummary] = useState<MailSummary | null>(null);
  const [tasks, setTasks] = useState<Paginated<MailTask> | null>(null);
  const [config, setConfig] = useState<MailSettings>(defaults);
  const [deliveries, setDeliveries] = useState<Paginated<MailRecord> | null>(null);
  const [deliveryPage, setDeliveryPage] = useState(1);
  const [deliveryStatus, setDeliveryStatus] = useState("all");
  const [deliveryTemplate, setDeliveryTemplate] = useState("all");
  const [deliveryKeyword, setDeliveryKeyword] = useState("");
  const [deliveryQuery, setDeliveryQuery] = useState("");
  const [saving, setSaving] = useState(false);
  const [testRecipient, setTestRecipient] = useState("");
  const [testing, setTesting] = useState(false);
  const [sending, setSending] = useState(false);
  const [compose, setCompose] = useState({
    recipient_type: "emails",
    emails: "",
    user_ids: "",
    subject: "",
    html_body: "",
    text_body: "",
  });
  const [records, setRecords] = useState<{
    task: MailTask;
    rows: MailRecord[];
  } | null>(null);
  const load = async () => {
    const [s, t, c] = await Promise.all([
      apiRequest<MailSummary>({ url: "/admin/mail/summary" }),
      apiRequest<Paginated<MailTask>>({
        url: "/admin/mail/tasks",
        params: { limit: 50 },
      }),
      apiRequest<MailSettings>({ url: "/admin/settings/mail" }),
    ]);
    setSummary(s);
    setTasks(t);
    setConfig(c);
  };
  useEffect(() => {
    load().catch((error) => toast.error(errorMessage(error)));
  }, []);
  const loadDeliveries = async () => {
    setDeliveries(await apiRequest<Paginated<MailRecord>>({
      url: "/admin/mail/records",
      params: {
        limit: 20,
        offset: (deliveryPage - 1) * 20,
        status: deliveryStatus === "all" ? undefined : deliveryStatus,
        template_code: deliveryTemplate === "all" ? undefined : deliveryTemplate,
        keyword: deliveryQuery || undefined,
      },
    }));
  };
  useEffect(() => {
    loadDeliveries().catch((error) => toast.error(errorMessage(error)));
  }, [deliveryPage, deliveryStatus, deliveryTemplate, deliveryQuery]);
  const save = async () => {
    setSaving(true);
    try {
      setConfig(
        await apiRequest<MailSettings>({
          method: "PATCH",
          url: "/admin/settings/mail",
          data: config,
        }),
      );
      toast.success("邮件配置已保存");
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSaving(false);
    }
  };
  const sendTest = async () => {
    setTesting(true);
    try {
      await apiRequest({
        method: "POST",
        url: "/admin/mail/test",
        data: { recipient: testRecipient },
      });
      toast.success("测试邮件发送成功");
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setTesting(false);
    }
  };
  const send = async () => {
    setSending(true);
    try {
      const data = {
        ...compose,
        user_ids: compose.user_ids
          .split(/[,;\s]+/)
          .map(Number)
          .filter(Boolean),
      };
      const task = await apiRequest<MailTask>({
        method: "POST",
        url: "/admin/mail/compose",
        data,
      });
      toast.success(`邮件任务已进入队列，共 ${task.total_count} 个收件人`);
      setCompose((c) => ({ ...c, subject: "", html_body: "", text_body: "" }));
      await load();
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSending(false);
    }
  };
  const showRecords = async (task: MailTask) => {
    try {
      setRecords({
        task,
        rows: await apiRequest<MailRecord[]>({
          url: `/admin/mail/tasks/${task.task_no}/records`,
        }),
      });
    } catch (error) {
      toast.error(errorMessage(error));
    }
  };
  const retry = async (task: MailTask) => {
    try {
      const result = await apiRequest<MailTask>({
        method: "POST",
        url: `/admin/mail/tasks/${task.task_no}/retry`,
      });
      toast.success(`失败邮件已重新进入队列，任务状态：${result.status}`);
      await load();
    } catch (error) {
      toast.error(errorMessage(error));
    }
  };
  const removeDelivery = async (row: MailRecord) => {
    if (!(await confirmCard({ title: "删除这条邮件记录？", description: `发送给 ${row.recipient} 的记录删除后无法恢复。`, confirmText: "删除记录" }))) return;
    try {
      await apiRequest<null>({ method: "DELETE", url: `/admin/mail/records/${row.id}` });
      toast.success("邮件发送记录已删除");
      await Promise.all([load(), loadDeliveries()]);
    } catch (error) {
      toast.error(errorMessage(error));
    }
  };
  if (!summary || !tasks)
    return (
      <div className="flex min-h-60 items-center justify-center">
        <Spinner label="正在加载邮件中心" />
      </div>
    );
  const stats = [
    ["邮件任务", summary.tasks],
    ["等待发送", summary.pending],
    ["已发送", summary.sent],
    ["发送失败", summary.failed],
    ["今日发送", summary.today_sent],
  ] as const;
  return (
    <div className="flex flex-col gap-5">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        {stats.map(([label, value]) => (
          <Card key={label} className="tf-glass-card rounded-[22px]">
            <CardBody className="gap-1 p-4">
              <p className="text-2xl font-semibold">{value}</p>
              <p className="text-xs text-default-500">{label}</p>
            </CardBody>
          </Card>
        ))}
      </div>
      <Tabs
        aria-label="邮件中心功能"
        variant="solid"
        classNames={{
          tabList: "tf-glass rounded-2xl p-1.5",
          cursor: "bg-primary",
          tabContent: "group-data-[selected=true]:text-white",
        }}
      >
        <Tab key="compose" title="发送邮件">
          <Card className="tf-glass-card mt-4 rounded-[26px]">
            <CardHeader className="gap-3 px-6 pt-6">
              <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary/10 text-xl text-primary">
                <LuSend />
              </div>
              <div>
                <h3 className="font-semibold">新建邮件</h3>
                <p className="text-xs text-default-500">
                  支持指定邮箱、指定用户或全部启用用户
                </p>
              </div>
            </CardHeader>
            <CardBody className="gap-4 px-6 pb-6">
              <Select
                label="收件人范围"
                selectedKeys={[compose.recipient_type]}
                onSelectionChange={(keys) =>
                  setCompose((c) => ({
                    ...c,
                    recipient_type: String(Array.from(keys)[0] || "emails"),
                  }))
                }
              >
                <SelectItem key="emails">指定邮箱</SelectItem>
                <SelectItem key="user_ids">指定用户 UID</SelectItem>
                <SelectItem key="all_active">全部启用用户</SelectItem>
              </Select>
              {compose.recipient_type === "emails" && (
                <Textarea
                  label="收件邮箱"
                  description="多个邮箱可用逗号、分号或换行分隔"
                  value={compose.emails}
                  onValueChange={(value) =>
                    setCompose((c) => ({ ...c, emails: value }))
                  }
                />
              )}
              {compose.recipient_type === "user_ids" && (
                <Input
                  label="用户 UID"
                  description="多个 UID 用逗号分隔，只会发送给已填写邮箱的用户"
                  value={compose.user_ids}
                  onValueChange={(value) =>
                    setCompose((c) => ({ ...c, user_ids: value }))
                  }
                />
              )}
              <Input
                label="邮件主题"
                value={compose.subject}
                onValueChange={(value) =>
                  setCompose((c) => ({ ...c, subject: value }))
                }
              />
              <Textarea
                label="HTML 正文"
                minRows={8}
                description="支持基础 HTML；若留空则使用纯文本正文"
                value={compose.html_body}
                onValueChange={(value) =>
                  setCompose((c) => ({ ...c, html_body: value }))
                }
              />
              <Textarea
                label="纯文本正文"
                minRows={4}
                value={compose.text_body}
                onValueChange={(value) =>
                  setCompose((c) => ({ ...c, text_body: value }))
                }
              />
              <div className="flex justify-end">
                <Button
                  color="primary"
                  className="tf-soft-button"
                  startContent={<LuSend />}
                  isLoading={sending}
                  onPress={() => void send()}
                >
                  立即发送
                </Button>
              </div>
            </CardBody>
          </Card>
        </Tab>
        <Tab key="tasks" title="邮件任务">
          <Card className="tf-glass-card tf-table-card mt-4">
            <CardBody className="overflow-x-auto p-0">
              <Table aria-label="邮件任务" removeWrapper>
                <TableHeader>
                  <TableColumn>主题</TableColumn>
                  <TableColumn>创建人</TableColumn>
                  <TableColumn>状态</TableColumn>
                  <TableColumn>发送结果</TableColumn>
                  <TableColumn>时间</TableColumn>
                  <TableColumn align="end">操作</TableColumn>
                </TableHeader>
                <TableBody emptyContent="暂无邮件任务">
                  {tasks.items.map((task) => (
                    <TableRow key={task.task_no}>
                      <TableCell>
                        <p className="max-w-72 truncate text-sm font-medium">
                          {task.subject}
                        </p>
                        <p className="font-mono text-xs text-default-400">
                          {task.task_no.slice(0, 12)}
                        </p>
                      </TableCell>
                      <TableCell>{task.username || "系统"}</TableCell>
                      <TableCell>
                        <StatusChip status={task.status} />
                      </TableCell>
                      <TableCell>
                        <span className="text-success">
                          {task.success_count}
                        </span>{" "}
                        /{" "}
                        <span className="text-danger">{task.failed_count}</span>{" "}
                        / {task.total_count}
                      </TableCell>
                      <TableCell>{formatDateTime(task.created_at)}</TableCell>
                      <TableCell>
                        <div className="flex justify-end gap-1">
                          <Button
                            size="sm"
                            variant="light"
                            onPress={() => void showRecords(task)}
                          >
                            明细
                          </Button>
                          {task.failed_count > 0 && (
                            <Button
                              isIconOnly
                              size="sm"
                              variant="light"
                              color="warning"
                              aria-label="重试失败邮件"
                              onPress={() => void retry(task)}
                            >
                              <LuRefreshCw />
                            </Button>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardBody>
          </Card>
        </Tab>
        <Tab key="records" title="发送记录">
          <div className="mt-4 flex flex-col gap-3">
            <div className="grid gap-2 lg:grid-cols-[1fr_160px_190px_auto]">
              <Input
                placeholder="搜索收件邮箱、用户名或邮件主题"
                startContent={<LuSearch />}
                value={deliveryKeyword}
                onValueChange={setDeliveryKeyword}
                onKeyDown={(event) => {
                  if (event.key === "Enter") {
                    setDeliveryPage(1);
                    setDeliveryQuery(deliveryKeyword);
                  }
                }}
              />
              <Select
                aria-label="发送状态"
                selectedKeys={[deliveryStatus]}
                onSelectionChange={(keys) => {
                  setDeliveryPage(1);
                  setDeliveryStatus(String(Array.from(keys)[0] || "all"));
                }}
              >
                <SelectItem key="all">全部状态</SelectItem>
                <SelectItem key="pending">等待发送</SelectItem>
                <SelectItem key="sending">发送中</SelectItem>
                <SelectItem key="sent">已发送</SelectItem>
                <SelectItem key="failed">发送失败</SelectItem>
              </Select>
              <Select
                items={templateOptions}
                aria-label="邮件类型"
                selectedKeys={[deliveryTemplate]}
                onSelectionChange={(keys) => {
                  setDeliveryPage(1);
                  setDeliveryTemplate(String(Array.from(keys)[0] || "all"));
                }}
              >
                {(item) => <SelectItem key={item.code}>{item.label}</SelectItem>}
              </Select>
              <Button variant="flat" startContent={<LuSearch />} onPress={() => { setDeliveryPage(1); setDeliveryQuery(deliveryKeyword); }}>筛选</Button>
            </div>
            <Card className="tf-glass-card tf-table-card">
              <CardBody className="overflow-x-auto p-0">
                <Table aria-label="邮件发送记录" removeWrapper>
                  <TableHeader>
                    <TableColumn>邮件</TableColumn>
                    <TableColumn>收件人</TableColumn>
                    <TableColumn>类型</TableColumn>
                    <TableColumn>状态</TableColumn>
                    <TableColumn>时间</TableColumn>
                    <TableColumn>错误</TableColumn>
                    <TableColumn align="end">操作</TableColumn>
                  </TableHeader>
                  <TableBody emptyContent="暂无符合条件的发送记录">
                    {(deliveries?.items || []).map((row) => (
                      <TableRow key={row.id}>
                        <TableCell><p className="max-w-64 truncate text-sm font-medium">{row.subject || "系统邮件"}</p><p className="font-mono text-xs text-default-400">{row.message_no.slice(0, 12)}</p></TableCell>
                        <TableCell><p className="text-sm">{row.recipient}</p><p className="text-xs text-default-400">{row.username || "系统收件人"}</p></TableCell>
                        <TableCell><span className="text-xs">{templateLabels[row.template_code || ""] || row.template_code || "系统通知"}</span></TableCell>
                        <TableCell><StatusChip status={row.status} /></TableCell>
                        <TableCell><p className="text-xs">{formatDateTime(row.sent_at || row.created_at)}</p><p className="text-xs text-default-400">尝试 {row.attempts} 次</p></TableCell>
                        <TableCell><p className="max-w-56 truncate text-xs text-danger">{row.last_error_message || "—"}</p></TableCell>
                        <TableCell><div className="flex justify-end"><Button isIconOnly size="sm" variant="light" color="danger" aria-label="删除邮件记录" isDisabled={row.status === "sending"} onPress={() => void removeDelivery(row)}><LuTrash2 /></Button></div></TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </CardBody>
            </Card>
            <div className="flex items-center justify-between"><p className="text-xs text-default-400">共 {deliveries?.total || 0} 条记录</p><Pagination showControls page={deliveryPage} total={Math.max(1, Math.ceil((deliveries?.total || 0) / 20))} onChange={setDeliveryPage} /></div>
          </div>
        </Tab>
        <Tab key="config" title="SMTP 配置">
          <Card className="tf-glass-card mt-4 rounded-[26px]">
            <CardHeader className="flex justify-between px-6 pt-6">
              <div className="flex items-center gap-3">
                <LuMailCheck className="text-xl text-success" />
                <div>
                  <h3 className="font-semibold">SMTP 发件服务</h3>
                  <p className="text-xs text-default-500">
                    密码加密保存，保存后不会回显
                  </p>
                </div>
              </div>
              <Switch
                isSelected={config.enabled}
                onValueChange={(value) =>
                  setConfig((c) => ({ ...c, enabled: value }))
                }
              >
                启用
              </Switch>
            </CardHeader>
            <CardBody className="gap-4 px-6 pb-6">
              <div className="grid gap-4 sm:grid-cols-3">
                <Input
                  label="SMTP 主机"
                  value={config.smtp_host}
                  onValueChange={(value) =>
                    setConfig((c) => ({ ...c, smtp_host: value }))
                  }
                />
                <Input
                  label="端口"
                  type="number"
                  value={String(config.smtp_port)}
                  onValueChange={(value) =>
                    setConfig((c) => ({ ...c, smtp_port: Number(value || 0) }))
                  }
                />
                <Select
                  label="加密方式"
                  selectedKeys={[config.smtp_encryption]}
                  onSelectionChange={(keys) =>
                    setConfig((c) => ({
                      ...c,
                      smtp_encryption: String(Array.from(keys)[0] || "ssl"),
                    }))
                  }
                >
                  <SelectItem key="ssl">SSL</SelectItem>
                  <SelectItem key="tls">STARTTLS</SelectItem>
                  <SelectItem key="none">不加密</SelectItem>
                </Select>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <Input
                  label="SMTP 用户名"
                  value={config.smtp_username}
                  onValueChange={(value) =>
                    setConfig((c) => ({ ...c, smtp_username: value }))
                  }
                />
                <Input
                  label={`SMTP 密码${config.smtp_password_configured ? "（已配置，留空不修改）" : ""}`}
                  type="password"
                  value={config.smtp_password}
                  onValueChange={(value) =>
                    setConfig((c) => ({ ...c, smtp_password: value }))
                  }
                />
                <Input
                  label="发件邮箱"
                  type="email"
                  value={config.from_email}
                  onValueChange={(value) =>
                    setConfig((c) => ({ ...c, from_email: value }))
                  }
                />
                <Input
                  label="发件名称"
                  value={config.from_name}
                  onValueChange={(value) =>
                    setConfig((c) => ({ ...c, from_name: value }))
                  }
                />
                <Input
                  label="回复邮箱"
                  type="email"
                  value={config.reply_to}
                  onValueChange={(value) =>
                    setConfig((c) => ({ ...c, reply_to: value }))
                  }
                />
                <Input
                  label="单次发送上限"
                  type="number"
                  value={String(config.batch_limit)}
                  onValueChange={(value) =>
                    setConfig((c) => ({
                      ...c,
                      batch_limit: Number(value || 1),
                    }))
                  }
                />
                <Input
                  label="每日签到汇总发送小时"
                  type="number"
                  min="0"
                  max="23"
                  description={`每天 ${String(config.daily_summary_hour).padStart(2, "0")}:00 后汇总发送`}
                  value={String(config.daily_summary_hour)}
                  onValueChange={(value) =>
                    setConfig((c) => ({
                      ...c,
                      daily_summary_hour: Math.max(0, Math.min(23, Number(value || 0))),
                    }))
                  }
                />
              </div>
              <div className="flex flex-col gap-3 rounded-2xl bg-default-100/70 p-4 sm:flex-row sm:items-end">
                <Input
                  label="测试收件邮箱"
                  type="email"
                  value={testRecipient}
                  onValueChange={setTestRecipient}
                />
                <Button
                  variant="flat"
                  color="success"
                  isLoading={testing}
                  onPress={() => void sendTest()}
                >
                  发送测试
                </Button>
              </div>
              <div className="flex justify-end">
                <Button
                  color="primary"
                  startContent={<LuSave />}
                  isLoading={saving}
                  onPress={() => void save()}
                >
                  保存 SMTP 配置
                </Button>
              </div>
            </CardBody>
          </Card>
        </Tab>
      </Tabs>
      <Modal
        isOpen={Boolean(records)}
        onOpenChange={(open) => {
          if (!open) setRecords(null);
        }}
        size="3xl"
        scrollBehavior="inside"
      >
        <ModalContent>
          <ModalHeader>{records?.task.subject} · 发送明细</ModalHeader>
          <ModalBody>
            <Table aria-label="邮件发送明细" removeWrapper>
              <TableHeader>
                <TableColumn>收件人</TableColumn>
                <TableColumn>状态</TableColumn>
                <TableColumn>尝试</TableColumn>
                <TableColumn>错误</TableColumn>
                <TableColumn>发送时间</TableColumn>
              </TableHeader>
              <TableBody>
                {(records?.rows || []).map((row) => (
                  <TableRow key={row.id}>
                    <TableCell>{row.recipient}</TableCell>
                    <TableCell>
                      <StatusChip status={row.status} />
                    </TableCell>
                    <TableCell>{row.attempts}</TableCell>
                    <TableCell>
                      <p className="max-w-72 text-xs text-danger">
                        {row.last_error_message || "—"}
                      </p>
                    </TableCell>
                    <TableCell>{formatDateTime(row.sent_at)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </ModalBody>
          <ModalFooter>
            <Button onPress={() => setRecords(null)}>关闭</Button>
          </ModalFooter>
        </ModalContent>
      </Modal>
    </div>
  );
}
