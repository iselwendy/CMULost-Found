### Yung Terms and Privacy Policy (legal.html) - gawin na lang modal instead of putting them on a separate page
pati yung "view details" sa gallery page, gawin ding modal na lang



### Maybe tanggalin na lang yung 'edit profile' sa view profile kasi na sa account settings na siya???



$user = [
    'id' => isset($_SESSION['user_id']) ? $_SESSION['user_id'],
    'profile_picture' => 'https://ui-avatars.com/api/?name=' . urlencode(isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User Name') . '&background=FFCC00&color=003366',
    'name' => isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User Name',
    'cmu_email' => isset($_SESSION['cmu_email']) ? $_SESSION['cmu_email'] : 'User Email',
    'school_number' => isset($_SESSION['school_number']) ? $_SESSION['school_number'] : 'School Number',
    'department' => isset($_SESSION['department']) ? $_SESSION['department'] : 'Department',
    'course_year' => isset($_SESSION['course_and_year']) ? $_SESSION['course_and_year'] : 'Course & Year',
    'created_at' => isset($_SESSION['created_at']) ? $_SESSION['created_at'] : 'Joined Date'
];