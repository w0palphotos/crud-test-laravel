<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\AccountValidation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Create or promote the deployment's administrator, from a shell.
 *
 * This is the only way an account becomes an administrator. Registration cannot
 * do it — `is_admin` is neither mass-assignable nor among the validated fields —
 * so the door is a person with a terminal and the knowledge of what they are
 * doing.
 *
 * The password is asked for with a hidden prompt rather than taken as an
 * argument. An argument is a value that lands in the shell history, in the
 * process list, and in whatever log runs the command; a hidden prompt reaches
 * none of those. It is also why there is no `ADMIN_PASSWORD` environment
 * variable to document: a secret in `.env.example` is a secret that ends up in a
 * repository, and this one would be a way into the ledger.
 *
 * Idempotent, because provisioning is something an operator runs more than once —
 * after a restore, after a mistaken `--force`, and to check rather than change.
 * A second run that finds an administrator already in place says so and changes
 * nothing, rather than quietly resetting a password nobody asked to reset.
 */
class AdminEnsure extends Command
{
    /**
     * {--email= : The account to create or promote, instead of being asked}
     * {--name= : The display name, when creating a new account}
     * {--reset : Replace the password of an existing administrator and revoke its tokens}
     * {--force : Do not ask before changing an existing account}
     */
    protected $signature = 'admin:ensure
                            {--email= : The account to create or promote, instead of being asked}
                            {--name= : The display name, when creating a new account}
                            {--reset : Replace the password of an existing administrator and revoke its tokens}
                            {--force : Do not ask before changing an existing account}';

    /**
     * @var string
     */
    protected $description = 'Create the administrator account, or promote an existing one to it';

    public function handle(): int
    {
        $email = AccountValidation::normaliseEmail(
            $this->option('email') ?: $this->ask('Email administrator')
        );

        if (! is_string($email) || $email === '') {
            $this->components->error('Email wajib diisi.');

            return self::FAILURE;
        }

        if (! $this->valid($email, AccountValidation::emailRules())) {
            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        return $user === null
            ? $this->create($email)
            : $this->promote($user);
    }

    /**
     * No account with this address exists, so make one that is already an admin.
     *
     * The password is written through the model, which hashes it on assignment,
     * rather than through UpdateAccount. That action exists to rotate a password
     * an owner has proved they hold; here nobody is proving anything, and the
     * account has no history to revoke.
     */
    private function create(string $email): int
    {
        // Asked with no default, so the prompt is one unambiguous question rather
        // than a question with a suggested answer tucked into it.
        $name = $this->option('name') ?: $this->ask('Nama tampilan');

        if (! is_string($name) || $name === '') {
            $this->components->error('Nama tampilan wajib diisi.');

            return self::FAILURE;
        }

        if (! $password = $this->askForPassword()) {
            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        // Set explicitly rather than passed to create(). is_admin is deliberately
        // absent from the model's fillable attributes, so handing it to create()
        // would drop it silently and produce an ordinary account that reported
        // itself as an administrator.
        $user->forceFill(['is_admin' => true])->save();

        $this->components->info("Administrator dibuat: {$user->email}");

        return self::SUCCESS;
    }

    /**
     * The account already exists, so the question is whether it may be given the
     * desk — and whether its password should be replaced at the same time.
     */
    private function promote(User $user): int
    {
        $alreadyAdmin = (bool) $user->is_admin;
        $resetting = (bool) $this->option('reset');

        // Already an administrator and nobody asked for a reset: nothing to do.
        // Saying so and stopping is the point of an idempotent command — the
        // alternative is re-running it to "make sure" and quietly rotating a
        // password that was working.
        if ($alreadyAdmin && ! $resetting) {
            $this->components->info("{$user->email} sudah menjadi administrator. Tidak ada yang diubah.");

            return self::SUCCESS;
        }

        // --force skips the question outright rather than answering it for the
        // operator. Defaulting the prompt to "no" would still ask, and a prompt
        // that asks and then does the thing anyway is not a confirmation.
        if (! $this->option('force')
            && ! $this->confirm($this->confirmationPrompt($user, $alreadyAdmin, $resetting))) {
            $this->components->warn('Dibatalkan. Tidak ada yang diubah.');

            return self::FAILURE;
        }

        if ($resetting && ! $password = $this->askForPassword()) {
            return self::FAILURE;
        }

        $user->forceFill(array_filter([
            'is_admin' => true,
            'password' => $resetting ? $password : null,
        ], fn ($value): bool => $value !== null))->save();

        if ($resetting) {
            // Every token, including the operator's own if they have one. A reset
            // is issued because a password is not trusted any more, and a token
            // minted before that is exactly what is not to be trusted either.
            $user->tokens()->delete();

            $this->components->info("Kata sandi {$user->email} diganti dan seluruh token dicabut.");
        }

        $this->components->info("{$user->email} sekarang menjadi administrator.");

        return self::SUCCESS;
    }

    private function confirmationPrompt(User $user, bool $alreadyAdmin, bool $resetting): string
    {
        if ($alreadyAdmin && $resetting) {
            return "Ganti kata sandi administrator {$user->email} dan cabut seluruh tokennya?";
        }

        if ($alreadyAdmin) {
            return "{$user->email} sudah administrator. Tetap jalankan?";
        }

        return "Jadikan akun {$user->email} ({$user->name}) menjadi administrator?";
    }

    /**
     * The password, twice, under the same policy as the registration form.
     *
     * Returns null when the operator declined or the value did not pass, so the
     * caller stops without having to distinguish those cases itself.
     */
    private function askForPassword(): ?string
    {
        $password = $this->secret('Kata sandi (minimal 12 karakter, ada huruf dan angka)');
        $confirmation = $this->secret('Ulangi kata sandi');

        if (! is_string($password) || $password === '') {
            $this->components->error('Kata sandi wajib diisi.');

            return null;
        }

        if ($password !== $confirmation) {
            $this->components->error('Kedua kata sandi tidak cocok.');

            return null;
        }

        // Both fields are given to the validator, because `confirmed` compares
        // against `password_confirmation` in the data — handed only the single
        // field it would have nothing to compare against and would refuse every
        // password, including a correct one. The manual comparison above already
        // covers the mismatch; this pass is for the policy.
        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'confirmed', AccountValidation::password()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return null;
        }

        return $password;
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    private function valid(string $value, array $rules, string $field = 'email'): bool
    {
        $validator = Validator::make([$field => $value], [$field => $rules]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return false;
        }

        return true;
    }
}
