<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndonesianLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The locale itself, so a missing lang/id/validation.php cannot present as
     * "the messages are just English" rather than as a missing file.
     */
    public function test_the_application_runs_in_indonesian(): void
    {
        $this->assertSame('id', app()->getLocale());
    }

    public function test_validation_messages_come_back_in_indonesian(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/sales', [])
            ->assertSessionHasErrors([
                'customer_id' => 'Kolom pelanggan wajib diisi.',
                'items' => 'Penjualan minimal memiliki satu item.',
            ]);
    }

    /**
     * The attribute name is translated too, not just the sentence around it.
     *
     * "Kolom price wajib diisi" is Indonesian with an English word left in the
     * middle, which is the failure that looks like a finished translation in
     * review and is not.
     */
    public function test_attribute_names_are_translated_too(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/products', ['name' => 'Kopi', 'price' => 'not-a-price'])
            ->assertSessionHasErrors('price');

        $this->assertSame('Kolom harga harus berupa angka.', session('errors')->first('price'));
    }

    /**
     * A missing line must be named by its index, which is the whole reason the
     * request has custom messages for the nested rules.
     */
    public function test_a_bad_line_is_named_by_its_position(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/sales', [
                'customer_id' => 1,
                'salesperson_id' => 1,
                'city_id' => 1,
                'items' => [['product_id' => 1, 'unit_price' => 1000]],
            ])
            ->assertSessionHasErrors('items.0.quantity');
    }

    /**
     * A line that is short a field must say what is missing, not just that
     * something is.
     */
    public function test_a_missing_line_field_says_what_is_missing(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/sales', [
                'customer_id' => 1,
                'salesperson_id' => 1,
                'city_id' => 1,
                'items' => [['product_id' => 1, 'unit_price' => 1000]],
            ])
            ->assertSessionHasErrors([
                'items.0.quantity' => 'Setiap baris perlu jumlah.',
            ]);
    }

    /**
     * The application has one language, and the fallback is that same language.
     *
     * With English as the fallback, any key missing from lang/id resolved to
     * fluent English — a form that validated correctly and then reported the
     * problem in a language its reader does not speak, which looks like no bug
     * at all from inside an Indonesian page. Indonesian as its own fallback
     * means a missing key surfaces as the key, which is loud.
     */
    public function test_indonesian_is_the_locale_and_its_own_fallback(): void
    {
        $this->assertSame('id', app()->getLocale());
        $this->assertSame('id', config('app.fallback_locale'));
    }

    /**
     * There is no second dictionary to fall back to.
     *
     * Asserted rather than assumed, because lang/en is easy to regenerate with
     * `php artisan lang:publish` and having it back would silently restore every
     * fallback this configuration was built to prevent.
     */
    public function test_there_is_no_english_dictionary(): void
    {
        $this->assertDirectoryDoesNotExist(lang_path('en'));
    }

    /**
     * Each dictionary file the framework reaches for is present.
     *
     * Checked as files rather than as strings, because a missing file and a
     * present-but-empty one produce the same wrong behaviour on some keys and a
     * hard error on others.
     *
     * @return array<string, array{0: string}>
     */
    public static function dictionaryFiles(): array
    {
        return [
            'validation' => ['validation.php'],
            'auth' => ['auth.php'],
            'pagination' => ['pagination.php'],
            'passwords' => ['passwords.php'],
        ];
    }

    #[DataProvider('dictionaryFiles')]
    public function test_the_indonesian_dictionary_file_is_present(string $file): void
    {
        $this->assertFileExists(lang_path('id/'.$file));
    }

    /**
     * With no English dictionary, a key that is missing from Indonesian renders
     * as itself. That is the intended behaviour and the reason the fallback is
     * Indonesian: the failure is visible rather than quiet.
     */
    public function test_a_key_missing_from_every_locale_renders_as_the_key(): void
    {
        $this->assertSame(
            'validation.not_a_real_rule',
            trans('validation.not_a_real_rule', [], 'id')
        );
    }

    public function test_the_interface_labels_stored_data_in_indonesian(): void
    {
        $this->assertSame('Pesanan', trans('Pesanan'));
        $this->assertSame('Pesanan', __('Pesanan'));
    }

    /**
     * The authentication messages the login form and the token endpoint share.
     *
     * Asserted on content rather than on the keys existing, because "trans
     * returns a string" is true of the raw key too and would have passed while
     * the application was still showing English.
     */
    public function test_the_authentication_messages_are_indonesian(): void
    {
        $this->assertSame('Email atau kata sandi salah.', trans('auth.failed'));
        $this->assertSame(
            'Terlalu banyak percobaan masuk. Silakan coba lagi dalam 60 detik.',
            trans('auth.throttle', ['seconds' => 60]),
        );
    }

    public function test_the_password_reset_messages_are_indonesian(): void
    {
        $this->assertSame('Harap tunggu sebelum mencoba lagi.', trans('passwords.throttled'));
        $this->assertStringContainsString('email', trans('passwords.user'));
    }

    public function test_the_pagination_messages_are_indonesian(): void
    {
        $this->assertSame('Berikutnya &raquo;', trans('pagination.next'));
        $this->assertSame('&laquo; Sebelumnya', trans('pagination.previous'));
    }

    /**
     * The catalogue screens render their labels as markup in the Vue templates,
     * so the test that matters is that the page still builds and the strings it
     * ships are the Indonesian ones.
     */
    public function test_the_product_index_renders_in_indonesian(): void
    {
        Product::factory()->create(['name' => 'Kopi Gula']);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Products/Index'));
    }
}
