<?php

/*
 * One rival credit for a transfer two customers could each claim, run as its own
 * process.
 *
 * The ownership gate records an ambiguous transfer instead of paying either candidate.
 * That record is written with a unique charge token, so its behaviour when a second
 * connection writes the same token at the same moment is an InnoDB question this suite
 * cannot answer on sqlite. This file is the second connection.
 *
 * It also works as a hand check, in a database that holds nothing but test data:
 *   MONEY_TESTS_DATABASE=vtu_money_tests \
 *   DB_CONNECTION=mysql DB_DATABASE=vtu_money_tests \
 *   php tests/Concurrency/MysqlRivalAmbiguousCredit.php <wallet_id> <charge_id> <account_number> [naira]
 */

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Wallet;
use App\Services\WalletFundingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

$walletId = (string) ($argv[1] ?? '');
$chargeId = (string) ($argv[2] ?? '');
$accountNumber = (string) ($argv[3] ?? '');
$naira = (string) ($argv[4] ?? '3000');

if ($walletId === '' || $chargeId === '' || $accountNumber === '' || !ctype_digit($walletId)) {
    fwrite(STDERR, "usage: MysqlRivalAmbiguousCredit.php <wallet_id> <charge_id> <account_number> [naira]\n");
    exit(2);
}

$declared = trim((string) getenv('MONEY_TESTS_DATABASE'));
$connected = (string) DB::connection()->getDatabaseName();
$driver = DB::connection()->getDriverName();

if ($declared === '' || strcasecmp($declared, $connected) !== 0
    || !in_array($driver, ['mysql', 'mariadb'], true)
    || app()->environment('production')) {
    fwrite(STDERR, "refusing to move money: MONEY_TESTS_DATABASE must name the database this connection is on "
        .'("'.$connected.'"), the engine must be MySQL or MariaDB, and the app must not be in production. '
        ."Run it only inside a database created for test data.\n");
    exit(5);
}

Notification::fake();

DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 30');

$started = microtime(true);

try {
    $wallet = Wallet::query()->findOrFail((int) $walletId);

    $credited = app(WalletFundingService::class)->creditFlutterwaveCharge(
        $wallet,
        [
            'id' => $chargeId,
            'flw_ref' => 'FLW-'.$chargeId,
            'tx_ref' => 'RIVAL-'.$chargeId,
            'status' => 'successful',
            'currency' => 'NGN',
            'charged_amount' => (float) $naira,
            'amount' => (float) $naira,
            'payment_type' => 'bank_transfer',
            'account_number' => $accountNumber,
            'created_at' => now()->toIso8601String(),
        ],
        'flutterwave_virtual_account',
        'rival_process'
    );

    echo json_encode([
        'ok' => $credited > 0,
        'reason' => $credited > 0 ? 'credited' : 'refused',
        'credited_kobo' => (int) $credited,
        'balance' => (int) Wallet::query()->where('id', $wallet->id)->value('balance'),
        'held_rows' => (int) DB::table('flutterwave_held_deposits')
            ->where('flutterwave_charge_id', $chargeId)->count(),
        'seconds' => round(microtime(true) - $started, 3),
    ]), "\n";

    exit(0);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'reason' => 'error',
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'seconds' => round(microtime(true) - $started, 3),
    ]), "\n";

    exit(4);
}
