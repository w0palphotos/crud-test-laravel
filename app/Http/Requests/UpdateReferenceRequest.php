<?php

namespace App\Http\Requests;

use App\Rules\AccountValidation;
use App\Support\ReferenceDefinition;
use App\Support\ReferenceRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReferenceRequest extends FormRequest
{
    public function reference(): ReferenceDefinition
    {
        return ReferenceRegistry::get((string) $this->route('reference'));
    }

    /**
     * The row being edited.
     *
     * Found through the definition's own model rather than a route-model binding,
     * because the class varies with the key and a binding is declared per route.
     * An id that matches nothing is a 404, which is the answer: the row the desk
     * was looking at is gone, and there is no form to refuse.
     */
    public function row(): Model
    {
        return $this->reference()->instance()
            ->newQuery()
            ->findOrFail((int) $this->route('row'));
    }

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
     * Partial update, so a field left out keeps its current value.
     *
     * The row is passed to the definition because it is what makes the unique
     * index ignore itself: without it, saving a name unchanged would be reported
     * as a clash with the row being saved.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->reference()->rules($this->row()) as $column => $columnRules) {
            $rules[$column] = ['sometimes', ...$columnRules];
        }

        return $rules;
    }
}
