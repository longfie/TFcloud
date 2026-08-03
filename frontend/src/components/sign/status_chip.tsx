import { Chip } from "@heroui/chip";

const labels: Record<string, string> = {
  active: "启用",
  disabled: "停用",
  pending_verification: "等待首次执行",
  credential_expired: "登录失效",
  normal: "正常",
  expired: "失效",
  deleted: "已删除",
  pending: "等待中",
  sending: "发送中",
  retrying: "重试中",
  running: "执行中",
  succeeded: "成功",
  already_done: "已完成",
  partial: "任务完成",
  failed: "失败",
  cancelled: "已取消",
  skipped: "已跳过",
  completed: "任务完成",
  ready: "可用",
  scaffold: "待接入",
  enabled: "启用",
  user: "用户",
  admin: "管理员",
};

function statusColor(
  status: string,
): "default" | "primary" | "secondary" | "success" | "warning" | "danger" {
  if (["active", "succeeded", "already_done", "completed", "ready", "enabled", "normal", "partial"].includes(status))
    return "success";
  if (
    [
      "pending",
      "retrying",
      "pending_verification",
      "sending",
    ].includes(status)
  )
    return "warning";
  if (["failed", "disabled", "deleted", "credential_expired", "expired"].includes(status))
    return "danger";
  if (["running", "admin"].includes(status)) return "primary";
  if (["cancelled", "skipped", "scaffold"].includes(status))
    return "default";
  return "secondary";
}

export default function StatusChip({
  status,
  size = "sm",
}: {
  status: string;
  size?: "sm" | "md";
}) {
  return (
    <Chip color={statusColor(status)} size={size} variant="flat">
      {labels[status] || status}
    </Chip>
  );
}
