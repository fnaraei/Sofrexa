<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/**
 * Plain PHP templates in app/views. A page renders into a layout through $content.
 * Layout variables a page sets inside its template ($headActions, $appActions, $bottom, $appSub, …)
 * are handed to the layout, so each page keeps its header buttons next to its markup.
 */
final class View
{
    private const LAYOUT_VARS = ['title', 'sub', 'appTitle', 'appSub', 'back', 'nav', 'tab', 'appActions', 'headActions', 'bottom', 'theme', 'scripts', 'noTabbar', 'noHead', 'bodyClass', 'aside', 'asideStart', 'topBanner'];

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/staff'): string
    {
        [$content, $vars] = self::capture($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, array_merge($data, $vars, ['content' => $content]));
    }

    public static function partial(string $template, array $data = []): string
    {
        return self::capture($template, $data)[0];
    }

    /** @return array{0:string,1:array} rendered HTML and the layout variables the template defined */
    private static function capture(string $__template, array $__data): array
    {
        $__file = APP_DIR . '/views/' . $__template . '.php';
        if (!is_file($__file)) {
            throw new \RuntimeException("View not found: $__template");
        }
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $__html = (string) ob_get_clean();
        return [$__html, array_intersect_key(get_defined_vars(), array_flip(self::LAYOUT_VARS))];
    }

    public static function page(string $template, array $data = [], ?string $layout = 'layouts/staff'): never
    {
        Response::html(self::render($template, $data, $layout));
    }
}
