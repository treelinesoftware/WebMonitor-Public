<?php
require_once __DIR__ . '/db.php';

/**
 * Notification channels: email (SMTP), ntfy, and webhooks (Discord, Slack,
 * or any endpoint that accepts JSON).
 *
 * Channel config (JSON in notification_channels.config):
 *   email:   host, port, security (starttls|ssl|none), username, password,
 *            from, to (comma-separated), verify_tls (bool)
 *   ntfy:    url (https://ntfy.sh/topic or self-hosted), token (optional)
 *   webhook: url
 */

const CHANNEL_TYPES = ['email', 'ntfy', 'webhook'];
const CHANNEL_SECRET_FIELDS = ['password', 'token'];

/** Sends to every enabled channel; returns how many succeeded. */
function notify_all(string $title, string $body, string $priority = 'warning', ?int $alertId = null): int {
    $ok = 0;
    foreach (db()->query('SELECT * FROM notification_channels WHERE enabled = 1')->fetchAll() as $ch) {
        $err = send_to_channel($ch, $title, $body, $priority);
        log_notification((int) $ch['id'], $alertId, $err === null, $err ?? 'sent');
        if ($err === null) $ok++;
    }
    return $ok;
}

function log_notification(?int $channelId, ?int $alertId, bool $ok, string $detail): void {
    db()->prepare('INSERT INTO notification_log (ts, channel_id, alert_id, ok, detail) VALUES (?, ?, ?, ?, ?)')
        ->execute([(int) round(microtime(true) * 1000), $channelId, $alertId, $ok ? 1 : 0, substr($detail, 0, 500)]);
}

/** Returns null on success or an error message. Never throws. */
function send_to_channel(array $ch, string $title, string $body, string $priority): ?string {
    $cfg = json_decode((string) $ch['config'], true) ?: [];
    try {
        switch ($ch['type']) {
            case 'email':
                return smtp_send($cfg, $title, $body . "\n\n-- \nSent by WebMonitor");
            case 'ntfy':
                $headers = [
                    'Title: ' . ntfy_header_value($title),
                    'Priority: ' . (['critical' => '5', 'warning' => '4'][$priority] ?? '3'),
                    'Tags: ' . (['critical' => 'rotating_light', 'warning' => 'warning'][$priority] ?? 'information_source'),
                    'Content-Type: text/plain; charset=utf-8',
                ];
                if (!empty($cfg['token'])) $headers[] = 'Authorization: Bearer ' . $cfg['token'];
                return http_post((string) ($cfg['url'] ?? ''), $body, $headers);
            case 'webhook':
                $url = (string) ($cfg['url'] ?? '');
                $text = "**$title**\n$body";
                if (preg_match('#^https://(discord|discordapp)\.com/api/webhooks/#', $url)) {
                    $payload = ['content' => mb_substr($text, 0, 1900)];
                } elseif (str_starts_with($url, 'https://hooks.slack.com/')) {
                    $payload = ['text' => str_replace('**', '*', $text)];
                } else {
                    $payload = ['title' => $title, 'text' => $text, 'content' => $text, 'priority' => $priority];
                }
                return http_post($url, json_encode($payload), ['Content-Type: application/json']);
        }
        return "unknown channel type '{$ch['type']}'";
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/** HTTP header values must be ASCII; ntfy accepts RFC 2047 for the rest. */
function ntfy_header_value(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function http_post(string $url, string $body, array $headers): ?string {
    if (!preg_match('#^https?://#i', $url)) return 'URL must start with http:// or https://';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'WebMonitor',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return "request failed: $err";
    if ($code < 200 || $code >= 300) return "HTTP $code: " . substr(trim((string) $resp), 0, 200);
    return null;
}

/**
 * Minimal SMTP client (no mail server is needed on this machine):
 * implicit TLS (465) or STARTTLS (587), AUTH LOGIN, UTF-8 plain text.
 */
function smtp_send(array $cfg, string $subject, string $body): ?string {
    $host = trim((string) ($cfg['host'] ?? ''));
    $port = (int) ($cfg['port'] ?? 587);
    $security = $cfg['security'] ?? 'starttls';
    $from = trim((string) ($cfg['from'] ?? ''));
    $to = array_values(array_filter(array_map('trim', explode(',', (string) ($cfg['to'] ?? '')))));
    if ($host === '' || !$from || !$to) return 'SMTP host, From and To are required';
    foreach (array_merge([$from], $to) as $addr) {
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) return "invalid email address: $addr";
    }

    $verify = !array_key_exists('verify_tls', $cfg) || $cfg['verify_tls'];
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => $verify, 'verify_peer_name' => $verify, 'peer_name' => $host,
    ]]);
    $target = ($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return "cannot connect to $host:$port: $errstr";
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): array {
        $text = '';
        while (($line = fgets($fp, 2048)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;   // last line of a reply
        }
        return [(int) substr($text, 0, 3), trim($text)];
    };
    $cmd = function (string $line, array $expect) use ($fp, $read): void {
        fwrite($fp, $line . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $expect, true)) {
            // Don't echo credentials or the message body into error messages.
            $shown = str_contains($line, "\r\n") ? 'message DATA'
                : (str_starts_with($line, 'AUTH') || strlen($line) > 60 ? 'AUTH' : $line);
            throw new RuntimeException("SMTP: '$shown' rejected: $text");
        }
    };

    try {
        [$code, $text] = $read();
        if ($code !== 220) throw new RuntimeException("SMTP greeting: $text");
        $me = gethostname() ?: 'webmonitor';
        $cmd("EHLO $me", [250]);
        if ($security === 'starttls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed (certificate problem?)');
            }
            $cmd("EHLO $me", [250]);
        }
        if (!empty($cfg['username'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string) $cfg['username']), [334]);
            $cmd(base64_encode((string) ($cfg['password'] ?? '')), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        foreach ($to as $rcpt) $cmd("RCPT TO:<$rcpt>", [250, 251]);
        $cmd('DATA', [354]);

        $headers = [
            'From: WebMonitor <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $me . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
        $cmd($data . '.', [250]);
        $cmd('QUIT', [221]);
        return null;
    } catch (RuntimeException $e) {
        return $e->getMessage();
    } finally {
        fclose($fp);
    }
}

