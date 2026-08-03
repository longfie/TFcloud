<?php

namespace app\service;

use app\exception\ApiException;
use support\Db;

final class PasswordChangeService
{
    private const CODE_TTL_SECONDS = 600;
    private const RESEND_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;
    private const MAX_PER_HOUR = 5;

    public function issue(int $userId): array
    {
        $user = Db::table('TF_users')->where('id', $userId)->first();
        $email = trim((string)($user->email ?? ''));
        if (!$user || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('EMAIL_REQUIRED', '请先在个人资料中设置有效邮箱', 422);
        }

        $latest = Db::table('TF_password_change_codes')
            ->where('user_id', $userId)
            ->where('purpose', 'change')
            ->orderByDesc('id')
            ->first();
        if ($latest && strtotime((string)$latest->created_at) > time() - self::RESEND_SECONDS) {
            throw new ApiException('VERIFICATION_CODE_TOO_FREQUENT', '验证码发送过于频繁，请稍后再试', 429);
        }
        $hourly = Db::table('TF_password_change_codes')
            ->where('user_id', $userId)
            ->where('purpose', 'change')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))
            ->count();
        if ((int)$hourly >= self::MAX_PER_HOUR) {
            throw new ApiException('VERIFICATION_CODE_LIMIT', '本小时验证码发送次数已达上限', 429);
        }

        $code = (string)random_int(100000, 999999);
        $now = date('Y-m-d H:i:s');
        $codeId = (int)Db::transaction(function () use ($userId, $email, $code, $now): int {
            Db::table('TF_password_change_codes')
                ->where('user_id', $userId)
                ->where('purpose', 'change')
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);
            return (int)Db::table('TF_password_change_codes')->insertGetId([
                'user_id' => $userId,
                'purpose' => 'change',
                'email' => mb_strtolower($email),
                'code_hash' => $this->hash($code),
                'attempts' => 0,
                'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS),
                'created_at' => $now,
            ]);
        });

        $template = (new EmailTemplateService())->notification(
            '修改登录密码',
            '你的修改密码验证码已送达',
            (string)$user->username,
            '你正在修改登录密码，请在 10 分钟内完成验证。如果不是本人操作，请忽略此邮件并检查账号安全。',
            [
                '验证码' => $code,
                '有效时间' => '10 分钟',
                '安全提示' => '请勿转发给任何人',
            ],
            '#6366f1',
            '返回个人设置'
        );
        try {
            (new MailService())->sendTransactional(
                $email,
                '修改登录密码验证码',
                $template['html'],
                $template['text'],
                'password_change_code',
                $userId
            );
        } catch (\Throwable $exception) {
            Db::table('TF_password_change_codes')->where('id', $codeId)->update(['consumed_at' => date('Y-m-d H:i:s')]);
            throw $exception;
        }

        return [
            'email_masked' => $this->maskEmail($email),
            'expires_in' => self::CODE_TTL_SECONDS,
            'resend_after' => self::RESEND_SECONDS,
        ];
    }

    public function confirm(int $userId, string $code, string $newPassword): void
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new ApiException('VERIFICATION_CODE_INVALID', '请输入 6 位邮箱验证码', 422);
        }
        if (strlen($newPassword) < 8 || strlen($newPassword) > 1024) {
            throw new ApiException('VALIDATION_FAILED', '新密码长度必须为8到1024个字符', 422);
        }

        $result = Db::transaction(function () use ($userId, $code, $newPassword): string {
            $record = Db::table('TF_password_change_codes')
                ->where('user_id', $userId)
                ->where('purpose', 'change')
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            $user = Db::table('TF_users')->where('id', $userId)->lockForUpdate()->first();
            if (!$record || !$user || strtotime((string)$record->expires_at) < time()) {
                return 'expired';
            }
            if ((int)$record->attempts >= self::MAX_ATTEMPTS) {
                return 'locked';
            }
            if (!hash_equals((string)$record->code_hash, $this->hash($code))
                || mb_strtolower(trim((string)$user->email)) !== (string)$record->email) {
                Db::table('TF_password_change_codes')->where('id', $record->id)->increment('attempts');
                return 'invalid';
            }

            $now = date('Y-m-d H:i:s');
            Db::table('TF_users')->where('id', $userId)->update([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'password_migrated_at' => $now,
                'updated_at' => $now,
            ]);
            Db::table('TF_password_change_codes')
                ->where('user_id', $userId)
                ->where('purpose', 'change')
                ->whereNull('consumed_at')
                ->update([
                    'consumed_at' => $now,
                ]);
            Db::table('TF_sessions')->where('user_id', $userId)->whereNull('revoked_at')->update([
                'revoked_at' => $now,
            ]);
            return 'changed';
        });

        match ($result) {
            'expired' => throw new ApiException('VERIFICATION_CODE_EXPIRED', '验证码已过期，请重新获取', 422),
            'locked' => throw new ApiException('VERIFICATION_CODE_LOCKED', '验证码错误次数过多，请重新获取', 429),
            'invalid' => throw new ApiException('VERIFICATION_CODE_INVALID', '邮箱验证码不正确', 422),
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
