<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();

        $this->actingAs($user)->get('/settings')->assertOk();
    }

    public function test_the_profile_page_only_reads_the_account_back_and_settings_is_the_only_writer(): void
    {
        $user = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => '08012345678']);

        $profile = $this->actingAs($user)->get('/profile')->assertOk()->getContent();

        $this->assertStringContainsString('Ada Lovelace', $profile);
        $this->assertStringContainsString('08012345678', $profile);
        $this->assertSame(4, substr_count($profile, 'readonly'), 'Every mirrored field is a read-only box.');

        // A mirror has no way to write: not a PATCH, not a PUT, not a DELETE,
        // and no file input to upload a photo through.
        $this->assertStringNotContainsString('name="_method"', $profile);
        $this->assertStringNotContainsString('enctype="multipart/form-data"', $profile);
        $this->assertStringNotContainsString('name="image"', $profile);
        $this->assertStringContainsString('/settings#profile', $profile, 'The mirror tells the customer where to edit.');

        $settings = $this->actingAs($user)->get('/settings')->assertOk()->getContent();

        $this->assertSame(2, substr_count($settings, 'role="tab"'), 'Change Password and Edit Profile are the two tabs.');
        $this->assertStringContainsString('Change Password', $settings);
        $this->assertStringContainsString('Edit Profile', $settings);
        $this->assertStringContainsString('enctype="multipart/form-data"', $settings);
        $this->assertMatchesRegularExpression('~<input[^>]+name="image"~', $settings);
        $this->assertStringContainsString('name="_method"', $settings);
        // The anchor the mobile menu links to still exists on the password panel.
        $this->assertStringContainsString('id="security-settings"', $settings);
        $this->assertStringContainsString('id="profile"', $settings);

        // Neither page is allowed a second hero heading now that they are siblings.
        $this->assertSame(1, substr_count($profile, '<h1'));
        $this->assertSame(1, substr_count($settings, '<h1'));
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'first_name' => 'Test',
                'last_name' => 'User',
                'phone' => '08012345678',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('Test', $user->first_name);
        $this->assertSame('User', $user->last_name);
        $this->assertSame('08012345678', $user->phone);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'first_name' => 'Test',
                'last_name' => 'User',
                'phone' => '08012345678',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_upload_a_profile_picture_and_it_renders_in_the_navigation(): void
    {
        Storage::fake('public');
        $user = User::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'avatar' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => $user->email,
                'image' => UploadedFile::fake()->image('me.jpg', 400, 400),
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $user->refresh();
        $this->assertNotNull($user->avatar);
        Storage::disk('public')->assertExists($user->avatar);

        $photo = $user->avatar_url;
        $this->assertStringContainsString('/storage/'.$user->avatar, $photo);
        $this->assertStringContainsString($photo, $this->actingAs($user)->get('/profile')->getContent());

        $dashboard = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $this->assertSame(3, substr_count($dashboard, 'src="'.e($photo).'"'), 'sidebar, drawer and dashboard greeting all use the photo');
        $this->assertStringNotContainsString('◯', $dashboard);
    }

    public function test_profile_picture_must_be_a_supported_image_within_the_size_limit(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['avatar' => null]);

        $this->actingAs($user)->patch('/profile', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => $user->email,
            'image' => UploadedFile::fake()->create('invoice.pdf', 12, 'application/pdf'),
        ])->assertSessionHasErrors('image');

        $this->assertNull($user->fresh()->avatar);

        $oversize = $this->oversizedPng(900, 2 * 1024 * 1024 + 1);
        $this->assertGreaterThan(
            2 * 1024 * 1024,
            (int) filesize($oversize),
            'The fixture has to be genuinely over the 2 MB cap or this test proves nothing.'
        );
        $this->assertNotNull(
            getimagesize($oversize),
            'A padded file that is no longer a decodable image would fail the image rule, not the size rule.'
        );

        $this->actingAs($user)->patch('/profile', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => $user->email,
            'image' => new UploadedFile($oversize, 'huge.png', 'image/png', null, true),
        ])->assertSessionHasErrors('image');

        $this->assertNull($user->fresh()->avatar);
    }

    /**
     * A real PNG the validator can decode, padded with bytes after IEND so the
     * size is deterministic rather than dependent on how well noise compresses.
     */
    private function oversizedPng(int $side, int $minimumBytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'avatar-').'.png';
        $image = imagecreatetruecolor($side, $side);
        $palette = [];

        for ($i = 0; $i < 64; $i++) {
            $palette[] = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        }

        for ($y = 0; $y < $side; $y++) {
            for ($x = 0; $x < $side; $x++) {
                imagesetpixel($image, $x, $y, $palette[random_int(0, 63)]);
            }
        }

        imagepng($image, $path);
        imagedestroy($image);

        $handle = fopen($path, 'ab');
        while (filesize($path) < $minimumBytes) {
            fwrite($handle, random_bytes(65_536));
        }
        fclose($handle);

        return $path;
    }

    public function test_replacing_the_profile_picture_removes_the_previous_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['avatar' => null]);

        $this->actingAs($user)->patch('/profile', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => $user->email,
            'image' => UploadedFile::fake()->image('first.jpg', 300, 300),
        ])->assertSessionHasNoErrors();

        $first = $user->fresh()->avatar;

        $this->actingAs($user)->patch('/profile', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => $user->email,
            'image' => UploadedFile::fake()->image('second.jpg', 300, 300),
        ])->assertSessionHasNoErrors();

        $second = $user->fresh()->avatar;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_saving_the_profile_without_choosing_a_picture_keeps_the_existing_one(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['avatar' => 'avatars/keep-me.jpg']);
        Storage::disk('public')->put('avatars/keep-me.jpg', 'jpeg bytes');

        $this->actingAs($user)->patch('/profile', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'phone' => '08012345678',
        ])->assertSessionHasNoErrors()->assertRedirect('/profile');

        $fresh = $user->fresh();
        $this->assertSame('avatars/keep-me.jpg', $fresh->avatar);
        $this->assertSame('ada@example.com', $fresh->email);
        Storage::disk('public')->assertExists('avatars/keep-me.jpg');
    }

    public function test_initials_are_shown_until_a_picture_is_uploaded(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'avatar' => null,
        ]);

        $this->assertNull($user->avatar_url);
        $this->assertSame('AL', $user->initials);

        $page = $this->actingAs($user)->get('/profile')->assertOk()->getContent();
        $this->assertStringContainsString('AL', $page);

        $settings = $this->actingAs($user)->get('/settings')->assertOk()->getContent();
        $this->assertStringContainsString('enctype="multipart/form-data"', $settings);
        $this->assertMatchesRegularExpression('~<input[^>]+name="image"~', $settings);
        $this->assertStringContainsString('AL', $settings);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
