<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** Plain PHP templates in app/views. A page renders into a layout through $content. */
final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/staff'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, $data + ['content' => $content]);
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = APP_DIR . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public static function page(string $template, array $data = [], ?string $layout = 'layouts/staff'): never
    {
        Response::html(self::render($template, $data, $layout));
    }
}
