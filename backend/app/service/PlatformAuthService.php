<?php

namespace app\service;

use app\exception\ApiException;
use app\sign\credential\CredentialVault;
use plugin\cloud189\app\Cloud189Client;
use plugin\netease\app\NeteaseClient;
use plugin\picacomic\app\PicacomicClient;
use plugin\sport\app\SportClient;
use support\Db;

final class PlatformAuthService
{
    private const FLOW_TTL = 300;

    public function startQr(int $userId, string $platformCode, string $method = 'qr'): array
    {
        if (!in_array($method, ['qr', 'qq_qr', 'wechat_qr'], true)
            || !in_array($method, (new PlatformCatalogService())->get($platformCode)['connection_methods'], true)) {
            throw new ApiException('PLATFORM_METHOD_UNSUPPORTED', '该平台不支持扫码添加', 422);
        }
        $active = Db::table('TF_platform_auth_flows')
            ->where('user_id', $userId)->where('platform_code', $platformCode)
            ->whereIn('status', ['waiting', 'scanned'])->where('expires_at', '>', date('Y-m-d H:i:s'))->count();
        if ($active >= 5) {
            throw new ApiException('PLATFORM_AUTH_TOO_MANY', '二维码获取过于频繁，请稍后再试', 429);
        }

        $started = match ($platformCode . ':' . $method) {
            'bilibili:qr' => $this->startBilibili(),
            'tieba:qr' => $this->startTieba(),
            'tieba:qq_qr' => $this->startTiebaQq(),
            'tieba:wechat_qr' => $this->startTiebaWechat(),
            'iqiyi:qr' => $this->startIqiyi(),
            default => throw new ApiException('PLATFORM_METHOD_UNSUPPORTED', '该平台不支持扫码添加', 422),
        };
        $flowNo = bin2hex(random_bytes(16));
        $encrypted = (new CredentialVault())->encrypt($started['context'], 'platform-auth:' . $flowNo);
        $now = date('Y-m-d H:i:s');
        Db::table('TF_platform_auth_flows')->insert([
            'flow_no' => $flowNo,
            'user_id' => $userId,
            'platform_code' => $platformCode,
            'method' => $method,
            'status' => 'waiting',
            'context_cipher' => $encrypted['payload_cipher'],
            'nonce' => $encrypted['nonce'],
            'key_version' => $encrypted['key_version'],
            'expires_at' => date('Y-m-d H:i:s', time() + self::FLOW_TTL),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return [
            'flow_no' => $flowNo,
            'status' => 'waiting',
            'qr_url' => $started['qr_url'] ?? null,
            'qr_image' => $started['qr_image'] ?? null,
            'expires_in' => self::FLOW_TTL,
        ];
    }

    public function pollQr(int $userId, string $platformCode, string $flowNo): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $flowNo)) {
            throw new ApiException('PLATFORM_AUTH_FLOW_INVALID', '登录流程编号无效', 422);
        }
        $flow = Db::table('TF_platform_auth_flows')
            ->where('flow_no', $flowNo)->where('user_id', $userId)
            ->where('platform_code', $platformCode)->first();
        if (!$flow) {
            throw new ApiException('PLATFORM_AUTH_FLOW_NOT_FOUND', '登录流程不存在', 404);
        }
        if ($flow->status === 'succeeded' || $flow->consumed_at) {
            throw new ApiException('PLATFORM_AUTH_FLOW_CONSUMED', '该二维码已完成使用', 409);
        }
        if (strtotime((string)$flow->expires_at) <= time()) {
            $this->updateFlow($flowNo, 'expired');
            return ['flow_no' => $flowNo, 'status' => 'expired', 'message' => '二维码已过期，请重新获取'];
        }
        $context = (new CredentialVault())->decrypt(
            $flow->context_cipher,
            $flow->nonce,
            'platform-auth:' . $flowNo
        );
        $result = match ($platformCode . ':' . $flow->method) {
            'bilibili:qr' => $this->pollBilibili($context),
            'tieba:qr' => $this->pollTieba($context),
            'tieba:qq_qr' => $this->pollTiebaQq($context),
            'tieba:wechat_qr' => $this->pollTiebaWechat($context),
            'iqiyi:qr' => $this->pollIqiyi($context),
            default => throw new ApiException('PLATFORM_METHOD_UNSUPPORTED', '该平台不支持扫码添加', 422),
        };
        $status = (string)$result['status'];
        if (isset($result['context']) && is_array($result['context'])) {
            $context = array_merge($context, $result['context']);
            $this->replaceFlowContext($flowNo, $context, $status);
        }
        if ($status !== 'succeeded') {
            if (!isset($result['context'])) $this->updateFlow($flowNo, $status);
            return ['flow_no' => $flowNo, 'status' => $status, 'message' => $result['message']];
        }

