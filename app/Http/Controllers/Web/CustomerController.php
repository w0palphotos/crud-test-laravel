<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerProfileRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\City;
use App\Models\Customer;
use App\Rules\CustomerProfileValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Adding, editing and onboarding the customers.
 *
 * Reads live in DirectoryController because reading a customer is reading a row of
 * the directory; writing one is not, and gets its own boundary for the validation
 * and the one-to-one profile.
 */
class CustomerController extends Controller
{
    /**
     * The create form.
     *
     * Arrives with an empty profile rather than no profile, so the fields the form
     * binds to exist either way — the same reason ProductController::create sends
     * an empty gallery.
     *
     * @return array<string, mixed>
     */
    public function create(): Response
    {
        return Inertia::render('Customers/Create', [
            'cities' => $this->cities(),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $customer = DB::transaction(function () use ($request): Customer {
            // Only the customer's own three columns; the profile fields ride along
            // in the same request but belong to another table.
            $customer = Customer::create($request->safe()->only(['name', 'email', 'phone']));

            $customer->profile()->create(CustomerProfileValidation::payload($request->validated()));

            return $customer;
        });

        return redirect()
            ->route('customers.index')
            ->with('status', "Pelanggan \"{$customer->name}\" ditambahkan.");
    }

    /**
     * @return array<string, mixed>
     */
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
            'cities' => $this->cities(),
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
                CustomerProfileValidation::payload($request->validated()),
            );
        });

        return redirect()
            ->route('customers.index')
            ->with('status', "Profil \"{$customer->name}\" diperbarui.");
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function cities(): array
    {
        return City::query()->orderBy('name')->get(['id', 'name'])->all();
    }
}
