<?php

namespace App\Support;

use App\Services\ProviderPlanPriceService;

/**
 * One answer to "can a customer buy this right now?", used by the menus, the
 * forms, the checkout and the admin switches alike. A service is off the shelf
 * either because the owner said so or because the provider stopped listing it;
 * a provider that merely failed to answer is never treated as a withdrawal.
 */
class ServiceAvailability
{
    /**
     * The switches are named per category on purpose: 'mtn' means MTN airtime on
     * one page and MTN recharge cards on another, and taking one down must not
     * silently take the other with it.
     */
    public const CATEGORIES = [
        'airtime' => 'Airtime',
        'data' => 'Data',
        'card' => 'Recharge cards',
        'cable' => 'Cable TV',
        'electricity' => 'Electricity',
        'exam' => 'Education',
    ];

    public function __construct(
        private readonly ProviderPlanPriceService $planPrices,
        private readonly ServiceCatalogue $catalogue,
    ) {
    }

    /**
     * The services a category is currently selling, so the admin board and the
     * customer menu are always reading the same list.
     *
     * @return array<string, string>
     */
    public function catalogueFor(string $category): array
    {
        return match ($category) {
            'airtime' => $this->catalogue->airtimeServices(),
            'data' => $this->catalogue->dataServices(),
            'card' => $this->catalogue->rechargeCardNetworkLabels(),
            'cable' => $this->catalogue->cableServices(),
            'electricity' => $this->catalogue->electricityServices(),
            'exam' => $this->catalogue->educationServices(),
            default => [],
        };
    }

    /**
     * Everything the settings page needs to let the owner take a service off the
     * shelf: the live catalogue per category, with each switch already named and
     * its current verdict already worked out.
     *
     * `declared` is the owner's own tick and is what the checkbox writes.
     * `withdrawn` is the provider having taken the plan off the shelf; the board
     * reports it but must not offer it as a tick, because that switch would stay
     * flipped long after the supplier starts listing the plan again.
     *
     * @return array<string, array{label: string, items: list<array{slug: string, label: string, key: string, down: bool, declared: bool, withdrawn: bool}>}>
     */
    public function board(): array
    {
        $board = [];

        foreach (self::CATEGORIES as $category => $categoryLabel) {
            $catalogue = $this->catalogueFor($category);
            $keys = array_values(array_filter(
                array_map(fn ($slug) => $this->key((string) $slug), array_keys($catalogue)),
                static fn (string $key) => $key !== ''
            ));
            $declared = $this->declaredSlugs($keys, $category);
            $withdrawn = $this->planPrices->withdrawnSlugs($keys);
            $items = [];

            foreach ($catalogue as $slug => $label) {
                $key = $this->key((string) $slug);
                $isDeclared = in_array($key, $declared, true);
                $isWithdrawn = in_array($key, $withdrawn, true);

                $items[] = [
                    'slug' => (string) $slug,
                    'label' => (string) $label,
                    'key' => $this->switchKey($category, (string) $slug),
                    'down' => $isDeclared || $isWithdrawn,
                    'declared' => $isDeclared,
                    'withdrawn' => $isWithdrawn,
                ];
            }

            $board[$category] = [
                'label' => $categoryLabel,
                'items' => $items,
            ];
        }

        return $board;
    }

    /**
     * Split a menu into what can be bought and what has to be announced.
     *
     * @param  array<string, string>  $services
     * @return array{up: array<string, string>, down: array<string, string>}
     */
    public function split(array $services, string $category): array
    {
        $down = $this->downSlugs(array_keys($services), $category);

        $up = [];
        $off = [];

        foreach ($services as $slug => $label) {
            if (in_array($this->key((string) $slug), $down, true)) {
                $off[$slug] = $label;
            } else {
                $up[$slug] = $label;
            }
        }

        return ['up' => $up, 'down' => $off];
    }

    /**
     * @param  array<int, string>  $slugs
     * @return list<string>
     */
    public function downSlugs(array $slugs, string $category): array
    {
        $keys = [];
        foreach ($slugs as $slug) {
            $key = $this->key((string) $slug);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        if ($keys === []) {
            return [];
        }

        $declared = $this->declaredSlugs($keys, $category);

        return array_values(array_unique(array_merge(
            $declared,
            $this->planPrices->withdrawnSlugs($keys),
        )));
    }

    /**
     * Which of these services the owner himself ticked off the shelf.
     *
     * @param  array<int, string>  $keys  Already normalised service keys.
     * @return list<string>
     */
    public function declaredSlugs(array $keys, string $category): array
    {
        $declared = [];

        foreach ($keys as $key) {
            if ((string) setting($this->switchKey($category, (string) $key), '') === '1') {
                $declared[] = (string) $key;
            }
        }

        return $declared;
    }

    public function isDown(string $slug, string $category): bool
    {
        return $this->downSlugs([$slug], $category) !== [];
    }

    /**
     * What the robot reads out. The owner can overwrite this from the settings
     * page, because the reason for an outage is something only he knows.
     *
     * @param  array<string, string>  $down
     */
    public function notice(array $down): string
    {
        if ($down === []) {
            return '';
        }

        $custom = trim((string) setting('service_maintenance_message', ''));
        if ($custom !== '') {
            return $custom;
        }

        $labels = array_values(array_filter(array_map(
            static fn ($label) => trim((string) $label),
            $down
        ), static fn ($label) => $label !== ''));

        if ($labels === []) {
            return 'Some services are not available right now. Service Under Maintenance. We will inform you when it is back.';
        }

        $count = count($labels);
        $listed = $count === 1
            ? $labels[0]
            : implode(', ', array_slice($labels, 0, -1)) . ' and ' . end($labels);

        return $listed . ' ' . ($count === 1 ? 'is' : 'are')
            . ' not available right now. Service Under Maintenance. We will inform you when it is back.';
    }

    /**
     * The settings key the owner flips to take one service off the shelf.
     */
    public function switchKey(string $category, string $slug): string
    {
        return 'service_down_' . $this->key($category) . '_' . $this->key($slug);
    }

    private function key(string $slug): string
    {
        return str_replace(' ', '_', strtolower(trim($slug)));
    }
}
