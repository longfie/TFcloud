import { Button } from "@heroui/button";
import { Card, CardBody, CardHeader } from "@heroui/card";
import { Input, Textarea } from "@heroui/input";
import { Select, SelectItem } from "@heroui/select";
import { Spinner } from "@heroui/spinner";
import { Switch } from "@heroui/switch";
import { Tab, Tabs } from "@heroui/tabs";
import { useEffect, useState } from "react";
import { toast } from "react-hot-toast";
import {
  LuBot,
  LuGlobe,
  LuLogIn,
  LuMegaphone,
  LuSave,
  LuSettings2,
  LuShieldCheck,
  LuTestTube,
} from "react-icons/lu";

import { apiRequest } from "@/api/client";
import type { AstrBotSettings, SiteSettings } from "@/types/sign";
import { errorMessage } from "@/utils/sign";

const defaults: SiteSettings = {
  name: "",
  title: "",
  description: "",
  base_url: "",
  logo_url: "",
  home_background_url: "",
  home_background_type: "auto",
  contact_email: "",
  icp_number: "",
  announcement: "",
  home_announcement: "",
  dashboard_announcement: "",
  maintenance_mode: false,
  registration_enabled: false,
  login_challenge_enabled: true,
  registration_challenge_enabled: true,
  registration_email_verification: true,
  default_quota: 2,
  qq_login_enabled: false,
  qq_auto_register: true,
  qq_login_provider: "relay",
  qq_app_id: "",
  qq_app_key: "",
  qq_app_key_configured: false,
  qq_callback_url: "",
  qq_relay_authorize_url: "",
  qq_relay_decode_key: "",
  qq_relay_decode_key_configured: false,
  qq_relay_app_secret: "",
  qq_relay_app_secret_configured: false,
  qq_relay_client_id: "",
  qq_relay_client_secret: "",
  qq_relay_client_secret_configured: false,
  qq_relay_login_url: "",
  qq_relay_application_status: "unconfigured",
  qq_relay_installation_id: "",
  qq_relay_verification_expires_at: "",
};

interface QqRelayApplicationResult {
  client_id: string;
  client_secret_configured: boolean;
  status: "pending" | "active";
  verified: boolean;
  callback_url: string;
  login_url?: string;
  verification_url?: string;
  verification_expires_at?: string;
  verification_error?: string;
}

function normalizeSiteSettings(site: SiteSettings): SiteSettings {
  const legacyAnnouncement = site.announcement?.trim() || "";
  return {
    ...site,
    home_announcement: site.home_announcement?.trim() || legacyAnnouncement,
    dashboard_announcement: site.dashboard_announcement?.trim() || legacyAnnouncement,
    qq_callback_url: site.qq_callback_url?.trim() || `${window.location.origin}/api/auth/qq/callback`,
  };
}

const astrbotDefaults: AstrBotSettings = {
  enabled: false,
  base_url: "",
  api_key: "",
  api_key_configured: false,
  config_id: "",
  bot_name: "天方助手",
  welcome_message: "你好，我是天方助手。有什么可以帮你？",
  request_timeout_seconds: 90,
  max_message_length: 4000,
  hourly_message_limit: 30,
};

