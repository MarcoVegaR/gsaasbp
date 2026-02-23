<?php

declare(strict_types=1);

namespace App\Support\Sso;

use Illuminate\Http\Response;
use RuntimeException;

final class SsoAutoSubmitPage
{
    /**
     * @param  array<string, scalar|null>  $fields
     */
    public function response(string $actionUrl, array $fields): Response
    {
        $script = "window.addEventListener('DOMContentLoaded',function(){var form=document.getElementById('sso-form');if(form){form.submit();}});";
        $scriptHash = base64_encode(hash('sha256', $script, true));
        $targetOrigin = $this->originFromUrl($actionUrl);

        $inputs = '';

        foreach ($fields as $name => $value) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $inputs .= sprintf(
                "<input type=\"hidden\" name=\"%s\" value=\"%s\">\n",
                $this->escape($name),
                $this->escape((string) ($value ?? '')),
            );
        }

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="referrer" content="no-referrer">
    <title>Redirecting…</title>
</head>
<body>
    <form id="sso-form" method="POST" action="{$this->escape($actionUrl)}">
{$inputs}    </form>
    <script>{$script}</script>
</body>
</html>
HTML;

        $response = response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);

        $response->headers->set('Content-Security-Policy', sprintf(
            "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action %s; script-src 'sha256-%s'",
            $targetOrigin,
            $scriptHash,
        ));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    private function originFromUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            throw new RuntimeException('Invalid action URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme === '' || $host === '') {
            throw new RuntimeException('Invalid action URL.');
        }

        $port = $parts['port'] ?? null;

        if ($port === null || ($scheme === 'https' && (int) $port === 443) || ($scheme === 'http' && (int) $port === 80)) {
            return sprintf('%s://%s', $scheme, $host);
        }

        return sprintf('%s://%s:%d', $scheme, $host, (int) $port);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
