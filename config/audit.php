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
    | Tamper protection at the database layer - NOT IMPLEMENTED, reads nothing
    |--------------------------------------------------------------------------
    |
    | This flag is inert: no code in this repository reads it, and the
    | admin_activity_logs migration installs the table only - there is no trigger
    | DDL anywhere in the branch. Setting it to true in .env would therefore
    | change nothing, and nothing here must be described as database-level tamper
    | protection because of it.
    |
    | What is actually in place is application-level only: the AdminActivityLog
    | model throws on any update or delete, which stops every write made through
    | the site. Anyone holding the database password can still change the table,
    | and no PHP setting can prevent that. Real database enforcement would be a
    | separate approved change - BEFORE UPDATE and BEFORE DELETE triggers, the
    | TRIGGER privilege for the application's MySQL user, and a deploy step that a
    | plain `git pull` plus `migrate` cannot reproduce.
    |
    */

    'database_triggers' => filter_var(env('AUDIT_DATABASE_TRIGGERS', false), FILTER_VALIDATE_BOOLEAN),

];