        $account = (new PluginAccountService())->create($userId, [
            'plugin_code' => $platformCode,
            'credentials' => $result['credentials'],
            'settings' => ['schedule_enabled' => false],
        ]);
        Db::table('TF_platform_auth_flows')->where('flow_no', $flowNo)->update([
            'status' => 'succeeded', 'consumed_at' => date('Y-m-d H:i:s'),
            'context_cipher' => random_bytes(32), 'nonce' => random_bytes(24), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['flow_no' => $flowNo, 'status' => 'succeeded', 'message' => '扫码登录成功', 'account' => $account];
    }

    public function password(int $userId, string $platformCode, array $input): array
    {
        $platform = (new PlatformCatalogService())->get($platformCode);
        if (!in_array('password', $platform['connection_methods'], true)) {
            throw new ApiException('PLATFORM_METHOD_UNSUPPORTED', '该平台不支持账号密码添加，请使用扫码登录', 422);
        }
        if ($platformCode === 'tieba') {
            return $this->loginTiebaPassword($userId, $input);
        }
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $loginType = trim((string)($input['login_type'] ?? 'auto'));
        if ($username === '' || $password === '' || mb_strlen($username) > 191 || strlen($password) > 1024) {
            throw new ApiException('VALIDATION_FAILED', '平台账号和密码不能为空或长度超出限制', 422);
        }
        $credentials = match ($platformCode) {
            'netease' => $this->loginNetease($username, $password, $loginType),
            'sport' => $this->loginSport($username, $password),
            'cloud189' => $this->loginCloud189($username, $password),
            'picacomic' => $this->loginPicacomic($username, $password),
            default => throw new ApiException('PLATFORM_METHOD_UNSUPPORTED', '该平台不支持账号密码添加', 422),
        };
        $account = (new PluginAccountService())->create($userId, [
            'plugin_code' => $platformCode,
            'credentials' => $credentials,
            'settings' => ['schedule_enabled' => false],
        ]);
        return ['status' => 'succeeded', 'message' => '登录成功', 'account' => $account];
    }

    private function loginSport(string $username, string $password): array
    {
        $client = new SportClient(['username' => $username, 'password' => $password]);
        $client->authenticate();
        return $client->credentialsWithSession();
    }

    private function loginCloud189(string $username, string $password): array
    {
        $client = new Cloud189Client(['username' => $username, 'password' => $password]);
        $client->authenticate();
        return $client->credentialsWithSession();
    }

    private function loginPicacomic(string $username, string $password): array
    {
        $client = new PicacomicClient(['username' => $username, 'password' => $password]);
        $client->authenticate();
        return $client->credentialsWithSession();
    }

    private function startBilibili(): array
    {
        $response = $this->request('GET', 'https://passport.bilibili.com/x/passport-login/web/qrcode/generate', null, [
            'Referer: https://www.bilibili.com/',
        ]);
        $json = $this->json($response['body']);
        if ((int)($json['code'] ?? -1) !== 0 || empty($json['data']['qrcode_key']) || empty($json['data']['url'])) {
            throw new ApiException('PLATFORM_QR_START_FAILED', '哔哩哔哩二维码获取失败', 502);
        }
        return ['qr_url' => $json['data']['url'], 'context' => ['qrcode_key' => $json['data']['qrcode_key']]];
    }

    private function pollBilibili(array $context): array
    {
        $response = $this->request('GET', 'https://passport.bilibili.com/x/passport-login/web/qrcode/poll?qrcode_key=' . rawurlencode((string)$context['qrcode_key']), null, [
            'Referer: https://www.bilibili.com/',
        ]);
        $json = $this->json($response['body']);
        $code = (int)($json['data']['code'] ?? -1);
        if ($code === 86101) return ['status' => 'waiting', 'message' => '等待使用哔哩哔哩客户端扫码'];
        if ($code === 86090) return ['status' => 'scanned', 'message' => '已扫码，请在手机端确认'];
        if ($code === 86038) return ['status' => 'expired', 'message' => '二维码已过期，请重新获取'];
        if ($code !== 0) return ['status' => 'waiting', 'message' => (string)($json['data']['message'] ?? '等待扫码')];
        $cookies = $this->cookies($response['headers'] . "\n" . (string)($json['data']['url'] ?? ''));
        $credentials = [
            'sessdata' => $cookies['SESSDATA'] ?? '',
            'bili_jct' => $cookies['bili_jct'] ?? '',
            'dede_user_id' => $cookies['DedeUserID'] ?? '',
            'dede_user_id_ckmd5' => $cookies['DedeUserID__ckMd5'] ?? '',
        ];
        if ($credentials['sessdata'] === '' || $credentials['bili_jct'] === '' || $credentials['dede_user_id'] === '') {
            throw new ApiException('PLATFORM_QR_CREDENTIAL_MISSING', '扫码成功，但未取得完整的哔哩哔哩登录凭据', 502);
        }
        return ['status' => 'succeeded', 'message' => '扫码成功', 'credentials' => $credentials];
    }

    private function startTieba(): array
    {
        $gid = strtoupper(bin2hex(random_bytes(16)));
        $url = 'https://passport.baidu.com/v2/api/getqrcode?lp=pc&qrloginfrom=pc&gid=' . $gid
            . '&apiver=v3&tt=' . (int)(microtime(true) * 1000) . '&callback=callback';
        $response = $this->request('GET', $url, null, ['Referer: https://passport.baidu.com/v2/?login']);
        $json = $this->jsonp($response['body']);
        if ((int)($json['errno'] ?? -1) !== 0 || empty($json['sign'])) {
            throw new ApiException('PLATFORM_QR_START_FAILED', '百度二维码获取失败', 502);
        }
        $qrUrl = 'https://wappass.baidu.com/wp/?qrlogin&t=' . time() . '&error=0&sign=' . rawurlencode($json['sign']) . '&cmd=login&lp=pc&tpl=&uaonly=';
        return ['qr_url' => $qrUrl, 'context' => ['sign' => $json['sign'], 'gid' => $gid]];
    }

    private function pollTieba(array $context): array
    {
        $url = 'https://passport.baidu.com/channel/unicast?channel_id=' . rawurlencode((string)$context['sign'])
            . '&tpl=pp&gid=' . rawurlencode((string)$context['gid']) . '&apiver=v3&tt=' . (int)(microtime(true) * 1000) . '&callback=callback';
        $response = $this->request('GET', $url, null, ['Referer: https://passport.baidu.com/v2/?login']);
        $json = $this->jsonp($response['body']);
        if ((int)($json['errno'] ?? -1) !== 0) {
            return ['status' => 'waiting', 'message' => '等待使用百度客户端扫码确认'];
        }
        $channel = json_decode((string)($json['channel_v'] ?? ''), true);
        if (!is_array($channel) || empty($channel['v'])) return ['status' => 'waiting', 'message' => '等待手机端确认'];
        $loginUrl = 'https://passport.baidu.com/v2/api/bdusslogin?bduss=' . rawurlencode((string)$channel['v'])
            . '&u=https%3A%2F%2Fpassport.baidu.com%2F&qrcode=1&tpl=pp&apiver=v3&tt=' . (int)(microtime(true) * 1000) . '&callback=callback';
        $login = $this->request('GET', $loginUrl, null, ['Referer: https://passport.baidu.com/v2/?login']);
        $payload = $this->jsonp($login['body']);
        if ((string)($payload['errInfo']['no'] ?? '') !== '0') {
            throw new ApiException('PLATFORM_QR_LOGIN_FAILED', (string)($payload['errInfo']['msg'] ?? '百度扫码登录失败'), 502);
        }
        $cookies = $this->cookies($login['headers']);
        if (empty($cookies['BDUSS'])) throw new ApiException('PLATFORM_QR_CREDENTIAL_MISSING', '扫码成功，但未取得百度登录凭据', 502);
        return ['status' => 'succeeded', 'message' => '扫码成功', 'credentials' => [
            'bduss' => $cookies['BDUSS'], 'stoken' => $cookies['STOKEN'] ?? '',
        ]];
    }

    private function startTiebaQq(): array
    {
        $url = 'https://ssl.ptlogin2.qq.com/ptqrshow?appid=716027609&e=2&l=M&s=4&d=72&v=4&t='
            . rawurlencode((string)microtime(true)) . '&daid=383&pt_3rd_aid=100312028';
        $response = $this->request('GET', $url, null, [
            'Referer: https://xui.ptlogin2.qq.com/cgi-bin/xlogin?appid=716027609&daid=383&pt_3rd_aid=100312028',
        ]);
        $cookies = $this->cookies($response['headers']);
        if (empty($cookies['qrsig']) || $response['body'] === '') {
            throw new ApiException('PLATFORM_QR_START_FAILED', 'QQ 登录二维码获取失败', 502);
        }
        return [
            'qr_image' => 'data:image/png;base64,' . base64_encode($response['body']),
            'context' => ['qrsig' => $cookies['qrsig']],
        ];
    }

    private function pollTiebaQq(array $context): array
    {
        $qrsig = (string)($context['qrsig'] ?? '');
        $url = 'https://ssl.ptlogin2.qq.com/ptqrlogin?u1=https%3A%2F%2Fgraph.qq.com%2Foauth2.0%2Flogin_jump'
            . '&ptqrtoken=' . $this->qqQrToken($qrsig)
            . '&ptredirect=0&h=1&t=1&g=1&from_ui=1&ptlang=2052&action=1-0-' . (int)(microtime(true) * 1000)
            . '&js_ver=10289&js_type=1&pt_uistyle=40&aid=716027609&daid=383&pt_3rd_aid=100312028';
        $response = $this->request('GET', $url, null, [
            'Referer: https://xui.ptlogin2.qq.com/cgi-bin/xlogin',
            'Cookie: qrsig=' . $qrsig,
        ]);
        if (!preg_match("/ptuiCB\\('(.*?)'\\)/", $response['body'], $match)) {
            throw new ApiException('PLATFORM_QR_RESPONSE_INVALID', 'QQ 登录状态返回异常', 502);
        }
        $parts = explode("','", str_replace("', '", "','", $match[1]));
        $code = (int)($parts[0] ?? -1);
        if ($code === 65) return ['status' => 'expired', 'message' => 'QQ 二维码已过期，请重新获取'];
        if ($code === 66) return ['status' => 'waiting', 'message' => '等待使用 QQ 扫码'];
        if ($code === 67) return ['status' => 'scanned', 'message' => '已扫码，请在 QQ 中确认授权'];
        if ($code !== 0 || empty($parts[2])) {
            throw new ApiException('PLATFORM_QR_LOGIN_FAILED', (string)($parts[4] ?? 'QQ 扫码登录失败'), 502);
        }

        $qqSession = $this->request('GET', $parts[2], null, ['Referer: https://xui.ptlogin2.qq.com/cgi-bin/xlogin']);
        $qqCookies = $this->cookies($qqSession['headers']);
        if (empty($qqCookies['p_skey'])) {
            throw new ApiException('PLATFORM_QR_CREDENTIAL_MISSING', 'QQ 授权成功，但未取得授权状态', 502);
        }
        $baiduStart = $this->request('GET', 'https://passport.baidu.com/phoenix/account/startlogin?type=15&tpl=pp&u=https%3A%2F%2Fpassport.baidu.com%2F&display=popup&act=optional');
        $baiduCookies = $this->cookies($baiduStart['headers'] . "\n" . $baiduStart['body']);
        $mkey = $baiduCookies['mkey'] ?? '';
        if ($mkey === '') throw new ApiException('PLATFORM_QR_LOGIN_FAILED', '百度第三方登录初始化失败', 502);
        $state = (string)time();
        $authorize = $this->request('POST', 'https://graph.qq.com/oauth2.0/authorize', [
            'response_type' => 'code', 'client_id' => '100312028',
            'redirect_uri' => 'https://passport.baidu.com/phoenix/account/afterauth?mkey=' . $mkey,
            'scope' => 'get_user_info,add_share,get_other_info,get_fanslist,get_idollist,add_idol,get_simple_userinfo',
            'state' => $state, 'switch' => '', 'from_ptlogin' => 1, 'src' => 1, 'update_auth' => 1,
            'openapi' => '80901010', 'g_tk' => $this->qqGtk((string)$qqCookies['p_skey']),
            'auth_time' => (int)(microtime(true) * 1000),
        ], ['Cookie: ' . $this->cookieHeader($qqCookies)]);
        if (!preg_match('/^Location:\s*(.+)$/mi', $authorize['headers'], $location)) {
            throw new ApiException('PLATFORM_QR_LOGIN_FAILED', 'QQ 授权回调地址获取失败', 502);
        }
        $callback = trim($location[1]);
        if (parse_url($callback, PHP_URL_HOST) !== 'passport.baidu.com') {
            throw new ApiException('PLATFORM_QR_LOGIN_FAILED', 'QQ 授权回调地址无效', 502);
        }
        $baidu = $this->request('GET', $callback, null, ['Cookie: mkey=' . $mkey]);
        return ['status' => 'succeeded', 'message' => 'QQ 扫码登录成功', 'credentials' => $this->baiduCredentials($baidu)];
    }

    private function startTiebaWechat(): array
    {
        $state = (string)time();
        $url = 'https://open.weixin.qq.com/connect/qrconnect?appid=wx85f17c29f3e648bf&response_type=code'
            . '&scope=snsapi_login&redirect_uri=https%3A%2F%2Fpassport.baidu.com%2Fphoenix%2Faccount%2Fafterauth'
            . '&state=' . $state . '&display=page&traceid=';
        $response = $this->request('GET', $url);
        if (!preg_match('!connect/qrcode/([^"\s<]+)!', $response['body'], $match)) {
            throw new ApiException('PLATFORM_QR_START_FAILED', '微信登录二维码获取失败', 502);
        }
        $image = $this->request('GET', 'https://open.weixin.qq.com/connect/qrcode/' . $match[1], null, [
            'Referer: https://open.weixin.qq.com/connect/qrconnect',
        ]);
        return [
            'qr_image' => 'data:image/jpeg;base64,' . base64_encode($image['body']),
            'context' => ['uuid' => $match[1], 'state' => $state],
        ];
    }

    private function pollTiebaWechat(array $context): array
    {
        $last = !empty($context['last']) ? '&last=' . rawurlencode((string)$context['last']) : '';
        $response = $this->request('GET', 'https://long.open.weixin.qq.com/connect/l/qrconnect?uuid='
            . rawurlencode((string)$context['uuid']) . $last . '&_=' . (int)(microtime(true) * 1000), null, [
            'Referer: https://open.weixin.qq.com/connect/qrconnect',
        ]);
        if (!preg_match("/wx_errcode=(\\d+);window.wx_code='(.*?)'/", $response['body'], $match)) {
            return ['status' => 'waiting', 'message' => '等待使用微信扫码'];
        }
        $code = (int)$match[1];
        if ($code === 408) return ['status' => 'waiting', 'message' => '等待使用微信扫码'];
        if ($code === 404) return ['status' => 'scanned', 'message' => '已扫码，请在微信中确认', 'context' => ['last' => '404']];
        if ($code === 402) return ['status' => 'expired', 'message' => '微信二维码已过期，请重新获取'];
        if ($code !== 405 || $match[2] === '') {
            throw new ApiException('PLATFORM_QR_LOGIN_FAILED', '微信扫码登录失败', 502);
        }
        $baiduStart = $this->request('GET', 'https://passport.baidu.com/phoenix/account/startlogin?type=42&tpl=pp&u=https%3A%2F%2Fpassport.baidu.com%2F&display=popup&act=optional');
        $cookies = $this->cookies($baiduStart['headers'] . "\n" . $baiduStart['body']);
        $mkey = $cookies['mkey'] ?? '';
        if ($mkey === '') throw new ApiException('PLATFORM_QR_LOGIN_FAILED', '百度第三方登录初始化失败', 502);
        $url = 'https://passport.baidu.com/phoenix/account/afterauth?mkey=' . rawurlencode($mkey)
            . '&appid=wx85f17c29f3e648bf&traceid=&code=' . rawurlencode($match[2])
            . '&state=' . rawurlencode((string)$context['state']);
        $baidu = $this->request('GET', $url, null, ['Cookie: mkey=' . $mkey]);
        return ['status' => 'succeeded', 'message' => '微信扫码登录成功', 'credentials' => $this->baiduCredentials($baidu)];
    }

    private function startIqiyi(): array
    {
        $response = $this->request('POST', 'https://passport.iqiyi.com/apis/qrcode/gen_login_token.action', [
            'agenttype' => 1, 'device_name' => '网页端', 'formSDK' => 1,
            'ptid' => '01010021010000000000', 'sdk_version' => '1.0.0', 'surl' => 1,
        ]);
        $json = $this->json($response['body']);
        if (($json['code'] ?? '') !== 'A00000' || empty($json['data']['token']) || empty($json['data']['url'])) {
            throw new ApiException('PLATFORM_QR_START_FAILED', '爱奇艺二维码获取失败', 502);
        }
        return ['qr_url' => $json['data']['url'], 'context' => ['token' => $json['data']['token']]];
    }

    private function pollIqiyi(array $context): array
    {
        $response = $this->request('POST', 'https://passport.iqiyi.com/apis/qrcode/is_token_login.action', [
            'agenttype' => 1, 'formSDK' => 1, 'ptid' => '01010021010000000000',
            'sdk_version' => '1.0.0', 'token' => (string)$context['token'],
        ]);
        $json = $this->json($response['body']);
        $code = (string)($json['code'] ?? '');
        if ($code === 'A00001') return ['status' => 'waiting', 'message' => '等待使用爱奇艺客户端扫码'];
        if ($code === 'P01006') return ['status' => 'scanned', 'message' => '已扫码，请在手机端确认'];
        if (in_array($code, ['P00501', 'P01007'], true)) return ['status' => 'expired', 'message' => '二维码已过期，请重新获取'];
        if ($code !== 'A00000') throw new ApiException('PLATFORM_QR_LOGIN_FAILED', (string)($json['msg'] ?? '爱奇艺扫码登录失败'), 502);
        $cookies = $this->cookies($response['headers']);
        if (empty($cookies['P00001']) || empty($cookies['P00003'])) {
            throw new ApiException('PLATFORM_QR_CREDENTIAL_MISSING', '扫码成功，但未取得完整的爱奇艺登录凭据', 502);
        }
        return ['status' => 'succeeded', 'message' => '扫码成功', 'credentials' => [
            'p00001' => $cookies['P00001'], 'p00003' => $cookies['P00003'],
        ]];
    }

    public function sendSms(int $userId, string $platformCode, array $input): array
    {
        if ($platformCode !== 'tieba') throw new ApiException('PLATFORM_METHOD_UNSUPPORTED', '该平台不支持短信登录', 422);
        $flowNo = trim((string)($input['flow_no'] ?? ''));
        $context = [];
        if ($flowNo !== '') {
            [$flow, $context] = $this->ownedFlow($userId, $platformCode, $flowNo, 'sms');
            $phone = (string)($context['phone'] ?? '');
        } else {
            $phone = trim((string)($input['phone'] ?? ''));
            if (!preg_match('/^1\d{10}$/', $phone)) throw new ApiException('VALIDATION_FAILED', '请输入正确的中国大陆手机号', 422);
            $context = ['phone' => $phone, 'gid' => $this->baiduGid()];
        }
        $form = [
            'username' => $phone, 'tpl' => 'tb', 'clientfrom' => 'native', 'countrycode' => '',
            'gid' => $context['gid'], 'dialogVerifyCode' => trim((string)($input['captcha'] ?? '')),
            'vcodesign' => (string)($context['vcodesign'] ?? ''),
            'vcodestr' => (string)($context['vcodestr'] ?? ''),
        ];
        $response = $this->request('POST', 'https://wappass.baidu.com/wp/api/login/sms?v=' . (int)(microtime(true) * 1000), $form, [
            'Referer: ' . $this->baiduMobileReferer(),
        ]);
        $json = $this->json($response['body']);
        $code = (string)($json['errInfo']['no'] ?? '-1');
        if ($code === '0') {
            $flowNo = $flowNo !== '' ? $flowNo : $this->createFlow($userId, 'tieba', 'sms', 'code_sent', $context);
            $this->replaceFlowContext($flowNo, $context, 'code_sent');
            return ['flow_no' => $flowNo, 'status' => 'code_sent', 'message' => '短信验证码已发送'];
        }
        if ($code === '50020' || $code === '500001' || $code === '500002') {
            $context['vcodestr'] = (string)($json['data']['vcodestr'] ?? $json['data']['codeString'] ?? '');
            $context['vcodesign'] = (string)($json['data']['vcodesign'] ?? '');
            if ($context['vcodestr'] === '') throw new ApiException('PLATFORM_SMS_SEND_FAILED', (string)($json['errInfo']['msg'] ?? '短信验证失败'), 422);
            $flowNo = $flowNo !== '' ? $flowNo : $this->createFlow($userId, 'tieba', 'sms', 'captcha_required', $context);
            $this->replaceFlowContext($flowNo, $context, 'captcha_required');
            return [
                'flow_no' => $flowNo, 'status' => 'captcha_required',
                'message' => (string)($json['errInfo']['msg'] ?? '请输入图片验证码'),
                'captcha_image' => $this->baiduCaptcha((string)$context['vcodestr']),
            ];
        }
        throw new ApiException('PLATFORM_SMS_SEND_FAILED', (string)($json['errInfo']['msg'] ?? '短信验证码发送失败'), 422);
    }

    public function completeSms(int $userId, string $platformCode, array $input): array
    {
        if ($platformCode !== 'tieba') throw new ApiException('PLATFORM_METHOD_UNSUPPORTED', '该平台不支持短信登录', 422);
        $flowNo = trim((string)($input['flow_no'] ?? ''));
        $smsCode = trim((string)($input['sms_code'] ?? ''));
        if (!preg_match('/^\d{4,8}$/', $smsCode)) throw new ApiException('VALIDATION_FAILED', '请输入正确的短信验证码', 422);
        [, $context] = $this->ownedFlow($userId, 'tieba', $flowNo, 'sms');
        $form = [
            'smsvc' => $smsCode, 'clientfrom' => 'native', 'tpl' => 'tb', 'login_share_strategy' => 'choice',
            'client' => 'android', 'adapter' => 3, 't' => (int)(microtime(true) * 1000), 'act' => 'bind_mobile',
            'loginLink' => 0, 'smsLoginLink' => 1, 'lPFastRegLink' => 0, 'fastRegLink' => 1, 'lPlayout' => 0,
            'lang' => 'zh-cn', 'regLink' => 1, 'action' => 'login', 'loginmerge' => '', 'isphone' => 0,
            'dialogVerifyCode' => '', 'dialogVcodestr' => '', 'dialogVcodesign' => '', 'gid' => $context['gid'],
            'agreement' => 1, 'vcodesign' => '', 'vcodestr' => '', 'smsverify' => 1, 'sms' => 1,
            'mobilenum' => $context['phone'], 'username' => $context['phone'], 'countrycode' => '',
            'passAppHash' => '', 'passAppVersion' => '',
        ];
        $response = $this->request('POST', 'https://wappass.baidu.com/wp/api/login?v=' . (int)(microtime(true) * 1000), $form, [
            'Referer: ' . $this->baiduMobileReferer(),
        ]);
        $credentials = $this->baiduCredentialsFromLoginJson($this->json($response['body']));
        return $this->completeInteractiveLogin($userId, $flowNo, $credentials, '短信登录成功');
    }

    private function loginTiebaPassword(int $userId, array $input): array
    {
        $flowNo = trim((string)($input['flow_no'] ?? ''));
        $context = [];
        if ($flowNo !== '') {
            [, $context] = $this->ownedFlow($userId, 'tieba', $flowNo, 'password');
            $username = (string)$context['username'];
            $password = (string)$context['password'];
            if (($context['stage'] ?? '') === 'security') {
                if (!empty($input['send_security_code'])) {
                    $url = 'https://wappass.baidu.com/wp/login/sec?ajax=1&v=' . (int)(microtime(true) * 1000)
                        . '&showtype=' . rawurlencode((string)$context['show_type'])
                        . '&lstr=' . rawurlencode((string)$context['lstr']) . '&ltoken=' . rawurlencode((string)$context['ltoken'])
                        . '&clientfrom=native&tpl=tb&client=android&adapter=3&action=login';
                    $json = $this->json($this->request('GET', $url, null, ['Referer: ' . $this->baiduMobileReferer()])['body']);
                    if ((string)($json['errInfo']['no'] ?? '') !== '0') throw new ApiException('PLATFORM_SECURITY_CODE_FAILED', (string)($json['errInfo']['msg'] ?? '安全验证码发送失败'), 422);
                    return ['flow_no' => $flowNo, 'status' => 'verification_required', 'message' => '安全验证码已发送', 'verification_target' => $context['target'] ?? '绑定设备'];
                }
                $securityCode = trim((string)($input['security_code'] ?? ''));
                if ($securityCode === '') return ['flow_no' => $flowNo, 'status' => 'verification_required', 'message' => '请输入安全验证码', 'verification_target' => $context['target'] ?? '绑定设备'];
                $url = 'https://wappass.baidu.com/wp/login/sec?type=2&v=' . (int)(microtime(true) * 1000);
                $form = [
                    'vcode' => $securityCode, 'clientfrom' => 'native', 'tpl' => 'tb', 'client' => 'android', 'adapter' => 3,
                    'action' => 'login', 'showtype' => $context['show_type'], 'lstr' => $context['lstr'], 'ltoken' => $context['ltoken'],
                ];
                $credentials = $this->baiduCredentialsFromLoginJson($this->json($this->request('POST', $url, $form, ['Referer: ' . $this->baiduMobileReferer()])['body']));
                return $this->completeInteractiveLogin($userId, $flowNo, $credentials, '账号密码登录成功');
            }
        } else {
            $username = trim((string)($input['username'] ?? ''));
            $password = (string)($input['password'] ?? '');
            if ($username === '' || $password === '' || mb_strlen($username) > 191 || strlen($password) > 1024) {
                throw new ApiException('VALIDATION_FAILED', '百度账号和密码不能为空或长度超出限制', 422);
            }
            $context = ['username' => $username, 'password' => $password, 'gid' => $this->baiduGid(), 'stage' => 'password'];
        }

        $timeJson = $this->json($this->request('GET', 'https://wappass.baidu.com/wp/api/security/antireplaytoken?tpl=tb&v=' . (int)(microtime(true) * 1000), null, [
            'Referer: ' . $this->baiduMobileReferer(),
        ])['body']);
        if ((int)($timeJson['errno'] ?? -1) !== 110000 || empty($timeJson['time'])) throw new ApiException('PLATFORM_PASSWORD_LOGIN_FAILED', '百度登录令牌获取失败', 502);
        $serverTime = (string)$timeJson['time'];
        $captcha = trim((string)($input['captcha'] ?? ''));
        $form = [
            'username' => $username, 'code' => '', 'password' => $this->baiduEncryptPassword($password . $serverTime),
            'verifycode' => $captcha, 'clientfrom' => 'native', 'tpl' => 'tb', 'login_share_strategy' => 'choice',
            'client' => 'android', 'adapter' => 3, 't' => (int)(microtime(true) * 1000), 'act' => 'bind_mobile',
            'loginLink' => 0, 'smsLoginLink' => 1, 'lPFastRegLink' => 0, 'fastRegLink' => 1, 'lPlayout' => 0,
            'loginInitType' => 0, 'lang' => 'zh-cn', 'regLink' => 1, 'action' => 'login', 'loginmerge' => 1,
            'isphone' => 0, 'dialogVerifyCode' => '', 'dialogVcodestr' => '', 'dialogVcodesign' => '',
            'gid' => $context['gid'], 'vcodestr' => (string)($context['vcodestr'] ?? ''), 'countrycode' => '',
            'servertime' => $serverTime, 'logLoginType' => 'sdk_login', 'passAppHash' => '', 'passAppVersion' => '',
        ];
        $json = $this->json($this->request('POST', 'https://wappass.baidu.com/wp/api/login?v=' . (int)(microtime(true) * 1000), $form, [
            'Referer: ' . $this->baiduMobileReferer(),
        ])['body']);
        $code = (string)($json['errInfo']['no'] ?? '-1');
        if ($code === '0') {
            $credentials = $this->baiduCredentialsFromLoginJson($json);
            $flowNo = $flowNo !== '' ? $flowNo : $this->createFlow($userId, 'tieba', 'password', 'processing', $context);
            return $this->completeInteractiveLogin($userId, $flowNo, $credentials, '账号密码登录成功');
        }
        if (in_array($code, ['310006', '500001', '500002'], true)) {
            $context['vcodestr'] = (string)($json['data']['codeString'] ?? $json['data']['vcodestr'] ?? '');
            $context['stage'] = 'captcha';
            if ($context['vcodestr'] === '') throw new ApiException('PLATFORM_PASSWORD_LOGIN_FAILED', (string)($json['errInfo']['msg'] ?? '需要图片验证码'), 422);
            $flowNo = $flowNo !== '' ? $flowNo : $this->createFlow($userId, 'tieba', 'password', 'captcha_required', $context);
            $this->replaceFlowContext($flowNo, $context, 'captcha_required');
            return ['flow_no' => $flowNo, 'status' => 'captcha_required', 'message' => (string)($json['errInfo']['msg'] ?? '请输入图片验证码'), 'captcha_image' => $this->baiduCaptcha($context['vcodestr'])];
        }
        if ($code === '400023') {
            $context['stage'] = 'security';
            $context['show_type'] = (string)($json['data']['showType'] ?? 'mobile');
            $context['lstr'] = (string)($json['data']['lstr'] ?? $json['data']['token'] ?? '');
            $context['ltoken'] = (string)($json['data']['ltoken'] ?? $json['data']['lurl'] ?? '');
            $context['target'] = (string)($json['data']['phone'] ?? $json['data']['email'] ?? $json['data']['account'] ?? '绑定设备');
            $flowNo = $flowNo !== '' ? $flowNo : $this->createFlow($userId, 'tieba', 'password', 'verification_required', $context);
            $this->replaceFlowContext($flowNo, $context, 'verification_required');
            return ['flow_no' => $flowNo, 'status' => 'verification_required', 'message' => '需要验证绑定的手机或邮箱', 'verification_target' => $context['target']];
        }
        throw new ApiException('PLATFORM_PASSWORD_LOGIN_FAILED', (string)($json['errInfo']['msg'] ?? '百度账号或密码登录失败'), 422);
    }

    private function loginNetease(string $username, string $password, string $loginType): array
    {
        $phone = $loginType === 'phone' || ($loginType === 'auto' && preg_match('/^1\d{10}$/', $username));
        $endpoint = $phone ? 'https://music.163.com/weapi/login/cellphone' : 'https://music.163.com/weapi/login';
        $payload = $phone
            ? ['phone' => $username, 'password' => md5($password), 'rememberLogin' => 'true', 'csrf_token' => '']
            : ['username' => $username, 'password' => md5($password), 'rememberLogin' => 'true', 'csrf_token' => ''];
        $form = (new NeteaseClient([]))->encryptPayload($payload);
        $response = $this->request('POST', $endpoint, $form, [
            'Referer: https://music.163.com/',
            'Cookie: os=pc; appver=2.9.7;',
        ]);
        $json = $this->json($response['body']);
        if ((int)($json['code'] ?? -1) !== 200) {
            $message = (string)($json['msg'] ?? $json['message'] ?? '账号或密码错误，或平台要求额外验证');
            throw new ApiException('PLATFORM_PASSWORD_LOGIN_FAILED', $message, 422);
        }
        $cookies = $this->cookies($response['headers']);
        if (empty($cookies['MUSIC_U'])) {
            throw new ApiException('PLATFORM_PASSWORD_CREDENTIAL_MISSING', '登录成功，但未取得网易云音乐登录凭据', 502);
        }
        return ['music_u' => $cookies['MUSIC_U'], 'csrf' => $cookies['__csrf'] ?? ''];
    }

    private function createFlow(int $userId, string $platformCode, string $method, string $status, array $context): string
    {
        $active = Db::table('TF_platform_auth_flows')->where('user_id', $userId)
            ->where('platform_code', $platformCode)->where('expires_at', '>', date('Y-m-d H:i:s'))->count();
        if ($active >= 8) throw new ApiException('PLATFORM_AUTH_TOO_MANY', '登录操作过于频繁，请稍后再试', 429);
        $flowNo = bin2hex(random_bytes(16));
        $encrypted = (new CredentialVault())->encrypt($context, 'platform-auth:' . $flowNo);
        $now = date('Y-m-d H:i:s');
        Db::table('TF_platform_auth_flows')->insert([
            'flow_no' => $flowNo, 'user_id' => $userId, 'platform_code' => $platformCode,
            'method' => $method, 'status' => $status, 'context_cipher' => $encrypted['payload_cipher'],
            'nonce' => $encrypted['nonce'], 'key_version' => $encrypted['key_version'],
            'expires_at' => date('Y-m-d H:i:s', time() + self::FLOW_TTL), 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $flowNo;
    }

    private function ownedFlow(int $userId, string $platformCode, string $flowNo, string $method): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $flowNo)) throw new ApiException('PLATFORM_AUTH_FLOW_INVALID', '登录流程编号无效', 422);
        $flow = Db::table('TF_platform_auth_flows')->where('flow_no', $flowNo)->where('user_id', $userId)
            ->where('platform_code', $platformCode)->where('method', $method)->first();
        if (!$flow) throw new ApiException('PLATFORM_AUTH_FLOW_NOT_FOUND', '登录流程不存在', 404);
        if ($flow->consumed_at || $flow->status === 'succeeded') throw new ApiException('PLATFORM_AUTH_FLOW_CONSUMED', '该登录流程已经完成', 409);
        if (strtotime((string)$flow->expires_at) <= time()) throw new ApiException('PLATFORM_AUTH_FLOW_EXPIRED', '登录流程已过期，请重新开始', 409);
        $context = (new CredentialVault())->decrypt($flow->context_cipher, $flow->nonce, 'platform-auth:' . $flowNo);
        return [$flow, $context];
    }

