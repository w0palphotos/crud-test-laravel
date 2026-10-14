<?php

namespace App\Rules;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The one definition of a valid credential pair, used by every door that accepts
 * an email or a password.
 *
 * These rules exist in five places — web registration, API registration, account
 * updates, token issuance and the interactive admin command — and every one of
 * them is a way in. Left to their own devices they drift, and the door that
 * drifts is the one nobody reads: registration accepts a nine-character password
 * for a year before someone notices the policy is only enforced elsewhere.
 *
 * So the policy lives here, and the five callers name it.
 */
final class AccountValidation
{
    /**
     * How short a password may be.
     *
     * Twelve rather than eight because this is the only numeric floor that does
     * real work: length is what resists guessing, and every other rule here is
     * about not choosing something already public.
     */
    public const PASSWORD_MIN_LENGTH = 12;

    /**
     * The email rules, without uniqueness.
     *
     * `email:rfc` rather than the bare rule, which is the same check made
     * explicit. The `dns` variant is deliberately not used: it proves a domain
     * has a mail exchanger, which is a fact about the domain and not about
     * whether this account exists, and it makes the form depend on a DNS answer.
     */
    public static function emailRules(): array
    {
        return [
            'required',
            'string',
            'email:rfc',
            'max:255',
        ];
    }

    /**
     * The email rules plus uniqueness against the users table.
     *
     * Compared in canonical form because every caller normalises first, so the
     * rule is checking the same value that will be stored. Without that, the
     * index would be asked whether "Ada@example.com" is free while the row is
     * stored as "ada@example.com", and the answer would depend on collation.
     *
     * @param  int|null  $ignoreUserId  The caller's own id, so keeping your own
     *                                  address is not a duplicate of yourself.
     * @return list<mixed>
     */
    public static function uniqueEmailRules(?int $ignoreUserId = null): array
    {
        return [
            ...self::emailRules(),
            Rule::unique('users', 'email')->ignore($ignoreUserId),
        ];
    }

    /**
     * The password policy.
     *
     * Length, a letter and a number — and no symbol rule. The symbol requirement
     * is the one people work around rather than satisfy: it produces
     * "Password1!" reliably, which is worse than a long phrase with no symbol in
     * it at all. Twelve characters plus a number, checked against the breach
     * list below, is the stronger position and the one people can actually live
     * with.
     *
     * `uncompromised()` is here because a long password is not a safe password if
     * it is a famous one. It is a k-anonymity lookup, so only the first five
     * characters of a hash leave the process.
     */
    public static function password(): Password
    {
        return Password::min(self::PASSWORD_MIN_LENGTH)
            ->letters()
            ->numbers()
            ->uncompromised();
    }

    /**
     * Fold an email to the one form this application stores and compares it in.
     *
     * Trimmed and lowercased. The lowercase is the part that matters: a unique
     * index treats "Ada@example.com" and "ada@example.com" as one value on some
     * collations and two on others, so without this the same person could
     * register the same address twice and would then not be able to sign in with
     * either one on the database that rejected the second.
     */
    public static function normaliseEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        return mb_strtolower(trim($email));
    }
}
