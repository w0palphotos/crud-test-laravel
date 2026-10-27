<?php

namespace App\Providers;

use App\Logs\ActivityJsonLog;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Sale;
use App\Models\SaleDocument;
use App\Models\SaleImage;
use App\Models\Salesperson;
use App\Models\SaleStatusEvent;
use App\Models\Supplier;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Opcodes\LogViewer\Facades\LogViewer;
use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Product Catalogue API',
    version: '1.0.0',
    description: 'A Sanctum-authenticated CRUD API. Issue a token at POST /api/auth/token, then paste it into Swagger UI\'s Authorize dialog.',
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum personal access token',
    description: 'Paste the token returned by POST /api/auth/token.',
)]
class AppServiceProvider extends ServiceProvider
{
    /**
     * Old attribute values captured during the `updating` event, keyed by the
     * model instance's object id. `updated` fires after Laravel has already
     * synced the originals, so the previous values have to be stashed here to
     * be reported alongside the new ones.
     *
     * @var array<int, array<string, mixed>>
     */
    public static array $activityOldValues = [];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // request_id diturunkan satu kali per request sehingga semua aktivitas
        // yang timbul dalam satu request (misal penjualan dengan itemnya) dapat
        // dikorelasikan antara tabel activity_logs dan file log per-user.
        $this->app->scoped('activity.request_id', fn (): string => (string) Str::uuid());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The assistant calls a metered external API on every request, so it gets
        // its own limit keyed on the account rather than the address. The built-in
        // throttle keys on the address for guests, and deployed behind Vercel
        // every visitor shares the proxy's IP, which would collapse the whole
        // application into one bucket.
        RateLimiter::for('assistant', fn (Request $request) => [
            Limit::perMinute(20)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);

        // Reading a document does the same and costs more: the model is handed up
        // to a whole document's text rather than one question, on top of the
        // extraction that happens before the call. Keyed the same way, and lower,
        // because nobody imports spreadsheets twenty times a minute and the limit
        // is there to bound a runaway loop rather than to ration ordinary use.
        RateLimiter::for('imports', fn (Request $request) => [
            Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);

        // Catat setiap create, update dan delete pada entitas yang sales boleh
        // ubah, sehingga admin dapat melacak siapa yang ngapain di activity log.
        // `updated` hanya dipicu bila ada atribut yang benar-benar berubah, jadi
        // edit tanpa perubahan (mis. simpan ulang tanpa sentuhan) tidak
        // menghasilkan baris log.
        //
        // `name` di context dipakai ActivityLog::record sebagai label yang
        // mudah dibaca; tanpanya subject_name akan jatuh ke primary key.
        $named = [
            Product::class => fn ($s) => $s->name,
            Customer::class => fn ($s) => $s->name,
            Supplier::class => fn ($s) => $s->name,
            Sale::class => fn ($s) => $s->reference,
            City::class => fn ($s) => $s->name,
            Category::class => fn ($s) => $s->name,
            Tag::class => fn ($s) => $s->name,
            Salesperson::class => fn ($s) => $s->name,
            ProductImage::class => fn ($s) => 'Gambar produk #'.$s->product_id,
            CustomerProfile::class => fn ($s) => 'Profil pelanggan #'.$s->customer_id,
            SaleImage::class => fn ($s) => 'Gambar penjualan #'.$s->sale_id,
            SaleDocument::class => fn ($s) => $s->original_name ?? ('Dokumen #'.$s->getKey()),
            SaleStatusEvent::class => fn ($s) => 'Perubahan status #'.$s->getKey(),
        ];

        foreach (array_keys($named) as $model) {
            $model::updating(function ($subject) {
                // Stash the pre-update values before Laravel syncs them, so the
                // `updated` handler below can report what actually changed.
                self::$activityOldValues[spl_object_id($subject)] = array_intersect_key(
                    $subject->getOriginal(),
                    $subject->getDirty(),
                );
            });

            $model::created(function ($subject) use ($named) {
                ActivityLog::record([
                    'subject' => $subject,
                    'action' => 'created',
                    'name' => $named[get_class($subject)]($subject),
                    'properties' => $subject->getAttributes(),
                ]);
            });

            $model::updated(function ($subject) use ($named) {
                $changes = $subject->getChanges();
                // Abaikan pembaruan yang hanya menyentuh timestamp (mis. touch),
                // agar log tidak penuh baris yang tidak bermakna.
                unset($changes['updated_at']);

                if ($changes === []) {
                    unset(self::$activityOldValues[spl_object_id($subject)]);

                    return;
                }

                $old = self::$activityOldValues[spl_object_id($subject)] ?? [];
                unset(self::$activityOldValues[spl_object_id($subject)]);

                ActivityLog::record([
                    'subject' => $subject,
                    'action' => 'updated',
                    'name' => $named[get_class($subject)]($subject),
                    'properties' => [
                        'old' => $old,
                        'new' => $changes,
                    ],
                ]);
            });

            $model::deleted(function ($subject) use ($named) {
                ActivityLog::record([
                    'subject' => $subject,
                    'action' => 'deleted',
                    'name' => $named[get_class($subject)]($subject),
                    'properties' => $subject->getAttributes(),
                ]);
            });
        }

        // Daftarkan custom log class untuk Log Viewer agar baris JSON Lines di
        // storage/logs/{user_id}/activity.log dikenali sebagai log aktivitas
        // pengguna, bukan file "tidak dikenal" yang disembunyikan UI.
        LogViewer::extend('activity-json', ActivityJsonLog::class);

        // Opcodes Log Viewer otorisasi produksi: izinkan hanya administrator.
        Gate::define('viewLogViewer', function (?User $user): bool {
            return (bool) $user?->is_admin;
        });
    }
}
