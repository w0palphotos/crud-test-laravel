<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman Inertia yang pernah dibuka tidak perlu dirender ulang dari nol setiap
 * kali. Respons di-cache oleh browser dengan ETag; kunjungan ulang ke halaman
 * yang sama jawab 304 Not Modified tanpa body JSON, jadi Vue tidak perlu
 * me-render ulang dan jaringan hanya menghemat sedikit waktu round-trip.
 *
 * Hanya merespons XHR Inertia (header X-Inertia) dengan status 200. Redirect,
 * 403, 404 dan halaman yang tidak diubah tidak di-cache agar data yang baru
 * tidak tertahan lama di sisi klien.
 */
class CacheInertiaResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->header('X-Inertia') && $response->getStatusCode() === 200) {
            $etag = md5($response->getContent());
            $response->setEtag($etag);
            $response->setCache([
                'max_age' => 60,
                'private' => true,
            ]);

            if ($request->getETags() && in_array($etag, array_map(fn ($e) => trim($e, '"'), $request->getETags()), true)) {
                return response('', 304, ['ETag' => $etag]);
            }
        }

        return $response;
    }
}
