<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * One reference list: what it is called, what may be typed into it, and what
 * still refers to it.
 *
 * The dimension tables differ only in their columns — two to four of them, none
 * of them carrying anything that needs a table of its own — so they are described
 * here once rather than as a controller and two form requests per table that
 * differ only in a table name. DirectoryController reached the same conclusion for
 * reading them; this carries it into writing.
 *
 * $fields is the whole contract with the browser. The form is drawn from it, the
 * validation is built from it, and the write is limited to it, so a column can
 * never become typable in one of those three and quietly stay missing in the
 * others.
 */
final class ReferenceDefinition
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $searchable  Columns the list's search box looks at.
     * @param  array<string, array{label: string, type: string, optional: bool, rules: array<int, mixed>, blank?: mixed, numeric?: bool}>  $fields
     * @param  list<string>  $listed  The fields the table shows, in column order. A subset of $fields.
     * @param  list<string>  $unique  Fields carrying a unique index, which is what makes the generated rule need the row.
     * @param  array<string, string>  $references  Relation name to what a reader would call the rows it reaches.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $singular,
        public readonly string $icon,
        public readonly string $model,
        public readonly array $searchable,
        public readonly array $fields,
        public readonly array $listed,
        public readonly array $unique,
        public readonly array $references,
        public readonly string $orderBy = 'name',
    ) {}

    /**
     * A blank row of this reference, for the create form.
     *
     * Every field is present rather than absent, so the form always has a key to
     * bind to and a row saved without touching a field stores the column's own
     * default rather than null.
     */
    public function blank(): array
    {
        $values = [];

        foreach ($this->fields as $column => $field) {
            $values[$column] = $field['blank'] ?? null;
        }

        return $values;
    }

    /**
     * The values to write, with an untouched optional field resolved.
     *
     * Two problems solved in one pass. An empty text field posts an empty string,
     * which `nullable` happily accepts and the column then stores — so "no
     * province" becomes a province that is the empty string rather than nothing.
     * And a field the schema declares NOT NULL with a default, such as a tag's
     * colour, cannot be given null at all: writing what the column already calls
     * its default is the only value that means "not chosen".
     *
     * Limited to the definition's fields, which is the second of the two bounds on
     * the write — the model's own `#[Fillable]` being the first.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function payload(array $values): array
    {
        $payload = [];

        foreach ($this->fields as $column => $field) {
            if (! array_key_exists($column, $values)) {
                continue;
            }

            $value = $values[$column];

            $payload[$column] = ($value === null || $value === '')
                ? ($field['blank'] ?? null)
                : $value;
        }

        return $payload;
    }

    public function instance(): Model
    {
        $model = $this->model;

        return new $model;
    }

    public function table(): string
    {
        $model = $this->model;

        return (new $model)->getTable();
    }

    /**
     * The rules for one write, from the fields plus whatever the schema enforces.
     *
     * The unique index is added here rather than written into each field's rules
     * because it is the only rule that needs to know which row is being edited:
     * without `ignore`, saving a name unchanged would be reported as a clash with
     * itself, which is the mistake any form with a unique column invites.
     *
     * It is added when creating too. A rule that only exists on the update path
     * reads as harmless until two people are sent to add the same city at once,
     * and then the uniqueness is enforced by the database rather than the form —
     * which means a 500 with no sentence in it instead of a field pointing at the
     * name that is already taken.
     *
     * @param  Model|null  $row  The row being edited, or null when creating.
     * @return array<string, array<int, mixed>>
     */
    public function rules(?Model $row = null): array
    {
        $rules = [];

        foreach ($this->fields as $column => $field) {
            $fieldRules = $field['rules'];

            if (in_array($column, $this->unique, true)) {
                $unique = Rule::unique($this->table(), $column);

                $fieldRules[] = $row === null ? $unique : $unique->ignore($row);
            }

            $rules[$column] = $fieldRules;
        }

        return $rules;
    }

    /**
     * The columns the table shows, in order.
     *
     * @return list<array{key: string, label: string, type: string, numeric: bool}>
     */
    public function columns(): array
    {
        return array_map(fn (string $column): array => [
            'key' => $column,
            'label' => $this->fields[$column]['label'],
            'type' => $this->fields[$column]['type'],
            'numeric' => $this->fields[$column]['numeric'] ?? false,
        ], $this->listed);
    }

    /**
     * Every field as the form needs it, which is more than the table shows.
     *
     * @return list<array{key: string, label: string, type: string, optional: bool, numeric: bool}>
     */
    public function formFields(): array
    {
        return array_map(fn (string $column, array $field): array => [
            'key' => $column,
            'label' => $field['label'],
            'type' => $field['type'],
            'optional' => $field['optional'],
            'numeric' => $field['numeric'] ?? false,
        ], array_keys($this->fields), $this->fields);
    }
}
