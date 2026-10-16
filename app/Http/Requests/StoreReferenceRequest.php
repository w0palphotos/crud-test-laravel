<?php

namespace App\Http\Requests;

use App\Rules\AccountValidation;
use App\Support\ReferenceDefinition;
use App\Support\ReferenceRegistry;
use Illuminate\Foundation\Http\FormRequest;

class StoreReferenceRequest extends FormRequest
{
    /**
     * The list being added to.
     *
     * Read through the registry rather than from the route directly, which is
     * where the 404 for a key that names no list comes from. Every other caller of
     * this request resolves it the same way, so a key outside the map is refused
     * before validation is even reached.
     */
    public function reference(): ReferenceDefinition
    {
        return ReferenceRegistry::get((string) $this->route('reference'));
    }

    /**
     * Fold an address to the same spelling everywhere else.
     *
     * The same reading registration and UpdateCustomerRequest take. Without it a
     * salesperson stored as "Ada@Example.com" could not be reached by typing the
     * address as it was first entered, and the desk would be told the address was
     * already taken by someone who is the same person.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach ($this->reference()->fields as $column => $field) {
            if ($field['type'] !== 'email' || ! $this->has($column)) {
                continue;
            }

            $normalised[$column] = AccountValidation::normaliseEmail($this->input($column));
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->reference()->rules();
    }
}
