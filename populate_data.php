<?php
/**
 * populate_data.php
 * Script to add 20 Rooms and 30 Nepali Students to the HMS Database.
 */
require_once 'db.php';

echo "<h1>Populating HMS Database...</h1>";

// 1. Add Rooms (20 Rooms)
$room_types = ['Single', 'Double', 'Triple'];
for ($i = 1; $i <= 20; $i++) {
    $num  = 100 + $i;
    $type = $room_types[array_rand($room_types)];
    $floor = ($i <= 10) ? 1 : 2;
    $status = 'available';
    
    $check = mysqli_query($conn, "SELECT id FROM rooms WHERE room_number = '$num'");
    if (mysqli_num_rows($check) == 0) {
        mysqli_query($conn, "INSERT INTO rooms (room_number, room_type, floor, status) VALUES ('$num', '$type', $floor, '$status')");
    }
}
echo "<p>✅ 20 Rooms ensured.</p>";

// 2. Add Nepali Students (30 Students)
$names = [
    "Aayush Shrestha", "Bipul Sharma", "Chirag Karki", "Deepak Magar", "Eshwar Gurung",
    "Fanish Tamang", "Gaurav Rai", "Himesh Adhikari", "Ishwor Paudel", "Jivan Thapa",
    "Kishor Lamichhane", "Lokesh Khatri", "Manish Basnet", "Nabin Dahal", "Om Bhattarai",
    "Prakash Ghimire", "Rojan Pandey", "Sagar Kc", "Tika Ram", "Umesh Silwal",
    "Vijay Bhandari", "Yubraj Sapkota", "Zivan Acharya", "Anit Maharjan", "Bibek Khadka",
    "Chetan Regmi", "Dipesh Bista", "Emon Joshi", "Farid Ansari", "Gopal Rawat"
];

$pass_hash = password_hash('Test1234', PASSWORD_DEFAULT);

foreach ($names as $idx => $name) {
    $email = strtolower(str_replace(' ', '.', $name)) . "@example.com";
    
    $check = mysqli_query($conn, "SELECT id FROM users WHERE email = '$email'");
    if (mysqli_num_rows($check) == 0) {
        mysqli_query($conn, "INSERT INTO users (name, email, password, role, is_verified, fee_status) 
                             VALUES ('$name', '$email', '$pass_hash', 'student', 1, 'unpaid')");
    }
}
echo "<p>✅ 30 Nepali Students ensured.</p>";

echo "<hr><a href='home/index.php'>Go to Home</a>";
?>
