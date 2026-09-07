<?php

namespace App\Modules\Notifications\Services\Engine;

/**
 * Renders a template string against a variable bag (doc 10 §5). Two placeholder
 * syntaxes, applied in order:
 *
 *   1. {var} / {var|default:"N/A"} — dot paths ({student.name}) walk
 *      arrays/objects. Empty/null uses the |default: fallback if given. Scalars
 *      cast to string; arrays/objects are json_encode'd.
 *   2. {{ var }} — Blade-style, runs AFTER {var}. null renders as empty string.
 */
class TemplateInterpolator
{
    /**
     * @param  array<string,mixed>  $vars
     */
    public function render(string $template, array $vars): string
    {
        $out = $this->renderCurly($template, $vars);

        return $this->renderBlade($out, $vars);
    }

    /**
     * @param  array<string,mixed>  $vars
     */
    private function renderCurly(string $template, array $vars): string
    {
        return preg_replace_callback(
            '/\{([a-zA-Z0-9_.]+)(?:\|default:"([^"]*)")?\}/',
            function (array $m) use ($vars): string {
                $value = $this->resolvePath($m[1], $vars);
                $default = $m[2] ?? null;

                if ($value === null || $value === '') {
                    return $default ?? '';
                }

                return $this->stringify($value);
            },
            $template,
        ) ?? $template;
    }

    /**
     * @param  array<string,mixed>  $vars
     */
    private function renderBlade(string $template, array $vars): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $m) use ($vars): string {
                $value = $this->resolvePath($m[1], $vars);

                return $value === null ? '' : $this->stringify($value);
            },
            $template,
        ) ?? $template;
    }

    /**
     * Resolve `{a.b}` against the variable bag.
     *
     * A FLAT key wins first: callers naturally write
     * `['exam.title' => $exam->title]`, which reads as the placeholder does, and
     * every dispatch site in the codebase passes variables that way. Walking the
     * path first would silently render those as an empty string — which is
     * exactly what "…the exam \"\"" was.
     *
     * Failing that, the path is walked through nested arrays/objects, so
     * `['exam' => $exam]` and `['exam' => ['title' => …]]` both work too. Null
     * if neither resolves.
     *
     * @param  array<string,mixed>  $vars
     */
    private function resolvePath(string $path, array $vars): mixed
    {
        if (array_key_exists($path, $vars)) {
            return $vars[$path];
        }

        $current = $vars;

        foreach (explode('.', $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } elseif (is_object($current) && isset($current->{$segment})) {
                $current = $current->{$segment};
            } else {
                return null;
            }
        }

        return $current;
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
