<?php

namespace app\middleware;

use app\exception\ApiException;
use app\service\AccessControlService;
use support\Db;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $authorization = trim((string)$request->header('authorization', ''));
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            throw new ApiException('AUTH_REQUIRED', '请先登录', 401);
        }

        $tokenHash = hash('sha256', trim($matches[1]));
        $session = Db::table('TF_sessions as s')
            ->join('TF_users as u', 'u.id', '=', 's.user_id')
            ->where('s.token_hash', $tokenHash)
            ->whereNull('s.revoked_at')
            ->where('s.expires_at', '>', date('Y-m-d H:i:s'))
            ->where('u.status', 'active')
            ->select(['s.id', 's.user_id', 's.last_seen_at', 'u.role'])
            ->first();

        if (!$session) {
            throw new ApiException('AUTH_TOKEN_INVALID', '登录状态已失效', 401);
        }

        if (!$request instanceof \support\Request) {
            throw new \RuntimeException('Configured request class must be support\\Request');
        }
        $request->setAuthenticatedUserId((int)$session->user_id);
        $request->setAuthenticatedUserRole(
            (new AccessControlService())->normalizeRole((string)$session->role)
        );
        if (!$session->last_seen_at || strtotime((string)$session->last_seen_at) < time() - 300) {
            Db::table('TF_sessions')->where('id', $session->id)->update([
                'last_seen_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $handler($request);
    }
}
