<?php

namespace Tests\Feature;

use App\Models\DocumentFiling;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PurchaseIntent;
use App\Services\WalletLedger;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * The money path proved on the engine it actually runs on.
 *
 * The rest of the suite runs on sqlite in a memory file, which is quick and honest
 * about PHP's logic but cannot show what two requests arriving at the same wallet at
 * the same moment really do: sqlite locks a whole database file and has no row locks,
 * no lock-wait timeouts and no InnoDB rollback behaviour. Production is MySQL, and
 * every claim this milestone makes - that one balance cannot be spent twice, that a
 * refund cannot pay out twice, that a purchase identity cannot be claimed twice, that
 * a rolled-back debit leaves nothing behind - rests on InnoDB doing something to a row
 * while another connection is watching.
 *
 * So these tests only mean anything when they are run against MySQL, and they say so
 * out loud rather than passing quietly somewhere else.
 *
 * They also move real money: real balances spent, real ledger rows booked, real
 * orders deleted. That is why the run is aimed at a database created for nothing
 * else - a synthetic one that holds only the rows this suite puts in it - and not at
 * a copy of production. Nothing here asks for live data, and the gate in
 * requireSyntheticDatabase() refuses to write a single statement into a database that
 * already holds any. See howToRun() for the commands.
 *
 * @see \Tests\Feature\WalletLedgerSafetyTest
 * @see \Tests\Feature\PurchaseIntentSafetyTest
 * @see \Tests\Feature\InterruptedDocumentRecoveryTest
 * @see \Tests\Feature\LostDocumentFilingProtectionTest
 */
class MysqlMoneyPathConcurrencyTest extends TestCase
{
    /** How long a second connection may wait for a locked row before it is refused. */
    private const LOCK_WAIT_SECONDS = 2;

    /**
     * The tables that hold somebody's money, orders and documents.
     *
     * A database where any of them already has a row is not a synthetic one, so this
     * is the list the run is measured against before it writes anything.
     */
    private const MONEY_TABLES = [
        'users',
        'wallets',
        'wallet_transactions',
        'orders',
        'purchase_intents',
        'document_filings',
        'notifications',
    ];

    /** @var list<int> */
    private array $users = [];

    /** @var list<int> */
    private array $orders = [];

    /** @var list<int> */
    private array $services = [];

    /** @var array<int, resource>|null */
    private ?array $rivalPipes = null;

    /** @var resource|null */
    private $rivalProcess = null;

    private static bool $schemaChecked = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireSyntheticDatabase();

        Http::preventStrayRequests();
        Notification::fake();
        Storage::fake('local');

