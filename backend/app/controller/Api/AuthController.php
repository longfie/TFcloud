<?php

namespace app\controller\Api;

use app\exception\ApiException;
use app\http\ApiResponse;
use app\service\AuthService;
use app\service\AuditService;
use app\service\HumanChallengeService;
use app\service\PasswordChangeService;
use app\service\PasswordResetService;
use app\service\PushNotificationService;
use app\service\QqAuthService;
use support\Request;
use support\Response;

final class AuthController
{
    public function startQqLogin(Request $request): Response
    {
        $returnUrl = trim((string)$request->post('return_url', ''));
        if ($returnUrl === '' || strlen($returnUrl) > 2048) {
            throw new ApiException('VALIDATION_FAILED', '登录返回地址不能为空', 422);
        }
        return ApiResponse::success((new QqAuthService())->start($request, $returnUrl));
    }

    public function startQqBinding(Request $request): Response
    {
        $returnUrl = trim((string)$request->post('return_url', ''));
        if ($returnUrl === '' || strlen($returnUrl) > 2048) {
            throw new ApiException('VALIDATION_FAILED', '绑定返回地址不能为空', 422);
        }
        return ApiResponse::success(
            (new QqAuthService())->startBinding($request, (int)$request->userId(), $returnUrl)
        );
    }

    public function qqCallback(Request $request): Response
    {
        return $this->handleQqCallback($request, trim((string)$request->get('state', '')));
    }

    public function qqRelayCallback(Request $request, string $state): Response
    {
        return $this->handleQqCallback($request, trim($state));
    }

    private function handleQqCallback(Request $request, string $state): Response
    {
        $service = new QqAuthService();
        $returnUrl = $service->callbackReturnUrl($request);
        try {
            $target = $service->complete(
                $request,
                $state,
                trim((string)$request->get('code', '')),
                trim((string)$request->get('error', '')),
                trim((string)$request->get('qqkey', ''))
            );
        } catch (ApiException $exception) {
            $target = $service->failureRedirect($returnUrl, $exception->errorCode);
        } catch (\Throwable) {
            $target = $service->failureRedirect($returnUrl, 'QQ_LOGIN_FAILED');
        }
        return redirect($target);
    }

    public function exchangeQqLogin(Request $request): Response
    {
        $code = trim((string)$request->post('exchange_code', ''));
        if ($code === '' || strlen($code) > 128) {
            throw new ApiException('QQ_LOGIN_EXCHANGE_INVALID', 'QQ 登录凭证无效', 422);
        }
        $result = (new QqAuthService())->exchange($request, $code);
        if (!empty($result['requires_profile_completion'])) {
            return ApiResponse::success($result, '请补全用户名和邮箱');
        }
        (new AuditService())->record((int)$result['user']['id'], 'auth.login.qq', 'session', null);
        return ApiResponse::success($result, 'QQ 登录成功');
    }

    public function completeQqRegistration(Request $request): Response
    {
        $result = (new QqAuthService())->completeRegistration(
            $request,
            trim((string)$request->post('onboarding_token', '')),
            trim((string)$request->post('username', '')),
            trim((string)$request->post('email', '')),
            trim((string)$request->post('email_code', ''))
        );
        (new AuditService())->record((int)$result['user']['id'], 'auth.register.qq', 'user', $result['user']['id']);
        return ApiResponse::created($result, 'QQ 快捷账号创建成功');
    }

    public function issueHumanChallenge(Request $request): Response
    {
        return ApiResponse::success((new HumanChallengeService())->issue(
            $request,
            trim((string)$request->post('purpose', 'login'))
        ));
    }

    public function verifyHumanChallenge(Request $request): Response
    {
        $challengeId = trim((string)$request->post('challenge_id', ''));
        $elapsedMs = (int)$request->post('elapsed_ms', 0);
        $interactionCount = (int)$request->post('interaction_count', 0);
        if ($challengeId === '' || strlen($challengeId) > 128) {
            throw new ApiException('VALIDATION_FAILED', '验证参数不完整', 422);
        }
        return ApiResponse::success(
            (new HumanChallengeService())->verify($request, $challengeId, $elapsedMs, $interactionCount),
            '验证通过'
        );
    }

    public function login(Request $request): Response
    {
        $account = trim((string)$request->post('username', ''));
        $password = (string)$request->post('password', '');
        $challengeId = trim((string)$request->post('challenge_id', ''));
        if ($account === '' || $password === '') {
            throw new ApiException('VALIDATION_FAILED', '账号和密码不能为空', 422);
        }
        if (mb_strlen($account) > 191 || strlen($password) > 1024) {
            throw new ApiException('VALIDATION_FAILED', '账号或密码长度超出限制', 422);
        }

        $site = (new \app\service\SettingsService())->group('site');
        if (!empty($site['login_challenge_enabled'])) {
            (new HumanChallengeService())->consume($request, $challengeId, 'login');
        }

        $result = (new AuthService())->login(
            $account,
            $password,
            $request->getRealIp(),
            (string)$request->header('user-agent', '')
        );
        (new AuditService())->record((int)$result['user']['id'], 'auth.login', 'session', null);

        return ApiResponse::success($result, '登录成功');
    }

