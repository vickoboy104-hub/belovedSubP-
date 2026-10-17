<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$u = App\Models\User::where('email', 'test@example.com')->first();
if ($u) {
    $u->is_admin = true;
    $u->save();
    echo "Updated: " . $u->email . " - admin: " . ($u->is_admin ? "yes" : "no") . PHP_EOL;
} else {
    echo "User not found" . PHP_EOL;
}