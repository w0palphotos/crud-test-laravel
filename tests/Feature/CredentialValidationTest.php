<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The email and password rules, exercised through the doors that accept them.
 *
 * Sent as real requests and asserted on the message a person would read, because
 * a rule that is declared and never enforced still fails this: the request is
 * what proves the rule is attached to the field.
 *
 * The breach lookup is faked everywhere. Left real, the suite would reach
 * api.pwnedpasswords.com and these tests would fail on a network rather than on
 * the code, and would pass on a machine that happened to be offline — where an
 * unreachable verifier is treated as "not compromised" and every weak password
 * passes.
 */
class CredentialValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No fake is installed here on purpose. Http::fake() *merges* stub
        // callbacks and the first match wins, so a default answer registered in
        // setUp would silently shadow every specific one a test installs later —
        // and the breach test would pass a known-leaked password while looking
        // green. Each test says what the lookup answers instead.
        //
        // With nothing faked, preventStrayRequests() turns an unexpected call
        // into a failure rather than a silent network hit.
        Http::preventStrayRequests();
    }

    /**
     * The answer the breach lookup gives: a body containing the suffix of this
     * password's own SHA-1, which is what the k-anonymity lookup matches on.
     *
     * @param  int  $occurrences  How many breaches report it. 0 means "not known".
     */
    private function fakeBreachLookupFor(string $password, int $occurrences = 0): void
    {
        $body = $occurrences > 0
            ? substr(strtoupper(sha1($password)), 5).':'.$occurrences
            : '';

        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response($body, 200)]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function passwordsMissingADigit(): array
    {
        return [
            'letters and symbols' => ['correct-horse-battery'],
            'long enough otherwise' => ['a-perfectly-long-passphrase'],
        ];
    }

    #[DataProvider('passwordsMissingADigit')]
    public function test_registration_refuses_a_password_with_no_number(string $password): void
    {
        $this->fakeBreachLookupFor($password);

        $this->postJson('/api/account', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame(0, User::count());
    }

    /**
     * The floor itself, at a length only the floor can refuse.
     *
     * Eleven characters with a letter and a number is long enough to pass an
     * eight-character policy and short enough to fail this one. Testing only a
     * six-character password would leave the exact floor untested, and the floor
     * is the number this policy is actually made of — lower it to eight and every
     * other test in this file still passes.
     */
    public function test_registration_refuses_a_password_under_twelve_characters(): void
    {
        $password = 'Passw0rd123';

        $this->assertSame(11, mb_strlen($password), 'This fixture must sit just under the floor.');

        $this->fakeBreachLookupFor($password);

        $this->postJson('/api/account', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame(0, User::count());
    }

    /**
     * And the same password one character longer is accepted, so the test above
     * is about the floor rather than about the characters in it.
     */
    public function test_registration_accepts_a_password_at_twelve_characters(): void
    {
        $password = 'Passw0rd1234';

        $this->assertSame(12, mb_strlen($password));

        $this->fakeBreachLookupFor($password);

        $this->postJson('/api/account', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertCreated();

        $this->assertSame(1, User::count());
    }

    public function test_registration_refuses_a_password_with_no_letter(): void
    {
        $this->postJson('/api/account', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => '1234567890123',
            'password_confirmation' => '1234567890123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_refuses_a_short_password(): void
    {
        $this->postJson('/api/account', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Short1',
            'password_confirmation' => 'Short1',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    /**
     * The breach list is consulted, and a hit is refused.
     *
     * The fake answers with the suffix of this password's own SHA-1, which is
     * what the k-anonymity lookup actually matches on. Faking an arbitrary body
     * would have left the test asserting that a random string was refused.
     */
    public function test_registration_refuses_a_known_breached_password(): void
    {
        $password = 'Tr0ub4dor&3xample';

        $this->fakeBreachLookupFor($password, occurrences: 12345);

        $this->postJson('/api/account', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_a_compliant_registration_is_accepted(): void
    {
        $this->fakeBreachLookupFor('a-perfectly-fine-passphrase9');

        $this->postJson('/api/account', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'a-perfectly-fine-passphrase9',
            'password_confirmation' => 'a-perfectly-fine-passphrase9',
        ])->assertCreated();

        $this->assertSame(1, User::count());
    }

    /**
     * The lookup is k-anonymous: only a prefix of the hash leaves the process.
     *
     * Asserted on the outgoing request because the property is about what the
     * network sees, and no response can demonstrate it.
     */
    public function test_the_breach_lookup_sends_only_a_hash_prefix(): void
    {
        $this->fakeBreachLookupFor('a-perfectly-fine-passphrase9');

        $this->postJson('/api/account', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'a-perfectly-fine-passphrase9',
            'password_confirmation' => 'a-perfectly-fine-passphrase9',
        ])->assertCreated();

        // Asserted after the request, because the point is what left the
        // process — and only five characters of the hash may appear in the URL.
        Http::assertSent(function (Request $request): bool {
            $hash = strtoupper(sha1('a-perfectly-fine-passphrase9'));
            $url = $request->url();

            // The five-character prefix identifies the bucket and nothing more.
            // The remaining thirty-six characters of the hash must never appear,
            // because they would let the response narrow the password down to one
            // candidate — which is the whole thing k-anonymity exists to prevent.
            return str_ends_with($url, '/range/'.substr($hash, 0, 5))
                && ! str_contains($url, substr($hash, 5));
        });
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedEmails(): array
    {
        return [
            'no domain' => ['ada@'],
            'no at sign' => ['ada.example.com'],
            'spaces inside' => ['ada lovelace@example.com'],
            'two at signs' => ['ada@@example.com'],
        ];
    }

    #[DataProvider('malformedEmails')]
    public function test_registration_refuses_a_malformed_email(string $email): void
    {
        $this->fakeBreachLookupFor('a-perfectly-fine-passphrase9');

        $this->postJson('/api/account', [
            'name' => 'Ada Lovelace',
            'email' => $email,
            'password' => 'a-perfectly-fine-passphrase9',
            'password_confirmation' => 'a-perfectly-fine-passphrase9',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(0, User::count());
    }

    /**
     * A case variant is the same account.
     *
     * Without canonicalisation the unique index would be asked whether
     * "Ada@example.com" is free while the row is stored as "ada@example.com", and
     * the answer would depend on the database's collation — which is how one
     * person ends up with two accounts and unable to sign in to either.
     */
    public function test_a_case_variant_of_a_taken_email_is_still_taken(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/account', [
            'name' => 'Ada Lovelace',
            'email' => 'ADA@Example.COM',
            'password' => 'a-perfectly-fine-passphrase9',
            'password_confirmation' => 'a-perfectly-fine-passphrase9',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, User::count());
    }

    public function test_a_mixed_case_email_is_stored_folded(): void
    {
        $this->postJson('/api/account', [
            'name' => 'Ada Lovelace',
            'email' => '  Ada@Example.COM  ',
            'password' => 'a-perfectly-fine-passphrase9',
            'password_confirmation' => 'a-perfectly-fine-passphrase9',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    }

    /**
     * Signing in is case-insensitive, because registration folded the address
     * and a person who types the capitalisation they first entered must still
     * reach their own account.
     */
    public function test_signing_in_ignores_the_case_of_the_address(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'a-perfectly-fine-passphrase9',
        ]);

        $this->post('/login', [
            'email' => '  ADA@EXAMPLE.COM ',
            'password' => 'a-perfectly-fine-passphrase9',
        ])->assertRedirect(route('account.show'));

        $this->assertAuthenticated();
    }

    /**
     * The token endpoint folds the address the same way, or the two doors would
     * disagree about who a caller is.
     */
    public function test_issuing_a_token_ignores_the_case_of_the_address(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'a-perfectly-fine-passphrase9',
        ]);

        $this->postJson('/api/auth/token', [
            'email' => 'ADA@EXAMPLE.COM',
            'password' => 'a-perfectly-fine-passphrase9',
        ])->assertOk();
    }

    /**
     * An account keeps its own address rather than colliding with itself.
     */
    public function test_an_account_may_keep_its_own_email(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $this->actingAs($user);

        $this->patch('/account', ['email' => 'ADA@example.com'])->assertRedirect(route('account.show'));

        $this->assertSame('ada@example.com', $user->fresh()->email);
    }

    /**
     * Rotation requires the current password, which is unchanged by the stricter
     * policy: an account created under an older one can still change its own.
     */
    public function test_rotation_still_requires_the_current_password(): void
    {
        $this->fakeBreachLookupFor('a-perfectly-fine-passphrase9');
        $user = User::factory()->create(['password' => 'original-password']);
        $this->actingAs($user);

        $this->patch('/account', [
            'password' => 'a-perfectly-fine-passphrase9',
            'password_confirmation' => 'a-perfectly-fine-passphrase9',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue($user->fresh()->password !== 'a-perfectly-fine-passphrase9');
    }

    /**
     * Re-sending the same password is not a rotation.
     *
     * Accepted silently before, it reported a password change and revoked every
     * token on the account for a password that had not moved — so an innocuous
     * submission logged every device out and looked like a successful change.
     */
    public function test_rotation_refuses_the_password_it_is_replacing(): void
    {
        $this->fakeBreachLookupFor('a-perfectly-fine-passphrase9');
        $user = User::factory()->create(['password' => 'a-perfectly-fine-passphrase9']);
        $this->actingAs($user);
        $user->createToken('some-device');

        $this->patch('/account', [
            'current_password' => 'a-perfectly-fine-passphrase9',
            'password' => 'a-perfectly-fine-passphrase9',
            'password_confirmation' => 'a-perfectly-fine-passphrase9',
        ])->assertSessionHasErrors('password');

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    /**
     * An older, weaker password still signs in.
     *
     * The creation policy is applied when a password is set, never when one is
     * checked. Applying it at sign-in would lock out every account created under
     * a previous policy, on the grounds that a password they cannot change is a
     * problem they are being asked to log in to report.
     */
    public function test_a_password_set_under_an_older_policy_still_signs_in(): void
    {
        User::factory()->create([
            'email' => 'legacy@example.com',
            'password' => 'legacy-1',
        ]);

        $this->post('/login', [
            'email' => 'legacy@example.com',
            'password' => 'legacy-1',
        ])->assertRedirect(route('account.show'));

        $this->assertAuthenticated();
    }

    /**
     * A wrong password and an unknown address say the same thing, in the same
     * language, on both doors.
     *
     * Asserted as an equality between the two responses because the failure being
     * guarded against is a difference: telling the two apart turns the login form
     * into a way to discover which addresses have accounts.
     */
    public function test_a_failed_sign_in_says_the_same_thing_either_way(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'a-perfectly-fine-passphrase9']);

        $wrongPassword = $this->postJson('/api/auth/token', [
            'email' => 'ada@example.com',
            'password' => 'not-the-password',
        ]);

        $unknownEmail = $this->postJson('/api/auth/token', [
            'email' => 'nobody@example.com',
            'password' => 'a-perfectly-fine-passphrase9',
        ]);

        $this->assertSame(
            $wrongPassword->json('errors.email.0'),
            $unknownEmail->json('errors.email.0'),
        );

        $this->assertSame(trans('auth.failed'), $wrongPassword->json('errors.email.0'));
    }

    public function test_the_web_login_failure_is_the_same_indonesian_message(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'a-perfectly-fine-passphrase9']);

        $this->from('/login')->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }
}
