<?php

declare(strict_types=1);

namespace App\Domain\Portfolios;

/**
 * Portfolio types: what kind of work a portfolio (an uploaded file) is for. Every type shares the core case fields
 * (ID, name, phones, address → map pin, assignment, status, follow-up, notes); each type adds its own fields.
 *
 * Storage (one table for every type, so reports, filters and exports work the same everywhere):
 *  - customers.portfolio_type = the type key;
 *  - three typed "role" columns every report uses, whatever the type calls them:
 *      outstanding    = the case's main value (loan outstanding, declared value, deal value, fee, bill…)
 *      overdue_amount = the amount to collect now (overdue, COD, fee due, bill due…)
 *      due_date       = the scheduled date (EMI due date, delivery date, appointment, visit, close date…)
 *  - Collections keeps its loan columns (principal_os, emi_amount, dpd…);
 *  - every other field is a typed value in customers.details (JSON), validated by its kind on upload;
 *  - columns of the file that match no field are kept too: details["x:<column header>"].
 *
 * Field: [key, label, kind, aliases, extra]; kind = text|money|number|int|date|choice|phone|email; extra: col (a real
 * column), options (choice), max (int). Outcome: [code, label, follow-up date needed, captures none|promise|payment, success].
 */
final class PortfolioTypes
{
    public const DEFAULT = 'collections';

    /** The three columns every type can fill (role => column). */
    public const ROLE_COLUMNS = ['value' => 'outstanding', 'due' => 'overdue_amount', 'date' => 'due_date'];

    /** Outcomes for every type (calling results). */
    public const COMMON_OUTCOMES = [
        ['CONNECTED', 'Connected (spoke to customer)', false, 'none', false],
        ['CALLBACK', 'Call back later', true, 'none', false],
        ['THIRD_PARTY', 'Spoke to family / third party', false, 'none', false],
        ['RINGING_NO_ANSWER', 'Ringing, no answer', false, 'none', false],
        ['SWITCHED_OFF', 'Switched off / out of coverage', false, 'none', false],
        ['NOT_REACHABLE', 'Not reachable', false, 'none', false],
        ['WRONG_NUMBER', 'Wrong number', false, 'none', false],
        ['BUSY', 'Busy', false, 'none', false],
        ['OTHER', 'Other', false, 'none', false],
    ];

