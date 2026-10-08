<?php
declare(strict_types=1);

/** Petites fonctions pour les gabarits. */

use SimpleCommerce\Support\Session;
use SimpleCommerce\Support\Text;

function e(mixed $s): string
{
    return Text::e($s);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Session::csrf()) . '">';
}

const SC_ICONS = [
    'home' => 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z',
    'list' => 'M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01',
    'text' => 'M4 6h16M4 12h16M4 18h10',
    'eye' => 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
    'eye-off' => 'M3 3l18 18M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7a9.8 9.8 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2',
    'draft' => 'M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16zM13.5 6.5l4 4',
    'clock' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
    'settings' => 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0M14 4v4M8 10v4M16 16v4',
    'plus' => 'M12 5v14M5 12h14',
    'photo' => 'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zM3 16l5-5 4 4 3-3 6 6M15.5 9.5h.01',
    'check' => 'M5 12.5 10 17l9-10',
    'undo' => 'M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',
    'lock' => 'M6 11h12v9H6zM8.5 11V8a3.5 3.5 0 0 1 7 0v3',
    'users' => 'M16 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9.5 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM21 20v-1a4 4 0 0 0-3-3.9M15.5 4.3a3.5 3.5 0 0 1 0 6.4',
    'link' => 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1',
    'copy' => 'M9 9h11v11H9zM5 15H4V4h11v1',
    'search' => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4',
    'download' => 'M12 4v11M7 10l5 5 5-5M5 20h14',
    'help' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01',
    'calendar' => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
    'up' => 'M12 19V5M6 11l6-6 6 6',
    'down' => 'M12 5v14M6 13l6 6 6-6',
    'trash' => 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
    'menu' => 'M4 6h16M4 12h16M4 18h16',
    'x' => 'M6 6l12 12M18 6 6 18',
    'store' => 'M4 9l1.5-5h13L20 9M4 9v11h16V9M4 9h16M9 20v-6h6v6',
    'grid' => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
    'bold' => 'M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z',
    'italic' => 'M10 5h8M6 19h8M14 5l-4 14',
    'bullets' => 'M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01',
    'external' => 'M14 4h6v6M20 4l-9 9M18 14v6H4V6h6',
];

function icon(string $name, string $class = 'icon'): string
{
    $d = SC_ICONS[$name] ?? SC_ICONS['text'];
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $d . '"/></svg>';
}

function when(?string $iso): string
{
    return Text::when($iso);
}

/** Pastille d'état : live (en ligne), draft (brouillon), off (masqué), error. */
function tag(string $kind, string $text): string
{
    return '<span class="tag tag-' . e($kind) . '">' . e($text) . '</span>';
}

/** Message d'erreur sous un champ. */
function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<span class="error-text">' . e($errors[$key]) . '</span>' : '';
}

function invalid(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' field-invalid' : '';
}

/** Adresse affichable d'une photo : en attente (portail), absolue, ou relative au site. */
function image_src(mixed $value, string $base): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    if (str_starts_with($value, 'sc-media:')) {
        return '/media/' . substr($value, 9);
    }
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }
    if (preg_match('/^(data|javascript):/i', $value)) {
        return null;
    }
    $base = rtrim($base, '/');
    return str_starts_with($value, '/') ? $base . $value : $base . '/' . preg_replace('#^\.?/#', '', $value);
}

/** Valeur courte d'un champ pour une liste ou l'historique. */
function show_value(?array $field, mixed $value, int $max = 80): string
{
    if ($value === null || $value === '' || $value === []) {
        return '';
    }
    $type = $field['type'] ?? 'text';
    if ($type === 'price') {
        return \SimpleCommerce\Support\Text::price(\SimpleCommerce\Content\Price::toNumber($field, $value));
    }
    if ($type === 'boolean') {
        return $value ? 'oui' : 'non';
    }
    if ($type === 'image') {
        return str_starts_with((string) $value, 'sc-media:') ? 'nouvelle photo' : (string) basename((string) $value);
    }
    if ($type === 'date' && is_string($value)) {
        return \SimpleCommerce\Support\Text::date($value);
    }
    if (is_array($value)) {
        return \SimpleCommerce\Support\Text::plural(count($value), 'élément');
    }
    $text = $type === 'richtext' ? \SimpleCommerce\Support\Text::stripHtml((string) $value) : (string) $value;
    return \SimpleCommerce\Support\Text::excerpt($text, $max);
}

function find_field(array $section, ?string $key): ?array
{
    foreach ($section['fields'] as $f) {
        if ($f['key'] === $key) {
            return $f;
        }
    }
    return null;
}
