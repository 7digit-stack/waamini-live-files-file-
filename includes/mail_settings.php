<?php
require_once __DIR__ . '/../services/EncryptionService.php';

if (!function_exists('waamini_mail_load_settings')) {
    function waamini_mail_load_settings(mysqli $conn): array
    {
        $settings = [];
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_group='email'");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
                }
            }
            $stmt->close();
        }
        return $settings;
    }
}

if (!function_exists('waamini_mail_secret_value')) {
    function waamini_mail_secret_value(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!function_exists('decrypt_secret')) {
            return (string)$value;
        }
        $decrypted = decrypt_secret((string)$value);
        return is_string($decrypted) && $decrypted !== '' ? $decrypted : (string)$value;
    }
}

if (!function_exists('waamini_mail_resolve_config')) {
    function waamini_mail_resolve_config(array $settings, array $overrides = []): array
    {
        $provider = strtolower(trim((string)($overrides['smtp_provider'] ?? $settings['smtp_provider'] ?? 'custom')));
        if (!in_array($provider, ['custom', 'sendgrid', 'ses'], true)) {
            $provider = 'custom';
        }

        $transport = strtolower(trim((string)($overrides['smtp_transport'] ?? $settings['smtp_transport'] ?? 'smtp')));
        if (!in_array($transport, ['smtp', 'sendmail'], true)) {
            $transport = 'smtp';
        }

        $region = trim((string)($overrides['smtp_region'] ?? $settings['smtp_region'] ?? 'us-east-1'));
        $host = trim((string)($overrides['smtp_host'] ?? $settings['smtp_host'] ?? ''));
        $port = (int)($overrides['smtp_port'] ?? $settings['smtp_port'] ?? 587);
        $encryption = strtolower(trim((string)($overrides['smtp_encryption'] ?? $settings['smtp_encryption'] ?? 'tls')));
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }

        $username = trim((string)($overrides['smtp_username'] ?? $settings['smtp_username'] ?? $settings['smtp_user'] ?? ''));
        $passwordRaw = trim((string)($overrides['smtp_password'] ?? $settings['smtp_password'] ?? $settings['smtp_pass'] ?? ''));
        $password = waamini_mail_secret_value($passwordRaw);

        $fromEmail = trim((string)($overrides['smtp_from_email'] ?? $settings['smtp_from_email'] ?? 'support@waamini.com'));
        $fromName = trim((string)($overrides['smtp_from_name'] ?? $settings['smtp_from_name'] ?? 'WAAMINI CRM'));
        $replyTo = trim((string)($overrides['smtp_reply_to'] ?? $settings['smtp_reply_to'] ?? ''));

        if ($provider === 'sendgrid') {
            if ($host === '') {
                $host = 'smtp.sendgrid.net';
            }
            if ($port <= 0) {
                $port = 587;
            }
            if ($encryption === '') {
                $encryption = 'tls';
            }
            if ($username === '') {
                $username = 'apikey';
            }
        } elseif ($provider === 'ses') {
            if ($host === '') {
                if ($region === '') {
                    $region = 'us-east-1';
                }
                $host = 'email-smtp.' . $region . '.amazonaws.com';
            }
            if ($port <= 0) {
                $port = 587;
            }
            if ($encryption === '') {
                $encryption = 'tls';
            }
        }

        return [
            'provider' => $provider,
            'transport' => $transport,
            'region' => $region,
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to' => $replyTo,
        ];
    }
}

if (!function_exists('waamini_mail_apply_config')) {
    function waamini_mail_apply_config(PHPMailer\PHPMailer\PHPMailer $mail, array $config): void
    {
        $transport = (string)($config['transport'] ?? 'smtp');
        if ($transport === 'sendmail') {
            $mail->isSendmail();
            return;
        }

        $mail->isSMTP();
        $mail->Host = (string)($config['host'] ?? '');
        $mail->SMTPAuth = true;
        $mail->Username = (string)($config['username'] ?? '');
        $mail->Password = (string)($config['password'] ?? '');
        $mail->SMTPSecure = (($config['encryption'] ?? 'tls') === 'none') ? '' : (string)($config['encryption'] ?? 'tls');
        $mail->Port = (int)($config['port'] ?? 587);
    }
}