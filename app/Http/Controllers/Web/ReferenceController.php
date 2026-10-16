<?php

namespace App\Http\Controllers\Web;

use App\Actions\ListReferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReferenceRequest;
use App\Http\Requests\UpdateReferenceRequest;
use App\Support\ReferenceDefinition;
use App\Support\ReferenceRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Adding to, changing and removing from the desk's reference lists.
 *
 * One controller for all of them, driven by ReferenceRegistry. The alternative —
 * a controller, two form requests and three pages per table — was nine files that
 * differed only in a table name and a column list, and the sixth list would have
 * been the sixth copy. DirectoryController made the same call for reading them.
 *
 * Nothing here names a table. `{reference}` is resolved through the registry,
 * which either knows the key or answers 404, and the model class comes from the
 * entry rather than from the URL — so the segment names one of three lists and
 * cannot be made to name anything else.
 */
class ReferenceController extends Controller
{
    public function __construct(private ListReferences $listReferences) {}

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request, string $reference): Response
    {
        $definition = ReferenceRegistry::get($reference);

        $search = trim((string) $request->query('q', ''));

        $rows = $this->listReferences->handle($request, $definition)
            ->withQueryString()
            ->through(fn (Model $row): array => $this->present($definition, $row));

        return Inertia::render('Reference/Index', [
            'reference' => $this->describe($definition, indexUrl: route('references.index', $definition->key)),
            // Reading the list is open to any signed-in account; changing one is not.
            // The write routes below are admin-only, so this is the page declining to
            // offer the buttons rather than the boundary enforcing them.
            'can' => ['write' => (bool) $request->user()->is_admin],
            'rows' => $rows,
            'search' => $search,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $reference): Response
    {
        $definition = ReferenceRegistry::get($reference);

        return Inertia::render('Reference/Form', [
            'reference' => $this->describe($definition),
            'row' => null,
            'values' => $definition->blank(),
            'storeUrl' => route('references.store', $definition->key),
            'cancelUrl' => route('references.index', $definition->key),
        ]);
    }

    public function store(StoreReferenceRequest $request, string $reference): RedirectResponse
    {
        $definition = $request->reference();

        $row = $definition->instance()->newQuery()->create(
            $definition->payload($request->validated()),
        );

        return redirect()
            ->route('references.index', $definition->key)
            ->with('status', "{$definition->label} \"{$row->getAttribute('name')}\" ditambahkan.");
    }

    /**
     * @return array<string, mixed>
     */
    public function edit(string $reference, string $row): Response
    {
        $definition = ReferenceRegistry::get($reference);

        $model = $definition->instance()->newQuery()->findOrFail((int) $row);

        $values = [];

        foreach ($definition->fields as $column => $field) {
            $value = $model->getAttribute($column);
            $values[$column] = $value === null ? '' : (string) $value;
        }

        return Inertia::render('Reference/Form', [
            'reference' => $this->describe($definition),
            'row' => $this->present($definition, $model),
            'values' => $values,
            'updateUrl' => route('references.update', [$definition->key, $model->getKey()]),
            'cancelUrl' => route('references.index', $definition->key),
        ]);
    }

    public function update(UpdateReferenceRequest $request, string $reference, string $row): RedirectResponse
    {
        $definition = $request->reference();
        $model = $request->row();

        $model->update($definition->payload($request->validated()));

        return redirect()
            ->route('references.index', $definition->key)
            ->with('status', "{$definition->label} \"{$model->getAttribute('name')}\" diperbarui.");
    }

    public function destroy(string $reference, string $row): RedirectResponse
    {
        $definition = ReferenceRegistry::get($reference);

        $model = $definition->instance()->newQuery()->findOrFail((int) $row);

        // Refused here rather than left to the database, and the difference is not
        // cosmetic. `sales` holds its customer, salesperson and city with
        // restrictOnDelete, so deleting one of those rows in use raises a foreign
        // key violation that surfaces as a 500 with no sentence in it. Tags and
        // suppliers cascade, which is worse rather than better: the delete appears
        // to work and quietly strips the labels off historical sales and the
        // supplier's prices off products.
        foreach ($definition->references as $relation => $what) {
            if (! $model->{$relation}()->exists()) {
                continue;
            }

            $count = $model->{$relation}()->count();

            throw ValidationException::withMessages([
                // Keyed on the row, so the list can say which one was refused
                // rather than painting the whole page.
                "references.{$model->getKey()}" => [
                    "\"{$model->getAttribute('name')}\" tidak bisa dihapus: masih dipakai oleh {$count} {$what}.",
                ],
            ]);
        }

        $name = $model->getAttribute('name');
        $model->delete();

        return redirect()
            ->route('references.index', $definition->key)
            ->with('status', "{$definition->label} \"{$name}\" dihapus.");
    }

    /**
     * What the pages need to know about this list.
     *
     * The URLs are built here because `route()` is a Blade helper the browser
     * cannot reach, and shared/routes.js exists precisely so a component never
     * assembles a path out of a key and a prefix. `indexUrl` is passed rather than
     * built because it is the one URL a caller already has — the one it is on.
     *
     * @return array<string, mixed>
     */
    private function describe(ReferenceDefinition $definition, ?string $indexUrl = null): array
    {
        return [
            'key' => $definition->key,
            'label' => $definition->label,
            'singular' => $definition->singular,
            'icon' => $definition->icon,
            'columns' => $definition->columns(),
            'fields' => $definition->formFields(),
            'indexUrl' => $indexUrl ?? route('references.index', $definition->key),
            'createUrl' => route('references.create', $definition->key),
            'storeUrl' => route('references.store', $definition->key),
        ];
    }

    /**
     * One row, with the URLs that act on it.
     *
     * The columns keep their database names. The form posts `form[field.key]`, so
     * renaming them here would put a translation between the field a person filled
     * in and the column it writes to, for no gain — the definitions already carry
     * the label a person reads.
     *
     * @return array<string, mixed>
     */
    private function present(ReferenceDefinition $definition, Model $row): array
    {
        $values = ['id' => $row->getKey()];

        foreach ($definition->listed as $column) {
            $values[$column] = $row->getAttribute($column);
        }

        return [
            ...$values,
            'editUrl' => route('references.edit', [$definition->key, $row->getKey()]),
            'destroyUrl' => route('references.destroy', [$definition->key, $row->getKey()]),
        ];
    }
}
