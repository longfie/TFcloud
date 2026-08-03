<?php

namespace app\service;

use app\exception\ApiException;
use support\Request;

final class HumanChallengeService
{
    private const SESSION_KEY = 'auth_human_challenge';
    private const CHALLENGE_TTL_SECONDS = 300;
    private const VERIFIED_TTL_SECONDS = 120;
    private const MIN_INTERACTION_MS = 300;

    public function issue(Request $request, string $purpose = 'login'): array
    {
        if (!in_array($purpose, ['login', 'register'], true)) {
            throw new ApiException('VALIDATION_FAILED', '人机验证用途无效', 422);
        }
        $challengeId = bin2hex(random_bytes(16));
        $nowMs = $this->nowMs();
        $request->session()->set(self::SESSION_KEY, [
            'id_hash' => hash('sha256', $challengeId),
            'purpose' => $purpose,
            'issued_at_ms' => $nowMs,
            'expires_at' => time() + self::CHALLENGE_TTL_SECONDS,
            'verified_at' => null,
            'attempts' => 0,
            'ip_hash' => $this->requestHash($request->getRealIp()),
            'agent_hash' => $this->requestHash((string)$request->header('user-agent', '')),
        ]);

        return [
            'challenge_id' => $challengeId,
            'expires_in' => self::CHALLENGE_TTL_SECONDS,
            'minimum_interaction_ms' => self::MIN_INTERACTION_MS,
        ];
    }

    public function verify(Request $request, string $challengeId, int $elapsedMs, int $interactionCount): array
    {
        $challenge = $this->challenge($request, $challengeId);
        $serverElapsedMs = $this->nowMs() - (int)$challenge['issued_at_ms'];
        $validInteraction = $elapsedMs >= self::MIN_INTERACTION_MS
            && $elapsedMs <= 120000
            && $serverElapsedMs >= self::MIN_INTERACTION_MS
            && $interactionCount >= 2
            && $interactionCount <= 1000;

        if (!$validInteraction) {
            $challenge['attempts'] = (int)$challenge['attempts'] + 1;
            if ($challenge['attempts'] >= 5) {
                $request->session()->delete(self::SESSION_KEY);
            } else {
                $request->session()->set(self::SESSION_KEY, $challenge);
            }
            throw new ApiException('HUMAN_CHALLENGE_INCOMPLETE', '请重新平稳滑动到最右侧', 422);
        }

        $challenge['verified_at'] = time();
        $request->session()->set(self::SESSION_KEY, $challenge);

        return [
            'challenge_id' => $challengeId,
            'verified' => true,
            'expires_in' => self::VERIFIED_TTL_SECONDS,
        ];
    }

    public function consume(Request $request, string $challengeId, string $purpose = 'login'): void
    {
        $challenge = $request->session()->pull(self::SESSION_KEY);
        if (!is_array($challenge)
            || !$this->matches($request, $challenge, $challengeId)
            || !hash_equals((string)($challenge['purpose'] ?? ''), $purpose)
            || empty($challenge['verified_at'])
            || (int)$challenge['verified_at'] < time() - self::VERIFIED_TTL_SECONDS) {
            throw new ApiException('HUMAN_VERIFICATION_REQUIRED', '请先完成人机验证', 422);
        }
    }

    private function challenge(Request $request, string $challengeId): array
    {
        $challenge = $request->session()->get(self::SESSION_KEY);
        if (!is_array($challenge)
            || !$this->matches($request, $challenge, $challengeId)
            || (int)($challenge['expires_at'] ?? 0) < time()) {
            $request->session()->delete(self::SESSION_KEY);
            throw new ApiException('HUMAN_CHALLENGE_EXPIRED', '验证已过期，请重试', 422);
        }
        return $challenge;
    }

    private function matches(Request $request, array $challenge, string $challengeId): bool
    {
        if ($challengeId === '' || !isset($challenge['id_hash'], $challenge['ip_hash'], $challenge['agent_hash'])) {
            return false;
        }
        return hash_equals((string)$challenge['id_hash'], hash('sha256', $challengeId))
            && hash_equals((string)$challenge['ip_hash'], $this->requestHash($request->getRealIp()))
            && hash_equals((string)$challenge['agent_hash'], $this->requestHash((string)$request->header('user-agent', '')));
    }

    private function requestHash(?string $value): string
    {
        return hash('sha256', (string)$value);
    }

    private function nowMs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }
}
