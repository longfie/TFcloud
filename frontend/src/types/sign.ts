export interface ApiEnvelope<T> {
  code: string;
  message: string;
  data: T;
  request_id: string;
}

export interface User {
  id: number;
  username: string;
  display_name: string;
  email: string | null;
  qq: string | null;
  role: 'user' | 'admin';
  status: 'active' | 'disabled';
  quota: number;
  daily_sign_email?: boolean;
  push_channel?: 'none' | 'pushplus' | 'serverchan' | 'bark';
  push_token?: string;
  has_password?: boolean;
  qq_binding?: {
    bound: boolean;
    requires_rebind?: boolean;
    provider: 'qq' | 'qq_relay' | null;
    display_name: string | null;
    linked_at: string | null;
  };
  last_login_at?: string | null;
  last_login_ip?: string | null;
  created_at: string;
  updated_at?: string;
}

export interface LoginResult {
  access_token: string;
  token_type: string;
  expires_at: string;
  user: User;
}

export interface HumanChallenge {
  challenge_id: string;
  expires_in: number;
  minimum_interaction_ms: number;
}

export interface HumanChallengeVerification {
  challenge_id: string;
  verified: boolean;
  expires_in: number;
}

export interface Plugin {
  code: string;
  name: string;
  version: string;
  description: string;
  credential_types: string[];
  implementation_status: 'ready' | 'scaffold';
  actions: string[];
  healthy?: boolean;
  health_message?: string;
}

export interface PluginDetail extends Plugin {
  credential_rules: Record<string, string[]>;
}

export type PlatformConnectionMethod = 'sms' | 'qr' | 'password' | 'qq_qr' | 'wechat_qr' | 'bduss' | 'cookie';

export interface Platform {
  code: string;
  name: string;
  description: string;
  connection_methods: PlatformConnectionMethod[];
  actions: string[];
}

export interface PlatformDetail extends Platform {
  credential_fields: Array<{ key: string; label: string; required: boolean }>;
}

export interface PlatformQrFlow {
  flow_no: string;
  status: 'waiting' | 'scanned' | 'expired' | 'succeeded';
  qr_url?: string;
  qr_image?: string;
  expires_in?: number;
  message?: string;
  account?: PluginAccount;
}

export interface PlatformAuthResult {
  flow_no?: string;
  status: 'captcha_required' | 'verification_required' | 'code_sent' | 'succeeded';
  message?: string;
  captcha_image?: string;
  verification_target?: string;
  account?: PluginAccount;
}

export interface PluginAccount {
  id: number;
  user_id?: number;
  username?: string;
  plugin_code: string;
  external_user_id: string | null;
  display_name: string;
  avatar_url?: string | null;
  status: string;
  login_status?: 'normal' | 'expired';
  settings: Record<string, unknown>;
  credential_configured: boolean;
  last_verified_at: string | null;
  last_run_at: string | null;
  next_run_at: string | null;
  last_error?: { code: string; message: string | null } | null;
  created_at: string;
  updated_at: string;
}

