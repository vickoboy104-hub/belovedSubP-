<?php

/*
 * One rival purchase, run as its own operating-system process.
 *
 * A single PHP process cannot prove that two requests hitting the same wallet at
 * the same moment cannot both spend it, because the database only ever sees one
 * client at a time. This file is the second client: the concurrency test starts it,
 * both sides then try to spend the same balance for real, and MySQL decides - in
 * whatever order it likes - which one is allowed to.
 *
 * It also works as a hand check against a scratch database:
 *   MONEY_TESTS_ALLOW=1 DB_CONNECTION=mysql DB_DATABASE=<scratch> \
 *   php tests/Concurrency/MysqlRivalDebit.php <wallet_id> <kobo> <reference>
 */

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exceptions\InsufficientWalletBalance;
use App\Models\Wallet;
use App\Services\WalletLedger;
use Illuminate\Support\Facades\DB;

$walletId = (string) ($argv[1] ?? '');
$kobo = (string) ($argv[2] ?? '');
$reference = (string) ($argv[3] ?? 'RIVAL-'.uniqid());

if ($walletId === '' || $kobo === '' || !ctype_digit($walletId) || !ctype_digit($kobo)) {
    fwrite(STDERR, "usage: MysqlRivalDebit.php <wallet_id> <kobo> <reference>\n");
    exit(2);
}

/*
 * The money this script moves is real money in whatever database it is pointed at,
 * so it only runs when a person has said so in the environment, and only while the
 * application is not in production.
 */
if (getenv('MONEY_TESTS_ALLOW') !== '1' || app()->environment('production')) {
    fwrite(STDERR, "refusing to move money: this script needs MONEY_TESTS_ALLOW=1 and a non-production app\n");
    exit(5);
}

// The patience of a real web request: this rival waits for the row instead of
// reading a balance that is already being spent and acting on it. Only MySQL and
// MariaDB have that knob, and only there does the concurrency proof run.
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 30');
}

$started = microtime(true);
$result = ['ok' => false, 'reason' => 'unknown', 'reference' => $reference];

try {
    $wallet = Wallet::query()->findOrFail((int) $walletId);

    app(WalletLedger::class)->debit($wallet, (int) $kobo, $reference, 'MySQL concurrency probe');

    $result = [
        'ok' => true,
        'reason' => 'debited',
        'reference' => $reference,
        'balance' => (int) Wallet::query()->where('id', $wallet->id)->value('balance'),
        'seconds' => round(microtime(true) - $started, 3),
    ];

    echo json_encode($result), "\n";
    exit(0);
} catch (InsufficientWalletBalance $e) {
    $result = ['ok' => false, 'reason' => 'insufficient', 'reference' => $reference];
} catch (Throwable $e) {
    $result = ['ok' => false, 'reason' => 'error', 'reference' => $reference, 'message' => $e->getMessage()];
}

$result['seconds'] = round(microtime(true) - $started, 3);

echo json_encode($result), "\n";

exit($result['reason'] === 'insufficient' ? 3 : 4);
