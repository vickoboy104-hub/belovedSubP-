<?php

/*
|--------------------------------------------------------------------------
| Administrator permissions
|--------------------------------------------------------------------------
|
| Which accounts are Master Admin.
|
| A Master Admin is not a row an administrator can write: it is a user id named
| here, in configuration the owner controls on the server. That is deliberate. An
| account that could promote itself through a website screen would make every other
| rule in this file decorative, and a promotion written into the database would be
| one more record an administrator with database access can edit.
|
| A Master Admin holds every permission implicitly, manages the permission set, and
| cannot be demoted, deactivated or edited by another administrator. The owner
| account is identified by its primary key, never by a display name or an email
| address, because those are editable and the id is not.
|
| The list is empty unless the owner fills it in, and empty means nobody is a Master:
| the permission screens fail closed rather than guessing which account is theirs.
| When MASTER_ADMIN_USER_IDS is unset the owner-only audit list is used instead, so
| one value can establish both roles during the first deployment.
|
*/

$ids = static function (mixed $value): array {
    if (is_array($value)) {
        $parts = $value;
    } else {
        $parts = explode(',', (string) $value);
    }

    return array_values(array_unique(array_filter(
        array_map(static fn (string $id): int => (int) trim($id), $parts),
        static fn (int $id): bool => $id > 0
    )));
};

return [

    'master_user_ids' => $ids(env('MASTER_ADMIN_USER_IDS', '')),

];
