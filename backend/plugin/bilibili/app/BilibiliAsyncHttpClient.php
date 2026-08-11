<?php

namespace plugin\bilibili\app;

use RuntimeException;
use Throwable;
use Workerman\Http\Client;
use Workerman\Http\Response;

/** 仅供 Bilibili Live Worker 使用的事件循环 HTTP 客户端。 */
final class BilibiliAsyncHttpClient
{
    private ?Client $injectedClient;
    /** @var array<string, Client> */
    private array $clients = [];

    public function __construct(?Client $client = null)
    {
        $this->injectedClient = $client;
    }

    private function clientFor(string $url): Client
    {
        if ($this->injectedClient !== null) {
            return $this->injectedClient;
        }
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            throw new RuntimeException('异步请求地址缺少主机名');
        }
        return $this->clients[$host] ??= new Client([
            'max_conn_per_addr' => max(1, (int)(getenv('BILIBILI_LIVE_MAX_CONN_PER_HOST') ?: 4)),
            'keepalive_timeout' => 15,
            'connect_timeout' => max(1, (int)(getenv('BILIBILI_LIVE_CONNECT_TIMEOUT_SECONDS') ?: 5)),
            'timeout' => max(1, (int)(getenv('BILIBILI_LIVE_REQUEST_TIMEOUT_SECONDS') ?: 10)),
            'context' => [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'peer_name' => $host,
                ],
            ],
        ]);
    }

    public function request(
        string $method,
        string $url,
        array $credentials,
        array $options,
        callable $success,
        callable $failure
    ): void {
        $query = is_array($options['query'] ?? null) ? $options['query'] : [];
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $headers = [
            'Accept' => 'application/json',
            'Accept-Language' => 'zh-CN,zh;q=0.8',
            'User-Agent' => (string)($options['user_agent'] ?? 'Mozilla/5.0 TF-Sign/1.0'),
            'Cookie' => self::cookie($credentials),
            'Connection' => 'keep-alive',
        ];
        if (!empty($options['referer'])) {
            $headers['Referer'] = (string)$options['referer'];
        }
        if (!empty($options['origin'])) {
            $headers['Origin'] = (string)$options['origin'];
        }
        foreach ((array)($options['headers'] ?? []) as $name => $value) {
            $headers[(string)$name] = (string)$value;
        }
        $request = [
            'method' => strtoupper($method),
            'headers' => $headers,
            'success' => static function (Response $response) use ($success, $failure): void {
                try {
                    $status = $response->getStatusCode();
                    $body = (string)$response->getBody();
                    if ($status < 200 || $status >= 300) {
                        throw new RuntimeException('第三方平台返回 HTTP ' . $status);
                    }
                    if (strlen($body) > 1024 * 1024) {
                        throw new RuntimeException('第三方平台响应内容过大');
                    }
                    $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($json)) {
                        throw new RuntimeException('第三方平台返回格式错误');
                    }
                    $success($json, $response);
                } catch (Throwable $exception) {
                    $failure($exception);
                }
            },
            'error' => static fn (Throwable $exception) => $failure($exception),
        ];
        if (array_key_exists('form', $options)) {
            $request['data'] = http_build_query((array)$options['form']);
            $request['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $this->clientFor($url)->request($url, $request);
    }

    public static function cookie(array $credentials): string
    {
        $map = [
            'dede_user_id' => 'DedeUserID',
            'sessdata' => 'SESSDATA',
            'bili_jct' => 'bili_jct',
            'dede_user_id_ckmd5' => 'DedeUserID__ckMd5',
            'live_buvid' => 'LIVE_BUVID',
            'buvid3' => 'buvid3',
            'buvid4' => 'buvid4',
        ];
        $parts = [];
        foreach ($map as $key => $name) {
            if (isset($credentials[$key]) && (string)$credentials[$key] !== '') {
                $parts[] = $name . '=' . (string)$credentials[$key];
            }
        }
        return implode('; ', $parts);
    }
}
