<?php

declare(strict_types=1);

namespace App\Support;

/** Call outcome (feedback) list for collections and the payment modes an employee can record. */
final class Outcomes
{
    /** [code, label, needs follow-up date, captures: none|promise|payment] */
    public const CODES = [
        ['CONNECTED', 'Connected (spoke to customer)', false, 'none'],
        ['CONNECTED_PTP', 'Promise to pay', true, 'promise'],
        ['PAID', 'Already paid', false, 'payment'],
        ['PART_PAID', 'Part payment received', false, 'payment'],
        ['VISIT_BRANCH', 'Will pay at branch / online', true, 'promise'],
        ['SETTLEMENT', 'Asked for settlement', true, 'none'],
        ['CALLBACK', 'Call back later', true, 'none'],
        ['THIRD_PARTY', 'Spoke to family / third party', false, 'none'],
        ['DISPUTE', 'Dispute raised', false, 'none'],
        ['REFUSED', 'Refused to pay', false, 'none'],
        ['RINGING_NO_ANSWER', 'Ringing, no answer', false, 'none'],
        ['SWITCHED_OFF', 'Switched off / out of coverage', false, 'none'],
        ['NOT_REACHABLE', 'Not reachable', false, 'none'],
        ['WRONG_NUMBER', 'Wrong number', false, 'none'],
        ['BUSY', 'Busy', false, 'none'],
        ['OTHER', 'Other', false, 'none'],
    ];

    public const PAYMENT_MODES = ['CASH' => 'Cash', 'UPI' => 'UPI', 'NEFT_IMPS' => 'NEFT / IMPS / RTGS', 'CHEQUE' => 'Cheque', 'DD' => 'Demand draft',
        'CARD' => 'Debit / credit card', 'ONLINE_LINK' => 'Payment link', 'BRANCH' => 'Paid at branch'];

    public const CAPTURES = ['none', 'promise', 'payment'];
}
