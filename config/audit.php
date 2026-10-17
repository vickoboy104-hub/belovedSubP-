<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Accounts allowed to read the audit trail
    |--------------------------------------------------------------------------
    |
    | Every person with is_admin = true can move a customer's wallet, so the
    | complete history cannot live behind that one flag: it would let any admin
    | read, and quietly rewrite, what the others did. Reading the trail is
    | therefore limited to the accounts named here.
    |
    | Deliberately empty out of the box. No account is guessed to be the website
    | owner - until the owner names their own user id in AUDIT_OWNER_USER_IDS
    | (comma separated), the All Administrators and Activity Log screens answer
    | 403 for everyone, including the first admin. That is the safe failure: an
    | unassigned privilege, not a wrongly assigned one.
    |
    */

    'owner_user_ids' => array_values(array_unique(array_filter(array_map(
        static fn (string $id): int => (int) trim($id),
        explode(',', (string) env('AUDIT_OWNER_USER_IDS', ''))
    ), static fn (int $id): bool => $id > 0))),

    /*
    |--------------------------------------------------------------------------
    | Tamper protection at the database layer
    |--------------------------------------------------------------------------
    |
    | The model itself refuses to update or delete a record, which covers every
    | write the application makes. That is application-level only: an account
    | with SQL access can still change the table. Setting this to true makes the
    | migration also install BEFORE UPDATE and BEFORE DELETE triggers, which the
    | database enforces no matter who issues the statement.
    |
    | Off by default because triggers need the TRIGGER privilege and are not
    | reproduced by a plain `git pull` plus migrate - they are a deployment step
    | the owner has to approve, so it is a switch rather than a surprise.
    |
    */

    'database_triggers' => filter_var(env('AUDIT_DATABASE_TRIGGERS', false), FILTER_VALIDATE_BOOLEAN),

];
