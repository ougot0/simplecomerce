<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

use SimpleCommerce\Content\Values;
use SimpleCommerce\Support\Http;
use SimpleCommerce\Support\Text;

/** Webflow — Data API v2. Les modifications sont publiées directement (« live »). */
final class WebflowAdapter extends BaseAdapter
{
    private const API = 'https://api.webflow.com/v2';

    public function __construct(private string $token, private ?string $siteId = null)
    {
    }

    private function api(string $method, string $path, mixed $body = null): array
    {
        return Http::json('Webflow', $method, self::API . $path, ['Authorization' => 'Bearer ' . $this->token], $body)['data'] ?? [];
    }

    private function site(): string
    {
        if ($this->siteId) {
            return $this->siteId;
        }
        $sites = $this->api('GET', '/sites')['sites'] ?? [];
        if (count($sites) !== 1) {
            throw new AdapterError('invalid', "Ce jeton donne accès à plusieurs sites : précisez l'identifiant du site dans les réglages avancés.");
        }
        return $this->siteId = $sites[0]['id'];
    }

    private static function mapField(array $f): ?array
    {
        $base = ['key' => $f['slug'], 'label' => $f['displayName']] + (!empty($f['isRequired']) ? ['required' => true] : []) + (!empty($f['helpText']) ? ['help' => mb_substr($f['helpText'], 0, 200)] : []);
        return match ($f['type']) {
            'PlainText' => $f['slug'] === 'slug' ? $base + ['type' => 'text', 'hidden' => true] : $base + ['type' => (($f['validations']['singleLine'] ?? true) === false) ? 'textarea' : 'text'] + (isset($f['validations']['maxLength']) ? ['maxLength' => (int) $f['validations']['maxLength']] : []),
            'RichText' => $base + ['type' => 'richtext'],
            'Image' => $base + ['type' => 'image', 'aspect' => 'libre'],
            'MultiImage' => $base + ['type' => 'gallery'],
            'Number' => preg_match('/(price|prix|tarif)/i', $f['slug']) ? $base + ['type' => 'price', 'priceFormat' => ['store' => 'number']] : $base + ['type' => 'number'],
            'Switch' => array_diff_key($base, ['required' => 1]) + ['type' => 'boolean'],
            'Link', 'VideoLink' => $base + ['type' => 'url'],
            'Email' => $base + ['type' => 'email'],
            'Phone' => $base + ['type' => 'phone'],
            'DateTime' => $base + ['type' => 'date'],
            'Option' => $base + ['type' => 'select', 'options' => array_map(fn ($o) => ['value' => $o['id'], 'label' => $o['name']], $f['validations']['options'] ?? [])],
            default => null,
        };
    }

    public function test(): array
    {
        try {
            $site = $this->api('GET', '/sites/' . $this->site());
            $cols = $this->api('GET', '/sites/' . $this->site() . '/collections')['collections'] ?? [];
            $checks = [['label' => "Site « {$site['displayName']} » trouvé", 'ok' => true], ['label' => count($cols) . ' collection(s) du CMS accessibles', 'ok' => true]];
        } catch (AdapterError $e) {
            $checks = [['label' => 'Connexion à Webflow', 'ok' => false, 'hint' => $e->userMessage]];
        }
        return ['ok' => !array_filter($checks, fn ($c) => !$c['ok']), 'checks' => $checks];
    }

    public function discover(): array
    {
        $sections = [];
        $used = [];
        foreach ($this->api('GET', '/sites/' . $this->site() . '/collections')['collections'] ?? [] as $c) {
            $detail = $this->api('GET', '/collections/' . $c['id']);
            $fields = array_values(array_filter(array_map([self::class, 'mapField'], $detail['fields'] ?? [])));
            $key = Text::slugify($c['slug'] ?: $c['displayName']);
            while (isset($used[$key])) {
                $key .= '-2';
            }
            $used[$key] = true;
            $img = null;
            $price = null;
            foreach ($fields as $f) {
                $img ??= in_array($f['type'], ['image', 'gallery'], true) ? $f['key'] : null;
                $price ??= $f['type'] === 'price' ? $f['key'] : null;
            }
            $sections[] = array_filter(['key' => $key, 'label' => $c['displayName'], 'kind' => 'collection', 'itemLabel' => mb_strtolower($c['singularName']), 'titleField' => 'name',
                'imageField' => $img, 'subtitleField' => $price, 'orderable' => false, 'remote' => ['collectionId' => $c['id']], 'fields' => $fields], fn ($v) => $v !== null);
        }
        return ['schema' => ['version' => 1, 'sections' => $sections], 'notes' => ["Les pages statiques de Webflow ne sont pas modifiables par l'API : seules les collections du CMS sont proposées."]];
    }

    private function col(array $section): string
    {
        return (string) ($section['remote']['collectionId'] ?? throw new AdapterError('invalid', 'Rubrique mal configurée.', $section['key']));
    }

