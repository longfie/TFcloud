import { Avatar } from "@heroui/avatar";
import { Button } from "@heroui/button";
import { Card, CardBody, CardHeader } from "@heroui/card";
import { Chip } from "@heroui/chip";
import { Spinner } from "@heroui/spinner";
import { useEffect, useState } from "react";
import { toast } from "react-hot-toast";
import {
  LuBookOpen,
  LuBox,
  LuCircleAlert,
  LuCircleCheck,
  LuClock3,
  LuCode,
  LuExternalLink,
  LuGithub,
  LuGlobe,
  LuHeartHandshake,
  LuRefreshCw,
  LuShieldCheck,
  LuSparkles,
  LuUserRound,
  LuUsersRound,
} from "react-icons/lu";

import { apiRequest } from "@/api/client";
import PlatformLogo from "@/components/platform_logo";
import type { SystemVersionInfo } from "@/types/sign";
import { errorMessage } from "@/utils/sign";

const authorAvatar = "https://q1.qlogo.cn/g?b=qq&nk=1790716272&s=640";

const statusCopy: Record<SystemVersionInfo["version"]["status"], string> = {
  up_to_date: "当前已是最新版本",
  update_available: "当前版本需要更新",
  ahead: "当前为开发版本",
  unavailable: "暂时无法确认是否最新",
};

