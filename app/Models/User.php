<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The column default, mirrored on the model.
     *
     * A database default applies when a row is inserted, not when a model is
     * instantiated — so an account loaded into a session, or built by a factory
     * that does not name the column, has no is_admin attribute at all until it is
     * re-read. That is not a theoretical gap: the middleware and the Inertia prop
     * both read the flag off an in-memory model, and `only('is_admin')` on a
     * model without the attribute omits the key entirely rather than yielding
     * false. An ordinary account then reached the frontend as `undefined`, which
     * in JavaScript is falsy by accident rather than by decision.
     */
    protected $attributes = [
        'is_admin' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * is_admin is cast so the flag is a real boolean everywhere it is read. The
     * column is a tinyint on SQLite and a real boolean on Postgres, and a raw
     * read would hand the middleware `0`/`1` on one driver and `false`/`true` on
     * the other — which is the kind of difference that passes the test database
     * and fails in production.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }
}