    private function toData(array $section, array $item): array
    {
        $out = [];
        foreach ($section['fields'] as $f) {
            $v = $item['fieldData'][$f['key']] ?? null;
            $out[$f['key']] = match ($f['type']) {
                'image' => $v['url'] ?? '',
                'gallery' => is_array($v) ? array_column($v, 'url') : [],
                'date' => is_string($v) ? substr($v, 0, 10) : '',
                'boolean' => (bool) $v,
                default => $v ?? '',
            };
        }
        return $out;
    }

    private function upload(string $token, array $ctx): string
    {
        $a = PendingAsset::find($ctx['assets'], $token);
        $d = $this->api('POST', '/sites/' . $this->site() . '/assets', ['fileName' => $a->fileName, 'fileHash' => md5($a->bytes)]);
        $form = $d['uploadDetails'];
        $form['file'] = new \CURLStringFile($a->bytes, $a->fileName, $a->mime);
        $r = Http::request('POST', $d['uploadUrl'], [], $form, 60);
        if ($r['status'] >= 300) {
            throw new AdapterError('remote', "L'envoi de la photo vers Webflow a échoué.", 'HTTP ' . $r['status']);
        }
        return $d['hostedUrl'];
    }

    private function fieldData(array $section, array $data, array $ctx, bool $new): array
    {
        $resolve = fn ($v) => str_starts_with((string) $v, Values::MEDIA_PREFIX) ? $this->upload(substr($v, strlen(Values::MEDIA_PREFIX)), $ctx) : $v;
        $out = [];
        foreach ($section['fields'] as $f) {
            if (!array_key_exists($f['key'], $data) || (!empty($f['hidden']) && $f['key'] !== 'slug')) {
                continue;
            }
            $v = $data[$f['key']];
            $out[$f['key']] = match ($f['type']) {
                'image' => $v ? ['url' => $resolve($v)] : null,
                'gallery' => array_map(fn ($u) => ['url' => $resolve($u)], $v ?: []),
                'date' => $v ? date('c', strtotime($v)) : null,
                default => $v,
            };
        }
        if ($new && empty($out['slug'])) {
            $out['slug'] = Text::slugify((string) ($data['name'] ?? 'element')) . '-' . substr(base_convert((string) time(), 10, 36), -4);
        }
        return $out;
    }

    public function listEntries(array $section): array
    {
        $out = [];
        for ($offset = 0; $offset < 2000; $offset += 100) {
            $d = $this->api('GET', '/collections/' . $this->col($section) . "/items?limit=100&offset=$offset");
            foreach ($d['items'] ?? [] as $i) {
                if (empty($i['isArchived'])) {
                    $out[] = ['id' => $i['id'], 'data' => $this->toData($section, $i)];
                }
            }
            if ($offset + 100 >= ($d['pagination']['total'] ?? 0)) {
                break;
            }
        }
        return $out;
    }

    public function getEntry(array $section, string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/i', $id)) {
            return null;
        }
        try {
            return ['id' => $id, 'data' => $this->toData($section, $this->api('GET', '/collections/' . $this->col($section) . "/items/$id"))];
        } catch (AdapterError $e) {
            if ($e->codeName === 'not_found') {
                return null;
            }
            throw $e;
        }
    }

    public function createEntry(array $section, array $data, array $ctx): array
    {
        $i = $this->api('POST', '/collections/' . $this->col($section) . '/items/live', ['isDraft' => false, 'isArchived' => false, 'fieldData' => $this->fieldData($section, $data, $ctx, true)]);
        return ['id' => $i['id'], 'before' => null, 'after' => Values::pick($section, $this->toData($section, $i))];
    }

    public function updateEntry(array $section, string $id, array $data, ?array $expected, array $ctx): array
    {
        $current = $this->getEntry($section, $id) ?? throw new ConflictError('élément supprimé');
        $this->assertSame($section, $current['data'], $expected);
        $i = $this->api('PATCH', '/collections/' . $this->col($section) . "/items/$id/live", ['fieldData' => $this->fieldData($section, array_replace($current['data'], $data), $ctx, false)]);
        return ['id' => $id, 'before' => $current['data'], 'after' => Values::pick($section, $this->toData($section, $i))];
    }

    public function deleteEntry(array $section, string $id, ?array $expected, array $ctx): array
    {
        $current = $this->getEntry($section, $id) ?? throw new ConflictError('élément déjà supprimé');
        $this->assertSame($section, $current['data'], $expected);
        try {
            $this->api('DELETE', '/collections/' . $this->col($section) . "/items/$id/live");
        } catch (AdapterError) {
        }
        $this->api('DELETE', '/collections/' . $this->col($section) . "/items/$id");
        return ['id' => $id, 'before' => $current['data'], 'after' => null];
    }
}
