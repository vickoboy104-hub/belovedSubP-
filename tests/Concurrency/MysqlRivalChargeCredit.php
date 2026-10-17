<?php

/*
 * One rival deposit credit, run as its own operating-system process.
 *
 * The application's own duplicate check for arriving money is scoped to the wallet
 * being credited, so it cannot see that some other wallet has already been paid for
 * the same transfer. What closes that is a unique index, and a unique index is only
 * worth claiming when two connections really are trying to write the same charge at
 * the same moment. This file is the second connection.
 *
 * It also works as a hand check, in a database that holds nothing but test data:
 *   MONEY_TESTS_DATABASE=vtu_money_tests \
 *   DB_CONNECTION=mysql DB_DATABASE=vtu_money_tests \
 *   php tests/Concurrency/MysqlRivalChargeCredit.php <wallet_id> <charge_id> <naira> [flw_ref]
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
$naira = (string) ($argv[3] ?? '3000');
$flwRef = (string) ($argv[4] ?? 'FLW-RIVAL-'.uniqid());

if ($walletId === '' || $chargeId === '' || !ctype_digit($walletId)) {
    fwrite(STDERR, "usage: MysqlRivalChargeCredit.php <wallet_id> <charge_id> <naira> [flw_ref]\n");
    exit(2);
}

/*
 * Same gate as the rival debit: money may only be moved in a database the person
 * running this has named, on the engine whose lock behaviour is being tested, and
 * never in production. See requireSyntheticDatabase() in
 * tests/Feature/MysqlMoneyPathConcurrencyTest.php.
 */
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

// The customer's email is not part of the proof, and a test run must not send one.
Notification::fake();

// Wait on the charge instead of deciding it is unseen, which is the whole point.
if (in_array($driver, ['mysql', 'mariadb'], true)) {
    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 30');
}

$started = microtime(true);

try {
    $wallet = Wallet::query()->findOrFail((int) $walletId);

    $credited = app(WalletFundingService::class)->creditFlutterwaveCharge(
        $wallet,
        [
            'id' => $chargeId,
            'flw_ref' => $flwRef,
            'tx_ref' => 'RIVAL-'.$chargeId,
            'status' => 'successful',
            'currency' => 'NGN',
            'charged_amount' => (float) $naira,
            'amount' => (float) $naira,
            'payment_type' => 'bank_transfer',
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
        'seconds' => round(microtime(true) - $started, 3),
    ]), "\n";

    exit(0);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'reason' => 'error',
        'message' => $e->getMessage(),
        'seconds' => round(microtime(true) - $started, 3),
    ]), "\n";

    exit(4);
}
