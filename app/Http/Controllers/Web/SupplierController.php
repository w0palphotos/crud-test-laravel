<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Adding and editing suppliers.
 *
 * Reads live in DirectoryController with the other directory lists. What a
 * supplier costs and how long they take is deliberately not written here: those
 * belong to a supplier paired with one product, so they live on that pairing and
 * are maintained from the product form.
 */
class SupplierController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public function create(): Response
    {
        return Inertia::render('Suppliers/Create');
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $supplier = Supplier::create($request->validated());

        return redirect()
            ->route('suppliers.index')
            ->with('status', "Supplier \"{$supplier->name}\" ditambahkan.");
    }

    /**
     * @return array<string, mixed>
     */
    public function edit(Supplier $supplier): Response
    {
        return Inertia::render('Suppliers/Edit', [
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contactEmail' => $supplier->contact_email,
                'phone' => $supplier->phone,
            ],
            'updateUrl' => route('suppliers.update', $supplier),
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        // Only the supplier's own columns. The product relationships and their
        // pivot costs stay exactly as they were: editing contact details must
        // not rewrite purchasing history by accident.
        $supplier->update($request->validated());

        return redirect()
            ->route('suppliers.index')
            ->with('status', "Supplier \"{$supplier->name}\" diperbarui.");
    }
}
