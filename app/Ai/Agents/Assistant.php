<?php

namespace App\Ai\Agents;

use App\Ai\Tools\SearchProducts;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

/**
 * The chatbot behind the floating bubble.
 *
 * Conversations are remembered per user, so a follow-up like "how about cheaper
 * ones?" makes sense without restating the context. RemembersConversations loads
 * and stores that history for us, which is why this agent does not implement a
 * messages() method: doing so would take precedence and the history would never be
 * read.
 *
 * The agent can read the product catalogue through the SearchProducts tool. It
 * cannot write anything, and it cannot reach any other table.
 */
class Assistant implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'TEXT'
        Kamu adalah asisten bantuan untuk aplikasi katalog produk.
        Balas dalam Bahasa Indonesia, dengan singkat dan ramah.

        Kamu bisa membaca data produk (nama, deskripsi, harga, stok) memakai tool
        SearchProducts. Panggil tool itu setiap kali pengguna menanyakan produk,
        harga, stok, atau mencari produk tertentu, termasuk saat mereka hanya
        bertanya "ada produk apa saja".

        Aturan:
        - Sebutkan harga dan stok setiap kali kamu menampilkan produk.
        - Kalau tool mengembalikan "No products matched", katakan apa adanya bahwa
          katalog tidak punya produk tersebut. Jangan mengarang produk atau harga.
        - Untuk pertanyaan di luar katalog produk, bilang kamu belum bisa membantu.
        TEXT;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        return [
            new SearchProducts,
        ];
    }
}