    public const TYPES = [
        'collections' => [
            'label' => 'Collections & Recovery', 'case' => 'Customer', 'value' => 'Total outstanding', 'due' => 'Overdue amount', 'date' => 'Next due date',
            'amount' => 'Collected', 'success' => 'Payments', 'hint' => 'Loan / card dues: outstanding, overdue, EMI, DPD.',
            'fields' => [
                ['loan_amount', 'Loan Amount', 'money', ['loan amount', 'loan amt', 'sanctioned amount', 'sanction amount', 'disbursed amount', 'finance amount'], ['col' => 'loan_amount']],
                ['outstanding', 'Total Outstanding', 'money', ['total outstanding', 'outstanding', 'tos', 'outstanding amount', 'total due', 'balance'], ['col' => 'outstanding']],
                ['principal_os', 'Principal Outstanding', 'money', ['principal outstanding', 'pos', 'principal os', 'principal'], ['col' => 'principal_os']],
                ['interest_os', 'Interest Outstanding', 'money', ['interest outstanding', 'interest os', 'interest'], ['col' => 'interest_os']],
                ['charges', 'Charges', 'money', ['charges', 'late fee', 'late fees', 'penalty', 'other charges', 'bounce charges'], ['col' => 'charges']],
                ['overdue_amount', 'Overdue Amount', 'money', ['overdue amount', 'overdue', 'total overdue', 'amount overdue', 'arrears', 'od amount'], ['col' => 'overdue_amount']],
                ['emi_amount', 'EMI Amount', 'money', ['emi', 'emi amount', 'installment amount', 'instalment amount'], ['col' => 'emi_amount']],
                ['due_date', 'Due Date', 'date', ['due date', 'emi date', 'next due date', 'installment due date', 'instalment due date'], ['col' => 'due_date']],
                ['oldest_due_date', 'Oldest Due Date', 'date', ['oldest due date', 'oldest unpaid date', 'first unpaid date', 'default date'], ['col' => 'oldest_due_date']],
                ['dpd', 'DPD', 'int', ['dpd', 'days past due', 'days overdue', 'overdue days'], ['col' => 'dpd', 'max' => 20000]],
                ['overdue_installments', 'Overdue EMIs', 'int', ['overdue installments', 'overdue instalments', 'overdue emis', 'emis overdue', 'pending emis'], ['col' => 'overdue_installments', 'max' => 1000]],
                ['last_paid_on', 'Last Payment Date', 'date', ['last payment date', 'last paid date', 'last paid on', 'lpd'], ['col' => 'last_paid_on']],
                ['last_paid_amount', 'Last Payment Amount', 'money', ['last payment amount', 'last paid amount', 'lpa'], ['col' => 'last_paid_amount']],
            ],
            'outcomes' => [
                ['CONNECTED_PTP', 'Promise to pay', true, 'promise', false],
                ['PAID', 'Already paid', false, 'payment', true],
                ['PART_PAID', 'Part payment received', false, 'payment', true],
                ['VISIT_BRANCH', 'Will pay at branch / online', true, 'promise', false],
                ['SETTLEMENT', 'Asked for settlement', true, 'none', false],
                ['DISPUTE', 'Dispute raised', false, 'none', false],
                ['REFUSED', 'Refused to pay', false, 'none', false],
            ],
        ],
        'courier' => [
            'label' => 'Parcel Delivery / Courier', 'case' => 'Consignee', 'value' => 'Declared value', 'due' => 'COD amount', 'date' => 'Delivery date',
            'amount' => 'COD collected', 'success' => 'Delivered', 'hint' => 'Shipments to deliver or pick up: AWB, COD amount, delivery date.',
            'fields' => [
                ['awb_no', 'AWB / Tracking No', 'text', ['awb', 'awb no', 'awb number', 'tracking no', 'tracking number', 'docket no', 'consignment no', 'waybill']],
                ['order_no', 'Order No', 'text', ['order no', 'order number', 'order id', 'reference no']],
                ['shipment_type', 'Shipment Type', 'choice', ['shipment type', 'type', 'job type'], ['options' => ['Delivery', 'Pickup', 'Return pickup', 'Exchange']]],
                ['payment_type', 'Payment Type', 'choice', ['payment type', 'payment mode', 'cod/prepaid', 'prepaid/cod'], ['options' => ['COD', 'Prepaid']]],
                ['cod_amount', 'COD Amount', 'money', ['cod amount', 'cod', 'cash to collect', 'collectable amount', 'amount to collect'], ['col' => 'overdue_amount']],
                ['declared_value', 'Declared Value', 'money', ['declared value', 'invoice value', 'shipment value', 'value'], ['col' => 'outstanding']],
                ['delivery_date', 'Delivery Date', 'date', ['delivery date', 'expected delivery', 'edd', 'scheduled date', 'pickup date'], ['col' => 'due_date']],
                ['time_slot', 'Time Slot', 'text', ['time slot', 'slot', 'delivery slot', 'delivery time']],
                ['packages', 'Packages', 'int', ['packages', 'pieces', 'no of pieces', 'boxes', 'qty'], ['max' => 10000]],
                ['weight_kg', 'Weight (kg)', 'number', ['weight', 'weight kg', 'weight (kg)']],
                ['contents', 'Contents', 'text', ['contents', 'item', 'product', 'description']],
                ['seller', 'Seller / Shipper', 'text', ['seller', 'shipper', 'client', 'merchant', 'vendor']],
                ['instructions', 'Delivery Instructions', 'text', ['instructions', 'delivery instructions', 'special instructions']],
            ],
            'outcomes' => [
                ['DLV_DELIVERED_COD', 'Delivered, COD collected', false, 'payment', true],
                ['DLV_DELIVERED', 'Delivered (prepaid)', false, 'none', true],
                ['DLV_PICKED_UP', 'Picked up', false, 'none', true],
                ['DLV_RESCHEDULED', 'Customer asked for another day', true, 'none', false],
                ['DLV_NOT_AVAILABLE', 'Customer not available', true, 'none', false],
                ['DLV_DOOR_LOCKED', 'Door locked', true, 'none', false],
                ['DLV_ADDRESS_ISSUE', 'Address not found / incomplete', false, 'none', false],
                ['DLV_REFUSED', 'Refused delivery', false, 'none', false],
                ['DLV_CASH_NOT_READY', 'COD amount not ready', true, 'none', false],
                ['DLV_DAMAGED', 'Shipment damaged', false, 'none', false],
                ['DLV_RTO', 'Return to origin (RTO)', false, 'none', false],
            ],
        ],
        'sales' => [
            'label' => 'Sales', 'case' => 'Lead', 'value' => 'Deal value', 'due' => 'Order value', 'date' => 'Expected close date',
            'amount' => 'Order value', 'success' => 'Orders won', 'hint' => 'Leads to convert: product, stage, deal value, close date.',
            'fields' => [
                ['company_name', 'Company', 'text', ['company', 'company name', 'organisation', 'organization', 'firm', 'business name']],
                ['designation', 'Designation', 'text', ['designation', 'title', 'job title', 'role']],
                ['product', 'Product / Service', 'text', ['product', 'service', 'product interested', 'interested in', 'plan']],
                ['lead_source', 'Lead Source', 'text', ['lead source', 'source', 'channel', 'enquiry source']],
                ['stage', 'Stage', 'choice', ['stage', 'lead stage', 'pipeline stage', 'lead status'], ['options' => ['New', 'Contacted', 'Interested', 'Demo / visit done', 'Quotation sent', 'Negotiation', 'Won', 'Lost']]],
                ['deal_value', 'Deal Value', 'money', ['deal value', 'expected value', 'opportunity value', 'budget', 'potential value'], ['col' => 'outstanding']],
                ['quantity', 'Quantity', 'int', ['quantity', 'qty', 'units', 'seats', 'users'], ['max' => 1000000]],
                ['expected_close_date', 'Expected Close Date', 'date', ['expected close date', 'close date', 'closing date', 'expected date'], ['col' => 'due_date']],
                ['last_contacted_on', 'Last Contacted On', 'date', ['last contacted', 'last contact date', 'last call date']],
            ],
            'outcomes' => [
                ['SAL_INTERESTED', 'Interested, follow up', true, 'none', false],
                ['SAL_DEMO_FIXED', 'Demo / visit fixed', true, 'none', false],
                ['SAL_QUOTE_SENT', 'Quotation sent', true, 'none', false],
                ['SAL_NEGOTIATION', 'In negotiation', true, 'none', false],
                ['SAL_ORDER_WON', 'Order won', false, 'payment', true],
                ['SAL_BUDGET_ISSUE', 'No budget now', true, 'none', false],
                ['SAL_BOUGHT_ELSEWHERE', 'Bought elsewhere', false, 'none', false],
                ['SAL_NOT_INTERESTED', 'Not interested', false, 'none', false],
            ],
        ],
        'marketing' => [
            'label' => 'Marketing', 'case' => 'Contact', 'value' => 'Expected value', 'due' => 'Amount received', 'date' => 'Event / offer date',
            'amount' => 'Amount received', 'success' => 'Registered / converted', 'hint' => 'Campaign contacts: offer, channel, segment, event date.',
            'fields' => [
                ['offer', 'Offer / Scheme', 'text', ['offer', 'scheme', 'promotion', 'promo']],
                ['product', 'Product', 'text', ['product', 'service', 'brand']],
                ['channel', 'Channel', 'choice', ['channel', 'source', 'medium'], ['options' => ['Call', 'Field visit', 'WhatsApp', 'SMS', 'Email', 'Event', 'Walk-in']]],
                ['segment', 'Segment', 'text', ['segment', 'category', 'customer type', 'audience']],
                ['company_name', 'Company', 'text', ['company', 'company name', 'organisation', 'shop name', 'outlet']],
                ['event_date', 'Event / Offer Date', 'date', ['event date', 'offer date', 'campaign date', 'valid till', 'offer valid till'], ['col' => 'due_date']],
                ['expected_value', 'Expected Value', 'money', ['expected value', 'potential', 'target value'], ['col' => 'outstanding']],
                ['consent', 'Consent', 'choice', ['consent', 'opt in', 'dnd'], ['options' => ['Yes', 'No']]],
            ],
            'outcomes' => [
                ['MKT_INTERESTED', 'Interested, follow up', true, 'none', false],
                ['MKT_MEETING_FIXED', 'Meeting / visit fixed', true, 'none', false],
                ['MKT_INFO_SHARED', 'Details shared', false, 'none', false],
                ['MKT_REGISTERED', 'Registered / signed up', false, 'none', true],
                ['MKT_PURCHASED', 'Purchased (amount received)', false, 'payment', true],
                ['MKT_NOT_INTERESTED', 'Not interested', false, 'none', false],
                ['MKT_DO_NOT_CONTACT', 'Do not contact again', false, 'none', false],
            ],
        ],
        'healthcare' => [
            'label' => 'Health Care / Pharma', 'case' => 'Patient / doctor', 'value' => 'Order value', 'due' => 'Amount due', 'date' => 'Visit / appointment date',
            'amount' => 'Amount collected', 'success' => 'Visits & orders', 'hint' => 'Patients, doctors, chemists: visits, prescriptions, orders, refills.',
            'fields' => [
                ['contact_type', 'Contact Type', 'choice', ['contact type', 'type', 'category'], ['options' => ['Patient', 'Doctor', 'Chemist / pharmacy', 'Hospital / clinic', 'Distributor']]],
                ['speciality', 'Speciality', 'text', ['speciality', 'specialty', 'department']],
                ['hospital', 'Hospital / Clinic', 'text', ['hospital', 'clinic', 'hospital name', 'clinic name', 'pharmacy']],
                ['doctor_name', 'Doctor', 'text', ['doctor', 'doctor name', 'consultant', 'referred by']],
                ['age', 'Age', 'int', ['age'], ['max' => 130]],
                ['gender', 'Gender', 'choice', ['gender', 'sex'], ['options' => ['Male', 'Female', 'Other']]],
                ['product', 'Medicine / Product', 'text', ['medicine', 'product', 'drug', 'brand', 'test', 'service']],
                ['prescription_no', 'Prescription / Order No', 'text', ['prescription no', 'rx no', 'order no', 'bill no']],
                ['order_value', 'Order Value', 'money', ['order value', 'bill amount', 'invoice amount', 'value'], ['col' => 'outstanding']],
                ['amount_due', 'Amount Due', 'money', ['amount due', 'balance due', 'due amount', 'pending amount'], ['col' => 'overdue_amount']],
                ['visit_date', 'Visit / Appointment Date', 'date', ['visit date', 'appointment date', 'appointment', 'next visit'], ['col' => 'due_date']],
                ['refill_date', 'Next Refill Date', 'date', ['refill date', 'next refill', 'refill due']],
            ],
            'outcomes' => [
                ['HC_VISIT_DONE', 'Visit done', false, 'none', true],
                ['HC_APPOINTMENT_FIXED', 'Appointment fixed', true, 'none', false],
                ['HC_ORDER_PLACED', 'Order placed / amount collected', false, 'payment', true],
                ['HC_SAMPLE_COLLECTED', 'Sample collected', false, 'none', true],
                ['HC_REFILL_DUE', 'Refill due, follow up', true, 'none', false],
                ['HC_DOCTOR_BUSY', 'Doctor / patient not available', true, 'none', false],
                ['HC_NOT_INTERESTED', 'Not interested', false, 'none', false],
            ],
        ],
        'nursing' => [
            'label' => 'Nursing', 'case' => 'Patient', 'value' => 'Package amount', 'due' => 'Amount due', 'date' => 'Next visit date',
            'amount' => 'Amount collected', 'success' => 'Visits done', 'hint' => 'Home nursing and care visits: condition, care plan, next visit.',
            'fields' => [
                ['age', 'Age', 'int', ['age', 'patient age'], ['max' => 130]],
                ['gender', 'Gender', 'choice', ['gender', 'sex'], ['options' => ['Male', 'Female', 'Other']]],
                ['condition', 'Condition', 'text', ['condition', 'diagnosis', 'illness', 'medical condition']],
                ['care_type', 'Care Type', 'choice', ['care type', 'service', 'service type', 'care'], ['options' => ['Injection / IV', 'Wound dressing', 'Elderly care', 'Post-surgery care', 'Physiotherapy', 'ICU at home', 'Other']]],
                ['shift', 'Shift', 'choice', ['shift', 'duty', 'timing'], ['options' => ['Visit', 'Day (12 h)', 'Night (12 h)', '24 h']]],
                ['visit_frequency', 'Visit Frequency', 'text', ['frequency', 'visit frequency', 'visits per week']],
                ['doctor_name', 'Doctor', 'text', ['doctor', 'doctor name', 'consultant']],
                ['emergency_contact', 'Emergency Contact', 'phone', ['emergency contact', 'emergency number', 'relative number', 'attendant number']],
                ['package_amount', 'Package Amount', 'money', ['package amount', 'package', 'plan amount', 'fees'], ['col' => 'outstanding']],
                ['amount_due', 'Amount Due', 'money', ['amount due', 'balance due', 'due amount', 'pending amount'], ['col' => 'overdue_amount']],
                ['visit_date', 'Next Visit Date', 'date', ['next visit', 'visit date', 'start date', 'service date'], ['col' => 'due_date']],
            ],
            'outcomes' => [
                ['NUR_VISIT_DONE', 'Visit done', false, 'none', true],
                ['NUR_VITALS_RECORDED', 'Visit done, vitals recorded', false, 'none', true],
                ['NUR_PAYMENT_COLLECTED', 'Payment collected', false, 'payment', true],
                ['NUR_RESCHEDULED', 'Visit rescheduled', true, 'none', false],
                ['NUR_FAMILY_NOT_AVAILABLE', 'Family / patient not available', true, 'none', false],
                ['NUR_ESCALATED', 'Patient unwell, escalated to doctor', false, 'none', false],
                ['NUR_SERVICE_STOPPED', 'Service stopped', false, 'none', false],
            ],
        ],
        'education' => [
            'label' => 'Education', 'case' => 'Student', 'value' => 'Total fee', 'due' => 'Fee due', 'date' => 'Fee due date',
            'amount' => 'Fee collected', 'success' => 'Admissions & fees', 'hint' => 'Admissions and fee follow-up: course, parent, fee due.',
            'fields' => [
                ['student_name', 'Student Name', 'text', ['student', 'student name', 'child name', 'candidate']],
                ['parent_name', 'Parent / Guardian', 'text', ['parent', 'parent name', 'father name', 'mother name', 'guardian']],
                ['course', 'Class / Course', 'text', ['class', 'course', 'grade', 'standard', 'program', 'programme']],
                ['admission_no', 'Admission / Enquiry No', 'text', ['admission no', 'enrollment no', 'enrolment no', 'enquiry no', 'roll no']],
                ['academic_year', 'Academic Year', 'text', ['academic year', 'session', 'batch', 'year']],
                ['lead_source', 'Source', 'text', ['source', 'lead source', 'enquiry source']],
                ['total_fee', 'Total Fee', 'money', ['total fee', 'fee', 'fees', 'course fee', 'annual fee'], ['col' => 'outstanding']],
                ['fee_due', 'Fee Due', 'money', ['fee due', 'balance fee', 'pending fee', 'due amount', 'amount due'], ['col' => 'overdue_amount']],
                ['due_date', 'Fee Due Date', 'date', ['fee due date', 'due date', 'last date'], ['col' => 'due_date']],
            ],
            'outcomes' => [
                ['EDU_INTERESTED', 'Interested, follow up', true, 'none', false],
                ['EDU_CAMPUS_VISIT', 'Campus visit / counselling fixed', true, 'none', false],
                ['EDU_ADMISSION_DONE', 'Admission done', false, 'payment', true],
                ['EDU_FEE_PAID', 'Fee paid', false, 'payment', true],
                ['EDU_FEE_PROMISE', 'Promised to pay fee', true, 'promise', false],
                ['EDU_JOINED_ELSEWHERE', 'Joined elsewhere', false, 'none', false],
                ['EDU_NOT_INTERESTED', 'Not interested', false, 'none', false],
            ],
        ],
        'service' => [
            'label' => 'Service', 'case' => 'Customer', 'value' => 'Contract value', 'due' => 'Service charge', 'date' => 'Visit date',
            'amount' => 'Charges collected', 'success' => 'Jobs completed', 'hint' => 'Installation, repair and AMC visits: ticket, product, issue, visit date.',
            'fields' => [
                ['ticket_no', 'Ticket / Job No', 'text', ['ticket', 'ticket no', 'job no', 'complaint no', 'service request', 'sr no']],
                ['product', 'Product / Equipment', 'text', ['product', 'equipment', 'model', 'appliance', 'machine']],
                ['serial_no', 'Serial No', 'text', ['serial no', 'serial number', 'imei']],
                ['issue', 'Issue / Work', 'text', ['issue', 'problem', 'complaint', 'work', 'job description']],
                ['priority', 'Priority', 'choice', ['priority', 'urgency'], ['options' => ['Low', 'Normal', 'High', 'Critical']]],
                ['warranty', 'Warranty', 'choice', ['warranty', 'warranty status', 'coverage'], ['options' => ['In warranty', 'Out of warranty', 'AMC']]],
                ['contract_value', 'Contract Value', 'money', ['contract value', 'amc value', 'value'], ['col' => 'outstanding']],
                ['service_charge', 'Service Charge', 'money', ['service charge', 'charges', 'amount to collect', 'visit charge'], ['col' => 'overdue_amount']],
                ['visit_date', 'Visit Date', 'date', ['visit date', 'appointment date', 'scheduled date', 'service date'], ['col' => 'due_date']],
            ],
            'outcomes' => [
                ['SRV_COMPLETED', 'Job completed', false, 'none', true],
                ['SRV_COMPLETED_PAID', 'Job completed, charges collected', false, 'payment', true],
                ['SRV_PART_REQUIRED', 'Part required, revisit', true, 'none', false],
                ['SRV_RESCHEDULED', 'Visit rescheduled', true, 'none', false],
                ['SRV_NOT_AVAILABLE', 'Customer not available', true, 'none', false],
                ['SRV_ESCALATED', 'Escalated to senior / company', false, 'none', false],
                ['SRV_CANCELLED', 'Request cancelled', false, 'none', false],
            ],
        ],
        'custom' => [
            'label' => 'Custom', 'case' => 'Contact', 'value' => 'Amount', 'due' => 'Amount to collect', 'date' => 'Scheduled date',
            'amount' => 'Amount received', 'success' => 'Done', 'hint' => 'Any other work: every column of your file is kept, with its own name.',
            'fields' => [
                ['amount', 'Amount', 'money', ['amount', 'value', 'total'], ['col' => 'outstanding']],
                ['amount_due', 'Amount to Collect', 'money', ['amount to collect', 'amount due', 'due amount', 'balance'], ['col' => 'overdue_amount']],
                ['scheduled_date', 'Scheduled Date', 'date', ['scheduled date', 'date', 'due date', 'visit date'], ['col' => 'due_date']],
            ],
            'outcomes' => [
                ['CUS_DONE', 'Done / successful', false, 'none', true],
                ['CUS_DONE_PAID', 'Done, amount received', false, 'payment', true],
                ['CUS_INTERESTED', 'Interested, follow up', true, 'none', false],
                ['CUS_RESCHEDULED', 'Rescheduled', true, 'none', false],
                ['CUS_NOT_INTERESTED', 'Not interested', false, 'none', false],
            ],
        ],
    ];

