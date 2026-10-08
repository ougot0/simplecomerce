<?php
declare(strict_types=1);

namespace SimpleCommerce\Http;

/** Réponse d'un contrôleur : page HTML, JSON, fichier ou redirection. */
final class Response
{
    public function __construct(public string $body = '', public int $status = 200, public array $headers = [])
    {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function page(string $template, array $vars = [], string $layout = 'plain', int $status = 200): self
    {
        return self::html(View::render($template, $vars, $layout), $status);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $to): self
    {
        return new self('', 303, ['Location' => $to]);
    }

    public static function file(string $bytes, string $type, ?string $downloadName = null, string $cache = 'private, max-age=3600'): self
    {
        $h = ['Content-Type' => $type, 'Cache-Control' => $cache, 'X-Content-Type-Options' => 'nosniff'];
        if ($downloadName !== null) {
            $h['Content-Disposition'] = 'attachment; filename="' . preg_replace('/[^\w.\-]/', '_', $downloadName) . '"';
        }
        return new self($bytes, 200, $h);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        echo $this->body;
    }
}
