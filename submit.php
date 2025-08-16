<?php
// --- CONFIGURATION ---
$admin_email = "bispage.research@gmail.com"; // Admin email
$log_file = "./enquiry_log.txt";
$allowed_file_types = ['image/jpeg','image/png','application/pdf'];
$max_file_size = 5*1024*1024; // 5 MB
$rate_limit_seconds = 60; // 1 submission per minute per IP

// --- HELPER FUNCTIONS ---
function sanitize($data){ 
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8'); 
}

function validate_file($file){
    global $allowed_file_types,$max_file_size;
    if($file['error']!==UPLOAD_ERR_OK) return false;
    if($file['size']>$max_file_size) return false;
    if(!in_array(mime_content_type($file['tmp_name']),$allowed_file_types)) return false;
    return true;
}

function check_rate_limit($seconds){
    $ip = $_SERVER['REMOTE_ADDR'];
    $rate_file = "logs/rate_".md5($ip).".txt";
    $last = file_exists($rate_file)?(int)file_get_contents($rate_file):0;
    if(time()-$last<$seconds){ 
        die(json_encode(['status'=>0,'msg'=>'Submit too frequently. Try later.'])); 
    }
    file_put_contents($rate_file,time(),LOCK_EX);
}

// --- POST HANDLING ---
if($_SERVER['REQUEST_METHOD']==='POST'){

    check_rate_limit($rate_limit_seconds);

    // Sanitize and validate inputs
    $name = sanitize($_POST['name'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $phone = preg_match("/^[0-9]{10}$/", $_POST['phone'] ?? '') ? $_POST['phone'] : false;
    $address = sanitize($_POST['address'] ?? '');
    $currentLocation = sanitize($_POST['currentLocation'] ?? '');
    $consultLocation = sanitize($_POST['consultLocation'] ?? '');
    $Mode = sanitize($_POST['Mode'] ?? '');
    $consultDay = sanitize($_POST['consultDay'] ?? '');
    $consultTime = sanitize($_POST['consultTime'] ?? '');
    $hoursNeeded = intval($_POST['hoursNeeded'] ?? 0);
    $subject = sanitize($_POST['subject'] ?? '');

    // All fields mandatory except attachment
    if(!$name || !$email || !$phone || !$address || !$currentLocation || !$Mode || !$consultLocation || !$consultDay || !$consultTime || !$hoursNeeded || !$subject){
        die(json_encode(['status'=>0,'msg'=>'All fields are mandatory. Please fill everything.']));
    }

    // Handle file upload if provided
    $attachment_name = "None";
    if(!empty($_FILES['attachment']['name'])){
        if(validate_file($_FILES['attachment'])){
            $upload_dir = __DIR__."/uploads/";
            if(!is_dir($upload_dir)) mkdir($upload_dir,0755,true);
            $attachment_name = time().'_'.preg_replace("/[^a-zA-Z0-9_\-\.]/","",basename($_FILES['attachment']['name']));
            $attachment_path = $upload_dir.$attachment_name;
            move_uploaded_file($_FILES['attachment']['tmp_name'],$attachment_path);
        } else {
            die(json_encode(['status'=>0,'msg'=>'Invalid file uploaded.']));
        }
    }

    // Prepare email content
    $message = "
Name: $name
Email: $email
Phone: $phone
Address: $address
Current Location: $currentLocation
Preferred Consultation Location: $consultLocation
Mode of consultation : $Mode
Preferred Day: $consultDay
Preferred Time: $consultTime
Hours Needed: $hoursNeeded
Subject: $subject
Attachment: $attachment_name
    ";

    $headers = "From: $email\r\nReply-To: $email";

    // Send email to admin
    mail($admin_email, "New Consultation Request", $message, $headers);

    // Send copy to enquirer
    $user_message = "Dear $name,\n\nThank you for your enquiry. Here is a copy of your submission:\n\n".$message."\n\nWe will contact you shortly.\n\nRegards,\nAdmin";
    mail($email, "Your Consultation Request", $user_message, "From: $admin_email\r\nReply-To: $admin_email");

    // Log submission
    $log_entry = date('Y-m-d H:i:s')." | $name | $email | $phone | $Mode | $consultLocation | $subject | $consultDay $consultTime | Attachment: $attachment_name\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND|LOCK_EX);

    echo json_encode(['status'=>1,'msg'=>'Your request has been submitted successfully.']);
}
?>
