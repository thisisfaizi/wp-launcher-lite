<?php

namespace Wpl;

/**
 * Optional webhook: POSTs JSON on site.created / site.expired / site.deleted,
 * signed with HMAC-SHA256 in X-WPL-Signature (same scheme as upstream), so a
 * Zapier/Make/n8n flow can pick up captured leads.
 */
class Webhook
{
    public static function send(string $event, array $data): void
    {
        $url = (string) Settings::get('webhook_url');
        if ($url === '' || !preg_match('#^https?://#', $url) || !function_exists('curl_init')) {
            return;
        }
        $body = json_encode(['event' => $event, 'timestamp' => gmdate('c'), 'data' => $data], JSON_UNESCAPED_SLASHES);
        $sig = hash_hmac('sha256', $body, (string) Settings::get('webhook_secret'));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-WPL-Event: ' . $event, 'X-WPL-Signature: sha256=' . $sig],
            CURLOPT_RETURNTRANSFER => true,
            // Never hold a visitor's launch hostage to a slow receiver.
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 4,
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($ok === false || $code >= 400) {
            Log::event('webhook.failed', $event . ' → HTTP ' . $code . ' ' . curl_error($ch), $data['slug'] ?? null);
        }
    }
}
