<?php

namespace App\Http\Controllers\Web;

use App\Ai\Agents\Assistant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * Answers a message from the chatbot bubble.
 */
class AssistantController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:500'],
        ]);

        try {
            $response = (new Assistant)
                ->forUser($request->user())
                ->prompt(
                    $validated['message'],
                    provider: Lab::Gemini,
                    // Left unset so config/ai.php decides. A retired model
                    // answers 404, and the fix for that belongs in configuration
                    // rather than in a code change.
                    timeout: 30,
                );
        } catch (Throwable $e) {
            // No API key, no quota, a timeout, or a retired model all end up here.
            // Reporting first matters: without it a configuration mistake looks
            // identical to a provider outage, and the message the user sees cannot
            // say which it was.
            report($e);

            return response()->json([
                'error' => 'Maaf, layanan AI sedang tidak tersedia. Coba lagi sebentar lagi.',
            ], 503);
        }

        return response()->json(['reply' => (string) $response]);
    }
}
