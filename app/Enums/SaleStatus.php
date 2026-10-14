<?php

namespace App\Enums;

/**
 * Where a sale is in its lifecycle.
 *
 * Stored as the backing value, never the case name, so a rename of a case is not a
 * data migration.
 *
 * Draft and Pending are the two the sales desk creates and revises. Cancelled is
 * reachable from any of them and is final, which is why it is the only status
 * with no onward transition: cancelling a delivered shipment is not a thing the
 * back office can do, it is a refund.
 */
enum SaleStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * The status this one may become.
     *
     * Enforced in one place so the status history on a sale cannot be written
     * with a transition the desk does not allow. Cancellation is allowed from
     * anywhere except the two states it cannot be undone out of, and nothing
     * follows a delivery.
     *
     * @return list<self>
     */
    public function nextStates(): array
    {
        return match ($this) {
            self::Draft => [self::Pending, self::Cancelled],
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->nextStates(), true);
    }

    /**
     * The states a sale no longer moves out of, so the dashboard can stop
     * counting it as work in progress.
     *
     * Static because it is a fact about the enum, not about any one case, and
     * the model asks the question of the type rather than of a sale's status.
     *
     * @return list<self>
     */
    public static function settled(): array
    {
        return [self::Delivered, self::Cancelled];
    }

    /**
     * For the tables and the dashboard, where a machine value would be shown to a
     * person as-is.
     *
     * Indonesian, because this string is the status column of every sale list and
     * of the dashboard tile behind it. The backing value stays English and
     * untranslated: it is what is stored, what a migration matches on, and what
     * an API returns. Only the label a person reads is translated, so the two
     * can never drift apart the way a translated stored value would.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Pending => 'Menunggu konfirmasi',
            self::Confirmed => 'Dikonfirmasi',
            self::Shipped => 'Dikirim',
            self::Delivered => 'Diterima',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
