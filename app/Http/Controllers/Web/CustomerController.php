<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerProfileRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\City;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function edit(Customer $customer): Response
    {
        $customer->load(['profile.city']);

        return Inertia::render('Customers/Edit', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ],
            'profile' => $customer->profile ? [
                'city_id' => $customer->profile->city_id,
                'address' => $customer->profile->address,
                'tax_id' => $customer->profile->tax_id,
                'credit_limit' => $customer->profile->credit_limit,
                'payment_terms' => $customer->profile->payment_terms,
            ] : null,
            'cities' => City::query()->orderBy('name')->get(['id', 'name'])->all(),
            'updateUrl' => route('customers.update', $customer),
            'profileUrl' => route('customers.profile.update', $customer),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        return redirect()
            ->route('customers.index')
            ->with('status', "Pelanggan \"{$customer->name}\" diperbarui.");
    }

    public function updateProfile(StoreCustomerProfileRequest $request, Customer $customer): RedirectResponse
    {
        // updateOrCreate, never create blindly: customer_id is unique, so a
        // second profile row is a database error rather than a second profile.
        // A transaction keeps the customer and profile reads consistent.
        DB::transaction(function () use ($request, $customer): void {
            $customer->profile()->updateOrCreate(
                ['customer_id' => $customer->getKey()],
                $request->validated(),
            );
        });

        return redirect()
            ->route('customers.index')
            ->with('status', "Profil \"{$customer->name}\" diperbarui.");
    }
}