    public static function exists(?string $type): bool
    {
        return $type !== null && isset(self::TYPES[$type]);
    }

    public static function of(?string $type): array
    {
        return self::TYPES[self::exists($type) ? $type : self::DEFAULT];
    }

    /** @return list<array{key: string, label: string, kind: string, aliases: list<string>, col: ?string, options: ?list<string>, max: ?int}> */
    public static function fields(?string $type): array
    {
        return array_map(fn ($f) => ['key' => $f[0], 'label' => $f[1], 'kind' => $f[2], 'aliases' => $f[3], 'col' => $f[4]['col'] ?? null,
            'options' => $f[4]['options'] ?? null, 'max' => $f[4]['max'] ?? null], self::of($type)['fields']);
    }

    /** Labels the console and the phone use for this type. */
    public static function labels(?string $type): array
    {
        $t = self::of($type);
        return ['type' => self::exists($type) ? $type : self::DEFAULT, 'label' => $t['label'], 'case' => $t['case'], 'value' => $t['value'], 'due' => $t['due'],
            'date' => $t['date'], 'amount' => $t['amount'], 'success' => $t['success']];
    }

    /** For the console's type picker. */
    public static function list(): array
    {
        return array_values(array_map(fn ($k) => self::labels($k) + ['hint' => self::TYPES[$k]['hint'],
            'fields' => array_map(fn ($f) => ['key' => $f['key'], 'label' => $f['label'], 'kind' => $f['kind'], 'options' => $f['options']], self::fields($k))], array_keys(self::TYPES)));
    }

