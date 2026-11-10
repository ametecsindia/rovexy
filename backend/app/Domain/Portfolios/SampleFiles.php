<?php

declare(strict_types=1);

namespace App\Domain\Portfolios;

/**
 * The sample upload file of each portfolio type (Portfolios → Download sample file): the core columns, the type's own
 * columns, and five realistic rows that import cleanly as downloaded (no Caller ID / group, so nothing depends on the
 * company's people). Dates are relative to today, so the file never looks stale. No latitude / longitude: map pins come
 * from the address.
 */
final class SampleFiles
{
    public const CORE_HEADERS = ['Customer ID', 'Customer Name', 'Phone Number', 'Alternate Number', 'Email', 'Address', 'Area', 'City', 'State', 'Pincode',
        'Preferred Time', 'Campaign', 'Caller ID', 'Assigned Team', 'Notes', 'Status', 'Follow-up Date'];

    private const PLACES = [
        ['Flat 304, Sai Residency, Road No 2', 'Kondapur', '500084'],
        ['Plot 21, Ayyappa Society', 'Madhapur', '500081'],
        ['H.No 8-2-120, Lane 4', 'Gachibowli', '500032'],
        ['7-1-55, Lal Bungalow Road', 'Ameerpet', '500016'],
        ['2-3-410, Main Road', 'Secunderabad', '500003'],
    ];

