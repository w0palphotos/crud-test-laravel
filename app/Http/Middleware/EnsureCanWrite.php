<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Izinkan akun non-admin (sales) menulis data sendiri.
 *
 * Admin (is_admin) mendapat akses penuh ke semua route write.
 * Akun non-admin hanya mencapai route yang ada di bawah middleware ini:
 * create (GET form), store (POST) dan destroy (DELETE).
 * Edit/update tetap di grup admin, dan ownership pada destroy
 * ditegakkan di controller (hapus hanya data milik sendiri).
 *
 * Guests bukan urusan middleware ini: `auth` jalankan dulu.
 */
class EnsureCanWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Administrator: kendali penuh atas semua route write.
        if ($user?->is_admin) {
            return $next($request);
        }

        // Non-admin (sales): create form, create submit, edit/update, dan
        // delete mencapai controller. Edit/update tidak dibatasi oleh
        // ownership — sales boleh mengubah data apa saja, sama seperti admin.
        if (in_array($request->method(), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        abort(403);
    }
}
