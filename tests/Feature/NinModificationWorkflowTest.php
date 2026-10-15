<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ManualFulfilmentService;
use App\Services\PurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * NIN Modification is not one form with a box for "the new value": the provider
 * asks a different question for every one of the ten corrections, and the one it
 * prices highest is the only one that demands a document. This is the crawled
 * page contract, held to it on the customer's screen, in the validator and in the
 * money.
 */
class NinModificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    /**
     * Every question the form can ask beyond the three it always asks, and the
     * corrections that ask it. Read against the provider's own submission page.
     *
     * @var array<string, list<string>>
     */
    private const BRANCH_QUESTIONS = [
        'fname' => ['name', 'name_phone', 'name_dob', 'name_email', 'name_address'],
        'lname' => ['name', 'name_phone', 'name_dob', 'name_email', 'name_address'],
        'middle_name' => ['name', 'name_phone', 'name_dob', 'name_email', 'name_address'],
        'phone' => ['phone', 'name_phone', 'dob_phone'],
        'email' => ['email', 'name_email'],
        'address' => ['address', 'name_address'],
        'date_of_birth' => ['name_dob', 'dob_phone'],
        'attestation_no' => ['dob'],
        'attestation_img' => ['dob'],
    ];

    public function test_each_correction_is_asked_for_its_own_answers_and_no_others(): void
    {
        $user = $this->member();

        foreach (['name', 'phone', 'address', 'email', 'dob', 'name_phone', 'name_dob', 'name_email', 'name_address', 'dob_phone'] as $correction) {
            $page = $this->branchPage($user, ['field_to_modify' => $correction]);

            foreach (self::BRANCH_QUESTIONS as $question => $corrections) {
                $block = $this->fieldBlock($page, $question);
                $this->assertNotSame('', $block, 'The page never renders the '.$question.' question at all.');

                if (in_array($correction, $corrections, true)) {
                    $this->assertStringNotContainsString(' hidden', $block, 'A '.$correction.' correction has to ask for '.$question.'.');
                    $this->assertStringNotContainsString('disabled', $block, 'The '.$question.' question is on the screen but cannot be answered.');
                } else {
                    $this->assertStringContainsString(' hidden', $block, 'A '.$correction.' correction was shown the '.$question.' question, which it never answers.');
                    $this->assertStringContainsString('disabled', $block, 'The '.$question.' question is out of sight but still travels in the request.');
                }
            }
        }
    }

    public function test_the_email_a_customer_links_is_only_asked_when_the_account_is_theirs(): void
    {
        $user = $this->member();

        $own = $this->branchPage($user, ['email_choice' => 'link']);
        $this->assertStringNotContainsString('data-form-field="link_email" hidden', $own);
        $this->assertStringContainsString('you@example.com', $own);

        $generated = $this->branchPage($user, ['email_choice' => 'auto']);
        $this->assertStringContainsString('data-form-field="link_email" hidden', $generated);
    }

    public function test_an_answer_meant_for_another_correction_is_neither_demanded_nor_kept(): void
    {
        $user = $this->member(9_000_000);

        // Changing a name never reaches the attestation, so those two answers
        // must not be validated as if they belonged here, and must not be filed
        // on the order the agent reads.
        $this->actingAs($user)
            ->post('/vtu/manual/nin_modification', [
                PurchaseIntent::FIELD => PurchaseIntent::mint(),
                'nin' => '12345678901',
                'email_choice' => 'auto',
                'field_to_modify' => 'name',
                'fname' => 'AISHA',
                'lname' => 'MOHAMMED',
                'attestation_no' => 'SO1250325358849A',
                'consent_agreed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $submitted = (array) (Order::query()->where('user_id', $user->id)->sole()->meta['submitted'] ?? []);

        $this->assertSame(
            ['nin', 'email_choice', 'field_to_modify', 'fname', 'lname', 'consent_agreed'],
            array_keys($submitted),
        );
    }

    public function test_the_date_of_birth_correction_is_refused_until_the_attestation_is_uploaded(): void
    {
        $user = $this->member(9_000_000);

        // The number alone, with no paper: the provider will not move a date of
        // birth on a customer's word, and neither can we, so the fee stays put.
        $this->actingAs($user)
            ->post('/vtu/manual/nin_modification', [
                PurchaseIntent::FIELD => PurchaseIntent::mint(),
                'nin' => '12345678901',
                'email_choice' => 'auto',
                'field_to_modify' => 'dob',
                'attestation_no' => 'SO1250325358849A',
                'consent_agreed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('attestation_img');

        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());
        $this->assertSame(9_000_000, $this->balance($user));

        // A name change asked for on the same page does not need the document.
        $this->actingAs($user)
            ->post('/vtu/manual/nin_modification', [
                PurchaseIntent::FIELD => PurchaseIntent::mint(),
                'nin' => '12345678901',
                'email_choice' => 'auto',
                'field_to_modify' => 'name',
                'fname' => 'AISHA',
                'lname' => 'MOHAMMED',
                'consent_agreed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Order::query()->where('user_id', $user->id)->count());
    }

    public function test_the_attestation_box_only_takes_the_pictures_the_provider_accepts(): void
    {
        $user = $this->member(9_000_000);

        $this->actingAs($user)
            ->post('/vtu/manual/nin_modification', [
                PurchaseIntent::FIELD => PurchaseIntent::mint(),
                'nin' => '12345678901',
                'email_choice' => 'auto',
                'field_to_modify' => 'dob',
                'attestation_no' => 'SO1250325358849A',
                'attestation_img' => UploadedFile::fake()->create('attestation.pdf', 40, 'application/pdf'),
                'consent_agreed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('attestation_img');

        // And the page says which files it will take before one is chosen.
        $page = $this->branchPage($user, ['field_to_modify' => 'dob']);
        $this->assertMatchesRegularExpression('/name="attestation_img"[^>]*accept="\.jpg,\.jpeg,\.png"/', $page);
    }

    public function test_the_warning_that_forfeits_a_refund_is_said_before_the_fields(): void
    {
        $page = $this->branchPage($this->member(), []);

        $this->assertStringContainsString(
            'You cannot submit the same modification request to another platform while we process it',
            $page,
        );

        // Said once. The same sentence also rides in the branch map at the foot of
        // the form so the browser can hide it with the answers it belongs to, so
        // counting the words on the page would never end well - only the lines
        // actually drawn as text are counted here.
        $this->assertSame(1, $this->hintLinesOn($page, 'You cannot submit the same modification request'));
    }

    /**
     * How many times a sentence is printed as a readable line on the page.
     */
    private function hintLinesOn(string $html, string $beginsWith): int
    {
        preg_match_all('/<p class="service-form-hint"[^>]*>(.*?)<\/p>/s', $html, $matches);

        $said = 0;
        foreach ($matches[1] ?? [] as $text) {
            if (str_starts_with(trim(html_entity_decode($text)), $beginsWith)) {
                $said++;
            }
        }

        return $said;
    }

    public function test_each_correction_is_charged_at_the_rate_the_provider_prices_it(): void
    {
        $manual = app(ManualFulfilmentService::class);

        $this->assertSame(6500.0, $manual->priceNaira('nin_modification', ['field_to_modify' => 'name']));
        $this->assertSame(6500.0, $manual->priceNaira('nin_modification', ['field_to_modify' => 'phone']));
        $this->assertSame(7500.0, $manual->priceNaira('nin_modification', ['field_to_modify' => 'name_phone']));
        $this->assertSame(15000.0, $manual->priceNaira('nin_modification', ['field_to_modify' => 'dob_phone']));
        $this->assertSame(40000.0, $manual->priceNaira('nin_modification', ['field_to_modify' => 'dob']));
    }

    public function test_the_uploaded_attestation_reaches_the_agent_working_the_job_and_nobody_else(): void
    {
        $user = $this->member(9_000_000);

        $this->actingAs($user)
            ->post('/vtu/manual/nin_modification', [
                PurchaseIntent::FIELD => PurchaseIntent::mint(),
                'nin' => '12345678901',
                'email_choice' => 'auto',
                'field_to_modify' => 'dob',
                'attestation_no' => 'SO1250325358849A',
                'attestation_img' => UploadedFile::fake()->create('attestation.jpg', 40, 'image/jpeg'),
                'consent_agreed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order = Order::query()->where('user_id', $user->id)->sole();
        $document = route('admin.manual-orders.document', ['order' => $order->id, 'field' => 'attestation_img']);

        // The agent has to be able to read the paper the correction is worked from,
        // and it has to be named the file the customer sent.
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/manual-orders/'.$order->id)
            ->assertOk()
            ->assertSee('Documents the customer uploaded')
            ->assertSee('attestation.jpg');

        $opened = $this->actingAs($admin)->get($document);
        $opened->assertOk();
        $this->assertStringContainsString('inline; filename="attestation.jpg"', $opened->headers->get('Content-Disposition'));

        // Somebody else's member page cannot borrow it, and a question this order
        // never asked for is not a window into another one.
        $this->actingAs($this->member())->get($document)->assertForbidden();
        $this->actingAs($admin)->get('/admin/manual-orders/'.$order->id.'/document/nin')->assertNotFound();
    }

    public function test_the_form_can_carry_a_document_before_the_customer_names_the_correction(): void
    {
        // Nothing is chosen yet, so the attestation box is out of sight and the
        // browser will be the one that reveals it. A form that only encodes for
        // multipart once it knows would drop the file on the floor at that point,
        // and the customer would be told they forgot something they did upload.
        $html = $this->branchPage($this->member(), []);

        $this->assertStringContainsString('data-form-field="attestation_img" hidden', $html);
        $this->assertMatchesRegularExpression('/<form\b[^>]*enctype="multipart\/form-data"/', $html);
    }

    private function branchPage(User $user, array $answers): string
    {
        return $this->actingAs($user)
            ->get('/vtu/manual/nin_modification?'.http_build_query($answers + ['nin' => '12345678901', 'email_choice' => 'auto']))
            ->assertOk()
            ->getContent();
    }

    /**
     * One question as the page actually draws it, so "on the screen" and "out of
     * the request" are read from the question itself rather than from anywhere
     * else on a page that repeats both words.
     */
    private function fieldBlock(string $page, string $name): string
    {
        preg_match('/<div class="service-form-field" data-form-field="'.$name.'".*?<\/div>/s', $page, $block);

        return (string) ($block[0] ?? '');
    }

    private function member(int $kobo = 500_000): User
    {
        $user = User::factory()->create();
        $user->email_verified_at = now();
        $user->save();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->where('user_id', $user->id)->value('balance');
    }
}