    /** Per type: core name header, ID prefix, names, campaign, notes, statuses, and the type's own values per row. */
    private static function rows(string $type): array
    {
        $d = fn (int $days) => now()->addDays($days)->format('Y-m-d');
        return match ($type) {
            'collections' => ['Customer Name', 'LN-1000', ['Ravi Kumar', 'Lakshmi Devi', 'Mohammed Imran', 'Sunita Reddy', 'Venkatesh Rao'],
                ['Personal Loan', 'Personal Loan', 'Two-wheeler Loan', 'Consumer Durable', 'Credit Card'],
                ['Promised to pay by month end', 'Asked for EMI restructuring', 'Not reachable on first attempt', 'Partial payment made', 'Paying on time — reminder call only'],
                [
                    ['loan_amount' => 300000, 'outstanding' => 224500, 'principal_os' => 210000, 'interest_os' => 12000, 'charges' => 2500, 'overdue_amount' => 19000, 'emi_amount' => 9500, 'due_date' => $d(9), 'dpd' => 38, 'overdue_installments' => 2, 'last_paid_on' => $d(-38), 'last_paid_amount' => 9500],
                    ['loan_amount' => 150000, 'outstanding' => 98300, 'principal_os' => 92000, 'interest_os' => 4800, 'charges' => 1500, 'overdue_amount' => 12400, 'emi_amount' => 6200, 'due_date' => $d(14), 'dpd' => 22, 'overdue_installments' => 2, 'last_paid_on' => $d(-22), 'last_paid_amount' => 6200],
                    ['loan_amount' => 85000, 'outstanding' => 61500, 'principal_os' => 58000, 'interest_os' => 2500, 'charges' => 1000, 'overdue_amount' => 9000, 'emi_amount' => 3000, 'due_date' => $d(5), 'dpd' => 65, 'overdue_installments' => 3, 'last_paid_on' => $d(-65), 'last_paid_amount' => 3000],
                    ['loan_amount' => 40000, 'outstanding' => 18600, 'principal_os' => 17800, 'interest_os' => 600, 'charges' => 200, 'overdue_amount' => 4700, 'emi_amount' => 4700, 'due_date' => $d(20), 'dpd' => 12, 'overdue_installments' => 1, 'last_paid_on' => $d(-12), 'last_paid_amount' => 4700],
                    ['loan_amount' => 120000, 'outstanding' => 54000, 'principal_os' => 50000, 'interest_os' => 3000, 'charges' => 1000, 'overdue_amount' => 0, 'emi_amount' => 5000, 'due_date' => $d(25), 'dpd' => 0, 'overdue_installments' => 0, 'last_paid_on' => $d(-5), 'last_paid_amount' => 5000],
                ]],
            'courier' => ['Consignee Name', 'CN-2000', ['Anil Sharma', 'Priya Nair', 'Syed Ahmed', 'Kavitha Rao', 'Rahul Verma'],
                ['Flipkart Nov', 'Flipkart Nov', 'Meesho', 'Amazon Returns', 'Local B2C'],
                ['Call before coming', 'Leave with security if not home', 'Gate 2, 3rd floor', 'Return pickup — check item', 'Office address, 10 am to 6 pm'],
                [
                    ['awb_no' => 'AWB7700125501', 'order_no' => 'OD4410023', 'shipment_type' => 'Delivery', 'payment_type' => 'COD', 'cod_amount' => 1899, 'declared_value' => 1899, 'delivery_date' => $d(0), 'time_slot' => '10 am - 1 pm', 'packages' => 1, 'weight_kg' => 0.8, 'contents' => 'Mobile cover', 'seller' => 'Flipkart', 'instructions' => 'Call before coming'],
                    ['awb_no' => 'AWB7700125502', 'order_no' => 'OD4410024', 'shipment_type' => 'Delivery', 'payment_type' => 'Prepaid', 'cod_amount' => 0, 'declared_value' => 4599, 'delivery_date' => $d(0), 'time_slot' => '1 pm - 4 pm', 'packages' => 2, 'weight_kg' => 3.2, 'contents' => 'Kitchen set', 'seller' => 'Flipkart', 'instructions' => ''],
                    ['awb_no' => 'AWB7700125503', 'order_no' => 'MS998812', 'shipment_type' => 'Delivery', 'payment_type' => 'COD', 'cod_amount' => 649, 'declared_value' => 649, 'delivery_date' => $d(1), 'time_slot' => 'Any time', 'packages' => 1, 'weight_kg' => 0.5, 'contents' => 'Kurti', 'seller' => 'Meesho', 'instructions' => ''],
                    ['awb_no' => 'AWB7700125504', 'order_no' => 'AMZ-RT-5521', 'shipment_type' => 'Return pickup', 'payment_type' => 'Prepaid', 'cod_amount' => 0, 'declared_value' => 2199, 'delivery_date' => $d(1), 'time_slot' => '10 am - 1 pm', 'packages' => 1, 'weight_kg' => 1.1, 'contents' => 'Shoes', 'seller' => 'Amazon', 'instructions' => 'Check item before pickup'],
                    ['awb_no' => 'AWB7700125505', 'order_no' => 'LB-7781', 'shipment_type' => 'Delivery', 'payment_type' => 'COD', 'cod_amount' => 12500, 'declared_value' => 12500, 'delivery_date' => $d(2), 'time_slot' => '10 am - 6 pm', 'packages' => 1, 'weight_kg' => 6, 'contents' => 'Microwave oven', 'seller' => 'Sri Electronics', 'instructions' => 'Office hours only'],
                ]],
            'sales' => ['Lead Name', 'LD-3000', ['Srinivas Reddy', 'Meena Iyer', 'Farhan Ali', 'Deepa Joshi', 'Karthik Menon'],
                ['ERP Q4', 'ERP Q4', 'Website enquiries', 'Expo leads', 'Referrals'],
                ['Wants a demo for 25 users', 'Comparing two vendors', 'Asked for price list', 'Decision after board meeting', 'Referred by existing client'],
                [
                    ['company_name' => 'Reddy Constructions', 'designation' => 'Director', 'product' => 'ERP — 25 users', 'lead_source' => 'Website', 'stage' => 'Interested', 'deal_value' => 375000, 'quantity' => 25, 'expected_close_date' => $d(20), 'last_contacted_on' => $d(-3)],
                    ['company_name' => 'Iyer Textiles', 'designation' => 'Accounts Head', 'product' => 'ERP — 10 users', 'lead_source' => 'Expo', 'stage' => 'Quotation sent', 'deal_value' => 150000, 'quantity' => 10, 'expected_close_date' => $d(12), 'last_contacted_on' => $d(-1)],
                    ['company_name' => 'Ali Traders', 'designation' => 'Owner', 'product' => 'Billing software', 'lead_source' => 'Website', 'stage' => 'New', 'deal_value' => 25000, 'quantity' => 2, 'expected_close_date' => $d(30), 'last_contacted_on' => ''],
                    ['company_name' => 'Joshi Pharma', 'designation' => 'CFO', 'product' => 'ERP — 50 users', 'lead_source' => 'Expo', 'stage' => 'Negotiation', 'deal_value' => 720000, 'quantity' => 50, 'expected_close_date' => $d(7), 'last_contacted_on' => $d(-2)],
                    ['company_name' => 'Menon Logistics', 'designation' => 'IT Manager', 'product' => 'Fleet module', 'lead_source' => 'Referral', 'stage' => 'Demo / visit done', 'deal_value' => 180000, 'quantity' => 15, 'expected_close_date' => $d(15), 'last_contacted_on' => $d(-5)],
                ]],
            'marketing' => ['Contact Name', 'MK-4000', ['Ramesh Gupta', 'Anitha Krishnan', 'Imtiaz Khan', 'Swathi Patil', 'Naveen Kumar'],
                ['Diwali Offer', 'Diwali Offer', 'New Store Launch', 'Loyalty Members', 'Society Activation'],
                ['Bought last season', 'Prefers WhatsApp', 'Shop owner — bulk interest', 'Asked for brochure', 'Society secretary'],
                [
                    ['offer' => 'Flat 20% off', 'product' => 'Home appliances', 'channel' => 'Call', 'segment' => 'Repeat customer', 'company_name' => '', 'event_date' => $d(5), 'expected_value' => 15000, 'consent' => 'Yes'],
                    ['offer' => 'Flat 20% off', 'product' => 'Home appliances', 'channel' => 'WhatsApp', 'segment' => 'New', 'company_name' => '', 'event_date' => $d(5), 'expected_value' => 8000, 'consent' => 'Yes'],
                    ['offer' => 'Launch day invite', 'product' => 'Store launch', 'channel' => 'Field visit', 'segment' => 'Retailer', 'company_name' => 'Khan General Stores', 'event_date' => $d(9), 'expected_value' => 50000, 'consent' => 'Yes'],
                    ['offer' => 'Double points', 'product' => 'Loyalty', 'channel' => 'SMS', 'segment' => 'Gold member', 'company_name' => '', 'event_date' => $d(3), 'expected_value' => 5000, 'consent' => 'Yes'],
                    ['offer' => 'Free demo stall', 'product' => 'Water purifier', 'channel' => 'Event', 'segment' => 'Housing society', 'company_name' => 'Green Meadows RWA', 'event_date' => $d(12), 'expected_value' => 60000, 'consent' => 'Yes'],
                ]],
            'healthcare' => ['Patient / Doctor Name', 'HC-5000', ['Dr. Suresh Babu', 'Kamala Devi', 'Dr. Ayesha Fatima', 'MedPlus Kondapur', 'Gopal Rao'],
                ['Cardio range', 'Home sample collection', 'Cardio range', 'Chemist orders', 'Refill reminders'],
                ['Prefers visits after 4 pm', 'Fasting sample, morning slot', 'New clinic opened', 'Monthly order', 'BP medicines every month'],
                [
                    ['contact_type' => 'Doctor', 'speciality' => 'Cardiology', 'hospital' => 'Sunshine Hospital', 'doctor_name' => '', 'age' => '', 'gender' => '', 'product' => 'Atorvastatin 10 mg', 'prescription_no' => '', 'order_value' => 0, 'amount_due' => 0, 'visit_date' => $d(1), 'refill_date' => ''],
                    ['contact_type' => 'Patient', 'speciality' => '', 'hospital' => '', 'doctor_name' => 'Dr. Ramesh', 'age' => 62, 'gender' => 'Female', 'product' => 'Full body check-up', 'prescription_no' => 'LAB-22019', 'order_value' => 1499, 'amount_due' => 1499, 'visit_date' => $d(1), 'refill_date' => ''],
                    ['contact_type' => 'Doctor', 'speciality' => 'General medicine', 'hospital' => 'Fatima Clinic', 'doctor_name' => '', 'age' => '', 'gender' => '', 'product' => 'Cardio range', 'prescription_no' => '', 'order_value' => 0, 'amount_due' => 0, 'visit_date' => $d(2), 'refill_date' => ''],
                    ['contact_type' => 'Chemist / pharmacy', 'speciality' => '', 'hospital' => 'MedPlus', 'doctor_name' => '', 'age' => '', 'gender' => '', 'product' => 'Monthly stock', 'prescription_no' => 'PO-8812', 'order_value' => 42000, 'amount_due' => 12000, 'visit_date' => $d(3), 'refill_date' => ''],
                    ['contact_type' => 'Patient', 'speciality' => '', 'hospital' => '', 'doctor_name' => 'Dr. Suresh Babu', 'age' => 58, 'gender' => 'Male', 'product' => 'Telmisartan 40 mg', 'prescription_no' => 'RX-55102', 'order_value' => 680, 'amount_due' => 680, 'visit_date' => $d(4), 'refill_date' => $d(4)],
                ]],
            'nursing' => ['Patient Name', 'NR-6000', ['Sarojini Devi', 'Prakash Rao', 'Mary Thomas', 'Abdul Rahman', 'Lalitha Kumari'],
                ['Home care Nov', 'Home care Nov', 'Post-surgery', 'Elderly care', 'Physio at home'],
                ['Diabetic — check sugar', 'Knee replacement 10 days ago', 'Daughter is the contact', 'Bedridden, needs 24 h attendant', 'Evening slot only'],
                [
                    ['age' => 74, 'gender' => 'Female', 'condition' => 'Diabetes, leg ulcer', 'care_type' => 'Wound dressing', 'shift' => 'Visit', 'visit_frequency' => 'Daily', 'doctor_name' => 'Dr. Kiran', 'emergency_contact' => '9876500011', 'package_amount' => 9000, 'amount_due' => 3000, 'visit_date' => $d(0)],
                    ['age' => 66, 'gender' => 'Male', 'condition' => 'Knee replacement', 'care_type' => 'Post-surgery care', 'shift' => 'Visit', 'visit_frequency' => 'Alternate days', 'doctor_name' => 'Dr. Anand', 'emergency_contact' => '9876500012', 'package_amount' => 12000, 'amount_due' => 6000, 'visit_date' => $d(1)],
                    ['age' => 81, 'gender' => 'Female', 'condition' => 'Hip fracture', 'care_type' => 'Elderly care', 'shift' => 'Day (12 h)', 'visit_frequency' => 'Daily', 'doctor_name' => 'Dr. Joseph', 'emergency_contact' => '9876500013', 'package_amount' => 36000, 'amount_due' => 18000, 'visit_date' => $d(0)],
                    ['age' => 79, 'gender' => 'Male', 'condition' => 'Stroke, bedridden', 'care_type' => 'Elderly care', 'shift' => '24 h', 'visit_frequency' => 'Daily', 'doctor_name' => 'Dr. Salma', 'emergency_contact' => '9876500014', 'package_amount' => 60000, 'amount_due' => 30000, 'visit_date' => $d(0)],
                    ['age' => 55, 'gender' => 'Female', 'condition' => 'Back pain', 'care_type' => 'Physiotherapy', 'shift' => 'Visit', 'visit_frequency' => '3 per week', 'doctor_name' => 'Dr. Kiran', 'emergency_contact' => '9876500015', 'package_amount' => 7500, 'amount_due' => 2500, 'visit_date' => $d(2)],
                ]],
            'education' => ['Contact Name', 'ED-7000', ['Rajesh Kumar', 'Fathima Begum', 'Sridevi Reddy', 'Mahesh Babu', 'Pooja Sharma'],
                ['Admissions 2027', 'Admissions 2027', 'Fee follow-up', 'Fee follow-up', 'Admissions 2027'],
                ['Father — prefers evening calls', 'Visited campus once', 'Second term fee pending', 'Asked for instalments', 'Sibling already studying here'],
                [
                    ['student_name' => 'Arjun Kumar', 'parent_name' => 'Rajesh Kumar', 'course' => 'Class 6', 'admission_no' => 'ENQ-2027-101', 'academic_year' => '2027-28', 'lead_source' => 'Website', 'total_fee' => 85000, 'fee_due' => 25000, 'due_date' => $d(15)],
                    ['student_name' => 'Zoya Begum', 'parent_name' => 'Fathima Begum', 'course' => 'Class 1', 'admission_no' => 'ENQ-2027-102', 'academic_year' => '2027-28', 'lead_source' => 'Walk-in', 'total_fee' => 65000, 'fee_due' => 15000, 'due_date' => $d(10)],
                    ['student_name' => 'Karthik Reddy', 'parent_name' => 'Sridevi Reddy', 'course' => 'Class 9', 'admission_no' => 'ADM-2025-334', 'academic_year' => '2026-27', 'lead_source' => '', 'total_fee' => 92000, 'fee_due' => 46000, 'due_date' => $d(-5)],
                    ['student_name' => 'Sai Teja', 'parent_name' => 'Mahesh Babu', 'course' => 'B.Com 2nd year', 'admission_no' => 'ADM-2025-512', 'academic_year' => '2026-27', 'lead_source' => '', 'total_fee' => 60000, 'fee_due' => 30000, 'due_date' => $d(3)],
                    ['student_name' => 'Riya Sharma', 'parent_name' => 'Pooja Sharma', 'course' => 'LKG', 'admission_no' => 'ENQ-2027-103', 'academic_year' => '2027-28', 'lead_source' => 'Referral', 'total_fee' => 55000, 'fee_due' => 10000, 'due_date' => $d(20)],
                ]],
            'service' => ['Customer Name', 'SR-8000', ['Naresh Goud', 'Vijaya Lakshmi', 'Hari Prasad', 'Salma Begum', 'Kiran Chowdary'],
                ['AC service Nov', 'AC service Nov', 'Water purifier AMC', 'Installation', 'Repairs'],
                ['Cooling issue since last week', 'Annual service due', 'Filter change', 'New split AC — install', 'Washing machine not spinning'],
                [
                    ['ticket_no' => 'TKT-90011', 'product' => 'Split AC 1.5 ton', 'serial_no' => 'AC15-77812', 'issue' => 'Not cooling', 'priority' => 'High', 'warranty' => 'In warranty', 'contract_value' => 0, 'service_charge' => 0, 'visit_date' => $d(0)],
                    ['ticket_no' => 'TKT-90012', 'product' => 'Window AC', 'serial_no' => 'WA-55120', 'issue' => 'Annual service', 'priority' => 'Normal', 'warranty' => 'AMC', 'contract_value' => 3500, 'service_charge' => 0, 'visit_date' => $d(1)],
                    ['ticket_no' => 'TKT-90013', 'product' => 'RO water purifier', 'serial_no' => 'RO-33019', 'issue' => 'Filter change', 'priority' => 'Normal', 'warranty' => 'AMC', 'contract_value' => 2999, 'service_charge' => 0, 'visit_date' => $d(1)],
                    ['ticket_no' => 'TKT-90014', 'product' => 'Split AC 1 ton', 'serial_no' => 'AC10-88231', 'issue' => 'New installation', 'priority' => 'Normal', 'warranty' => 'In warranty', 'contract_value' => 0, 'service_charge' => 1500, 'visit_date' => $d(2)],
                    ['ticket_no' => 'TKT-90015', 'product' => 'Washing machine', 'serial_no' => 'WM-44102', 'issue' => 'Drum not spinning', 'priority' => 'High', 'warranty' => 'Out of warranty', 'contract_value' => 0, 'service_charge' => 850, 'visit_date' => $d(0)],
                ]],
            default => ['Contact Name', 'CT-9000', ['Ashok Kumar', 'Bhavana Rao', 'Chandu Naik', 'Divya Menon', 'Eshwar Reddy'],
                ['November list', 'November list', 'November list', 'November list', 'November list'],
                ['', '', '', '', ''],
                [
                    ['amount' => 5000, 'amount_due' => 2000, 'scheduled_date' => $d(1)],
                    ['amount' => 12000, 'amount_due' => 0, 'scheduled_date' => $d(2)],
                    ['amount' => 3000, 'amount_due' => 3000, 'scheduled_date' => $d(1)],
                    ['amount' => 0, 'amount_due' => 0, 'scheduled_date' => $d(3)],
                    ['amount' => 8000, 'amount_due' => 4000, 'scheduled_date' => $d(5)],
                ]],
        };
    }

