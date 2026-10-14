<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
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
