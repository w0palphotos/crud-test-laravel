<?php

namespace App\Support;

use App\Models\City;
use App\Models\Salesperson;
use App\Models\Tag;

/**
 * The reference lists the desk can add to, and the only way one is named.
 *
 * This map is a whitelist, not a convenience. `{reference}` arrives in the URL
 * from the browser, so resolving it to a table and a model class is exactly the
 * place a careless application lets a request choose its own query target. Nothing
 * here reads a request: `get()` either finds one of these three entries or
 * answers 404, and the controller takes the table and the class from the entry it
 * found. A key naming any other table — `users`, `sales`, a name that is not a
 * table at all — never becomes a model, so there is nothing for it to reach.
 *
 * Adding a seventh reference list is one entry here plus its navigation label.
 * Nothing in the controller, the requests or the Vue pages changes.
 */
final class ReferenceRegistry
{
    /**
     * @var array<string, ReferenceDefinition>|null
     */
    private static ?array $definitions = null;

    /**
     * Every reference list, keyed by the segment that names it in the URL.
     *
     * @return array<string, ReferenceDefinition>
     */
    public static function all(): array
    {
        return self::$definitions ??= self::build();
    }

    /**
     * The keys, for navigation and for anything that has to enumerate the lists.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * One list by its key, or 404.
     *
     * The abort is the security decision, not the lookup: every caller of this
     * method — the controller, and both form requests through it — needs a
     * definition, and a key outside the map has no definition to give.
     */
    public static function get(string $key): ReferenceDefinition
    {
        $definitions = self::all();

        abort_unless(isset($definitions[$key]), 404);

        return $definitions[$key];
    }

    /**
     * @return array<string, ReferenceDefinition>
     */
    private static function build(): array
    {
        $definitions = [
            // A city is where a sale was delivered, and the customer's own address
            // lives on their profile instead — a Jakarta branch buying for a Bandung
            // warehouse is a real arrangement, so the two are not collapsed. Both
            // references are therefore the same word here: deleting a city reaches
            // sales first, and only then the profiles that point at it.
            'cities' => new ReferenceDefinition(
                key: 'cities',
                label: 'Kota',
                singular: 'kota',
                icon: 'globe',
                model: City::class,
                searchable: ['name', 'province'],
                fields: [
                    'name' => [
                        'label' => 'Nama',
                        'type' => 'text',
                        'optional' => false,
                        'rules' => ['required', 'string', 'max:255'],
                    ],
                    'province' => [
                        'label' => 'Provinsi',
                        'type' => 'text',
                        'optional' => true,
                        'rules' => ['nullable', 'string', 'max:255'],
                    ],
                ],
                listed: ['name', 'province'],
                unique: ['name'],
                references: ['sales' => 'penjualan'],
            ),

            // commission_rate is a percentage rather than money, so a pay rise does
            // not reshape every historical commission. The ceiling is the app's
            // judgement rather than the schema's: a rate above 100 earns more than
            // the sale, which is a typo rather than an arrangement.
            'salespeople' => new ReferenceDefinition(
                key: 'salespeople',
                label: 'Tim Sales',
                singular: 'sales',
                icon: 'users',
                model: Salesperson::class,
                searchable: ['name', 'email'],
                fields: [
                    'name' => [
                        'label' => 'Nama',
                        'type' => 'text',
                        'optional' => false,
                        'rules' => ['required', 'string', 'max:255'],
                    ],
                    'email' => [
                        'label' => 'Email',
                        'type' => 'email',
                        'optional' => true,
                        'rules' => ['nullable', 'email', 'max:255'],
                    ],
                    'phone' => [
                        'label' => 'Telepon',
                        'type' => 'tel',
                        'optional' => true,
                        'rules' => ['nullable', 'string', 'max:24'],
                    ],
                    'commission_rate' => [
                        'label' => 'Komisi (%)',
                        'type' => 'decimal',
                        'optional' => true,
                        // NOT NULL with a default of 0, so an empty field stores 0
                        // rather than the null the form would otherwise send.
                        'blank' => 0,
                        'numeric' => true,
                        'rules' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
                    ],
                ],
                listed: ['name', 'email', 'phone', 'commission_rate'],
                unique: ['name', 'email'],
                references: ['sales' => 'penjualan'],
            ),

            // A hex value rather than a colour name, so the dashboard can use it as
            // a background without a lookup it would otherwise have to fail.
            'tags' => new ReferenceDefinition(
                key: 'tags',
                label: 'Tag',
                singular: 'tag',
                icon: 'tag',
                model: Tag::class,
                searchable: ['name'],
                fields: [
                    'name' => [
                        'label' => 'Nama',
                        'type' => 'text',
                        'optional' => false,
                        'rules' => ['required', 'string', 'max:255'],
                    ],
                    'colour' => [
                        'label' => 'Warna',
                        'type' => 'colour',
                        'optional' => true,
                        'blank' => '#e6e8eb',
                        'rules' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
                    ],
                ],
                listed: ['name', 'colour'],
                unique: ['name'],
                references: ['sales' => 'penjualan'],
            ),
        ];

        return $definitions;
    }
}