export interface SignTask {
  task_no: string;
  user_id?: number;
  account_id: number;
  plugin_code: string;
  action: string;
  trigger_type: string;
  status: string;
  schedule_date: string | null;
  attempts: number;
  max_attempts: number;
  counts: {
    total: number;
    success: number;
    already: number;
    skipped: number;
    failed: number;
  };
  summary: Record<string, unknown>;
  last_error: { code: string; message: string | null } | null;
  scheduled_at: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface SignRun extends Record<string, unknown> {
  id: number;
  run_no: string;
  attempt_no: number;
  status: string;
  started_at: string;
  finished_at: string | null;
}

export interface SignRecord extends Record<string, unknown> {
  id: number;
  action: string;
  target_name: string | null;
  target_type: string | null;
  status: string;
  result_code: string | null;
  message: string | null;
  created_at: string;
}

export interface SignCalendarDay {
  date: string;
  total: number;
  completed: number;
  failed: number;
  pending: number;
  cancelled: number;
}

export interface SignCalendar {
  month: string;
  days: SignCalendarDay[];
}

export interface Paginated<T> {
  items: T[];
  total: number;
  limit: number;
  offset: number;
  page?: number;
  per_page?: number;
  total_pages?: number;
}

export interface AdminOverview {
  users: { total: number; active: number };
  plugin_accounts: Array<{ plugin_code: string; status: string; total: number }>;
  tasks_today: Record<string, number>;
  queue: { pending: number; running: number; failed_24h: number };
}

export interface AuditLog {
  id: number;
  request_id: string | null;
  user_id: number | null;
  action: string;
  resource_type: string | null;
  resource_id: string | null;
  ip_address: string | null;
  context: Record<string, unknown>;
  created_at: string;
}

export interface SiteSettings {
  name: string; title: string; description: string; base_url: string; logo_url: string;
  home_background_url: string; home_background_type: 'auto' | 'image' | 'video';
  contact_email: string; icp_number: string; announcement: string;
  home_announcement: string; dashboard_announcement: string;
  maintenance_mode: boolean; registration_enabled: boolean; default_quota: number;
  login_challenge_enabled: boolean; registration_challenge_enabled: boolean;
  registration_email_verification?: boolean;
  qq_login_enabled: boolean; qq_auto_register?: boolean; qq_login_provider?: 'relay' | 'official';
  qq_app_id?: string; qq_app_key?: string; qq_app_key_configured?: boolean; qq_callback_url?: string;
  qq_relay_authorize_url?: string; qq_relay_decode_key?: string; qq_relay_decode_key_configured?: boolean;
  qq_relay_app_secret?: string; qq_relay_app_secret_configured?: boolean;
  qq_relay_client_id?: string; qq_relay_client_secret?: string; qq_relay_client_secret_configured?: boolean;
  qq_relay_login_url?: string;
  qq_relay_application_status?: 'unconfigured' | 'pending' | 'active';
  qq_relay_installation_id?: string; qq_relay_verification_expires_at?: string;
  running_since?: string; running_days?: number;
  user_count?: number; today_signed_accounts?: number; today_signed_users?: number;
  assistant_enabled?: boolean; assistant_name?: string;
}

export interface AstrBotSettings {
  enabled: boolean;
  base_url: string;
  api_key: string;
  api_key_configured: boolean;
  config_id: string;
  bot_name: string;
  welcome_message: string;
  request_timeout_seconds: number;
  max_message_length: number;
  hourly_message_limit: number;
}

export interface AstrBotStatus {
  enabled: boolean;
  ready: boolean;
  name: string;
  welcome_message: string;
  max_message_length: number;
}

export interface AstrBotMessage {
  id: number;
  role: 'user' | 'assistant';
  content: string;
  status: 'pending' | 'completed' | 'failed';
  error: { code: string; message: string | null } | null;
  created_at: string;
  updated_at: string;
}

export interface AstrBotExchange {
  user_message: AstrBotMessage;
  assistant_message: AstrBotMessage;
}

export type AstrBotStreamEvent =
  | {
      event: 'started' | 'done';
      data: AstrBotExchange;
    }
  | {
      event: 'delta' | 'replace';
      data: { content: string };
    }
  | {
      event: 'error';
      data: { code: string; message: string };
    };

export interface QqLoginStart {
  authorization_url: string;
  provider: 'relay' | 'official';
  expires_in: number;
}

export interface QqOnboarding {
  requires_profile_completion: true;
  onboarding_token: string;
  qq_profile: { nickname: string; avatar: string };
  expires_in: number;
}

export type QqExchangeResult = LoginResult | QqOnboarding;

export interface InstallStatus {
  installed: boolean;
  lock_exists: boolean;
  env_exists: boolean;
  can_install: boolean;
  restart_hint: string;
}

export interface InstallCheckItem {
  key: string;
  label: string;
  ok: boolean;
  detail: string;
}

export interface InstallChecksResult {
  passed: boolean;
  checks: InstallCheckItem[];
}

export interface InstallResult {
  installed: boolean;
  admin_username: string;
  site_url: string;
  server_resources: { cpu_cores: number; memory_mb: number };
  process_counts: { webman: number; sign_worker: number };
  restart_required: boolean;
  message: string;
}

export interface SystemVersionInfo {
  program: {
    name: string;
    description: string;
    features: string[];
  };
  author: {
    name: string;
    qq: string;
    qq_group: string;
    blog_url: string;
  };
  copyright: {
    owner: string;
    start_year: number;
    website: string;
    website_url: string;
    notice: string;
  };
  version: {
    current: string;
    latest: string | null;
    status: "up_to_date" | "update_available" | "ahead" | "unavailable";
    update_available: boolean;
    checked_at: string | null;
    error: string | null;
    update_page_url: string;
  };
  repository_url: string;
}

export interface MailSettings {
  enabled: boolean; smtp_host: string; smtp_port: number; smtp_encryption: string; smtp_username: string;
  smtp_password: string; smtp_password_configured: boolean; from_email: string; from_name: string;
  reply_to: string; batch_limit: number; daily_summary_hour: number;
}

export interface MailSummary { tasks: number; pending: number; sent: number; failed: number; today_sent: number }
export interface MailTask { id?: number; task_no: string; username?: string | null; subject: string; status: string; total_count: number; success_count: number; failed_count: number; created_at: string; finished_at: string | null }
export interface MailRecord { id: number; message_no: string; task_no?: string | null; template_code?: string | null; subject?: string | null; username?: string | null; recipient: string; status: string; attempts: number; provider_message_id?: string | null; last_error_message: string | null; sent_at: string | null; created_at: string }

export interface PasswordCodeResponse {
  email_masked: string;
  expires_in: number;
  resend_after: number;
}
