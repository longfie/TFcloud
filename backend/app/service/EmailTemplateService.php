<?php

namespace app\service;

final class EmailTemplateService
{
    public function notification(
        string $title,
        string $preheader,
        string $username,
        string $message,
        array $facts = [],
        string $accent = '#6366f1',
        ?string $actionLabel = null,
        ?string $actionUrl = null
    ): array {
        $site = (new SettingsService())->publicSite();
        $siteName = trim((string)($site['name'] ?? '天方云签')) ?: '天方云签';
        $siteUrl = trim((string)($site['base_url'] ?? ''));
        $safeSite = $this->escape($siteName);
        $safeUser = $this->escape($username !== '' ? $username : '用户');
        $safeTitle = $this->escape($title);
        $safePreheader = $this->escape($preheader);
        $safeMessage = nl2br($this->escape($message));
        $factRows = '';
        $textFacts = [];
        foreach ($facts as $label => $value) {
            if ((string)$label === '验证码') {
                $factRows .= '<tr><td colspan="2" style="padding:16px 0;text-align:center;"><div style="color:#8b90a0;font-size:12px;">验证码</div>'
                    . '<div style="margin-top:7px;color:' . $this->escape($accent) . ';font-size:32px;font-weight:800;letter-spacing:8px;font-family:SFMono-Regular,Consolas,monospace;">'
                    . $this->escape((string)$value) . '</div></td></tr>';
            } else {
                $factRows .= '<tr><td style="padding:9px 0;color:#8b90a0;font-size:13px;">'
                    . $this->escape((string)$label)
                    . '</td><td style="padding:9px 0;color:#202334;font-size:13px;font-weight:700;text-align:right;">'
                    . $this->escape((string)$value)
                    . '</td></tr>';
            }
            $textFacts[] = $label . '：' . $value;
        }
        $actionUrl ??= $siteUrl !== '' ? $siteUrl : null;
        $actionLabel ??= $actionUrl ? '打开站点' : null;
        $button = $actionUrl && $actionLabel
            ? '<div style="margin-top:26px;"><a href="' . $this->escape($actionUrl)
                . '" style="display:inline-block;padding:13px 24px;border-radius:12px;background:' . $this->escape($accent)
                . ';color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;box-shadow:0 8px 20px rgba(99,102,241,.22);">'
                . $this->escape($actionLabel) . '</a></div>'
            : '';
        $siteLink = $siteUrl !== ''
            ? '<a href="' . $this->escape($siteUrl) . '" style="color:#6366f1;text-decoration:none;">' . $safeSite . '</a>'
            : $safeSite;

        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $safeTitle . '</title></head><body style="margin:0;background:#f4f5fb;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica Neue,Arial,PingFang SC,Microsoft YaHei,sans-serif;color:#202334;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $safePreheader . '</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f5fb;padding:32px 14px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 18px 50px rgba(32,35,52,.10);">'
            . '<tr><td style="height:7px;background:linear-gradient(90deg,' . $this->escape($accent) . ',#a855f7,#22c55e);"></td></tr>'
            . '<tr><td style="padding:34px 38px 16px;"><div style="display:inline-block;padding:7px 12px;border-radius:999px;background:#f0f1ff;color:#6366f1;font-size:12px;font-weight:700;letter-spacing:.04em;">'
            . $safeSite . '</div><h1 style="margin:18px 0 10px;font-size:27px;line-height:1.3;color:#171925;">' . $safeTitle
            . '</h1><p style="margin:0;color:#777d90;font-size:14px;line-height:1.8;">你好，<strong style="color:#33374a;">' . $safeUser . '</strong></p></td></tr>'
            . '<tr><td style="padding:0 38px 36px;"><div style="margin-top:14px;padding:20px 22px;border:1px solid #eceefa;border-radius:16px;background:#fafaff;color:#54596d;font-size:14px;line-height:1.9;">'
            . $safeMessage . '</div>'
            . ($factRows !== '' ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:18px;padding:8px 18px;border-radius:15px;background:#f8f9fc;">' . $factRows . '</table>' : '')
            . $button . '</td></tr>'
            . '<tr><td style="padding:22px 38px;border-top:1px solid #f0f1f6;background:#fbfbfd;color:#9a9eac;font-size:12px;line-height:1.8;">'
            . '这是一封由 ' . $siteLink . ' 自动发送的服务通知，请勿直接回复。<br>© ' . date('Y') . ' ' . $safeSite . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        $text = $siteName . "\n\n" . $title . "\n你好，" . $username . "\n\n" . $message;
        if ($textFacts !== []) {
            $text .= "\n\n" . implode("\n", $textFacts);
        }
        if ($actionUrl) {
            $text .= "\n\n" . ($actionLabel ?: '打开站点') . '：' . $actionUrl;
        }
        return ['html' => $html, 'text' => $text];
    }

    public function custom(string $title, string $htmlBody, string $textBody, string $username = '用户'): array
    {
        $message = $textBody !== '' ? $textBody : trim(strip_tags($htmlBody));
        $template = $this->notification($title, $message, $username, $message);
        if ($htmlBody !== '') {
            $safeMessage = nl2br($this->escape($message));
            $template['html'] = str_replace($safeMessage, $htmlBody, $template['html']);
        }
        return $template;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