    /**
     * Every outcome code: common ones (type null) and each type's own. Collections keeps its original codes.
     * @return list<array{code: string, label: string, follow_up: bool, captures: string, success: bool, type: ?string}>
     */
    public static function outcomes(): array
    {
        $out = [];
        foreach (self::COMMON_OUTCOMES as $o) {
            $out[] = ['code' => $o[0], 'label' => $o[1], 'follow_up' => $o[2], 'captures' => $o[3], 'success' => $o[4], 'type' => null];
        }
        foreach (self::TYPES as $key => $t) {
            foreach ($t['outcomes'] as $o) {
                $out[] = ['code' => $o[0], 'label' => $o[1], 'follow_up' => $o[2], 'captures' => $o[3], 'success' => $o[4], 'type' => $key];
            }
        }
        return $out;
    }

    /** Value of one field on a customer (role/loan column, else details). */
    public static function valueOf(object $customer, array $field): mixed
    {
        if ($field['col'] !== null) {
            $v = $customer->{$field['col']} ?? null;
            return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
        }
        return ((array) ($customer->details ?? []))[$field['key']] ?? null;
    }

    /**
     * The type's fields with a value, then the file's own extra columns — ready to show (console, phone, exports).
     * @return list<array{key: string, label: string, kind: string, value: mixed, text: string}>
     */
    public static function present(object $customer): array
    {
        $out = [];
        $type = $customer->portfolio_type ?? self::DEFAULT;
        foreach (self::fields($type) as $f) {
            if ($f['key'] === 'dpd') {
                continue;   // collections: shown as days overdue, worked out from the oldest due date
            }
            $v = self::valueOf($customer, $f);
            if ($v !== null && $v !== '') {
                $out[] = ['key' => $f['key'], 'label' => $f['label'], 'kind' => $f['kind'], 'value' => $v, 'text' => self::text($f['kind'], $v)];
            }
        }
        foreach ((array) ($customer->details ?? []) as $k => $v) {
            if (str_starts_with((string) $k, 'x:') && $v !== null && $v !== '') {
                $out[] = ['key' => $k, 'label' => substr((string) $k, 2), 'kind' => 'text', 'value' => $v, 'text' => (string) $v];
            }
        }
        return $out;
    }

    public static function text(string $kind, mixed $v): string
    {
        return match ($kind) {
            'money' => '₹' . number_format((float) $v, fmod((float) $v, 1.0) ? 2 : 0),
            'date' => ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $v, 0, 10))) ? $d->format('d M Y') : (string) $v,
            'number' => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'),
            default => (string) $v,
        };
    }
}
