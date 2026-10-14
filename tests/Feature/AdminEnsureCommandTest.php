<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The one door an account becomes an administrator through.
 *
 * It is a console command rather than a route or a seeder, so these tests drive
 * it through the same harness an operator uses. The questions that matter are
 * what it creates, what it refuses to create, and what it never writes down.
 */
class AdminEnsureCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The command applies the same password policy as every other door, so
        // it makes the same breach lookup and needs the same answer.
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    private function questions(string $password = 'a-perfectly-fine-passphrase9'): array
    {
        return [
            'email' => 'admin@example.com',
            'name' => 'Administrator',
            'password' => $password,
            'confirmation' => $password,
        ];
    }

    public function test_it_creates_an_administrator(): void
    {
        $this->artisan('admin:ensure')
            ->expectsQuestion('Email administrator', 'admin@example.com')
            ->expectsQuestion('Nama tampilan', 'Administrator')
            ->expectsQuestion('Kata sandi (minimal 12 karakter, ada huruf dan angka)', 'a-perfectly-fine-passphrase9')
            ->expectsQuestion('Ulangi kata sandi', 'a-perfectly-fine-passphrase9')
            ->assertSuccessful();

        $admin = User::where('email', 'admin@example.com')->sole();

        $this->assertTrue($admin->is_admin);
        $this->assertSame('Administrator', $admin->name);
    }

    /**
     * The password reaches the database hashed and nowhere else.
     *
     * Asserted on the stored value rather than on the prompts, because a command
     * that echoed the password would still hash correctly — and echoing is the
     * failure that matters.
     */
    public function test_it_stores_the_password_hashed(): void
    {
        $this->runCommand();

        $admin = User::where('email', 'admin@example.com')->sole();

        $this->assertNotSame('a-perfectly-fine-passphrase9', $admin->password);
        $this->assertTrue(Hash::check('a-perfectly-fine-passphrase9', $admin->password));
    }

    public function test_it_never_prints_the_password(): void
    {
        $this->artisan('admin:ensure')
            ->expectsQuestion('Email administrator', 'admin@example.com')
            ->expectsQuestion('Nama tampilan', 'Administrator')
            ->expectsQuestion('Kata sandi (minimal 12 karakter, ada huruf dan angka)', 'a-perfectly-fine-passphrase9')
            ->expectsQuestion('Ulangi kata sandi', 'a-perfectly-fine-passphrase9')
            ->doesntExpectOutput('a-perfectly-fine-passphrase9')
            ->assertSuccessful();
    }

    /**
     * There is deliberately no option to pass a password in.
     *
     * Asserted on the signature rather than by reading the source, so adding
     * `--password=` later fails here instead of quietly putting every operator's
     * administrator password into their shell history.
     */
    public function test_there_is_no_option_that_takes_a_password(): void
    {
        $definition = $this->app->make(Kernel::class)->all();

        $signature = collect($definition)->first(fn ($command) => $command->getName() === 'admin:ensure')
            ?->getDefinition()
            ?? null;

        $this->assertNotNull($signature, 'admin:ensure must be registered.');

        foreach (array_keys($signature->getOptions()) as $option) {
            $this->assertStringNotContainsString('password', $option);
            $this->assertStringNotContainsString('secret', $option);
        }
    }

    public function test_it_promotes_an_existing_account(): void
    {
        User::factory()->create(['email' => 'admin@example.com', 'name' => 'Ordinary']);

        $this->artisan('admin:ensure')
            ->expectsQuestion('Email administrator', 'admin@example.com')
            ->expectsConfirmation(
                'Jadikan akun admin@example.com (Ordinary) menjadi administrator?',
                'yes',
            )
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'admin@example.com')->sole()->is_admin);
    }

    /**
     * Promotion is refused when the answer is no, because an operator who typed
     * the wrong address should not have granted access they did not intend.
     */
    public function test_promotion_can_be_declined(): void
    {
        // Named explicitly: the question quotes the account's display name, and
        // a factory name would make the expected question a coin flip.
        $user = User::factory()->create(['email' => 'admin@example.com', 'name' => 'Ordinary']);

        $this->artisan('admin:ensure')
            ->expectsQuestion('Email administrator', 'admin@example.com')
            ->expectsConfirmation(
                'Jadikan akun admin@example.com (Ordinary) menjadi administrator?',
                'no',
            )
            ->assertFailed();

        $this->assertFalse($user->fresh()->is_admin);
    }

    /**
     * Running it twice is not an accident waiting to happen.
     *
     * The second run finds the administrator already in place and changes
     * nothing. The alternative — re-promoting "to be sure" and quietly rotating
     * a working password — is why the reset is behind its own flag.
     */
    public function test_running_it_twice_changes_nothing(): void
    {
        $this->runCommand();
        $hash = User::where('email', 'admin@example.com')->sole()->password;

        $this->artisan('admin:ensure')
            ->expectsQuestion('Email administrator', 'admin@example.com')
            ->expectsOutputToContain('sudah menjadi administrator')
            ->assertSuccessful();

        $this->assertSame($hash, User::where('email', 'admin@example.com')->sole()->password);
    }

    /**
     * A deliberate reset replaces the password and revokes every token.
     *
     * The tokens are the point: a reset is requested because something about the
     * old credential is not trusted, and a token minted before that is exactly as
     * untrusted as the password it replaced.
     */
    public function test_an_explicit_reset_replaces_the_password_and_revokes_tokens(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->forceFill(['is_admin' => true])->save();
        $admin->createToken('stale-device');

        $this->artisan('admin:ensure', ['--reset' => true, '--force' => true])
            ->expectsQuestion('Email administrator', 'admin@example.com')
            ->expectsQuestion('Kata sandi (minimal 12 karakter, ada huruf dan angka)', 'a-perfectly-replacement9')
            ->expectsQuestion('Ulangi kata sandi', 'a-perfectly-replacement9')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('a-perfectly-replacement9', $admin->fresh()->password));
        $this->assertSame(0, $admin->fresh()->tokens()->count());
    }

    public function test_it_refuses_a_password_that_breaks_the_policy(): void
    {
        $this->artisan('admin:ensure')
            ->expectsQuestion('Email administrator', 'admin@example.com')
            ->expectsQuestion('Nama tampilan', 'Administrator')
            ->expectsQuestion('Kata sandi (minimal 12 karakter, ada huruf dan angka)', 'short')
            ->expectsQuestion('Ulangi kata sandi', 'short')
            ->expectsOutputToContain('Kolom kata sandi')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_mismatched_confirmation(): void
    {
        $this->artisan('admin:ensure')
            ->expectsQuestion('Email administrator', 'admin@example.com')
            ->expectsQuestion('Nama tampilan', 'Administrator')
            ->expectsQuestion('Kata sandi (minimal 12 karakter, ada huruf dan angka)', 'a-perfectly-fine-passphrase9')
            ->expectsQuestion('Ulangi kata sandi', 'something-else-entirely')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_a_malformed_email(): void
    {
        $this->artisan('admin:ensure')
            ->expectsQuestion('Email administrator', 'not-an-email')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    /**
     * The address is folded before the lookup, so the command finds the account
     * registration stored rather than creating a second one beside it.
     */
    public function test_it_matches_the_address_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com', 'name' => 'Ordinary']);

        $this->artisan('admin:ensure', ['--force' => true])
            ->expectsQuestion('Email administrator', '  ADMIN@Example.COM ')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->is_admin);
        $this->assertSame(1, User::count());
    }

    public function test_the_email_option_skips_the_prompt(): void
    {
        $this->artisan('admin:ensure', [
            '--email' => 'admin@example.com',
            '--name' => 'Operator',
        ])
            ->expectsQuestion('Kata sandi (minimal 12 karakter, ada huruf dan angka)', 'a-perfectly-fine-passphrase9')
            ->expectsQuestion('Ulangi kata sandi', 'a-perfectly-fine-passphrase9')
            ->assertSuccessful();

        $this->assertSame('Operator', User::where('email', 'admin@example.com')->sole()->name);
    }

    /**
     * Create an administrator answering every prompt.
     */
    private function runCommand(): void
    {
        $this->artisan('admin:ensure')
            ->expectsQuestion('Email administrator', 'admin@example.com')
            ->expectsQuestion('Nama tampilan', 'Administrator')
            ->expectsQuestion('Kata sandi (minimal 12 karakter, ada huruf dan angka)', 'a-perfectly-fine-passphrase9')
            ->expectsQuestion('Ulangi kata sandi', 'a-perfectly-fine-passphrase9')
            ->assertSuccessful();
    }
}
