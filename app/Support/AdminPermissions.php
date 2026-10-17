<?php

namespace App\Support;

/**
 * The list of things an administrator can be allowed to do, and the only place that
 * decides what a permission slug means.
 *
 * The site used to have one switch: a boolean on the user row that opened every
 * screen at once. Reading a customer's wallet history and moving that customer's
 * money are not the same kind of act, so they cannot stay behind the same door.
 * Each entry here is enforced by middleware before a route action runs, which is
 * what makes a refusal real: a hidden button stops nobody who types the URL or
 * replays the form post.
 *
 * Nothing in this list grants the ability to hand out permissions. That ability
 * belongs to a Master Admin alone, and a Master Admin comes from configuration the
 * owner controls - never from a row an administrator can write.
 */
final class AdminPermissions
{
    /**
     * Held by a Master Admin implicitly. Not assignable, not revokable, and never
     * offered on the permissions screen, so no grant can widen anybody's reach.
     */
    public const MASTER_PERMISSION = 'admins.permissions';

    /** @return array<string, array{label: string, group: string, description: string}> */
    public static function all(): array
    {
        return [
            'customers.view' => [
                'label' => 'View customers',
                'group' => 'Customers',
                'description' => 'Open the customer list, a customer profile and their recorded details.',
            ],
            'customers.edit' => [
                'label' => 'Edit customer information',
                'group' => 'Customers',
                'description' => 'Change a customer profile.',
            ],
            'customers.discount' => [
                'label' => 'Change a customer discount',
                'group' => 'Customers',
                'description' => 'Set the discount percentage applied to a customer purchase.',
            ],
            'customers.password_reset' => [
                'label' => 'Reset customer passwords',
                'group' => 'Customers',
                'description' => 'Issue a new password for a customer account.',
            ],
            'wallet.transactions.view' => [
                'label' => 'View wallet transactions',
                'group' => 'Wallet',
                'description' => 'Read the ledger, including a customer transaction history. Never changes a balance.',
            ],
            'wallet.history.view' => [
                'label' => 'View customer purchase history',
                'group' => 'Wallet',
                'description' => 'Read the orders and services a customer has paid for.',
            ],
            'wallet.credit' => [
                'label' => 'Credit customer wallets',
                'group' => 'Wallet',
                'description' => 'Add money to a customer wallet, with a mandatory reason.',
            ],
            'wallet.debit' => [
                'label' => 'Debit customer wallets',
                'group' => 'Wallet',
                'description' => 'Take money back out of a customer wallet, with a mandatory reason.',
            ],
            'wallet.balance.set' => [
                'label' => 'Set exact wallet balances',
                'group' => 'Wallet',
                'description' => 'Replace a balance with a figure instead of moving it by an amount.',
            ],
            'wallet.held.view' => [
                'label' => 'Review held deposits',
                'group' => 'Wallet',
                'description' => 'Read transfers this site received but could not attribute to a customer.',
            ],
            'wallet.held.resolve' => [
                'label' => 'Approve held-deposit resolutions',
                'group' => 'Wallet',
                'description' => 'Credit a held deposit to a named customer, or dismiss it as not ours.',
            ],
            'wallet.settlement.declare' => [
                'label' => 'Declare a manual settlement',
                'group' => 'Wallet',
                'description' => 'Record that a specific gateway charge was already paid by hand, or retract that declaration.',
            ],
            'wallet.stats.view' => [
                'label' => 'View wallet statistics',
                'group' => 'Wallet',
                'description' => 'Read the funding and balance aggregates.',
            ],
            'orders.view' => [
                'label' => 'View and manage orders',
                'group' => 'Orders',
                'description' => 'Read the order records for every customer.',
            ],
            'orders.keys' => [
                'label' => 'Issue or change exam keys',
                'group' => 'Orders',
                'description' => 'Reveal and set the keys attached to an order.',
            ],
            'orders.manual.view' => [
                'label' => 'View the manual NIN/BVN queue',
                'group' => 'Orders',
                'description' => 'Read the identity requests waiting for an admin, without claiming one.',
            ],
            'orders.manual.claim' => [
                'label' => 'Claim or release manual orders',
                'group' => 'Orders',
                'description' => 'Take a queued identity request onto your own desk, or hand it back.',
            ],
            'orders.manual.answer' => [
                'label' => 'Approve, reject and fulfil orders',
                'group' => 'Orders',
                'description' => 'Record the outcome of a manual identity request and complete or refuse it.',
            ],
            'prices.edit' => [
                'label' => 'Modify service prices',
                'group' => 'Platform',
                'description' => 'Change what a customer is charged for a service.',
            ],
            'provider.config' => [
                'label' => 'Modify provider configurations',
                'group' => 'Platform',
                'description' => 'Change provider endpoints, keys, agent codes and routing.',
            ],
            'settings.change' => [
                'label' => 'Change website settings',
                'group' => 'Platform',
                'description' => 'Change site-wide behaviour outside prices and providers.',
            ],
            'content.edit' => [
                'label' => 'Edit website content',
                'group' => 'Platform',
                'description' => 'Rewrite the storefront text, images and pages.',
            ],
            'services.forms' => [
                'label' => 'Edit service forms',
                'group' => 'Platform',
                'description' => 'Change the questions and options a customer sees on a service form.',
            ],
            'support.manage' => [
                'label' => 'Manage customer support',
                'group' => 'Communication',
                'description' => 'Read and answer support conversations.',
            ],
            'broadcasts.send' => [
                'label' => 'Send broadcasts',
                'group' => 'Communication',
                'description' => 'Message every customer at once, and export the recipient list.',
            ],
            'admins.view' => [
                'label' => 'View administrators',
                'group' => 'Administration',
                'description' => 'Read the administrator list and the activity log.',
            ],
            'admins.grant' => [
                'label' => 'Grant or revoke administrator access',
                'group' => 'Administration',
                'description' => 'Make a customer an administrator, or deactivate an administrator.',
            ],
        ];
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    /**
     * The note on a grant row no person signed: the baseline the first deployment of
     * granular permissions gave to accounts that were already administrators.
     */
    public static function baselineNote(): string
    {
        return 'Baseline: this account was already an administrator when granular permissions were introduced.';
    }

    public static function exists(string $slug): bool
    {
        return array_key_exists($slug, self::all());
    }

    /** @return array<string, array<string, array{label: string, group: string, description: string}>> */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::all() as $slug => $definition) {
            $groups[$definition['group']][$slug] = $definition;
        }

        return $groups;
    }

    /**
     * The user ids the owner has named as Master Admin, from configuration only.
     *
     * An id, never a name or an address, because a display name is editable and an
     * email can be re-typed: the primary key is the one thing in this database that
     * cannot be quietly changed. Keeping the list out of the source also means the
     * owner account is not written into the repository.
     *
     * @return list<int>
     */
    public static function masterUserIds(): array
    {
        $configured = config('admin.master_user_ids');

        if (!is_array($configured) || $configured === []) {
            $configured = config('audit.owner_user_ids');
        }

        if (!is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            $configured
        ), static fn (int $id): bool => $id > 0)));
    }
}
