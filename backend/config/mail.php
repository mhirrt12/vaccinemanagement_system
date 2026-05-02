<?php
/**
 * Mail Configuration and Email Sending Service
 * 
 * Uses PHPMailer for SMTP (recommended) or fallback to mail().
 */

// Load environment variables
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            putenv(trim($parts[0]) . '=' . trim($parts[1]));
        }
    }
}

// Mail configuration constants
define('MAIL_HOST', getenv('MAIL_HOST') ?: 'smtp.gmail.com');
define('MAIL_PORT', (int)(getenv('MAIL_PORT') ?: 587));
define('MAIL_USERNAME', getenv('MAIL_USERNAME') ?: '');
define('MAIL_PASSWORD', getenv('MAIL_PASSWORD') ?: '');
define('MAIL_ENCRYPTION', getenv('MAIL_ENCRYPTION') ?: 'tls');
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: 'noreply@vaccine-ms.com');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Vaccine Management System');

// Try to load PHPMailer via Composer autoload
$usePHPMailer = false;
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    $usePHPMailer = true;
    // Now we can use the classes; we'll use their full names in the function to avoid 'use' statement issues
}

/**
 * Send an email using configured SMTP or mail() function
 */
function sendEmail($to, $subject, $body, $altBody = '', $attachments = []) {
    global $usePHPMailer;
    if ($usePHPMailer) {
        return sendEmailPHPMailer($to, $subject, $body, $altBody, $attachments);
    } else {
        return sendEmailNative($to, $subject, $body);
    }
}

/**
 * Send email using PHPMailer (requires Composer autoload)
 */
function sendEmailPHPMailer($to, $subject, $body, $altBody, $attachments) {
    try {
        // Use fully qualified class name because we didn't import with 'use'
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port = MAIL_PORT;
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($to);
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $altBody ?: strip_tags($body);
        
        foreach ($attachments as $file) {
            if (file_exists($file)) {
                $mail->addAttachment($file);
            }
        }
        
        $mail->send();
        error_log("Email sent successfully to $to");
        return true;
    } catch (\Exception $e) {
        error_log("Email failed to $to: " . $e->getMessage());
        return false;
    }
}

/**
 * Fallback: Send email using PHP's native mail() function
 */
function sendEmailNative($to, $subject, $body) {
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM_ADDRESS . ">\r\n";
    
    $result = mail($to, $subject, $body, $headers);
    if ($result) {
        error_log("Native email sent to $to");
    } else {
        error_log("Native email failed to $to");
    }
    return $result;
}

/**
 * Send appointment reminder email to parent
 */
function sendAppointmentReminder($parentEmail, $childName, $vaccineName, $appointmentDate, $nurseName = '') {
    $subject = "Vaccination Appointment Reminder - Vaccine Management System";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; }
            .header { background: #007bff; color: white; padding: 10px; text-align: center; border-radius: 5px; }
            .content { padding: 20px; }
            .footer { font-size: 12px; color: #777; text-align: center; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Vaccination Reminder</h2>
            </div>
            <div class='content'>
                <p>Dear Parent,</p>
                <p>This is a reminder for your child's upcoming vaccination:</p>
                <ul>
                    <li><strong>Child:</strong> " . htmlspecialchars($childName) . "</li>
                    <li><strong>Vaccine:</strong> " . htmlspecialchars($vaccineName) . "</li>
                    <li><strong>Date:</strong> " . htmlspecialchars($appointmentDate) . "</li>
                    " . ($nurseName ? "<li><strong>Nurse:</strong> " . htmlspecialchars($nurseName) . "</li>" : "") . "
                </ul>
                <p>Please bring your child's immunization card (Yellow Card) to the health center.</p>
                <p>Thank you for keeping your child safe!</p>
            </div>
            <div class='footer'>
                &copy; " . date('Y') . " Vaccine Management System, Ethiopia
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($parentEmail, $subject, $body);
}

/**
 * Send certificate approval notification to parent
 */
function sendCertificateReadyEmail($parentEmail, $childName, $certificateLink) {
    $subject = "Your Child's Vaccination Certificate is Ready";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; }
            .header { background: #28a745; color: white; padding: 10px; text-align: center; border-radius: 5px; }
            .content { padding: 20px; }
            .button { display: inline-block; background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Certificate Ready for Download</h2>
            </div>
            <div class='content'>
                <p>Dear Parent,</p>
                <p>The vaccination certificate for <strong>" . htmlspecialchars($childName) . "</strong> has been approved and is now available for download.</p>
                <p><a href='" . htmlspecialchars($certificateLink) . "' class='button'>Download Certificate</a></p>
                <p>If the button doesn't work, copy this link: " . htmlspecialchars($certificateLink) . "</p>
                <p>Thank you for using the Vaccine Management System.</p>
            </div>
            <div class='footer'>
                &copy; " . date('Y') . " Vaccine Management System, Ethiopia
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($parentEmail, $subject, $body);
}

/**
 * Send account approval notification to parent
 */
function sendAccountApprovedEmail($parentEmail, $parentName) {
    $subject = "Your Account has been Approved";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; }
            .header { background: #28a745; color: white; padding: 10px; text-align: center; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Account Approved</h2>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($parentName) . ",</p>
                <p>Your account has been verified and approved by the nurse.</p>
                <p>You can now log in to the Vaccine Management System to view your children's vaccination schedules and download certificates.</p>
                <p>Thank you!</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($parentEmail, $subject, $body);
}

/**
 * Send low stock alert to admin
 */
function sendLowStockAlert($adminEmail, $vaccineName, $remainingQuantity) {
    $subject = "Low Stock Alert: $vaccineName";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .alert { background: #dc3545; color: white; padding: 10px; text-align: center; }
        </style>
    </head>
    <body>
        <div class='alert'>
            <h2>Low Stock Alert</h2>
        </div>
        <p>Dear Admin,</p>
        <p>The vaccine <strong>" . htmlspecialchars($vaccineName) . "</strong> is running low.</p>
        <p>Remaining quantity: <strong>$remainingQuantity doses</strong></p>
        <p>Please restock as soon as possible to avoid shortage.</p>
    </body>
    </html>
    ";
    
    return sendEmail($adminEmail, $subject, $body);
}

/**
 * Send expiry notification for vaccine batches
 */
function sendExpiryNotification($adminEmail, $vaccineName, $batchNumber, $expiryDate, $quantity) {
    $subject = "Vaccine Expiry Alert: $vaccineName (Batch: $batchNumber)";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .warning { background: #ffc107; padding: 10px; }
        </style>
    </head>
    <body>
        <div class='warning'>
            <h2>Vaccine Batch Expiring Soon</h2>
        </div>
        <p>The following vaccine batch will expire on <strong>$expiryDate</strong>:</p>
        <ul>
            <li>Vaccine: $vaccineName</li>
            <li>Batch: $batchNumber</li>
            <li>Quantity remaining: $quantity doses</li>
        </ul>
        <p>Please prioritize using or discarding these doses.</p>
    </body>
    </html>
    ";
    
    return sendEmail($adminEmail, $subject, $body);
}
?>