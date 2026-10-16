<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/PHPMailer/src/Exception.php';
require 'vendor/PHPMailer/src/PHPMailer.php';
require 'vendor/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/includes/mail_settings.php';

function sendResetMail($toEmail, $resetLink)
{
    global $conn; // Access the database connection from the calling file

    if (!isset($conn)) {
        return false;
    }

    $settings = waamini_mail_load_settings($conn);
    $mailConfig = waamini_mail_resolve_config($settings);

    $mail = new PHPMailer(true);

    try {
        // $mail->SMTPDebug = 2; // Keep disabled to avoid leaking transport details in production
        $mail->Debugoutput = 'html';
        waamini_mail_apply_config($mail, $mailConfig);

        if ($mailConfig['transport'] !== 'sendmail' && (empty($mailConfig['host']) || empty($mailConfig['username']) || empty($mailConfig['password']))) {
            error_log("SMTP credentials missing from both DB and ENV");
            return false;
        }

        $fromEmail = !empty($mailConfig['from_email']) ? $mailConfig['from_email'] : 'support@waamini.com';
        $fromName = !empty($mailConfig['from_name']) ? $mailConfig['from_name'] : 'WAAMINI CRM';
        $mail->setFrom($fromEmail, $fromName);
        if (!empty($mailConfig['reply_to'])) {
            $mail->addReplyTo($mailConfig['reply_to']);
        }

        $mail->addAddress($toEmail);

        // Business-letter email: plain text styling plus one reset banner/button.
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = 'Password reset request - WAAMINI';
        $expires = date('d M Y, h:i A T', strtotime('+1 hour'));
        $eEmail = htmlspecialchars((string)$toEmail, ENT_QUOTES, 'UTF-8');
        $eLink = htmlspecialchars((string)$resetLink, ENT_QUOTES, 'UTF-8');
        $p = 'margin:0 0 16px;';
        $mail->Body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#222222;">'
            . "<p style=\"$p\">Dear User,</p>"
            . "<p style=\"$p\">We received a request to reset the password for the WAAMINI account registered with this email address (<b>$eEmail</b>).</p>"
            . "<p style=\"$p\">To choose a new password, please use the button below. It can be used once and is valid for 1 hour (until $expires).</p>"
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:6px 0 20px;max-width:480px;"><tr>'
            . '<td align="center" bgcolor="#11887A" style="border-radius:6px;">'
            . "<a href=\"$eLink\" target=\"_blank\" style=\"display:block;padding:15px 20px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;\">Reset your password</a>"
            . '</td></tr></table>'
            . "<p style=\"$p\">If the button does not work, copy and paste this link into your browser:<br><a href=\"$eLink\" style=\"color:#11887A;word-break:break-all;\">$eLink</a></p>"
            . "<p style=\"$p\">If you did not request a password reset, no action is required. Your password will remain unchanged.</p>"
            . "<p style=\"$p\">For your security, WAAMINI will never ask you for your password by email or chat.</p>"
            . "<p style=\"$p\">If you need any assistance, please contact us at <a href=\"mailto:support@waamini.com\" style=\"color:#11887A;\">support@waamini.com</a>.</p>"
            . '<p style="margin:0;">Regards,<br>WAAMINI Support Team<br>support@waamini.com</p>'
            . '</div>';
        $mail->AltBody = "Dear User,\n\n"
            . "We received a request to reset the password for the WAAMINI account registered with this email address ($toEmail).\n\n"
            . "To choose a new password, open the link below. It can be used once and is valid for 1 hour (until $expires).\n\n"
            . "$resetLink\n\n"
            . "If you did not request a password reset, no action is required. Your password will remain unchanged.\n\n"
            . "WAAMINI will never ask you for your password by email or chat.\n\n"
            . "Need help? support@waamini.com\n\nRegards,\nWAAMINI Support Team";
        $mail->send();
        return true;

    }
    catch (Exception $e) {
        return false;
    }
}
?>