export default function AdminVersionPage() {
  const [info, setInfo] = useState<SystemVersionInfo | null>(null);
  const [loading, setLoading] = useState(true);

  const load = async (refresh = false) => {
    setLoading(true);
    try {
      const result = await apiRequest<SystemVersionInfo>({
        url: "/admin/system/version",
        params: refresh ? { refresh: 1 } : undefined,
      });
      setInfo(result);
      if (refresh) {
        if (result.version.status === "up_to_date") toast.success("当前已是最新版本");
        else if (result.version.status === "update_available") toast("检测到可用更新");
        else if (result.version.status === "ahead") toast.success("当前为开发版本");
        else toast.error(result.version.error || "远程版本检查失败");
      }
    } catch (error) {
      toast.error(`版本信息加载失败：${errorMessage(error)}`);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
  }, []);

  if (loading && !info) {
    return <div className="flex min-h-64 items-center justify-center"><Spinner label="正在检查程序版本" /></div>;
  }

  const status = info?.version.status || "unavailable";
  const statusColor = status === "update_available" ? "warning" : status === "unavailable" ? "default" : "success";
  const statusIcon = status === "update_available" || status === "unavailable" ? <LuCircleAlert /> : <LuCircleCheck />;

  return (
    <div className="flex flex-col gap-5">
      <Card className="relative isolate overflow-hidden rounded-[30px] border border-primary/15 bg-content1/80 shadow-xl shadow-primary/5 backdrop-blur-xl">
        <div className="pointer-events-none absolute -right-24 -top-32 -z-10 h-72 w-72 rounded-full bg-primary/20 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-32 left-1/3 -z-10 h-64 w-64 rounded-full bg-secondary/15 blur-3xl" />
        <CardBody className="grid gap-6 p-6 md:grid-cols-[1fr_auto] md:items-center md:p-8">
          <div className="flex items-center gap-5">
            <div className="grid h-20 w-20 shrink-0 place-items-center rounded-[24px] bg-background/80 shadow-xl shadow-primary/15 ring-1 ring-primary/15">
              <PlatformLogo size={62} />
            </div>
            <div className="min-w-0">
              <div className="mb-2 flex flex-wrap items-center gap-2"><Chip color="primary" size="sm" variant="flat" startContent={<LuSparkles />}>系统信息</Chip><span className="font-mono text-xs text-default-400">v{info?.version.current || "0.0.0"}</span></div>
              <h2 className="text-2xl font-bold tracking-tight md:text-3xl">{info?.program.name || "天方云签"}</h2>
              <p className="mt-2 max-w-2xl text-sm leading-6 text-default-500">统一查看当前程序状态、项目能力与作者信息，界面颜色会自动跟随后台主题。</p>
            </div>
          </div>
          <div className="flex min-w-64 flex-col gap-3 rounded-3xl border border-default-200/70 bg-background/65 p-4 shadow-sm">
            <div className="flex items-center justify-between gap-3"><span className="text-xs text-default-400">版本状态</span><Chip size="sm" variant="flat" color={statusColor} startContent={statusIcon}>{statusCopy[status]}</Chip></div>
            <div className="flex items-end justify-between gap-4"><div><p className="text-xs text-default-400">当前版本</p><p className="mt-1 font-mono text-2xl font-bold text-primary">v{info?.version.current || "0.0.0"}</p></div><Button isIconOnly size="sm" variant="flat" aria-label="重新检查版本" isLoading={loading} onPress={() => void load(true)}><LuRefreshCw /></Button></div>
          </div>
        </CardBody>
      </Card>

      <div className="grid gap-3 sm:grid-cols-3">
        <div className="flex items-center gap-3 rounded-2xl border border-default-200/60 bg-content1/65 p-4"><div className="grid h-10 w-10 place-items-center rounded-xl bg-primary/10 text-primary"><LuBox /></div><div><p className="text-xs text-default-400">程序版本</p><p className="font-mono text-sm font-semibold">v{info?.version.current || "0.0.0"}</p></div></div>
        <div className="flex items-center gap-3 rounded-2xl border border-default-200/60 bg-content1/65 p-4"><div className="grid h-10 w-10 place-items-center rounded-xl bg-success/10 text-success"><LuShieldCheck /></div><div><p className="text-xs text-default-400">运行状态</p><p className="text-sm font-semibold">{statusCopy[status]}</p></div></div>
        <div className="flex items-center gap-3 rounded-2xl border border-default-200/60 bg-content1/65 p-4"><div className="grid h-10 w-10 place-items-center rounded-xl bg-secondary/10 text-secondary"><LuClock3 /></div><div><p className="text-xs text-default-400">最近检查</p><p className="text-sm font-semibold">{info?.version.checked_at ? new Date(info.version.checked_at).toLocaleString("zh-CN", { hour: "2-digit", minute: "2-digit" }) : "尚未检查"}</p></div></div>
      </div>

      <div className="grid gap-5 xl:grid-cols-[1.1fr_0.9fr]">
        <Card className="tf-glass-card overflow-hidden rounded-[26px] border border-default-200/60">
          <CardHeader className="flex-col items-start gap-1 px-6 pt-6">
            <h3 className="font-semibold">版本状态</h3>
            <p className="text-xs text-default-500">只展示当前安装版本及是否需要更新</p>
          </CardHeader>
          <CardBody className="gap-5 px-6 pb-6">
            <div className="flex flex-col justify-between gap-4 rounded-2xl border border-primary/10 bg-gradient-to-br from-primary/10 to-secondary/5 p-5 sm:flex-row sm:items-center">
              <div>
                <p className="text-xs text-default-400">当前版本</p>
                <p className="mt-1 font-mono text-3xl font-semibold text-primary">v{info?.version.current || "0.0.0"}</p>
              </div>
              <Chip size="lg" variant="flat" color={statusColor} startContent={statusIcon}>{statusCopy[status]}</Chip>
            </div>
            {info?.version.error && (
              <div className="rounded-2xl border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning-700 dark:text-warning-300">
                {info.version.error}
              </div>
            )}
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p className="text-xs text-default-400">
                {info?.version.checked_at ? `最后检查：${new Date(info.version.checked_at).toLocaleString("zh-CN")}` : "尚未检查"}
              </p>
              <div className="flex flex-wrap gap-2">
                <Button variant="flat" startContent={<LuRefreshCw />} isLoading={loading} onPress={() => void load(true)}>重新检查</Button>
                {info?.version.update_available && (
                  <Button as="a" color="warning" href={info.version.update_page_url} target="_blank" rel="noreferrer" startContent={<LuExternalLink />}>查看更新</Button>
                )}
              </div>
            </div>
          </CardBody>
        </Card>

        <Card className="tf-glass-card overflow-hidden rounded-[26px] border border-default-200/60">
          <CardHeader className="gap-4 px-6 pt-6">
            <Avatar className="h-16 w-16 shrink-0 ring-4 ring-primary/10" src={authorAvatar} name="龙辉" />
            <div><div className="flex items-center gap-2"><h3 className="font-semibold">{info?.author.name || "龙辉"}</h3><Chip size="sm" color="secondary" variant="flat" startContent={<LuHeartHandshake />}>项目作者</Chip></div><p className="mt-1 text-xs text-default-500">作者信息与项目交流</p></div>
          </CardHeader>
          <CardBody className="gap-3 px-6 pb-6">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
              <div className="rounded-2xl bg-default-100/70 px-4 py-3"><p className="flex items-center gap-1 text-xs text-default-400"><LuUserRound />QQ</p><p className="mt-1 font-mono font-medium">{info?.author.qq || "1790716272"}</p></div>
              <div className="rounded-2xl bg-default-100/70 px-4 py-3"><p className="flex items-center gap-1 text-xs text-default-400"><LuUsersRound />交流群</p><p className="mt-1 font-mono font-medium">{info?.author.qq_group || "701550577"}</p></div>
            </div>
            <div className="flex flex-wrap gap-2 pt-1">
              <Button as="a" color="primary" variant="flat" href={info?.copyright?.website_url || "https://www.yunsign.net"} target="_blank" rel="noreferrer" startContent={<LuGlobe />}>官方网站</Button>
              <Button as="a" variant="flat" href={info?.author.blog_url || "https://blog.eirds.cn/"} target="_blank" rel="noreferrer" startContent={<LuBookOpen />}>访问博客</Button>
              <Button as="a" variant="flat" href={info?.repository_url || "https://github.com/longfie/TFcloud"} target="_blank" rel="noreferrer" startContent={<LuGithub />}>GitHub 仓库</Button>
            </div>
          </CardBody>
        </Card>

        <Card className="tf-glass-card rounded-[26px] border border-default-200/60 xl:col-span-2">
          <CardHeader className="flex-col items-start gap-1 px-6 pt-6"><h3 className="flex items-center gap-2 font-semibold"><LuCode className="text-primary" />程序说明</h3><p className="text-xs text-default-500">关于天方云签</p></CardHeader>
          <CardBody className="gap-4 px-6 pb-6">
            <p className="text-sm leading-7 text-default-600 dark:text-default-300">{info?.program.description || "基于 Webman 与 React 构建的模块化自动签到管理平台。"}</p>
            <div className="grid gap-3 sm:grid-cols-2">
              {(info?.program.features || []).map((feature) => (
                <div key={feature} className="flex items-center gap-3 rounded-2xl border border-default-200/50 bg-default-100/55 px-4 py-3 text-sm transition-colors hover:border-primary/20 hover:bg-primary/5"><span className="grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-success/10 text-success"><LuCircleCheck /></span>{feature}</div>
              ))}
            </div>
          </CardBody>
        </Card>
      </div>
      <div className="text-center text-xs leading-6 text-default-400">
        <p>© {info?.copyright?.start_year || 2016}–{new Date().getFullYear()} {info?.copyright?.notice || "天方云签 · 龙辉 版权所有"}</p>
        <a className="transition-colors hover:text-primary" href={info?.copyright?.website_url || "https://www.yunsign.net"} target="_blank" rel="noreferrer">官方网站：{info?.copyright?.website || "www.yunsign.net"}</a>
      </div>
    </div>
  );
}
