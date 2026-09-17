<?php

namespace App\Enums;

/**
 * 2026-09-16 — WHERE THE PARCEL IS.
 *
 * ── WHY THIS DID NOT EXIST BEFORE ──
 *
 * `products.requires_shipping` and the three `orders.shipping_*` columns have
 * been here since ADR-033: the customer is asked for an address on the payment
 * page and it is stored. Nothing ever read it back. No screen in the admin
 * console displayed it, there was no tracking number, and no way to say a
 * parcel had gone — the address went into the database and stopped.
 *
 * That was survivable while we shipped nothing. It stops being survivable the
 * moment a SUPPLIER ships on our behalf (owner, 2026-09-16: "supplier เป็นคน
 * ส่งเอง"), because now somebody outside this company needs to be told what to
 * send and where, and somebody inside it needs to know whether they did.
 *
 * ── THREE STATES, NOT FOUR ──
 *
 * No "cancelled". A parcel that is not going is an ORDER that is not going,
 * and orders already have a status for that. A second cancellation flag would
 * let the two disagree, and then no screen could say which one was true.
 *
 * `Delivered` here means WE SENT IT and the courier says it arrived — it is
 * not a customer confirmation, because nothing in this system asks the
 * customer. Naming it anything stronger would overstate what we know.
 */
enum ShippingStatus: string
{
    /** Nothing has been sent. The state every shippable order starts in. */
    case Pending = 'pending';

    /** The supplier handed it to a courier and recorded the tracking number. */
    case Shipped = 'shipped';

    /** Confirmed as arrived. */
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'รอจัดส่ง',
            self::Shipped => 'จัดส่งแล้ว',
            self::Delivered => 'ได้รับแล้ว',
        };
    }

    /**
     * Has the parcel left?
     *
     * The predicate SupplierReleaseTrigger::OnDelivered pays out on. Both
     * Shipped and Delivered count: the supplier has done their part at the
     * moment the parcel is handed over, and holding their money hostage to a
     * courier's scan — which nothing in this system reads automatically —
     * would mean it never releases at all.
     */
    public function hasLeft(): bool
    {
        return $this !== self::Pending;
    }
}
