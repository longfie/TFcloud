<?php

namespace app\service;

use app\exception\ApiException;
use support\Db;

final class EmailVerificationService
{
    private const CODE_TTL_SECONDS = 600;
    private const RESEND_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;
    private const MAX_PER_HOUR_EMAIL = 5;
    private const MAX_PER_HOUR_IP = 10;

    public function issue(string $email, string $ip, string $purpose = 'register'): array
    {
        $email = mb_strtolower(trim($email));
        if (!in_array($purpose, ['register', 'change_email', 'login'], true)) {
            throw new ApiException('VALIDATION_FAILED', '验证用途无效', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            throw new ApiException('VALIDATION_FAILED', '请输入有效的邮箱地址', 422);
        }
        if (empty((new SettingsService())->group('mail')['enabled'])) {
            throw new ApiException('MAIL_DISABLED', '邮件服务尚未启用，请联系管理员', 409);
        }
        if ($purpose === 'register' && Db::table('TF_users')->where('email', $email)->exists()) {
            throw new ApiException('USER_FIELD_DUPLICATE', '该邮箱已注册过账号，可直接登录或找回密码', 409);
        }
        $deliver = true;
        if ($purpose === 'login') {
            $user = Db::table('TF_users')->where('email', $email)->first();
            $deliver = $user !== null && $user->status === 'active';
        }

        $ipHash = $this->hash($ip);
        $latest = Db::table('TF_email_verification_codes')
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->orderByDesc('id')
            ->first();
        if ($latest && strtotime((string)$latest->created_at) > time() - self::RESEND_SECONDS) {
            throw new ApiException('VERIFICATION_CODE_TOO_FREQUENT', '验证码发送过于频繁，请稍后再试', 429);
        }
        $hourAgo = date('Y-m-d H:i:s', time() - 3600);
        $emailHourly = (int)Db::table('TF_email_verification_codes')
            ->where('email', $email)->where('purpose', $purpose)
            ->where('created_at', '>=', $hourAgo)->count();
        $ipHourly = (int)Db::table('TF_email_verification_codes')
            ->where('ip_hash', $ipHash)
            ->where('created_at', '>=', $hourAgo)->count();
        if ($emailHourly >= self::MAX_PER_HOUR_EMAIL || $ipHourly >= self::MAX_PER_HOUR_IP) {
            throw new ApiException('VERIFICATION_CODE_TOO_FREQUENT', '发送次数已达上限，请一小时后再试', 429);
        }

        $code = (string)random_int(100000, 999999);
        $now = date('Y-m-d H:i:s');
        $codeId = (int)Db::transaction(function () use ($email, $purpose, $code, $ipHash, $now): int {
            Db::table('TF_email_verification_codes')
                ->where('email', $email)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);
            return (int)Db::table('TF_email_verification_codes')->insertGetId([
                'email' => $email,
                'purpose' => $purpose,
                'code_hash' => $this->hash($code),
                'ip_hash' => $ipHash,
                'attempts' => 0,
                'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS),
                'created_at' => $now,
            ]);
        });

        if (!$deliver) {
            // 仍写入一次性记录用于统一限流，但不向未注册或停用邮箱发送邮件。
            Db::table('TF_email_verification_codes')->where('id', $codeId)->update([
                'consumed_at' => date('Y-m-d H:i:s'),
            ]);
            return [
                'expires_in' => self::CODE_TTL_SECONDS,
                'resend_after' => self::RESEND_SECONDS,
            ];
        }

        [$title, $preheader, $message, $subject, $templateCode] = match ($purpose) {
            'change_email' => [
                '更换邮箱验证',
                '你的更换邮箱验证码已送达',
                '你正在更换账号绑定的邮箱，请在 10 分钟内输入以下验证码确认本人操作。如果不是本人操作，请尽快修改密码并检查账号安全。',
                '更换邮箱验证码',
                'change_email_code',
            ],
            'login' => [
                '邮箱验证码登录',
                '你的登录验证码已送达',
                '你正在使用邮箱验证码登录天方云签，请在 10 分钟内输入以下验证码。如果不是本人操作，请忽略此邮件并检查账号安全。',
                '登录验证码',
                'login_email_code',
            ],
            default => [
                '注册邮箱验证',
                '你的注册验证码已送达',
                '欢迎注册！请在 10 分钟内输入以下验证码完成邮箱验证。如果不是本人操作，请忽略此邮件。',
                '注册邮箱验证码',
                'register_email_code',
            ],
        };
        $template = (new EmailTemplateService())->notification(
            $title,
            $preheader,
            $purpose === 'register' ? '新朋友' : '用户',
            $message,
            [
                '验证码' => $code,
                '有效时间' => '10 分钟',
                '安全提示' => '验证码仅可使用一次',
            ],
            '#6366f1'
        );
        try {
            (new MailService())->sendTransactional(
                $email,
                $subject,
                $template['html'],
                $template['text'],
                $templateCode
            );
        } catch (\Throwable $exception) {
            Db::table('TF_email_verification_codes')->where('id', $codeId)->update([
                'consumed_at' => date('Y-m-d H:i:s'),
            ]);
            throw $exception;
        }
        return [
            'expires_in' => self::CODE_TTL_SECONDS,
            'resend_after' => self::RESEND_SECONDS,
        ];
    }

