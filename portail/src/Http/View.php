<?php
declare(strict_types=1);

namespace SimpleCommerce\Http;

/** Gabarits PHP : templates/<nom>.php, insérés dans une mise en page (templates/layout/<mise en page>.php). */
final class View
{
    public static function render(string $template, array $vars = [], ?string $layout = null): string
    {
        $content = self::partial($template, $vars);
        if ($layout === null) {
            return $content;
        }
        return self::partial('layout/' . $layout, ['content' => $content] + $vars);
    }

    public static function partial(string $template, array $vars = []): string
    {
        $file = SC_ROOT . '/templates/' . $template . '.php';
        if (!preg_match('#^[a-z0-9/_-]+$#', $template) || !is_file($file)) {
            throw new \RuntimeException("Gabarit introuvable : $template");
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