    private function replaceFlowContext(string $flowNo, array $context, string $status): void
    {
        $encrypted = (new CredentialVault())->encrypt($context, 'platform-auth:' . $flowNo);
        Db::table('TF_platform_auth_flows')->where('flow_no', $flowNo)->update([
            'status' => $status, 'context_cipher' => $encrypted['payload_cipher'], 'nonce' => $encrypted['nonce'],
            'key_version' => $encrypted['key_version'], 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function completeInteractiveLogin(int $userId, string $flowNo, array $credentials, string $message): array
    {
        $account = (new PluginAccountService())->create($userId, [
            'plugin_code' => 'tieba', 'credentials' => $credentials, 'settings' => ['schedule_enabled' => false],
        ]);
        Db::table('TF_platform_auth_flows')->where('flow_no', $flowNo)->update([
            'status' => 'succeeded', 'consumed_at' => date('Y-m-d H:i:s'),
            'context_cipher' => random_bytes(32), 'nonce' => random_bytes(24), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['flow_no' => $flowNo, 'status' => 'succeeded', 'message' => $message, 'account' => $account];
    }

    private function baiduCredentialsFromLoginJson(array $json): array
    {
        if ((string)($json['errInfo']['no'] ?? '') !== '0') {
            throw new ApiException('PLATFORM_LOGIN_FAILED', (string)($json['errInfo']['msg'] ?? '百度登录失败'), 422);
        }
        if (!empty($json['data']['loginProxy'])) {
            $json = $this->json($this->request('GET', (string)$json['data']['loginProxy'], null, ['Referer: ' . $this->baiduMobileReferer()])['body']);
        }
        $xml = (string)($json['data']['xml'] ?? '');
        $values = [];
        foreach (['bduss', 'stoken'] as $field) {
            if (preg_match('!<' . $field . '>(.*?)</' . $field . '>!is', $xml, $match)) $values[$field] = html_entity_decode($match[1], ENT_QUOTES | ENT_XML1);
        }
        if (empty($values['bduss'])) throw new ApiException('PLATFORM_CREDENTIAL_MISSING', '百度登录成功，但未取得 BDUSS', 502);
        return ['bduss' => $values['bduss'], 'stoken' => $values['stoken'] ?? ''];
    }

    private function baiduCredentials(array $response): array
    {
        $cookies = $this->cookies(str_replace('=deleted', '=', $response['headers'] . "\n" . $response['body']));
        if (empty($cookies['BDUSS'])) throw new ApiException('PLATFORM_QR_CREDENTIAL_MISSING', '第三方扫码成功，但未取得百度登录凭据', 502);
        return ['bduss' => $cookies['BDUSS'], 'stoken' => $cookies['STOKEN'] ?? ''];
    }

    private function baiduCaptcha(string $codeString): string
    {
        $image = $this->request('GET', 'https://wappass.baidu.com/cgi-bin/genimage?' . rawurlencode($codeString)
            . '&v=' . (int)(microtime(true) * 1000), null, ['Referer: ' . $this->baiduMobileReferer()]);
        return 'data:image/jpeg;base64,' . base64_encode($image['body']);
    }

    private function baiduEncryptPassword(string $plain): string
    {
        if (!extension_loaded('gmp')) throw new ApiException('PLATFORM_PASSWORD_ENCRYPT_UNAVAILABLE', '服务器缺少密码加密扩展', 500);
        $modulus = gmp_init('B3C61EBBA4659C4CE3639287EE871F1F48F7930EA977991C7AFE3CC442FEA49643212E7D570C853F368065CC57A2014666DA8AE7D493FD47D171C0D894EEE3ED7F99F6798B7FFD7B5873227038AD23E3197631A8CB642213B9F27D4901AB0D92BFA27542AE890855396ED92775255C977F5C302F1E7ED4B1E369C12CB6B1822F', 16);
        $exponent = gmp_init('10001', 16);
        $bytes = array_values(unpack('C*', $plain));
        $chunkSize = 126;
        while (count($bytes) % $chunkSize !== 0) $bytes[] = 0;
        $blocks = [];
        foreach (array_chunk($bytes, $chunkSize) as $chunk) {
            $number = gmp_init(0);
            foreach ($chunk as $index => $byte) $number = gmp_add($number, gmp_mul($byte, gmp_pow(256, $index)));
            $blocks[] = str_pad(gmp_strval(gmp_powm($number, $exponent, $modulus), 16), 256, '0', STR_PAD_LEFT);
        }
        return implode(' ', $blocks);
    }

    private function baiduGid(): string
    {
        $hex = strtoupper(bin2hex(random_bytes(16)));
        return substr($hex, 0, 7) . '-' . substr($hex, 7, 4) . '-' . substr($hex, 11, 4) . '-' . substr($hex, 15, 4) . '-' . substr($hex, 19, 12);
    }

    private function baiduMobileReferer(): string
    {
        return 'https://wappass.baidu.com/passport/login?clientfrom=native&tpl=tb&client=android&adapter=3&smsLoginLink=1';
    }

    private function qqQrToken(string $qrsig): int
    {
        $hash = 0;
        for ($i = 0, $length = strlen($qrsig); $i < $length; $i++) {
            $hash += (($hash << 5) & 2147483647) + ord($qrsig[$i]);
            $hash &= 2147483647;
        }
        return $hash & 2147483647;
    }

    private function qqGtk(string $skey): int
    {
        $hash = 5381;
        for ($i = 0, $length = strlen($skey); $i < $length; $i++) {
            $hash += (($hash << 5) & 2147483647) + ord($skey[$i]);
            $hash &= 2147483647;
        }
        return $hash & 2147483647;
    }

    private function cookieHeader(array $cookies): string
    {
        return implode('; ', array_map(static fn (string $name, string $value): string => $name . '=' . $value, array_keys($cookies), array_values($cookies)));
    }

    private function request(string $method, string $url, ?array $form = null, array $headers = []): array
    {
        $ch = curl_init($url);
        $responseHeaders = '';
        $headers[] = 'Accept: application/json, text/plain, */*';
        $headers[] = 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126 Safari/537.36';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $responseHeaders .= $line;
                return strlen($line);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form ?? []));
        }
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $error !== '' || $status >= 500) {
            throw new ApiException('PLATFORM_UPSTREAM_UNAVAILABLE', '平台登录服务暂时不可用，请稍后再试', 502);
        }
        return ['body' => (string)$body, 'headers' => $responseHeaders, 'status' => $status];
    }

    private function json(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new ApiException('PLATFORM_RESPONSE_INVALID', '平台登录服务返回了无效数据', 502);
        return $decoded;
    }

    private function jsonp(string $body): array
    {
        if (!preg_match('/^[^(]*\((.*)\)\s*;?\s*$/s', trim($body), $match)) return [];
        return $this->json($match[1]);
    }

    private function cookies(string $source): array
    {
        $cookies = [];
        preg_match_all('/(?:Set-Cookie:\s*|[?&;])([A-Za-z0-9_]+)=([^;\s&]+)/i', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) $cookies[$match[1]] = urldecode($match[2]);
        return $cookies;
    }

    private function updateFlow(string $flowNo, string $status): void
    {
        Db::table('TF_platform_auth_flows')->where('flow_no', $flowNo)->update([
            'status' => $status, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
