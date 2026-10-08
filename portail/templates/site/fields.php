<?php
/**
 * Champs du formulaire, selon leur type. Les noms suivent la structure des données (data[horaires][r0][jour]) :
 * le serveur reconstruit les valeurs sans dépendre du JavaScript.
 */

use SimpleCommerce\Content\Price;
use SimpleCommerce\Support\Text;

if (!function_exists('sc_field')) {
    function sc_field_id(string $path): string
    {
        return 'f-' . preg_replace('/[^\w-]/', '-', $path);
    }

    function sc_label(array $f, string $id): string
    {
        return '<label for="' . e($id) . '">' . e($f['label']) . (!empty($f['required']) ? ' <span class="optional">(obligatoire)</span>' : '') . '</label>';
    }

    function sc_foot(array $f, array $errors, string $path): string
    {
        return (!empty($f['help']) ? '<span class="help">' . e($f['help']) . '</span>' : '') . field_error($errors, $path);
    }

    function sc_counter(array $f): string
    {
        return !empty($f['maxLength']) ? '<span class="counter" data-counter="' . (int) $f['maxLength'] . '"></span>' : '';
    }

    function sc_image_item(string $name, string $value, string $base, bool $gallery): string
    {
        $src = image_src($value, $base);
        $frame = '<span class="frame">' . ($src ? '<img src="' . e($src) . '" alt="">' : 'Pas de photo') . '</span>';
        if (!$gallery) {
            return $frame;
        }
        return '<div class="gallery-item" data-gallery-item>' . $frame . '<input type="hidden" name="' . e($name) . '[]" value="' . e($value) . '">'
            . '<div class="actions"><button type="button" class="btn btn-small btn-quiet" data-move="-1" aria-label="Avant">' . icon('up') . '</button>'
            . '<button type="button" class="btn btn-small btn-quiet" data-move="1" aria-label="Après">' . icon('down') . '</button>'
            . '<button type="button" class="btn btn-small btn-quiet" data-remove aria-label="Retirer">' . icon('x') . '</button></div></div>';
    }

    /** @param array{errors: array, base: string, slug: string} $ctx */
    function sc_field(array $f, mixed $value, string $name, string $path, array $ctx): string
    {
        if (!empty($f['hidden'])) {
            return '';
        }
        $id = sc_field_id($path);
        $errors = $ctx['errors'];
        $cls = 'field' . invalid($errors, $path);
        if (!empty($f['readOnly'])) {
            $shown = is_scalar($value) && $value !== '' ? (string) $value : '—';
            return '<div class="field"><span class="label">' . e($f['label']) . '</span><span class="muted">' . e($shown) . '</span></div>';
        }
        $str = is_scalar($value) ? (string) $value : '';
        $preview = ' data-key="' . e($f['key']) . '"';
        switch ($f['type']) {
            case 'text':
            case 'url':
            case 'email':
            case 'phone':
                $type = ['url' => 'url', 'email' => 'email', 'phone' => 'tel'][$f['type']] ?? 'text';
                return '<div class="' . $cls . '">' . sc_label($f, $id) . '<input id="' . e($id) . '" name="' . e($name) . '" type="' . $type . '" value="' . e($str) . '"'
                    . ($f['type'] === 'url' ? ' placeholder="https://…"' : '') . (!empty($f['maxLength']) ? ' data-max="' . (int) $f['maxLength'] . '"' : '') . $preview . '>'
                    . sc_counter($f) . sc_foot($f, $errors, $path) . '</div>';
            case 'textarea':
            case 'markdown':
                $rows = min(14, max(4, (int) ceil(mb_strlen($str) / 70)));
                return '<div class="' . $cls . '">' . sc_label($f, $id) . '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="' . $rows . '"'
                    . (!empty($f['maxLength']) ? ' data-max="' . (int) $f['maxLength'] . '"' : '') . $preview . '>' . e($str) . '</textarea>' . sc_counter($f)
                    . ($f['type'] === 'markdown' ? '<span class="help">Mise en forme : **gras**, *italique*, une ligne vide entre deux paragraphes, « - » en début de ligne pour une liste.</span>' : '')
                    . sc_foot($f, $errors, $path) . '</div>';
            case 'richtext':
                return '<div class="' . $cls . '">' . sc_label($f, $id) . '<div class="richtext" data-richtext><textarea id="' . e($id) . '" name="' . e($name) . '" rows="6"' . $preview . '>' . e($str) . '</textarea></div>'
                    . sc_foot($f, $errors, $path) . '</div>';
            case 'price':
                $n = Price::toNumber($f, $value);
                $shown = $n === null ? (is_string($value) ? $value : '') : number_format($n, floor($n) == $n ? 0 : 2, ',', '');
                return '<div class="' . $cls . '">' . sc_label($f, $id) . '<div class="input-suffix price-input"><input id="' . e($id) . '" name="' . e($name) . '" type="text" inputmode="decimal" value="' . e($shown) . '" data-price' . $preview . '><span>€</span></div>'
                    . sc_foot($f, $errors, $path) . '</div>';
            case 'number':
                return '<div class="' . $cls . '">' . sc_label($f, $id) . '<input id="' . e($id) . '" name="' . e($name) . '" type="text" inputmode="decimal" class="narrow" value="' . e($str) . '"' . $preview . '>'
                    . sc_foot($f, $errors, $path) . '</div>';
            case 'boolean':
                return '<div class="' . $cls . '"><input type="hidden" name="' . e($name) . '" value="0"><label class="switch"><input id="' . e($id) . '" type="checkbox" name="' . e($name) . '" value="1"' . ($value === true ? ' checked' : '') . $preview . '>'
                    . '<span class="switch-track" aria-hidden="true"></span><span><strong>' . e($f['label']) . '</strong></span></label>' . sc_foot($f, $errors, $path) . '</div>';
            case 'select':
                $opts = '<option value="">—</option>';
                foreach ($f['options'] ?? [] as $o) {
                    $opts .= '<option value="' . e($o['value']) . '"' . ($o['value'] === $str ? ' selected' : '') . '>' . e($o['label']) . '</option>';
                }
                return '<div class="' . $cls . '">' . sc_label($f, $id) . '<select id="' . e($id) . '" name="' . e($name) . '" class="medium"' . $preview . '>' . $opts . '</select>' . sc_foot($f, $errors, $path) . '</div>';
            case 'date':
                $short = preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) || $str === '';
                return '<div class="' . $cls . '">' . sc_label($f, $id) . '<input id="' . e($id) . '" name="' . e($name) . '" type="' . ($short ? 'date' : 'text') . '" class="medium" value="' . e($str) . '"' . $preview . '>'
                    . sc_foot($f, $errors, $path) . '</div>';
            case 'image':
                $aspect = $f['aspect'] ?? '4:3';
                return '<div class="' . $cls . '"><span class="label">' . e($f['label']) . '</span><div class="image-field" data-image data-aspect="' . e($aspect) . '" data-max-width="' . (int) ($f['maxWidth'] ?? 2000) . '">'
                    . sc_image_item($name, $str, $ctx['base'], false) . '<input type="hidden" name="' . e($name) . '" value="' . e($str) . '"' . $preview . ' data-image-value>'
                    . '<div class="actions"><button type="button" class="btn btn-small" data-pick>' . icon('photo') . ' ' . ($str !== '' ? 'Changer la photo' : 'Choisir une photo') . '</button>'
                    . '<button type="button" class="btn btn-small btn-quiet" data-library>Mes photos</button>'
                    . '<button type="button" class="btn btn-small btn-quiet" data-clear' . ($str === '' ? ' hidden' : '') . '>Retirer</button></div></div>'
                    . '<span class="help">JPEG, PNG ou WebP, 8 Mo au plus. Vous pourrez recadrer avant l\'envoi.</span>' . sc_foot($f, $errors, $path) . '</div>';
            case 'gallery':
                $items = '';
                foreach (is_array($value) ? $value : [] as $img) {
                    $items .= sc_image_item($name, (string) $img, $ctx['base'], true);
                }
                return '<div class="' . $cls . '"><span class="label">' . e($f['label']) . '</span><div data-gallery data-name="' . e($name) . '" data-aspect="' . e($f['aspect'] ?? '4:3') . '" data-max-width="' . (int) ($f['maxWidth'] ?? 2000) . '">'
                    . '<input type="hidden" name="' . e($name) . '[]" value=""><div class="gallery" data-items>' . $items . '</div>'
                    . '<div class="actions mt-8"><button type="button" class="btn btn-small" data-pick>' . icon('plus') . ' Ajouter une photo</button><button type="button" class="btn btn-small btn-quiet" data-library>Mes photos</button></div></div>'
                    . sc_foot($f, $errors, $path) . '</div>';
            case 'list':
                $rows = '';
                foreach (is_array($value) ? $value : [] as $item) {
                    $rows .= '<div class="list-row" data-row><input type="text" name="' . e($name) . '[]" value="' . e(is_scalar($item) ? (string) $item : '') . '" aria-label="' . e($f['label']) . '"><button type="button" class="btn btn-small btn-quiet" data-remove aria-label="Retirer">' . icon('x') . '</button></div>';
                }
                $tpl = '<div class="list-row" data-row><input type="text" name="' . e($name) . '[]" value="" aria-label="' . e($f['label']) . '"><button type="button" class="btn btn-small btn-quiet" data-remove aria-label="Retirer">' . icon('x') . '</button></div>';
                return '<div class="' . $cls . '"><span class="label">' . e($f['label']) . '</span><div class="list" data-list><input type="hidden" name="' . e($name) . '[]" value=""><div class="list-rows" data-items>' . $rows . '</div>'
                    . '<template>' . $tpl . '</template><button type="button" class="btn btn-small mt-8" data-add>' . icon('plus') . ' Ajouter</button></div>' . sc_foot($f, $errors, $path) . '</div>';
            case 'group':
                $inner = '';
                foreach ($f['fields'] ?? [] as $sub) {
                    $inner .= sc_field($sub, is_array($value) ? ($value[$sub['key']] ?? null) : null, $name . '[' . $sub['key'] . ']', $path . '.' . $sub['key'], $ctx);
                }
                return '<fieldset class="group"><legend>' . e($f['label']) . '</legend>' . $inner . '</fieldset>';
            case 'repeater':
                $fixed = !empty($f['fixedRows']);
                $rows = '';
                foreach (array_values(is_array($value) ? $value : []) as $i => $row) {
                    $rows .= sc_repeater_row($f, is_array($row) ? $row : [], $name, "r$i", $path . '.' . $i, $ctx, $row['__index'] ?? $i, $fixed);
                }
                $tpl = sc_repeater_row($f, [], $name, '__ROW__', $path . '.new', ['errors' => []] + $ctx, null, false);
                $item = $f['itemLabel'] ?? 'ligne';
                return '<fieldset class="group"><legend>' . e($f['label']) . '</legend><div class="repeater" data-repeater><input type="hidden" name="' . e($name) . '[_]" value=""><div class="repeater-rows" data-items>' . $rows . '</div>'
                    . ($fixed ? '' : '<template>' . $tpl . '</template><button type="button" class="btn btn-small" data-add>' . icon('plus') . ' ' . e(Text::addLabel($item)) . '</button>')
                    . '</div>' . sc_foot($f, $errors, $path) . '</fieldset>';
        }
        return '';
    }

    function sc_repeater_row(array $f, array $row, string $name, string $key, string $path, array $ctx, mixed $index, bool $fixed): string
    {
        $inner = '';
        foreach ($f['fields'] ?? [] as $sub) {
            $inner .= sc_field($sub, $row[$sub['key']] ?? null, "{$name}[$key][{$sub['key']}]", "$path.{$sub['key']}", $ctx);
        }
        $idx = is_int($index) ? '<input type="hidden" name="' . e("{$name}[$key][__index]") . '" value="' . $index . '">' : '';
        $actions = '<button type="button" class="btn btn-small btn-quiet" data-move="-1" aria-label="Monter">' . icon('up') . '</button><button type="button" class="btn btn-small btn-quiet" data-move="1" aria-label="Descendre">' . icon('down') . '</button>'
            . ($fixed ? '' : '<button type="button" class="btn btn-small btn-quiet" data-remove>Retirer</button>');
        return '<div class="repeater-row" data-row>' . $idx . '<div class="repeater-row-fields">' . $inner . '</div><div class="row-actions">' . $actions . '</div></div>';
    }
}
