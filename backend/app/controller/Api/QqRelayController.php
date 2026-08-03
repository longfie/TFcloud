<?php

namespace app\controller\Api;

use app\service\QqRelayApplicationService;
use support\Response;

final class QqRelayController
{
    public function verification(): Response
    {
        $content = (new QqRelayApplicationService())->verificationContent();
        if ($content === null) {
            return new Response(404, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ], 'Not Found');
        }

        return new Response(200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $content);
    }
}
