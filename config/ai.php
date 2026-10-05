<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    | Gemini is the default because it is the only provider this application
    | configures a key for (GEMINI_API_KEY). The other per-feature defaults are
    | left as shipped so adding a key is an explicit, separate step.
    |
    */

    'default' => env('AI_DEFAULT', 'gemini'),
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',
    'default_for_classification' => 'typesafe',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),

            /*
               * The base URL of the Gemini API, not a full endpoint. The SDK
               * appends the path it needs (such as /interactions), so a value
               * ending in :generateContent produces a request to
               * ".../models/gemini-flash-latest:generateContent/interactions"
               * and a 404 from Google. Leave GEMINI_URL unset to use the default.
               */
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),

            'models' => [
                'text' => [
                    /*
                       * 2.5-flash is retired for newly created keys and Google
                       * answers a request for it with 404, so the model is set
                       * here rather than in code: it changes as models are
                       * retired, and that should not need a code change.
                       */
                    'default' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
                ],
            ],
        ],
    ],
];
