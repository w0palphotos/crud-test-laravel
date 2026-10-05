<?php

namespace App\Ai\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;

/**
 * The chatbot behind the floating bubble.
 *
 * Conversations are remembered per user, so a follow-up like "what about
 * cheaper ones?" makes sense without restating the context. RemembersConversations
 * loads and stores that history for us, which is why this agent does not implement
 * a messages() method: doing so would take precedence and the history would never
 * be read.
 */
class Assistant implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'TEXT'
        Kamu adalah asisten bantuan untuk aplikasi katalog produk sederhana.
        Balas dalam Bahasa Indonesia, dengan singkat dan ramah.

        Kamu tidak punya akses ke data aplikasi. Kalau pengguna menanyakan produk,
        harga, atau stok, katakan bahwa kamu belum bisa melihat data tersebut dan
        saratkan mereka membuka halaman Products.
        TEXT;
    }
}