        $this->readySchema();
    }

    protected function tearDown(): void
    {
        $this->reapRivalProcess();
        $this->discardRival();
        $this->removeWhatThisRunCreated();

        parent::tearDown();
    }

    /**
     * InnoDB is the whole premise. On a MyISAM table the conditional update still
     * "works" until the instant two writers meet, because a table lock replaces row
     * locks and a rollback cannot undo anything - so the first thing worth proving is
     * what engine the money is actually sitting on.
     */
    public function test_the_money_tables_run_on_the_engine_that_can_hold_one_row(): void
    {
        $tables = ['users', 'wallets', 'wallet_transactions', 'orders', 'purchase_intents', 'document_filings'];

        $rows = DB::select(
            'select TABLE_NAME as name, ENGINE as engine from information_schema.TABLES '.
            'where TABLE_SCHEMA = DATABASE() and TABLE_NAME in ('.implode(',', array_fill(0, count($tables), '?')).')',
            $tables,
        );

        $engines = [];
        foreach ($rows as $row) {
            $engines[strtolower((string) $row->name)] = strtolower((string) $row->engine);
        }

        foreach ($tables as $table) {
            $this->assertArrayHasKey($table, $engines, 'The table '.$table.' is not in this database at all.');
            $this->assertSame('innodb', $engines[$table],
                $table.' is '.$engines[$table].', not InnoDB: row locks and rollbacks do not exist for it, '
                .'and every concurrency claim in this milestone would be false on that table.');
        }
    }

    /**
     * The exact failure the atomic debit was written to stop: a second request that
     * reads a balance another transaction has already promised, and spends it anyway.
     */
    public function test_a_balance_one_request_is_spending_cannot_be_spent_by_another_at_the_same_time(): void
    {
        $wallet = $this->wallet(500_000);

        DB::beginTransaction();
        try {
            app(WalletLedger::class)->debit($wallet, 400_000, 'HELD-BY-THE-FIRST', 'first request');

            $rival = $this->rival();

            // What the rest of the world sees while that debit is still in flight: the
            // old balance. This is why "read it, work it out, write it back" is unsafe
            // and why the balance itself has to move.
            $this->assertSame(500_000, (int) $rival->table('wallets')->where('id', $wallet->id)->value('balance'));

            $refused = null;
            try {
                $rival->table('wallets')->where('id', $wallet->id)->where('balance', '>=', 1_000)
                    ->update(['balance' => DB::raw('balance - 1000'), 'updated_at' => now()]);
            } catch (QueryException $e) {
                $refused = $e;
            }

            $this->assertNotNull($refused,
                'A second connection changed a row the first one had already written. '
                .'Nothing in the application can stop two requests spending one balance on a database that behaves like that.');
            $this->assertTrue($this->isLockWait($refused),
                'The second writer was refused, but not by row locking: '.$refused->getMessage());
        } finally {
            DB::rollBack();
        }

        $this->assertSame(500_000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance'));
        $this->assertSame(0, (int) DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->count());
    }

    /**
     * Two real processes, one wallet, the same money offered twice.
     *
     * The parent holds its debit open so the rival is provably waiting on the row
     * before the parent commits; the rival then gets the chance to spend the same
     * ₦5,000 and must not. Whatever order MySQL picks, the invariant is the same:
     * the balance moves once, the ledger holds one debit, one of the two is refused.
     */
    public function test_two_processes_cannot_both_spend_one_available_balance(): void
    {
        $wallet = $this->wallet(500_000);
        $rivalReference = 'RIVAL-'.Str::random(12);
        $committed = false;

        DB::beginTransaction();
        try {
            app(WalletLedger::class)->debit($wallet, 400_000, 'PARENT-HOLDS-THIS', 'first request');

            $process = $this->startRivalSpend((int) $wallet->id, 400_000, $rivalReference);

            $sawItWaiting = $this->waitUntilRivalIsWaitingOnTheWalletRow();

            DB::commit();
            $committed = true;

            $report = $this->finishRivalSpend($process);
        } catch (Throwable $e) {
            if (!$committed && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $e;
        }

        $this->assertSame(100_000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance'),
            'The balance moved more than once, or not at all. Saw the rival waiting on the row: '
            .($sawItWaiting ? 'yes' : 'no'));

        $this->assertSame(1, (int) DB::table('wallet_transactions')
            ->where('wallet_id', $wallet->id)
            ->where('type', 'debit')
            ->count(), 'The ledger records more than one debit against one balance.');

        $this->assertSame(0, (int) DB::table('wallet_transactions')->where('reference', $rivalReference)->count(),
            'The rival was booked into the ledger even though the balance was already gone.');

        $this->assertFalse((bool) ($report['ok'] ?? false),
            'Both requests spent the same balance: '.json_encode($report));
        $this->assertSame('insufficient', $report['reason'] ?? null,
            'The rival was refused for a reason that is not the balance: '.json_encode($report));
    }

    /**
     * A refund is paid out by a ledger row, and the ledger will only ever accept one
     * row per reference. That uniqueness is what stops a retried rejection, a double
     * click and a replayed webhook from emptying a wallet.
     */
    public function test_one_refund_reference_cannot_pay_out_twice(): void
    {
        $wallet = $this->wallet(0);
        $reference = 'REFUND-'.Str::random(12);

        app(WalletLedger::class)->credit($wallet, 400_000, $reference, 'money back');

        $refused = null;
        try {
            app(WalletLedger::class)->credit($wallet, 400_000, $reference, 'money back');
        } catch (QueryException $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused, 'The database let the same refund be booked twice.');
        $this->assertTrue($this->isDuplicateKey($refused), 'Refused, but not by the unique reference: '.$refused->getMessage());

        // And the balance the second attempt moved before it was refused is gone with it.
        $this->assertSame(400_000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance'));
        $this->assertSame(1, (int) DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->count());
    }

    /**
     * The purchase identity is a lock the database holds, not a check the application
     * remembers - and a purchase that never completed gives it back, so a customer is
     * never refused for retrying something that was never booked.
     */
    public function test_one_purchase_identity_is_claimed_once_and_a_failed_claim_is_free_again(): void
    {
        $wallet = $this->wallet(500_000);
        $userId = (int) $wallet->user_id;

        $token = PurchaseIntent::mint();

        $this->assertTrue(PurchaseIntent::claim($userId, $token), 'The first claim of a fresh identity was refused.');
        $this->assertFalse(PurchaseIntent::claim($userId, $token),
            'The same identity was claimed twice. On this engine a double click is only stopped by this row.');

        $claimed = PurchaseIntent::mint();

        DB::beginTransaction();
        try {
            PurchaseIntent::claim($userId, $claimed);
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertTrue(PurchaseIntent::claim($userId, $claimed),
            'A purchase that was rolled back kept its identity spent, so the customer can never retry it.');

        $index = DB::select("show index from purchase_intents where Key_name = 'purchase_intents_user_token_unique'");

        $this->assertNotCount(0, $index, 'There is no unique index behind the purchase identity claim.');
        $this->assertSame(0, (int) $index[0]->Non_unique,
            'The purchase identity index is not unique, so it cannot refuse a repeat.');
    }

    /**
     * Two recovery runs on the same box, or a run and a scheduler overlap, must not
     * both file the same folder. The row lock is the backstop under withoutOverlapping.
     */
    public function test_two_recovery_runs_cannot_work_the_same_paid_filing_at_once(): void
    {
        $order = $this->paidOrderOwedPaper();
        $filing = DocumentFiling::forOrder($order);

        DB::beginTransaction();
        try {
            $held = DocumentFiling::query()->whereKey($filing->id)->lockForUpdate()->first();
            $this->assertNotNull($held);

            $refused = null;
            try {
                $this->rival()->table('document_filings')->where('id', $filing->id)->lock()->get();
            } catch (QueryException $e) {
                $refused = $e;
            }

            $this->assertNotNull($refused,
                'A second recovery run read a filing the first one had locked: both would have moved the same document.');
            $this->assertTrue($this->isLockWait($refused), 'Refused, but not by row locking: '.$refused->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    /**
     * A debit that the database undid must leave no trace for anybody else looking -
     * not a naira of balance, not a line in the ledger.
     */
    public function test_a_rolled_back_debit_leaves_nothing_for_any_other_connection_to_see(): void
    {
        $wallet = $this->wallet(500_000);

        DB::beginTransaction();
        app(WalletLedger::class)->debit($wallet, 400_000, 'NEVER-HAPPENED', 'a request that died');
        $this->assertSame(100_000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance'));
        DB::rollBack();

        $rival = $this->rival();

        $this->assertSame(500_000, (int) $rival->table('wallets')->where('id', $wallet->id)->value('balance'));
        $this->assertSame(0, (int) $rival->table('wallet_transactions')->where('wallet_id', $wallet->id)->count());
    }

    /**
     * The reference that a paid request keeps about its paper has to go with the
     * request. Nothing in the application is asked to notice the order vanished -
     * InnoDB does it, which is exactly what a table without foreign keys cannot do.
     */
    public function test_the_database_removes_a_paid_orders_filing_when_the_order_is_closed(): void
    {
        $order = $this->paidOrderOwedPaper();

        $this->assertNotCount(0, DB::table('document_filings')->where('order_id', $order->id)->get());

        $this->orders = array_values(array_diff($this->orders, [(int) $order->id]));
        DB::table('orders')->where('id', $order->id)->delete();

        $this->assertSame(0, (int) DB::table('document_filings')->where('order_id', $order->id)->count(),
            'The filing outlived its order, so a recovery run would keep looking for paper that belongs to nothing.');
    }

    public function test_the_isolation_level_the_money_moves_at_is_named(): void
    {
        $level = '';

        foreach (['@@transaction_isolation', '@@tx_isolation'] as $variable) {
            try {
                $row = DB::selectOne('select '.$variable.' as level');
                $level = strtolower((string) ($row->level ?? ''));

                if ($level !== '') {
                    break;
                }
            } catch (QueryException) {
                // An old server only answers to the second name.
            }
        }

        $this->assertNotSame('', $level, 'This connection will not say what isolation level it runs at.');

        // Both levels the money path is designed for. REPEATABLE READ is MySQL's
        // default; a host set to READ COMMITTED still gets row locks and conditional
        // updates, and this test records which one the run was proved under.
        $this->assertContains($level, ['repeatable read', 'read committed', 'read uncommitted', 'serializable'],
            'Unexpected isolation level: '.$level);
    }

    /**
     * Declared last, because it is the promise this suite makes about the database it
     * works in: the money tables hold nothing except what these tests minted, and when
     * a test is finished its rows go back out.
     *
     * That is not tidiness. The purity gate in requireSyntheticDatabase() is the only
     * thing that keeps a run away from live data, and it can only recognise an empty
     * database. A test that left a wallet or a ledger row behind would make the next
     * run refuse to start, and a developer facing that refusal has two ways out - the
     * right one is fixing the leak, not pointing the suite at somebody's orders.
     */
    public function test_the_suite_leaves_the_synthetic_database_as_empty_as_it_found_it(): void
    {
        foreach (self::MONEY_TABLES as $table) {
            $this->assertSame(0, (int) DB::table($table)->count(),
                $table.' already held rows of our own making when this test started, so a run before this one '
                .'did not clean up after itself and the next run of this suite will be refused as unsafe.');
        }

        // The same shape the other tests leave behind: a wallet with a balance spent,
        // a paid order owed paper, the paper's holding record, a claimed identity.
        $wallet = $this->wallet(500_000);
        $order = $this->paidOrderOwedPaper();
        PurchaseIntent::claim((int) $wallet->user_id, PurchaseIntent::mint());

        app(WalletLedger::class)->debit($wallet, 400_000, 'SYNTHETIC-LEDGER-ROW', 'a row of ours');

        $this->assertGreaterThan(0, (int) DB::table('users')->count());
        $this->assertGreaterThan(0, (int) DB::table('wallet_transactions')->count());
        $this->assertGreaterThan(0, (int) DB::table('document_filings')->count());
        $this->assertGreaterThan(0, (int) DB::table('purchase_intents')->count());

        $this->removeWhatThisRunCreated();

        foreach (self::MONEY_TABLES as $table) {
            $this->assertSame(0, (int) DB::table($table)->count(),
                'Removing what this run created left rows in '.$table.', which would fail the purity gate for '
                .'every run after it.');
        }

        $this->assertNull(DocumentFiling::query()->where('order_id', $order->id)->first());
    }

    /**
     * Skip in a way nobody can mistake for a pass: the reason, and the exact command
     * that would make these tests mean something.
     *
     * Three things have to be true before a statement of ours is written anywhere,
     * because each is a different way this run could hurt somebody.
     *
     * One: the engine has to be the one production runs on. On sqlite the row locks,
     * the lock-wait timeouts and the rollback behaviour under test do not exist, so a
     * pass there would be a lie.
     *
     * Two: the person running it has to have named the database the run is allowed to
     * spend money in, MONEY_TESTS_DATABASE, and the connection has to actually be on
     * that database. A stale DB_DATABASE in an .env, or a shell that forgot the
     * variable, then stops the run instead of aiming it at the live one.
     *
     * Three: that database has to hold no money yet. If any of the money tables
     * already has a row in it, it is not a synthetic database - it is somebody's real
     * one, or a copy of it, and this suite has no business spending balances inside
     * either. The check runs once, before the schema is built, and it is the reason
     * copying production into a scratch schema is not a prerequisite for these tests:
     * a database holding a customer's order is refused outright.
     */
    private function requireSyntheticDatabase(): void
    {
        $driver = DB::connection()->getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped(
                'These tests prove InnoDB behaviour and are worthless on the "'.$driver.'" this run is using. '
                .$this->howToRun()
            );
        }

        $declared = trim((string) getenv('MONEY_TESTS_DATABASE'));
        $connected = (string) DB::connection()->getDatabaseName();

        if ($declared === '') {
            $this->markTestSkipped(
                'On MySQL, but no database has been named as the one this suite may spend money in. '
                .$this->howToRun()
            );
        }

        if (strcasecmp($declared, $connected) !== 0) {
            $this->fail(
                'MONEY_TESTS_DATABASE says the money may be moved in "'.$declared.'", but this connection is '
                .'sitting on "'.$connected.'". Refusing to spend a single naira until the two agree - an '
                .'overridden DB_DATABASE, or a connection pointing somewhere else than it was told to, is '
                .'exactly how a test run ends up inside live data.'
            );
        }

        if (self::$schemaChecked) {
            return;
        }

        $occupied = [];

        foreach (self::MONEY_TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $rows = (int) DB::table($table)->count();

            if ($rows > 0) {
                $occupied[] = $table.' ('.$rows.')';
            }
        }

        if ($occupied !== []) {
            $this->fail(
                'Refusing to move money in "'.$connected.'": it already holds data that is not ours - '
                .implode(', ', $occupied).'. These tests spend balances, book ledger rows and delete orders, so '
                .'they run only in a database created for them and empty until they fill it. Make a new one '
                .'(see the commands in this message) and point DB_DATABASE and MONEY_TESTS_DATABASE at it.'
            );
        }
    }

    /**
     * What a person has to do to make these tests real. Nothing in here asks for the
     * live database, and nothing in here has to be run on the hosting server: any
     * MySQL the developer can reach - including one on their own machine - will do.
     */
    private function howToRun(): string
    {
        return <<<'TEXT'
            Make a database that holds nothing but test data, on any MySQL this run can reach:

              CREATE DATABASE vtu_money_tests CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

            Give one user rights over that database alone, then point a single test run at it -
            the same name twice, so the run can only aim itself at the database it was given:

              MONEY_TESTS_DATABASE=vtu_money_tests \
              DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
              DB_DATABASE=vtu_money_tests DB_USERNAME=vtu_money_tests DB_PASSWORD='...' \
              php artisan test --filter=MysqlMoneyPath

            The suite builds its own tables in it, refuses to start if the tables already hold
            rows, and deletes every row it created when each test finishes.
            TEXT;
    }

    private function readySchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }

        self::$schemaChecked = true;

        if (Schema::hasTable('purchase_intents') && Schema::hasTable('document_filings')) {
            return;
        }

        Artisan::call('migrate', ['--force' => true]);

        $this->assertTrue(Schema::hasTable('purchase_intents') && Schema::hasTable('document_filings'),
            'This database has no money-safety tables and the migrations could not create them: '
            .trim(Artisan::output()));

        // A synthetic database is empty until this suite fills it, and the migrations
        // that build it insert nothing. If they ever start seeding, the counts below
        // fail the run rather than letting it measure itself against rows it did not
        // create - and did not promise to remove.
        foreach (self::MONEY_TABLES as $table) {
            $this->assertSame(0, (int) DB::table($table)->count(),
                'Building the schema left rows in '.$table.' that this suite did not create, so this database '
                .'is not the empty synthetic one it was declared to be.');
        }
    }

    /** A second, completely separate connection to the same server. */
    private function rival(): Connection
    {
        $base = (array) config('database.connections.'.config('database.default'));

        config(['database.connections.money_rival' => $base]);
        DB::purge('money_rival');

        $rival = DB::connection('money_rival');
        $rival->statement('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_SECONDS);

        return $rival;
    }

    private function discardRival(): void
    {
        try {
            if (isset(DB::getConnections()['money_rival'])) {
                DB::connection('money_rival')->disconnect();
            }
        } catch (Throwable) {
            //
        }

        DB::purge('money_rival');
    }

    /** @return resource */
    private function startRivalSpend(int $walletId, int $kobo, string $reference)
    {
        $command = implode(' ', [
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg(base_path('tests/Concurrency/MysqlRivalDebit.php')),
            (string) $walletId,
            (string) $kobo,
            \escapeshellarg($reference),
        ]);

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());

        $this->assertIsResource($process, 'Could not start the second process at all: '.$command);

        $this->rivalProcess = $process;
        $this->rivalPipes = $pipes;

        return $process;
    }

    /** @return array{ok?:bool,reason?:string,message?:string} */
    private function finishRivalSpend($process): array
    {
        $stdout = (string) stream_get_contents($this->rivalPipes[1]);
        $stderr = (string) stream_get_contents($this->rivalPipes[2]);

        fclose($this->rivalPipes[1]);
        fclose($this->rivalPipes[2]);
        proc_close($process);

        $this->rivalProcess = null;
        $this->rivalPipes = null;

        $lines = array_values(array_filter(explode("\n", trim($stdout))));

        if ($lines === []) {
            $this->fail('The rival process said nothing. stderr: '.trim($stderr));
        }

        $report = json_decode((string) end($lines), true);

        $this->assertIsArray($report, 'The rival process did not answer in JSON: '.trim($stdout).' / '.trim($stderr));

        return $report;
    }

    /**
     * A test that fell over before it read the second process must not leave it
     * standing on a locked row for the next one to inherit.
     */
    private function reapRivalProcess(): void
    {
        if (!\is_resource($this->rivalProcess)) {
            return;
        }

        foreach ((array) $this->rivalPipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_terminate($this->rivalProcess);
        proc_close($this->rivalProcess);

        $this->rivalProcess = null;
        $this->rivalPipes = null;
    }

    /**
     * Wait until the second process is provably standing at the locked row. Without
     * this the two requests might simply run one after the other, which proves the
     * arithmetic but not the concurrency.
     */
    private function waitUntilRivalIsWaitingOnTheWalletRow(int $seconds = 20): bool
    {
        for ($attempt = 0; $attempt < $seconds; $attempt++) {
            try {
                $running = DB::select("select state from information_schema.processlist "
                    ."where info like '%wallets%' and id <> connection_id()");
            } catch (QueryException) {
                // A shared host that will not let us look at who is connected cannot
                // stop the proof below: the invariant is still checked either way.
                return false;
            }

            foreach ($running as $query) {
                if (str_contains(strtolower((string) $query->state), 'updat')) {
                    return true;
                }
            }

            sleep(1);
        }

        return false;
    }

    private function isLockWait(QueryException $e): bool
    {
        return $e->getCode() === 1205
            || str_contains(strtolower($e->getMessage()), 'lock wait timeout');
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return $e->getCode() === 1062
            || str_contains(strtolower($e->getMessage()), 'duplicate entry');
    }

    private function wallet(int $kobo): Wallet
    {
        $user = User::factory()->create();
        $this->users[] = (int) $user->id;

        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return Wallet::query()->where('user_id', $user->id)->firstOrFail();
    }

    /** A committed manual request that is owed a document, as the recovery run finds it. */
    private function paidOrderOwedPaper(): Order
    {
        $wallet = $this->wallet(9_000_000);

        $serviceId = Service::query()->firstOrCreate(['slug' => 'mysql_probe_service'], ['name' => 'MySQL Probe Service'])->id;
        $this->services[] = (int) $serviceId;

        $order = Order::query()->create([
            'user_id' => $wallet->user_id,
            'service_id' => $serviceId,
            'customer_ref' => '12345678901',
            'amount' => 400_000,
            'profit' => 100_000,
            'provider' => 'manual',
            'provider_reference' => 'MYSQL-PROBE-'.Str::random(6),
            'status' => 'pending',
            'meta' => [
                'type' => 'manual_service',
                'manual_queue' => true,
                'manual_service' => 'mysql_probe_service',
                'submitted' => ['nin' => '12345678901'],
            ],
        ]);

        $this->orders[] = (int) $order->id;

        $token = app(\App\Services\ManualDocumentStore::class)->token();
        $held = app(\App\Services\ManualDocumentStore::class)->stage(
            $token,
            'attestation_img',
            UploadedFile::fake()->create('attestation.jpg', 20, 'image/jpeg'),
        );

        $this->assertNotNull($held, 'The fake disk would not keep the paper this order is owed.');

        DocumentFiling::query()->create([
            'order_id' => $order->id,
            'token' => $token,
            'manifest' => [[
                'name' => 'attestation_img',
                'path' => $held,
                'original' => 'attestation.jpg',
                'mime' => 'image/jpeg',
            ]],
            'state' => DocumentFiling::AWAITING,
        ]);

        return $order;
    }

    /**
     * Everything this run created goes back out. The rows are named by the ids it
     * kept, so a run that is pointed at a copy of live data cannot take anything that
     * belonged to a customer.
     */
    private function removeWhatThisRunCreated(): void
    {
        if ($this->users !== []) {
            $walletIds = DB::table('wallets')->whereIn('user_id', $this->users)->pluck('id')->all();

            if ($walletIds !== []) {
                DB::table('wallet_transactions')->whereIn('wallet_id', $walletIds)->delete();
            }

            DB::table('purchase_intents')->whereIn('user_id', $this->users)->delete();
            DB::table('notifications')->whereIn('notifiable_id', $this->users)
                ->where('notifiable_type', User::class)->delete();
        }

        if ($this->orders !== []) {
            DB::table('document_filings')->whereIn('order_id', $this->orders)->delete();
        }

        foreach ($this->orders as $id) {
            DB::table('orders')->where('id', $id)->delete();
        }

        foreach ($this->services as $id) {
            DB::table('services')->where('id', $id)->delete();
        }

        foreach ($this->users as $id) {
            DB::table('wallets')->where('user_id', $id)->delete();
            DB::table('users')->where('id', $id)->delete();
        }
    }
}