    public function issueChangeEmailCode(int $userId, string $ip): array
    {
        $user = Db::table('TF_users')->where('id', $userId)->first();
        if (!$user) {
            throw new ApiException('USER_NOT_FOUND', '用户不存在', 404);
        }
        $email = mb_strtolower(trim((string)($user->email ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('EMAIL_REQUIRED', '当前账号尚未绑定邮箱，无法发送验证码', 422);
        }
        $result = $this->issue($email, $ip, 'change_email');
        return $result + [
            'email_masked' => $this->maskEmail($email),
        ];
    }

    public function consume(string $email, string $code, string $purpose = 'register'): void
    {
        $email = mb_strtolower(trim($email));
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new ApiException('VERIFICATION_CODE_INVALID', '请输入邮箱收到的6位验证码', 422);
        }
        $result = Db::transaction(function () use ($email, $code, $purpose): string {
            $record = Db::table('TF_email_verification_codes')
                ->where('email', $email)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if (!$record || strtotime((string)$record->expires_at) < time()) {
                return 'expired';
            }
            if ((int)$record->attempts >= self::MAX_ATTEMPTS) {
                return 'locked';
            }
            if (!hash_equals((string)$record->code_hash, $this->hash($code))) {
                Db::table('TF_email_verification_codes')->where('id', $record->id)->increment('attempts');
                return 'invalid';
            }
            Db::table('TF_email_verification_codes')->where('id', $record->id)->update([
                'consumed_at' => date('Y-m-d H:i:s'),
            ]);
            return 'ok';
        });
        match ($result) {
            'expired' => throw new ApiException('VERIFICATION_CODE_EXPIRED', '验证码已过期，请重新获取', 422),
            'locked' => throw new ApiException('VERIFICATION_CODE_LOCKED', '验证码错误次数过多，请重新获取', 429),
            'invalid' => throw new ApiException('VERIFICATION_CODE_INVALID', '验证码不正确，请检查后重试', 422),
            default => null,
        };
    }

    private function hash(string $value): string
    {
        $key = (string)(getenv('APP_KEY') ?: '');
        if (str_starts_with($key, 'base64:')) {
            $key = (string)base64_decode(substr($key, 7), true);
        }
        if ($key === '') {
            throw new ApiException('APP_KEY_MISSING', '应用密钥未配置', 500);
        }
        return hash_hmac('sha256', $value, $key);
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($name, 0, min(2, mb_strlen($name)));
        return $visible . str_repeat('*', max(2, min(6, mb_strlen($name) - mb_strlen($visible)))) . '@' . $domain;
    }
}