    public function issueLoginEmailCode(Request $request): Response
    {
        $challengeId = trim((string)$request->post('challenge_id', ''));
        $site = (new \app\service\SettingsService())->group('site');
        if (!empty($site['login_challenge_enabled'])) {
            (new HumanChallengeService())->consume($request, $challengeId, 'login');
        }
        $result = (new \app\service\EmailVerificationService())->issue(
            trim((string)$request->post('email', '')),
            $request->getRealIp(),
            'login'
        );
        return ApiResponse::success($result, '如果该邮箱已绑定账号，验证码将发送至邮箱');
    }

    public function loginWithEmailCode(Request $request): Response
    {
        $result = (new AuthService())->loginWithEmailCode(
            trim((string)$request->post('email', '')),
            trim((string)$request->post('email_code', '')),
            $request->getRealIp(),
            (string)$request->header('user-agent', '')
        );
        (new AuditService())->record((int)$result['user']['id'], 'auth.login.email_code', 'session', null);
        return ApiResponse::success($result, '邮箱验证码登录成功');
    }

    public function issueRegisterEmailCode(Request $request): Response
    {
        $site = (new \app\service\SettingsService())->group('site');
        $qqAllowed = !empty($site['qq_login_enabled']) && ($site['qq_auto_register'] ?? true);
        if (empty($site['registration_enabled']) && !$qqAllowed) {
            throw new ApiException('REGISTRATION_DISABLED', '当前未开放用户注册', 403);
        }
        $result = (new \app\service\EmailVerificationService())->issue(
            trim((string)$request->post('email', '')),
            $request->getRealIp()
        );
        return ApiResponse::success($result, '验证码已发送，请查收邮箱');
    }

    public function issueChangeEmailCode(Request $request): Response
    {
        $result = (new \app\service\EmailVerificationService())->issueChangeEmailCode(
            (int)$request->userId(),
            $request->getRealIp()
        );
        (new AuditService())->record($request->userId(), 'user.email.code.issue', 'user', $request->userId());
        return ApiResponse::success($result, '验证码已发送至当前邮箱');
    }

    public function register(Request $request): Response
    {
        $site = (new \app\service\SettingsService())->group('site');
        if (empty($site['registration_enabled'])) {
            throw new ApiException('REGISTRATION_DISABLED', '当前未开放用户注册', 403);
        }
        $challengeId = trim((string)$request->post('challenge_id', ''));
        if (!empty($site['registration_challenge_enabled'])) {
            (new HumanChallengeService())->consume($request, $challengeId, 'register');
        }
        $result = (new AuthService())->register(
            $request->all(),
            $request->getRealIp(),
            (string)$request->header('user-agent', '')
        );
        (new AuditService())->record((int)$result['user']['id'], 'auth.register', 'user', $result['user']['id']);
        return ApiResponse::created($result, '注册成功');
    }

    public function logout(Request $request): Response
    {
        $authorization = (string)$request->header('authorization', '');
        preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches);
        (new AuthService())->logout(trim((string)($matches[1] ?? '')));
        (new AuditService())->record($request->userId(), 'auth.logout', 'session', null);
        return ApiResponse::success(null, '退出成功');
    }

    public function me(Request $request): Response
    {
        return ApiResponse::success((new AuthService())->publicUser((int)$request->userId()));
    }

    public function updateMe(Request $request): Response
    {
        $result = (new AuthService())->updateProfile((int)$request->userId(), $request->all());
        (new AuditService())->record($request->userId(), 'user.profile.update', 'user', $request->userId(), [
            'changed_fields' => array_values(array_intersect(
                array_keys($request->all()),
                ['email', 'qq', 'daily_sign_email', 'push_channel', 'push_token', 'current_email_code']
            )),
        ]);
        return ApiResponse::success($result, '个人资料已更新');
    }

    public function testPush(Request $request): Response
    {
        (new PushNotificationService())->test((int)$request->userId());
        return ApiResponse::success(null, '测试推送已发送，请在对应渠道查收');
    }

    public function changePassword(Request $request): Response
    {
        $verificationCode = trim((string)$request->post('verification_code', ''));
        $newPassword = (string)$request->post('new_password', '');
        if ($verificationCode === '' || $newPassword === '') {
            throw new ApiException('VALIDATION_FAILED', '邮箱验证码和新密码不能为空', 422);
        }
        (new PasswordChangeService())->confirm((int)$request->userId(), $verificationCode, $newPassword);
        (new AuditService())->record($request->userId(), 'user.password.change', 'user', $request->userId());
        return ApiResponse::success(null, '密码已修改，请重新登录');
    }

    public function issuePasswordCode(Request $request): Response
    {
        $result = (new PasswordChangeService())->issue((int)$request->userId());
        (new AuditService())->record($request->userId(), 'user.password.code.issue', 'user', $request->userId());
        return ApiResponse::success($result, '验证码已发送');
    }

    public function issuePasswordResetCode(Request $request): Response
    {
        return ApiResponse::success(
            (new PasswordResetService())->issue(trim((string)$request->post('email', ''))),
            '如果邮箱已绑定账号，验证码将发送到该邮箱'
        );
    }

    public function resetPassword(Request $request): Response
    {
        (new PasswordResetService())->confirm(
            trim((string)$request->post('email', '')),
            trim((string)$request->post('verification_code', '')),
            (string)$request->post('new_password', '')
        );
        return ApiResponse::success(null, '密码已重置，请使用新密码登录');
    }
}