export default function AdminSettingsPage() {
  const [form, setForm] = useState<SiteSettings>(defaults);
  const [astrbotForm, setAstrbotForm] = useState<AstrBotSettings>(astrbotDefaults);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [savingAstrbot, setSavingAstrbot] = useState(false);
  const [testingAstrbot, setTestingAstrbot] = useState(false);
  const [applyingQqRelay, setApplyingQqRelay] = useState(false);
  const [verifyingQqRelay, setVerifyingQqRelay] = useState(false);
  const [savingCustomQqRelay, setSavingCustomQqRelay] = useState(false);
  useEffect(() => {
    Promise.all([
      apiRequest<SiteSettings>({ url: "/admin/settings/site" }),
      apiRequest<AstrBotSettings>({ url: "/admin/settings/astrbot" }),
    ])
      .then(([site, astrbot]) => {
        setForm(normalizeSiteSettings(site));
        setAstrbotForm(astrbot);
      })
      .catch((error) => toast.error(errorMessage(error)))
      .finally(() => setLoading(false));

  }, []);
  const field = <K extends keyof SiteSettings>(
    key: K,
    value: SiteSettings[K],
  ) => setForm((current) => ({ ...current, [key]: value }));
  const astrbotField = <K extends keyof AstrBotSettings>(
    key: K,
    value: AstrBotSettings[K],
  ) => setAstrbotForm((current) => ({ ...current, [key]: value }));
  const save = async () => {
    setSaving(true);
    try {
      const sitePayload: Record<string, unknown> = { ...form, announcement: "" };
      [
        "qq_relay_client_id",
        "qq_relay_client_secret",
        "qq_relay_client_secret_configured",
        "qq_relay_login_url",
        "qq_relay_application_status",
        "qq_relay_installation_id",
        "qq_relay_verification_content",
        "qq_relay_verification_content_configured",
        "qq_relay_verification_expires_at",
      ].forEach((key) => delete sitePayload[key]);
      setForm(
        await apiRequest<SiteSettings>({
          method: "PATCH",
          url: "/admin/settings/site",
          data: sitePayload,
        }),
      );
      toast.success("网站信息已保存");
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSaving(false);
    }
  };
  const saveAstrbot = async () => {
    setSavingAstrbot(true);
    try {
      setAstrbotForm(
        await apiRequest<AstrBotSettings>({
          method: "PATCH",
          url: "/admin/settings/astrbot",
          data: astrbotForm,
        }),
      );
      toast.success("AstrBot 配置已保存");
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSavingAstrbot(false);
    }
  };
  const testAstrbot = async () => {
    setTestingAstrbot(true);
    try {
      await apiRequest<{ connected: boolean }>({
        method: "POST",
        url: "/admin/astrbot/test",
        timeout: 35000,
      });
      toast.success("AstrBot 连接正常");
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setTestingAstrbot(false);
    }
  };
  const reloadSite = async () => {
    setForm(normalizeSiteSettings(await apiRequest<SiteSettings>({ url: "/admin/settings/site" })));
  };
  const applyQqRelay = async () => {
    setApplyingQqRelay(true);
    try {
      const result = await apiRequest<QqRelayApplicationResult>({
        method: "POST",
        url: "/admin/settings/qq-relay/apply",
        timeout: 45000,
      });
      await reloadSite();
      if (result.verified) {
        toast.success("AppID、AppKey 申请成功，域名验证已完成");
      } else {
        toast(result.verification_error || "申请已提交，请稍后重新验证域名", { icon: "⚠️" });
      }
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setApplyingQqRelay(false);
    }
  };
  const verifyQqRelay = async () => {
    setVerifyingQqRelay(true);
    try {
      await apiRequest<QqRelayApplicationResult>({
        method: "POST",
        url: "/admin/settings/qq-relay/verify",
        timeout: 35000,
      });
      await reloadSite();
      toast.success("域名验证成功，QQ 快捷登录已自动启用");
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setVerifyingQqRelay(false);
    }
  };
  const saveCustomQqRelay = async () => {
    setSavingCustomQqRelay(true);
    try {
      await apiRequest<QqRelayApplicationResult>({
        method: "POST",
        url: "/admin/settings/qq-relay/configure",
        data: {
          client_id: form.qq_relay_client_id?.trim() || "",
          client_secret: form.qq_relay_client_secret?.trim() || "",
        },
        timeout: 35000,
      });
      await reloadSite();
      toast.success("自定义 AppID 和 AppKey 已验证并加密保存");
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setSavingCustomQqRelay(false);
    }
  };
  if (loading)
    return (
      <div className="flex min-h-60 items-center justify-center">
        <Spinner label="正在加载网站设置" />
      </div>
    );

  const saveSiteButton = (label: string) => (
    <Button
      color="primary"
      className="tf-soft-button"
      startContent={<LuSave />}
      isLoading={saving}
      onPress={() => void save()}
    >
      {label}
    </Button>
  );

  return (
    <div className="flex flex-col gap-5">
      <Card className="overflow-hidden rounded-[28px] border border-primary/15 bg-gradient-to-r from-primary/10 via-background to-secondary/10 shadow-none">
        <CardBody className="flex-row items-center gap-4 p-5 md:p-6">
          <div className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary text-2xl text-white shadow-lg shadow-primary/20">
            <LuSettings2 />
          </div>
          <div className="min-w-0 flex-1">
            <h2 className="text-lg font-semibold">网站设置</h2>
            <p className="mt-1 text-sm text-default-500">基础信息、访问策略和外部服务按模块独立配置，保存后立即生效。</p>
          </div>
          <div className="hidden max-w-sm items-center gap-2 rounded-2xl border border-success/15 bg-success/5 px-4 py-3 text-xs text-default-500 lg:flex">
            <LuShieldCheck className="shrink-0 text-lg text-success" />
            敏感密钥加密保存，管理接口不会回显原文
          </div>
        </CardBody>
      </Card>

      <Tabs
        aria-label="网站设置分类"
        variant="underlined"
        classNames={{
          base: "w-full",
          tabList: "w-full justify-start gap-2 overflow-x-auto rounded-2xl border border-default-200 bg-content1 px-2",
          cursor: "bg-primary",
          tab: "h-12 px-4",
          panel: "px-0 pt-5",
        }}
      >
        <Tab key="site" title={<span className="flex items-center gap-2"><LuGlobe />网站信息</span>}>
          <div className="grid gap-5 xl:grid-cols-[1.25fr_0.75fr]">
            <Card className="tf-glass-card rounded-[26px]">
              <CardHeader className="flex-col items-start gap-1 px-6 pt-6">
                <h3 className="font-semibold">网站信息填写</h3>
                <p className="text-xs text-default-500">用于首页、登录页面、浏览器标题和邮件模板</p>
              </CardHeader>
              <CardBody className="gap-4 px-6 pb-6">
                <div className="grid gap-4 sm:grid-cols-2">
                  <Input label="网站名称" value={form.name} onValueChange={(value) => field("name", value)} />
                  <Input label="浏览器标题" value={form.title} onValueChange={(value) => field("title", value)} />
                </div>
                <Textarea label="网站描述" value={form.description} onValueChange={(value) => field("description", value)} />
                <div className="grid gap-4 sm:grid-cols-2">
                  <Input label="网站地址" type="url" placeholder="https://example.com" value={form.base_url} onValueChange={(value) => field("base_url", value)} />
                  <Input label="Logo 地址" type="url" value={form.logo_url} onValueChange={(value) => field("logo_url", value)} />
                  <Input className="sm:col-span-2" label="首页背景 URL" type="url" placeholder="https://example.com/background.jpg" description="支持图片或可直接播放的视频文件 URL，留空使用默认背景" value={form.home_background_url} onValueChange={(value) => field("home_background_url", value)} />
                  <Select label="首页背景类型" selectedKeys={[form.home_background_type]} onSelectionChange={(keys) => field("home_background_type", String(Array.from(keys)[0] || "auto") as "auto" | "image" | "video")}>
                    <SelectItem key="auto" description="根据 URL 文件扩展名识别">自动识别</SelectItem>
                    <SelectItem key="image">背景图片</SelectItem>
                    <SelectItem key="video">背景视频</SelectItem>
                  </Select>
                  <Input label="联系邮箱" type="email" value={form.contact_email} onValueChange={(value) => field("contact_email", value)} />
                  <Input label="ICP备案号" value={form.icp_number} onValueChange={(value) => field("icp_number", value)} />
                </div>
              </CardBody>
            </Card>

            <Card className="tf-glass-card rounded-[26px]">
              <CardHeader className="gap-3 px-6 pt-6">
                <div className="grid h-10 w-10 place-items-center rounded-2xl bg-warning/10 text-xl text-warning"><LuMegaphone /></div>
                <div>
                  <h3 className="font-semibold">公告内容</h3>
                  <p className="text-xs text-default-500">首页访客与登录用户分别展示</p>
                </div>
              </CardHeader>
              <CardBody className="gap-4 px-6 pb-6">
                <Textarea
                  label="首页公告"
                  description="展示在官网首页主视觉区域"
                  minRows={4}
                  value={form.home_announcement}
                  onValueChange={(value) => field("home_announcement", value)}
                />
                <Textarea
                  label="后台公告"
                  description="展示在用户登录后的控制台"
                  minRows={4}
                  value={form.dashboard_announcement}
                  onValueChange={(value) => field("dashboard_announcement", value)}
                />
              </CardBody>
            </Card>
            <div className="flex justify-end xl:col-span-2">{saveSiteButton("保存网站信息")}</div>
          </div>
        </Tab>

        <Tab key="access" title={<span className="flex items-center gap-2"><LuShieldCheck />访问策略</span>}>
          <Card className="tf-glass-card max-w-4xl rounded-[26px]">
            <CardHeader className="flex-col items-start gap-1 px-6 pt-6">
              <h3 className="font-semibold">注册与安全策略</h3>
              <p className="text-xs text-default-500">统一控制维护状态、注册入口和人机验证</p>
            </CardHeader>
            <CardBody className="gap-3 px-6 pb-6">
              {[
                ["维护模式", "向前端公开维护状态", "maintenance_mode", "warning"],
                ["开放注册", "允许用户通过邮箱或 QQ 创建账号", "registration_enabled", "primary"],
                ["登录人机验证", "用户名密码登录前必须完成验证", "login_challenge_enabled", "primary"],
                ["注册人机验证", "新用户注册前必须完成验证", "registration_challenge_enabled", "primary"],
                ["注册邮箱验证", "注册时向邮箱发送验证码确认（需先启用邮件服务）", "registration_email_verification", "primary"],
              ].map(([label, description, key, color]) => (
                <div key={key} className="flex items-center justify-between gap-4 rounded-2xl bg-default-100/70 px-4 py-3.5">
                  <div><p className="text-sm font-medium">{label}</p><p className="mt-0.5 text-xs text-default-400">{description}</p></div>
                  <Switch
                    color={color as "warning" | "primary"}
                    isSelected={Boolean(form[key as keyof SiteSettings])}
                    onValueChange={(value) => field(key as keyof SiteSettings, value as never)}
                  />
                </div>
              ))}
              <Input
                className="mt-2 max-w-sm"
                label="新用户默认账号配额"
                type="number"
                min="0"
                value={String(form.default_quota)}
                onValueChange={(value) => field("default_quota", Number(value || 0))}
              />
              <div className="flex justify-end pt-2">{saveSiteButton("保存访问策略")}</div>
            </CardBody>
          </Card>
        </Tab>

        <Tab key="qq" title={<span className="flex items-center gap-2"><LuLogIn />QQ 登录</span>}>
          <Card className="tf-glass-card max-w-4xl rounded-[26px]">
            <CardHeader className="gap-3 px-6 pt-6">
              <div className="grid h-11 w-11 place-items-center rounded-2xl bg-sky-500/10 font-semibold text-sky-500">QQ</div>
              <div><h3 className="font-semibold">QQ 快捷登录</h3><p className="text-xs text-default-500">支持一键申请天方中转凭据和官方 QQ OAuth</p></div>
            </CardHeader>
            <CardBody className="gap-4 px-6 pb-6">
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="flex items-center justify-between gap-3 rounded-2xl bg-default-100/70 px-4 py-3">
                  <div><p className="text-sm font-medium">启用 QQ 登录</p><p className="text-xs text-default-400">配置完整后显示入口</p></div>
                  <Switch isSelected={Boolean(form.qq_login_enabled)} onValueChange={(value) => field("qq_login_enabled", value)} />
                </div>
                <div className="flex items-center justify-between gap-3 rounded-2xl bg-default-100/70 px-4 py-3">
                  <div><p className="text-sm font-medium">自动创建账号</p><p className="text-xs text-default-400">首次登录补充资料</p></div>
                  <Switch isSelected={Boolean(form.qq_auto_register)} onValueChange={(value) => field("qq_auto_register", value)} />
                </div>
              </div>
              <Select
                label="身份提供方式"
                selectedKeys={[form.qq_login_provider || "relay"]}
                onSelectionChange={(keys) => field("qq_login_provider", String(Array.from(keys)[0] || "relay") as "relay" | "official")}
              >
                <SelectItem key="relay" description="自动申请独立 AppID 和 AppKey">天方 QQ 中转</SelectItem>
                <SelectItem key="official" description="使用当前站点自己的 QQ 互联应用">官方 QQ OAuth</SelectItem>
              </Select>
              {form.qq_login_provider === "official" ? (
                <div className="grid gap-4 sm:grid-cols-2">
                  <Input label="QQ 互联 App ID" value={form.qq_app_id || ""} onValueChange={(value) => field("qq_app_id", value)} />
                  <Input label="QQ 互联 App Key" type="password" placeholder={form.qq_app_key_configured ? "已配置，留空则不修改" : "请输入 App Key"} value={form.qq_app_key || ""} onValueChange={(value) => field("qq_app_key", value)} />
                </div>
              ) : (
                <div className="flex flex-col gap-4">
                  <div className="rounded-3xl border border-sky-500/15 bg-gradient-to-br from-sky-500/10 via-content1 to-primary/5 p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <div className="flex items-center gap-2">
                          <p className="font-semibold">天方中转快捷申请</p>
                          <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${
                            form.qq_relay_application_status === "active"
                              ? "bg-success/10 text-success"
                              : form.qq_relay_application_status === "pending"
                                ? "bg-warning/10 text-warning"
                                : "bg-default-100 text-default-500"
                          }`}>
                            {form.qq_relay_application_status === "active" ? "已验证" : form.qq_relay_application_status === "pending" ? "待验证" : "未申请"}
                          </span>
                        </div>
                        <p className="mt-1 text-xs leading-5 text-default-500">
                          自动提交当前域名、发布验证文件并保存加密 AppKey，成功后立即启用 QQ 登录。
                        </p>
                      </div>
                      {form.qq_relay_application_status === "pending" ? (
                        <div className="flex flex-wrap gap-2">
                          <Button variant="flat" isLoading={applyingQqRelay} onPress={() => void applyQqRelay()}>
                            重新申请
                          </Button>
                          <Button color="warning" variant="flat" isLoading={verifyingQqRelay} onPress={() => void verifyQqRelay()}>
                            重新验证域名
                          </Button>
                        </div>
                      ) : form.qq_relay_application_status === "active" ? (
                        <div className="flex items-center gap-2 rounded-2xl bg-success/10 px-3 py-2 text-xs font-medium text-success">
                          <LuShieldCheck />申请已完成
                        </div>
                      ) : (
                        <Button color="primary" className="tf-soft-button" isLoading={applyingQqRelay} onPress={() => void applyQqRelay()}>
                          一键申请 AppID 和 Key
                        </Button>
                      )}
                    </div>
                    <div className="mt-4 grid gap-3 sm:grid-cols-2">
                      <Input
                        label="中转 AppID"
                        placeholder="site_xxx"
                        value={form.qq_relay_client_id || ""}
                        onValueChange={(value) => field("qq_relay_client_id", value)}
                      />
                      <Input
                        label="中转 AppKey"
                        type="password"
                        placeholder={form.qq_relay_client_secret_configured ? "已加密保存，留空则不修改" : "qs_xxx"}
                        value={form.qq_relay_client_secret || ""}
                        onValueChange={(value) => field("qq_relay_client_secret", value)}
                      />
                      <Input
                        className="sm:col-span-2"
                        isReadOnly
                        label="中转授权地址"
                        placeholder="验证成功后自动填写"
                        value={form.qq_relay_login_url || ""}
                      />
                    </div>
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                      <p className="text-xs text-default-500">手动填写的凭据会先校验所属域名和回调地址，AppKey 只在后端加密保存。</p>
                      <Button
                        variant="flat"
                        isDisabled={!form.qq_relay_client_id?.trim()}
                        isLoading={savingCustomQqRelay}
                        onPress={() => void saveCustomQqRelay()}
                      >
                        验证并保存自定义凭据
                      </Button>
                    </div>
                    {form.qq_relay_application_status === "pending" && form.qq_relay_verification_expires_at && (
                      <p className="mt-3 text-xs text-warning">验证挑战有效期至 {form.qq_relay_verification_expires_at}</p>
                    )}
                  </div>

                  {form.qq_relay_application_status === "unconfigured" && (
                    <details className="rounded-2xl border border-default-200 bg-default-50 px-4 py-3">
                      <summary className="cursor-pointer text-sm font-medium text-default-600">兼容旧版中转配置</summary>
                      <div className="mt-4 grid gap-4 sm:grid-cols-2">
                        <Input className="sm:col-span-2" label="旧版中转授权地址" type="url" placeholder="https://relay.example.com/qqlogin/oauth/" value={form.qq_relay_authorize_url || ""} onValueChange={(value) => field("qq_relay_authorize_url", value)} />
                        <Input label="旧版回调解码密钥" type="password" placeholder={form.qq_relay_decode_key_configured ? "已配置，留空则不修改" : "请输入解码密钥"} value={form.qq_relay_decode_key || ""} onValueChange={(value) => field("qq_relay_decode_key", value)} />
                        <Input label="旧版应用签名密钥" type="password" placeholder={form.qq_relay_app_secret_configured ? "已配置，留空则不修改" : "请输入应用签名密钥"} value={form.qq_relay_app_secret || ""} onValueChange={(value) => field("qq_relay_app_secret", value)} />
                      </div>
                    </details>
                  )}
                </div>
              )}
              <Input
                isReadOnly={form.qq_login_provider === "relay"}
                label="本站接收回调地址"
                type="url"
                description={form.qq_login_provider === "relay" ? "根据当前访问域名自动填写，申请时一并提交" : "必须与 QQ 互联后台填写的地址完全一致"}
                placeholder="https://example.com/api/auth/qq/callback"
                value={form.qq_callback_url || ""}
                onValueChange={(value) => field("qq_callback_url", value)}
              />
              <div className="flex justify-end">{saveSiteButton("保存 QQ 登录设置")}</div>
            </CardBody>
          </Card>
        </Tab>

        <Tab key="astrbot" title={<span className="flex items-center gap-2"><LuBot />智能助手</span>}>
          <Card className="tf-glass-card max-w-5xl rounded-[26px]">
            <CardHeader className="gap-3 px-6 pt-6">
              <div className="grid h-11 w-11 place-items-center rounded-2xl bg-gradient-to-br from-primary/15 to-secondary/15 text-xl text-primary"><LuBot /></div>
              <div><h3 className="font-semibold">AstrBot 智能助手</h3><p className="text-xs text-default-500">为站内用户提供独立、连续的 AI 对话</p></div>
            </CardHeader>
            <CardBody className="gap-4 px-6 pb-6">
              <div className="flex items-center justify-between gap-3 rounded-2xl bg-default-100/70 px-4 py-3">
                <div><p className="text-sm font-medium">开放智能助手</p><p className="text-xs text-default-400">开启后用户侧显示智能助手入口</p></div>
                <Switch isSelected={astrbotForm.enabled} onValueChange={(value) => astrbotField("enabled", value)} />
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <Input label="AstrBot 服务地址" type="url" description="填写 AstrBot WebUI 根地址" placeholder="http://127.0.0.1:6185" value={astrbotForm.base_url} onValueChange={(value) => astrbotField("base_url", value)} />
                <Input label="API Key" type="password" description="只需授予 chat 权限" placeholder={astrbotForm.api_key_configured ? "已配置，留空则不修改" : "abk_xxx"} value={astrbotForm.api_key} onValueChange={(value) => astrbotField("api_key", value)} />
                <Input label="助手名称" value={astrbotForm.bot_name} onValueChange={(value) => astrbotField("bot_name", value)} />
                <Input label="配置文件 ID（可选）" value={astrbotForm.config_id} onValueChange={(value) => astrbotField("config_id", value)} />
              </div>
              <Textarea label="欢迎语" minRows={3} value={astrbotForm.welcome_message} onValueChange={(value) => astrbotField("welcome_message", value)} />
              <div className="grid gap-4 sm:grid-cols-3">
                <Input label="请求超时（秒）" type="number" min="10" max="180" value={String(astrbotForm.request_timeout_seconds)} onValueChange={(value) => astrbotField("request_timeout_seconds", Number(value || 90))} />
                <Input label="单条字符上限" type="number" min="100" max="10000" value={String(astrbotForm.max_message_length)} onValueChange={(value) => astrbotField("max_message_length", Number(value || 4000))} />
                <Input label="每用户每小时次数" type="number" min="1" max="1000" value={String(astrbotForm.hourly_message_limit)} onValueChange={(value) => astrbotField("hourly_message_limit", Number(value || 30))} />
              </div>
              <div className="flex flex-wrap justify-end gap-2">
                <Button variant="flat" startContent={<LuTestTube />} isLoading={testingAstrbot} onPress={() => void testAstrbot()}>测试连接</Button>
                <Button color="primary" className="tf-soft-button" startContent={<LuSave />} isLoading={savingAstrbot} onPress={() => void saveAstrbot()}>保存 AstrBot 配置</Button>
              </div>
            </CardBody>
          </Card>
        </Tab>

      </Tabs>
    </div>
  );
}
