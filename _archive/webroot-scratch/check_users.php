<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Users: " . App\Models\User::count() . PHP_EOL;
App\Models\User::all()->each(function($u) { 
    echo $u->email . ' - admin: ' . ($u->is_admin ? 'yes' : 'no') . PHP_EOL; 
});