/** Channel as shown to the browser: secrets replaced by "is it set". */
function channel_public(array $ch): array {
    $cfg = json_decode((string) $ch['config'], true) ?: [];
    foreach (CHANNEL_SECRET_FIELDS as $f) {
        $cfg[$f . '_set'] = !empty($cfg[$f]);
        unset($cfg[$f]);
    }
    return ['id' => (int) $ch['id'], 'type' => $ch['type'], 'name' => $ch['name'],
            'enabled' => (bool) $ch['enabled'], 'config' => $cfg];
}

/**
 * Validates and normalizes a channel config from the browser. Secret
 * fields left blank keep their previous value.
 */
function channel_config(string $type, array $in, array $previous = []): array {
    $str = fn($k, $max = 255) => substr(trim((string) ($in[$k] ?? '')), 0, $max);
    switch ($type) {
        case 'email':
            $cfg = [
                'host' => $str('host'), 'port' => (int) ($in['port'] ?? 587) ?: 587,
                'security' => in_array($in['security'] ?? '', ['starttls', 'ssl', 'none'], true) ? $in['security'] : 'starttls',
                'username' => $str('username'), 'password' => (string) ($in['password'] ?? ''),
                'from' => $str('from'), 'to' => $str('to', 1000),
                'verify_tls' => !array_key_exists('verify_tls', $in) || (bool) $in['verify_tls'],
            ];
            if ($cfg['host'] === '' || $cfg['from'] === '' || $cfg['to'] === '') throw new InvalidArgumentException('SMTP server, From and To are required.');
            break;
        case 'ntfy':
            $cfg = ['url' => $str('url', 500), 'token' => (string) ($in['token'] ?? '')];
            if (!preg_match('#^https?://[^/]+/[^/]+#', $cfg['url'])) throw new InvalidArgumentException('ntfy URL should look like https://ntfy.sh/your-topic');
            break;
        case 'webhook':
            $cfg = ['url' => $str('url', 1000)];
            if (!preg_match('#^https?://#', $cfg['url'])) throw new InvalidArgumentException('Webhook URL must start with https://');
            break;
        default:
            throw new InvalidArgumentException('Unknown channel type.');
    }
    foreach (CHANNEL_SECRET_FIELDS as $f) {
        if (array_key_exists($f, $cfg) && $cfg[$f] === '' && !empty($previous[$f])) $cfg[$f] = $previous[$f];
    }
    return $cfg;
}
