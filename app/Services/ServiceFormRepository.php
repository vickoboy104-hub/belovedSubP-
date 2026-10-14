<?php

namespace App\Services;

use App\Models\ServiceForm;
use App\Support\ServiceForm\Schema;
use Illuminate\Support\Facades\Cache;

/**
 * Where a service's form shape comes from.
 *
 * The owner edits services in Admin, so the stored rows are the truth. The
 * catalogue in ManualFulfilmentService is the fallback that a service still
 * inherits before it has ever been saved, which keeps an upgrade from emptying
 * the hub and lets the new editor start with sensible defaults instead of a
 * blank table.
 */
class ServiceFormRepository
{
    private const CACHE_KEY = 'service_forms.all.v1';

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addDay(), function (): array {
            $definitions = ManualFulfilmentService::legacyCatalogue();

            foreach ($this->rows() as $row) {
                $definitions[$row->slug] = $this->fromRow($row, $definitions[$row->slug] ?? []);
            }

            return array_map(
                static fn (array $definition): array => Schema::normalize($definition),
                $definitions,
            );
        });
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /** @return list<string> */
    public function slugs(): array
    {
        return array_keys($this->all());
    }

    /**
     * The services a customer reaches as a page of their own - the only kind an
     * owner can pause. A slip printed under another page, and a job the wired
     * API answers, are not shelf services.
     *
     * @return array<string, string>
     */
    public function shelfServices(): array
    {
        $shelf = [];
        foreach ($this->all() as $slug => $definition) {
            if (!empty($definition['wired_only']) || !empty($definition['sub_service'])
                || !empty($definition['hidden_from_hub'])) {
                continue;
            }
            $shelf[$slug] = (string) ($definition['title'] ?? $slug);
        }

        return $shelf;
    }

    public function isHidden(string $slug): bool
    {
        $definition = $this->find($slug);
        if ($definition === null) {
            return false;
        }

        return !empty($definition['hidden_from_hub'])
            || !empty($definition['wired_only'])
            || !empty($definition['sub_service']);
    }

    public function wiredOnly(string $slug): bool
    {
        return !empty($this->find($slug)['wired_only']);
    }

    /**
     * Save the shape of one service. Prices stay in settings, not here, and the
     * consent clauses stay in code, since no editor owns their wording.
     */
    public function persist(string $slug, array $attributes): void
    {
        $existing = $this->find($slug) ?? [];

        ServiceForm::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'title' => (string) ($attributes['title'] ?? $existing['title'] ?? $slug),
                'group' => (string) ($attributes['group'] ?? $existing['group'] ?? 'other'),
                'summary' => (string) ($attributes['summary'] ?? $existing['summary'] ?? ''),
                'icon' => (string) ($attributes['icon'] ?? $existing['icon'] ?? ''),
                'cta' => $attributes['cta'] ?? ($existing['cta'] ?? null),
                'definition' => $this->definitionForStorage($attributes, $existing),
                'visibility' => $this->visibilityForStorage($attributes, $existing),
                'sort_order' => (int) ($attributes['sort_order'] ?? $existing['sort_order'] ?? 0),
            ],
        );

        $this->flush();
    }

    public function delete(string $slug): void
    {
        ServiceForm::query()->where('slug', $slug)->delete();
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return \Illuminate\Support\Collection<int, ServiceForm> */
    private function rows()
    {
        return ServiceForm::query()->orderBy('sort_order')->orderBy('title')->get();
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $inherited
     * @return array<string, mixed>
     */
    private function fromRow(ServiceForm $row, array $inherited): array
    {
        $stored = (array) $row->definition;

        // A saved row overrides what it carries, including an empty list: an
        // owner who deleted every note deleted them, rather than falling back to
        // the shipped ones. Anything it does not carry - the consent clauses, for
        // instance, which no editor owns - still comes from the code.
        $definition = array_merge($inherited, [
            'slug' => $row->slug,
            'title' => $row->title,
            'group' => $row->group,
            'summary' => (string) $row->summary,
            'icon' => (string) $row->icon,
        ], $stored);

        // The shipped notices are the plain-text form of the same line a saved
        // note holds, so once the owner has written their own list the code's
        // list must not be added underneath it a second time.
        if (array_key_exists('notes', $stored)) {
            unset($definition['notices']);
        }

        $definition['cta'] = $row->cta ?? ($inherited['cta'] ?? null);
        $definition['sort_order'] = $row->sort_order;
        $definition['hidden_from_hub'] = $row->visibility === 'hidden';
        $definition['wired_only'] = $row->visibility === 'wired';

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function definitionForStorage(array $attributes, array $existing): array
    {
        // Consent clauses and the provider's plain notices are written in code and
        // are not this editor's to freeze: leaving them out keeps an edited
        // service following every wording change made there.
        $keep = ['fields', 'notes', 'price_key', 'cost_text', 'turnaround_default', 'sub_service'];
        $definition = [];
        foreach ($keep as $key) {
            $value = array_key_exists($key, $attributes)
                ? $attributes[$key]
                : ($existing[$key] ?? null);

            if ($value !== null && $value !== '') {
                $definition[$key] = $value;
            }
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $existing
     */
    private function visibilityForStorage(array $attributes, array $existing): string
    {
        if (isset($attributes['visibility'])) {
            return (string) $attributes['visibility'];
        }

        if (array_key_exists('wired_only', $attributes)) {
            return !empty($attributes['wired_only']) ? 'wired' : 'hub';
        }

        if (array_key_exists('hidden_from_hub', $attributes)) {
            return !empty($attributes['hidden_from_hub']) ? 'hidden' : 'hub';
        }

        if (!empty($existing['wired_only'])) {
            return 'wired';
        }

        return !empty($existing['hidden_from_hub']) ? 'hidden' : 'hub';
    }
}
