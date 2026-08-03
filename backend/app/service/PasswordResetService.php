<?php

namespace app\service;

use app\exception\ApiException;
use support\Db;

final class PasswordResetService
{
    private const CODE_TTL_SECONDS = 600;
    private const RESEND_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;
    private const MAX_PER_HOUR = 5;

    public function issue(string $email): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            throw new ApiException('VALIDATION_FAILED', '请输入有效的邮箱地址', 422);
        }
        if (empty((new SettingsService())->group('mail')['enabled'])) {
            throw new ApiException('MAIL_DISABLED', '邮件服务尚未启用，请联系管理员', 409);
        }
        $response = [
            'email_masked' => $this->maskEmail($email),
            'expires_in' => self::CODE_TTL_SECONDS,
            'resend_after' => self::RESEND_SECONDS,
        ];
        $user = Db::table('TF_users')
            ->where('email', $email)
            ->where('status', 'active')
            ->first();
        if (!$user) {
            usleep(200000);
            return $response;
        }
        $latest = Db::table('TF_password_change_codes')
            ->where('user_id', $user->id)
            ->where('purpose', 'reset')
            ->orderByDesc('id')
            ->first();
        if ($latest && strtotime((string)$latest->created_at) > time() - self::RESEND_SECONDS) {
            return $response;
        }
        $hourly = (int)Db::table('TF_password_change_codes')
            ->where('user_id', $user->id)
            ->where('purpose', 'reset')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))
            ->count();
        if ($hourly >= self::MAX_PER_HOUR) {
            return $response;
        }

        $code = (string)random_int(100000, 999999);
        $now = date('Y-m-d H:i:s');
        $codeId = (int)Db::transaction(function () use ($user, $email, $code, $now): int {
            Db::table('TF_password_change_codes')
                ->where('user_id', $user->id)
                ->where('purpose', 'reset')
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);
            return (int)Db::table('TF_password_change_codes')->insertGetId([
                'user_id' => $user->id,
                'purpose' => 'reset',
                'email' => $email,
                'code_hash' => $this->hash($code),
                'attempts' => 0,
                'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS),
                'created_at' => $now,
            ]);
        });
        $template = (new EmailTemplateService())->notification(
            '重置登录密码',
            '你的密码重置验证码已送达',
            (string)$user->username,
            '你正在通过邮箱找回登录密码，请在 10 分钟内完成验证。如果不是本人操作，请忽略此邮件。',
            [
                '验证码' => $code,
                '有效时间' => '10 分钟',
                '安全提示' => '验证码仅可使用一次',
            ],
            '#6366f1',
            '返回密码重置页面'
        );
        try {
            (new MailService())->sendTransactional(
                $email,
                '重置登录密码验证码',
                $template['html'],
                $template['text'],
                'password_reset_code',
                (int)$user->id
            );
        } catch (\Throwable $exception) {
            Db::table('TF_password_change_codes')->where('id', $codeId)->update([
                'consumed_at' => date('Y-m-d H:i:s'),
            ]);
            throw $exception;
        }
        return $response;
    }

    public function confirm(string $email, string $code, string $newPassword): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
            throw new ApiException('PASSWORD_RESET_INVALID', '邮箱或验证码不正确', 422);
        }
        if (strlen($newPassword) < 8 || strlen($newPassword) > 1024) {
            throw new ApiException('VALIDATION_FAILED', '新密码长度必须为8到1024个字符', 422);
        }
        $result = Db::transaction(function () use ($email, $code, $newPassword): string {
            $user = Db::table('TF_users')->where('email', $email)->where('status', 'active')->lockForUpdate()->first();
            if (!$user) {
                return 'invalid';
            }
            $record = Db::table('TF_password_change_codes')
                ->where('user_id', $user->id)
                ->where('purpose', 'reset')
                ->where('email', $email)
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
                Db::table('TF_password_change_codes')->where('id', $record->id)->increment('attempts');
                return 'invalid';
            }
            $now = date('Y-m-d H:i:s');
            Db::table('TF_users')->where('id', $user->id)->update([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'password_migrated_at' => $now,
                'updated_at' => $now,
            ]);
            Db::table('TF_password_change_codes')
                ->where('user_id', $user->id)
                ->where('purpose', 'reset')
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);
            Db::table('TF_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->update([
                'revoked_at' => $now,
            ]);
            return 'changed';
        });
        match ($result) {
            'expired' => throw new ApiException('VERIFICATION_CODE_EXPIRED', '验证码已过期，请重新获取', 422),
            'locked' => throw new ApiException('VERIFICATION_CODE_LOCKED', '验证码错误次数过多，请重新获取', 429),
            'invalid' => throw new ApiException('PASSWORD_RESET_INVALID', '邮箱或验证码不正确', 422),
            default => null,
        };
    }

    private function hash(string $code): string
    {
        $key = (string)(getenv('APP_KEY') ?: '');
        if (str_starts_with($key, 'base64:')) {
            $key = (string)base64_decode(substr($key, 7), true);
        }
        if ($key === '') {
            throw new ApiException('APP_KEY_MISSING', '应用密钥未配置', 500);
        }
        return hash_hmac('sha256', $code, $key);
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($name, 0, min(2, mb_strlen($name)));
        return $visible . str_repeat('*', max(2, min(6, mb_strlen($name) - mb_strlen($visible)))) . '@' . $domain;
    }
}