    /** Header row + rows, ready for a spreadsheet. */
    public static function table(string $type): array
    {
        $type = PortfolioTypes::exists($type) ? $type : PortfolioTypes::DEFAULT;
        [$nameHeader, $prefix, $names, $campaigns, $notes, $values] = self::rows($type);
        $fields = PortfolioTypes::fields($type);
        $head = self::CORE_HEADERS;
        $head[1] = $nameHeader;
        $head = [...$head, ...array_column($fields, 'label')];
        if ($type === 'custom') {
            $head = [...$head, 'Your Own Column', 'Another Column'];   // any other column is kept with its own name
        }
        $statuses = ['new', 'new', 'follow_up', 'in_progress', 'new'];
        $rows = [];
        foreach (self::PLACES as $i => [$address, $area, $pin]) {
            $row = [$prefix . ($i + 1), $names[$i], '98765432' . sprintf('%02d', 10 + $i), $i === 0 ? '9876501234' : '', $i % 2 ? '' : strtolower(str_replace([' ', '.'], ['.', ''], $names[$i])) . '@example.com',
                $address, $area, 'Hyderabad', 'Telangana', $pin, ['After 6 pm', '10 am - 1 pm', '2 pm - 5 pm', 'Anytime', 'After 7 pm'][$i], $campaigns[$i], '', '', $notes[$i], $statuses[$i],
                $statuses[$i] === 'new' ? '' : now()->addDays($i)->format('Y-m-d')];
            foreach ($fields as $f) {
                $row[] = (string) ($values[$i][$f['key']] ?? '');
            }
            if ($type === 'custom') {
                $row = [...$row, ['Gold', 'Silver', 'Gold', 'Bronze', 'Silver'][$i], ['North', 'West', 'North', 'East', 'South'][$i]];
            }
            $rows[] = $row;
        }
        return [$head, $rows];
    }
}
