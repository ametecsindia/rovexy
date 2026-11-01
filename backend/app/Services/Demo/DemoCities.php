<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * Demo client datasets: one entry per city. To add a city, add an entry to CITIES (key = lower-case slug) with its
 * state, region (people's names, address style), recording languages and 8–12 real localities [name, pincode, lat, lng].
 * The first locality is the branch office. Everything else (people, customers, calls, payments) is built from this.
 */
final class DemoCities
{
    public const CITIES = [
        'mumbai' => ['Mumbai', 'Maharashtra', 'MH', 'maharashtra', ['hi', 'mr', 'en'], [
            ['Andheri East', '400069', 19.1136, 72.8697], ['Goregaon West', '400062', 19.1663, 72.8526], ['Borivali West', '400092', 19.2307, 72.8567],
            ['Malad West', '400064', 19.1874, 72.8484], ['Kandivali East', '400101', 19.2047, 72.8742], ['Ghatkopar East', '400077', 19.0790, 72.9080],
            ['Mulund West', '400080', 19.1726, 72.9425], ['Chembur', '400071', 19.0522, 72.9005], ['Powai', '400076', 19.1176, 72.9060],
            ['Vikhroli West', '400083', 19.1110, 72.9280], ['Bhandup West', '400078', 19.1430, 72.9370], ['Kurla West', '400070', 19.0700, 72.8800]]],
        'pune' => ['Pune', 'Maharashtra', 'PN', 'maharashtra', ['mr', 'hi'], [
            ['Kothrud', '411038', 18.5074, 73.8077], ['Hadapsar', '411028', 18.5089, 73.9260], ['Wakad', '411057', 18.5980, 73.7650],
            ['Baner', '411045', 18.5590, 73.7868], ['Viman Nagar', '411014', 18.5679, 73.9143], ['Kharadi', '411014', 18.5515, 73.9348],
            ['Aundh', '411007', 18.5580, 73.8075], ['Pimple Saudagar', '411027', 18.5987, 73.7997], ['Katraj', '411046', 18.4529, 73.8650],
            ['Warje', '411058', 18.4800, 73.8000], ['Bibwewadi', '411037', 18.4690, 73.8650], ['Sinhagad Road', '411041', 18.4810, 73.8230]]],
        'nagpur' => ['Nagpur', 'Maharashtra', 'NG', 'maharashtra', ['mr', 'hi'], [
            ['Dharampeth', '440010', 21.1400, 79.0700], ['Sitabuldi', '440012', 21.1450, 79.0830], ['Manish Nagar', '440015', 21.0870, 79.0700],
            ['Sadar', '440001', 21.1610, 79.0820], ['Pratap Nagar', '440022', 21.1150, 79.0560], ['Wardhaman Nagar', '440008', 21.1490, 79.1310],
            ['Hingna', '440016', 21.1130, 78.9950], ['Manewada', '440024', 21.1010, 79.1050], ['Itwari', '440002', 21.1570, 79.1130], ['Trimurti Nagar', '440022', 21.1200, 79.0450]]],
        'delhi' => ['Delhi', 'Delhi', 'DL', 'north', ['hi', 'en'], [
            ['Laxmi Nagar', '110092', 28.6304, 77.2777], ['Janakpuri', '110058', 28.6219, 77.0878], ['Rohini Sector 7', '110085', 28.7158, 77.1133],
            ['Dwarka Sector 10', '110075', 28.5820, 77.0580], ['Mayur Vihar Phase 1', '110091', 28.6040, 77.2930], ['Karol Bagh', '110005', 28.6519, 77.1909],
            ['Pitampura', '110034', 28.6980, 77.1380], ['Saket', '110017', 28.5245, 77.2066], ['Uttam Nagar', '110059', 28.6210, 77.0550],
            ['Shahdara', '110032', 28.6730, 77.2890], ['Malviya Nagar', '110017', 28.5355, 77.2090], ['Lajpat Nagar', '110024', 28.5677, 77.2433]]],
        'lucknow' => ['Lucknow', 'Uttar Pradesh', 'LK', 'north', ['hi', 'en'], [
            ['Gomti Nagar', '226010', 26.8500, 81.0060], ['Aliganj', '226024', 26.8900, 80.9390], ['Indira Nagar', '226016', 26.8820, 80.9990],
            ['Hazratganj', '226001', 26.8500, 80.9460], ['Alambagh', '226005', 26.8140, 80.9020], ['Rajajipuram', '226017', 26.8460, 80.8890],
            ['Chinhat', '226028', 26.8790, 81.0510], ['Aashiana', '226012', 26.7900, 80.9300], ['Mahanagar', '226006', 26.8800, 80.9620], ['Vikas Nagar', '226022', 26.8960, 80.9550]]],
        'jaipur' => ['Jaipur', 'Rajasthan', 'JP', 'north', ['hi', 'en'], [
            ['Malviya Nagar', '302017', 26.8520, 75.8050], ['Mansarovar', '302020', 26.8690, 75.7600], ['Vaishali Nagar', '302021', 26.9110, 75.7430],
            ['Raja Park', '302004', 26.8990, 75.8270], ['Sanganer', '302029', 26.8230, 75.7950], ['Jhotwara', '302012', 26.9480, 75.7400],
            ['Pratap Nagar', '302033', 26.8010, 75.8230], ['C-Scheme', '302001', 26.9070, 75.7980], ['Jagatpura', '302017', 26.8240, 75.8620], ['Sodala', '302006', 26.9020, 75.7700]]],
        'indore' => ['Indore', 'Madhya Pradesh', 'IN', 'north', ['hi', 'en'], [
            ['Vijay Nagar', '452010', 22.7533, 75.8937], ['Palasia', '452001', 22.7240, 75.8840], ['Bhawarkuan', '452001', 22.6930, 75.8670],
            ['Sudama Nagar', '452009', 22.6990, 75.8350], ['Nipania', '452010', 22.7600, 75.9200], ['Rajendra Nagar', '452012', 22.6720, 75.8270],
            ['Bengali Square', '452016', 22.7230, 75.9100], ['Annapurna', '452009', 22.6980, 75.8460], ['Khajrana', '452016', 22.7340, 75.9070], ['Rau', '453331', 22.6400, 75.8100]]],
        'patna' => ['Patna', 'Bihar', 'PT', 'north', ['hi', 'en'], [
            ['Boring Road', '800001', 25.6190, 85.1190], ['Kankarbagh', '800020', 25.5960, 85.1660], ['Rajendra Nagar', '800016', 25.6050, 85.1600],
            ['Danapur', '801503', 25.6300, 85.0460], ['Patliputra Colony', '800013', 25.6270, 85.0970], ['Bailey Road', '800014', 25.6100, 85.0850],
            ['Anisabad', '800002', 25.5850, 85.0980], ['Agam Kuan', '800007', 25.6050, 85.2000], ['Ashok Rajpath', '800004', 25.6190, 85.1550], ['Phulwari Sharif', '801505', 25.5770, 85.0750]]],
        'chandigarh' => ['Chandigarh', 'Chandigarh', 'CH', 'punjab', ['hi', 'en'], [
            ['Sector 22', '160022', 30.7350, 76.7730], ['Sector 35', '160035', 30.7230, 76.7590], ['Sector 44', '160047', 30.7120, 76.7640],
            ['Manimajra', '160101', 30.7140, 76.8350], ['Sector 15', '160015', 30.7570, 76.7700], ['Sector 40', '160036', 30.7340, 76.7480],
            ['Sector 46', '160047', 30.7020, 76.7700], ['Sector 8', '160009', 30.7420, 76.8010], ['Mohali Phase 7', '160062', 30.7110, 76.7210], ['Zirakpur', '140603', 30.6420, 76.8170]]],
        'hyderabad' => ['Hyderabad', 'Telangana', 'HY', 'telugu', ['te', 'hi'], [
            ['Kukatpally', '500072', 17.4948, 78.3996], ['Madhapur', '500081', 17.4483, 78.3915], ['Ameerpet', '500016', 17.4375, 78.4482],
            ['Dilsukhnagar', '500060', 17.3688, 78.5247], ['LB Nagar', '500074', 17.3457, 78.5522], ['Uppal', '500039', 17.4058, 78.5591],
            ['Mehdipatnam', '500028', 17.3950, 78.4310], ['Secunderabad', '500003', 17.4399, 78.4983], ['Miyapur', '500049', 17.4968, 78.3614],
            ['Tolichowki', '500008', 17.4010, 78.4120], ['Malakpet', '500036', 17.3740, 78.5040], ['Kondapur', '500084', 17.4612, 78.3549]]],
        'visakhapatnam' => ['Visakhapatnam', 'Andhra Pradesh', 'VZ', 'telugu', ['te', 'en'], [
            ['MVP Colony', '530017', 17.7380, 83.3300], ['Gajuwaka', '530026', 17.6900, 83.2120], ['Dwaraka Nagar', '530016', 17.7290, 83.3050],
            ['Madhurawada', '530048', 17.8160, 83.3570], ['Seethammadhara', '530013', 17.7440, 83.3100], ['Akkayyapalem', '530016', 17.7300, 83.2960],
            ['Pendurthi', '531173', 17.7930, 83.2050], ['NAD Junction', '530009', 17.7420, 83.2350], ['Kancharapalem', '530008', 17.7300, 83.2700], ['Arilova', '530040', 17.7640, 83.3150]]],
        'vijayawada' => ['Vijayawada', 'Andhra Pradesh', 'VJ', 'telugu', ['te', 'en'], [
            ['Benz Circle', '520010', 16.4990, 80.6560], ['Patamata', '520010', 16.4950, 80.6670], ['Governorpet', '520002', 16.5110, 80.6290],
            ['Moghalrajpuram', '520010', 16.5060, 80.6480], ['Gunadala', '520004', 16.5300, 80.6620], ['Bhavanipuram', '520012', 16.5300, 80.5980],
            ['Auto Nagar', '520007', 16.4950, 80.6800], ['Satyanarayanapuram', '520011', 16.5250, 80.6330], ['Kanuru', '520007', 16.4870, 80.6940], ['Ajit Singh Nagar', '520015', 16.5360, 80.6370]]],
        'bengaluru' => ['Bengaluru', 'Karnataka', 'BL', 'karnataka', ['kn', 'en'], [
            ['Koramangala', '560034', 12.9352, 77.6245], ['Whitefield', '560066', 12.9698, 77.7500], ['Jayanagar', '560041', 12.9250, 77.5938],
            ['Marathahalli', '560037', 12.9569, 77.7011], ['Electronic City', '560100', 12.8452, 77.6602], ['BTM Layout', '560076', 12.9166, 77.6101],
            ['Rajajinagar', '560010', 12.9910, 77.5550], ['Yelahanka', '560064', 13.1007, 77.5963], ['HSR Layout', '560102', 12.9116, 77.6474],
            ['Banashankari', '560070', 12.9255, 77.5468], ['Malleshwaram', '560003', 13.0035, 77.5700], ['Indiranagar', '560038', 12.9784, 77.6408]]],
        'chennai' => ['Chennai', 'Tamil Nadu', 'CN', 'tamil', ['ta', 'en'], [
            ['T. Nagar', '600017', 13.0418, 80.2341], ['Anna Nagar', '600040', 13.0850, 80.2101], ['Velachery', '600042', 12.9815, 80.2180],
            ['Tambaram', '600045', 12.9249, 80.1000], ['Porur', '600116', 13.0382, 80.1565], ['Ambattur', '600053', 13.1143, 80.1548],
            ['Adyar', '600020', 13.0012, 80.2565], ['Chromepet', '600044', 12.9516, 80.1462], ['Perambur', '600011', 13.1210, 80.2330],
            ['Kodambakkam', '600024', 13.0521, 80.2255], ['Mylapore', '600004', 13.0330, 80.2680], ['Sholinganallur', '600119', 12.9010, 80.2279]]],
        'coimbatore' => ['Coimbatore', 'Tamil Nadu', 'CB', 'tamil', ['ta', 'en'], [
            ['RS Puram', '641002', 11.0090, 76.9500], ['Gandhipuram', '641012', 11.0168, 76.9558], ['Peelamedu', '641004', 11.0300, 77.0170],
            ['Saibaba Colony', '641011', 11.0260, 76.9450], ['Singanallur', '641005', 10.9990, 77.0320], ['Ganapathy', '641006', 11.0420, 76.9800],
            ['Saravanampatti', '641035', 11.0790, 77.0020], ['Podanur', '641023', 10.9610, 76.9790], ['Kuniyamuthur', '641008', 10.9620, 76.9530], ['Vadavalli', '641041', 11.0280, 76.9050]]],
        'madurai' => ['Madurai', 'Tamil Nadu', 'MD', 'tamil', ['ta', 'en'], [
            ['Anna Nagar', '625020', 9.9220, 78.1460], ['KK Nagar', '625020', 9.9300, 78.1430], ['Tallakulam', '625002', 9.9370, 78.1300],
            ['Simmakkal', '625001', 9.9250, 78.1210], ['Thirunagar', '625006', 9.8790, 78.0700], ['Palanganatham', '625003', 9.9050, 78.0950],
            ['SS Colony', '625010', 9.9230, 78.1000], ['Arapalayam', '625016', 9.9300, 78.1020], ['Mattuthavani', '625007', 9.9450, 78.1590], ['Villapuram', '625012', 9.8970, 78.1180]]],
        'tiruchirappalli' => ['Tiruchirappalli', 'Tamil Nadu', 'TR', 'tamil', ['ta', 'en'], [
            ['Thillai Nagar', '620018', 10.8270, 78.6880], ['Srirangam', '620006', 10.8620, 78.6930], ['KK Nagar', '620021', 10.7700, 78.7000],
            ['Cantonment', '620001', 10.8000, 78.6900], ['Woraiyur', '620003', 10.8290, 78.6800], ['Puthur', '620017', 10.8130, 78.6830],
            ['Ariyamangalam', '620010', 10.8100, 78.7300], ['Thiruverumbur', '620013', 10.7810, 78.7700], ['Edamalaipatti Pudur', '620012', 10.7790, 78.6820], ['Golden Rock', '620004', 10.7880, 78.7140]]],
        'salem' => ['Salem', 'Tamil Nadu', 'SL', 'tamil', ['ta', 'en'], [
            ['Fairlands', '636016', 11.6720, 78.1330], ['Hasthampatti', '636007', 11.6700, 78.1500], ['Ammapet', '636003', 11.6480, 78.1750],
            ['Suramangalam', '636005', 11.6650, 78.1180], ['Shevapet', '636002', 11.6560, 78.1570], ['Alagapuram', '636004', 11.6820, 78.1300],
            ['Kondalampatti', '636010', 11.6280, 78.1310], ['Gugai', '636006', 11.6450, 78.1570], ['Meyyanur', '636004', 11.6610, 78.1420], ['Kitchipalayam', '636015', 11.6420, 78.1640]]],
        'kolkata' => ['Kolkata', 'West Bengal', 'KL', 'bengal', ['bn', 'en'], [
            ['Salt Lake Sector V', '700091', 22.5760, 88.4330], ['Behala', '700034', 22.4980, 88.3110], ['Dum Dum', '700028', 22.6220, 88.4240],
            ['Tollygunge', '700033', 22.4990, 88.3460], ['Garia', '700084', 22.4640, 88.3910], ['Kasba', '700042', 22.5180, 88.3870],
            ['Jadavpur', '700032', 22.4990, 88.3710], ['New Town', '700156', 22.5790, 88.4610], ['Shyambazar', '700004', 22.6020, 88.3730],
            ['Ballygunge', '700019', 22.5270, 88.3660], ['Lake Town', '700089', 22.6040, 88.4040], ['Baranagar', '700036', 22.6430, 88.3770]]],
        'bhubaneswar' => ['Bhubaneswar', 'Odisha', 'BB', 'odisha', ['hi', 'en'], [
            ['Saheed Nagar', '751007', 20.2900, 85.8440], ['Patia', '751024', 20.3530, 85.8190], ['Nayapalli', '751012', 20.2950, 85.8070],
            ['Khandagiri', '751030', 20.2600, 85.7800], ['Chandrasekharpur', '751016', 20.3250, 85.8180], ['Old Town', '751002', 20.2380, 85.8360],
            ['Jaydev Vihar', '751013', 20.2960, 85.8190], ['Rasulgarh', '751010', 20.2930, 85.8600], ['Baramunda', '751003', 20.2780, 85.7980], ['Sundarpada', '751002', 20.2450, 85.7930]]],
        'ahmedabad' => ['Ahmedabad', 'Gujarat', 'AH', 'gujarat', ['hi', 'en'], [
            ['Maninagar', '380008', 22.9960, 72.6000], ['Satellite', '380015', 23.0300, 72.5170], ['Navrangpura', '380009', 23.0370, 72.5600],
            ['Vastrapur', '380015', 23.0380, 72.5290], ['Naroda', '382330', 23.0700, 72.6550], ['Bopal', '380058', 23.0330, 72.4630],
            ['Chandkheda', '382424', 23.1080, 72.5850], ['Vastral', '382418', 23.0040, 72.6540], ['Nikol', '382350', 23.0430, 72.6700], ['Ghatlodia', '380061', 23.0640, 72.5420]]],
        'kochi' => ['Kochi', 'Kerala', 'KC', 'kerala', ['en'], [
            ['Kakkanad', '682030', 10.0159, 76.3419], ['Edappally', '682024', 10.0261, 76.3083], ['Palarivattom', '682025', 10.0050, 76.3060],
            ['Vyttila', '682019', 9.9680, 76.3210], ['Kaloor', '682017', 9.9970, 76.2950], ['Thrippunithura', '682301', 9.9440, 76.3480],
            ['Aluva', '683101', 10.1076, 76.3516], ['Kalamassery', '683104', 10.0510, 76.3150], ['Kadavanthra', '682020', 9.9670, 76.2990], ['Maradu', '682304', 9.9330, 76.3240]]],
    ];

    /** [male first names, female first names, surnames] per region. */
    public const NAMES = [
        'maharashtra' => [['Rahul', 'Sachin', 'Amit', 'Prashant', 'Nilesh', 'Sandeep', 'Mahesh', 'Ganesh', 'Vinayak', 'Rohit', 'Sagar', 'Tushar', 'Yogesh', 'Ajinkya', 'Omkar', 'Swapnil', 'Kiran', 'Akshay', 'Pravin', 'Shubham'],
            ['Pooja', 'Snehal', 'Priyanka', 'Ashwini', 'Manisha', 'Sayali', 'Vaishali', 'Rupali', 'Shital', 'Komal', 'Pallavi', 'Madhuri', 'Neha', 'Rutuja', 'Sonali'],
            ['Patil', 'Deshmukh', 'Kulkarni', 'Jadhav', 'Shinde', 'Pawar', 'More', 'Gaikwad', 'Joshi', 'Kale', 'Bhosale', 'Chavan', 'Sawant', 'Deshpande', 'Kadam', 'Salunkhe', 'Mane', 'Wagh']],
        'north' => [['Rajesh', 'Amit', 'Vikas', 'Sunil', 'Manoj', 'Deepak', 'Rakesh', 'Ankit', 'Saurabh', 'Gaurav', 'Pankaj', 'Ravi', 'Ajay', 'Vivek', 'Rohit', 'Arun', 'Nitin', 'Abhishek', 'Sanjay', 'Mohit'],
            ['Neha', 'Pooja', 'Priya', 'Anjali', 'Sunita', 'Kavita', 'Ritu', 'Shalini', 'Preeti', 'Nisha', 'Ruchi', 'Swati', 'Sapna', 'Komal', 'Meenakshi'],
            ['Sharma', 'Verma', 'Gupta', 'Singh', 'Yadav', 'Mishra', 'Srivastava', 'Tiwari', 'Pandey', 'Chauhan', 'Agarwal', 'Saxena', 'Rawat', 'Kumar', 'Jain', 'Tripathi', 'Dubey', 'Rathore']],
        'punjab' => [['Gurpreet', 'Harpreet', 'Manpreet', 'Jaspreet', 'Amandeep', 'Rajinder', 'Sukhwinder', 'Kuldeep', 'Navdeep', 'Rohit', 'Vikram', 'Sandeep', 'Ankush', 'Karan', 'Lovepreet', 'Gagandeep'],
            ['Simran', 'Harleen', 'Navneet', 'Ramandeep', 'Kiranjit', 'Jasleen', 'Manjeet', 'Pooja', 'Neha', 'Sonia', 'Rupinder', 'Amrita'],
            ['Singh', 'Kaur', 'Gill', 'Sandhu', 'Sidhu', 'Dhillon', 'Grewal', 'Bains', 'Sharma', 'Malhotra', 'Arora', 'Bedi', 'Kapoor', 'Sethi']],
        'telugu' => [['Srinivas', 'Ravi Kumar', 'Venkatesh', 'Suresh', 'Ramesh', 'Naresh', 'Mahesh', 'Praveen', 'Kiran Kumar', 'Sai Teja', 'Anil', 'Chandra Shekar', 'Raju', 'Vamshi', 'Mohammed Imran', 'Syed Arif', 'Abdul Rahim', 'Harish', 'Prasad', 'Nagaraju'],
            ['Lakshmi', 'Swathi', 'Sravani', 'Divya', 'Sirisha', 'Padma', 'Anusha', 'Bhavani', 'Kavya', 'Sowmya', 'Ayesha', 'Farheen', 'Madhavi', 'Sunitha', 'Pavani'],
            ['Reddy', 'Rao', 'Naidu', 'Goud', 'Chowdary', 'Yadav', 'Varma', 'Sharma', 'Raju', 'Kumar', 'Khan', 'Siddiqui', 'Shaik', 'Patel', 'Gupta', 'Babu', 'Murthy', 'Achari']],
        'karnataka' => [['Manjunath', 'Prakash', 'Nagaraj', 'Shivakumar', 'Raghavendra', 'Suresh', 'Kiran', 'Harish', 'Santosh', 'Vinay', 'Mahesh', 'Ravi', 'Girish', 'Pradeep', 'Chetan', 'Abhishek', 'Lokesh', 'Darshan'],
            ['Kavitha', 'Shwetha', 'Deepa', 'Rekha', 'Asha', 'Bhavya', 'Nandini', 'Pavithra', 'Roopa', 'Sahana', 'Divya', 'Meghana', 'Anitha', 'Sushma'],
            ['Gowda', 'Shetty', 'Hegde', 'Rao', 'Kumar', 'Naik', 'Bhat', 'Reddy', 'Murthy', 'Prasad', 'Patil', 'Kamath', 'Shenoy', 'Swamy', 'Achar']],
        'tamil' => [['Karthikeyan', 'Senthil Kumar', 'Murugan', 'Saravanan', 'Prakash', 'Vignesh', 'Arun Kumar', 'Balaji', 'Ramesh', 'Dinesh', 'Manikandan', 'Suresh', 'Gopinath', 'Rajesh', 'Selvam', 'Sathish', 'Vijay', 'Anand'],
            ['Lakshmi', 'Priya', 'Divya', 'Kavitha', 'Meena', 'Revathi', 'Gayathri', 'Saranya', 'Deepa', 'Anitha', 'Vani', 'Sangeetha', 'Malathi', 'Bhuvana', 'Nithya'],
            ['Subramanian', 'Krishnan', 'Raman', 'Pandian', 'Murugesan', 'Rajendran', 'Natarajan', 'Sundaram', 'Venkatesan', 'Palanisamy', 'Chandrasekaran', 'Arumugam', 'Ganesan', 'Kannan', 'Velu', 'Shanmugam']],
        'bengal' => [['Sourav', 'Arijit', 'Subhajit', 'Debashis', 'Partha', 'Abhijit', 'Sandip', 'Tanmoy', 'Rajib', 'Sumanta', 'Biswajit', 'Indranil', 'Pritam', 'Anirban', 'Kaushik', 'Soumen'],
            ['Moumita', 'Puja', 'Sreya', 'Riya', 'Payel', 'Ananya', 'Sudeshna', 'Tanushree', 'Rupa', 'Sanchita', 'Mitali', 'Debjani', 'Sharmila'],
            ['Banerjee', 'Chatterjee', 'Mukherjee', 'Ghosh', 'Das', 'Bose', 'Sen', 'Dutta', 'Roy', 'Saha', 'Majumdar', 'Sarkar', 'Paul', 'Mondal', 'Biswas', 'Chakraborty']],
        'odisha' => [['Subrat', 'Prasanta', 'Bikash', 'Debasis', 'Manas', 'Sanjay', 'Pradeep', 'Ashok', 'Rakesh', 'Soumya', 'Jitendra', 'Satya', 'Ramesh', 'Alok', 'Bijay'],
            ['Sasmita', 'Mamata', 'Sunita', 'Pragyan', 'Lipsa', 'Rashmi', 'Itishree', 'Sangita', 'Smita', 'Puja', 'Swagatika'],
            ['Mohanty', 'Das', 'Patnaik', 'Sahoo', 'Mishra', 'Nayak', 'Swain', 'Behera', 'Panda', 'Jena', 'Rout', 'Pradhan', 'Mallick', 'Dash']],
        'gujarat' => [['Rakesh', 'Hitesh', 'Jignesh', 'Bhavesh', 'Mehul', 'Nirav', 'Ketan', 'Paresh', 'Chirag', 'Darshan', 'Kalpesh', 'Vipul', 'Sanjay', 'Hardik', 'Dhaval', 'Mitesh'],
            ['Hetal', 'Krupa', 'Nisha', 'Bhavna', 'Jagruti', 'Payal', 'Dhara', 'Riddhi', 'Komal', 'Falguni', 'Ami', 'Pinal'],
            ['Patel', 'Shah', 'Desai', 'Mehta', 'Parmar', 'Joshi', 'Trivedi', 'Chauhan', 'Solanki', 'Panchal', 'Modi', 'Thakkar', 'Rana', 'Vaghela', 'Bhatt']],
        'kerala' => [['Anil', 'Sreejith', 'Rajesh', 'Biju', 'Suresh', 'Arun', 'Vineeth', 'Jithin', 'Shaji', 'Thomas', 'Joseph', 'Rahul', 'Akhil', 'Faisal', 'Nikhil'],
            ['Anju', 'Divya', 'Remya', 'Sreeja', 'Lekshmi', 'Asha', 'Bindu', 'Neethu', 'Aswathy', 'Mariya', 'Shabna', 'Reshma'],
            ['Nair', 'Menon', 'Pillai', 'Kurup', 'Thomas', 'Joseph', 'Varghese', 'Mathew', 'Kurian', 'Panicker', 'Nambiar', 'George', 'Abraham', 'Rahman']],
    ];

    /** Building / street words used in addresses, per region. */
    public const PLACES = [
        'maharashtra' => [['Sai Krupa CHS', 'Shree Ganesh Apartments', 'Gokul Dham Society', 'Mayur Residency', 'Om Sai Heights', 'Siddhivinayak Towers', 'Shivneri Apartments', 'Vrindavan Society', 'Laxmi Niwas', 'Akshay Park'],
            ['Lane No. %d', 'Road No. %d', 'Gully No. %d', 'Station Road', 'MG Road', 'Link Road']],
        'north' => [['Shiv Shakti Apartments', 'Gyan Kunj', 'Ashirwad Enclave', 'Krishna Vihar', 'Sai Residency', 'Ganga Apartments', 'Vishal Enclave', 'Shanti Niketan'],
            ['Block %s', 'Gali No. %d', 'Pocket %s', 'Main Road', 'Sector Road']],
        'punjab' => [['House No. %d', 'Kothi No. %d', 'Shivalik Enclave', 'Green Avenue', 'Silver City', 'Sunny Enclave'],
            ['Phase %d', 'Main Market Road', 'Inner Road', 'Block %s']],
        'telugu' => [['Sai Nilayam', 'Lakshmi Residency', 'Sri Venkateshwara Apartments', 'Vasavi Enclave', 'Green Valley Apartments', 'Padmavathi Towers', 'Balaji Nilayam', 'Rohini Residency'],
            ['Road No. %d', 'Street No. %d', 'Main Road', 'Lane %d', 'Colony Road']],
        'karnataka' => [['Sri Sai Nilaya', 'Brindavan Apartments', 'Sapthagiri Residency', 'Nandi Enclave', 'Ganga Sadan', 'Lakshmi Krupa', 'Sai Krupa Apartments'],
            ['%dth Cross', '%dth Main', 'Ring Road', '1st Stage', '2nd Block']],
        'tamil' => [['Sri Ram Flats', 'Ganga Apartments', 'Lakshmi Nivas', 'Sakthi Flats', 'Annai Illam', 'Murugan Nagar', 'Kamatchi Apartments'],
            ['%dth Street', 'Main Road', 'Cross Street', 'Bharathi Street', 'Gandhi Street', 'Nehru Street']],
        'bengal' => [['Shanti Kunja', 'Basundhara Apartment', 'Ma Kali Abasan', 'Sonar Bangla Apartment', 'Rabindra Pally', 'Netaji Abasan'],
            ['Lane %d', 'Road No. %d', 'Main Road', 'Rabindra Sarani', 'Netaji Subhas Road', 'Pally Road']],
        'odisha' => [['Jagannath Residency', 'Shanti Vihar', 'Kalinga Apartments', 'Maa Tarini Nivas', 'Sai Enclave'],
            ['Lane %d', 'Main Road', 'Plot No. %d', 'Sector %d']],
        'gujarat' => [['Shivam Apartments', 'Shreeji Residency', 'Gokul Society', 'Swaminarayan Park', 'Shanti Kunj Society', 'Krishna Flats'],
            ['Near Char Rasta', 'Main Road', 'Society Road', 'Lane %d', 'Near Bus Stand']],
        'kerala' => [['Sreevalsam', 'Thanal', 'Ambadi House', 'Prasanthi', 'Mangalath House', 'Skyline Apartments'],
            ['Lane %d', 'Main Road', 'Church Road', 'Temple Road', 'Bypass Road']],
    ];

    /** Cities for the registration form: key => "City, State". */
    public static function options(): array
    {
        return collect(self::CITIES)->map(fn ($c) => "{$c[0]}, {$c[1]}")->sort()->all();
    }

    /** [key, name, state, code, region, languages, localities] or null. */
    public static function get(?string $key): ?array
    {
        $c = self::CITIES[$key] ?? null;
        return $c ? [$key, ...$c] : null;
    }
}
