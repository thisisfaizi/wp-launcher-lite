<?php

namespace Wpl;

/** Renders PHP templates from app/views inside a layout. */
class View
{
    public static function render(string $template, array $vars = [], string $layout = 'layout', int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        Http::securityHeaders();
        $content = self::fetch($template, $vars);
        echo $layout ? self::fetch($layout, $vars + ['content' => $content]) : $content;
        exit;
    }

    public static function fetch(string $template, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require WPL_APP . '/views/' . $template . '.php';
        return (string) ob_get_clean();
    }

    /** Hidden CSRF input for forms. */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(Auth::csrf()) . '">';
    }
}
