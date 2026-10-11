<?php
/**
 * Metro Valet Parking — mailer.
 *
 * Default transport is PHP mail() with a branded HTML wrapper (works on
 * Hostinger shared hosting). Every template send is logged to
 * notification_logs with status sent/failed; dedupe_key prevents duplicates.
 * Defines functions only.
 */

/** Branded HTML wrapper around a template body fragment. */
function _mail_wrap(string $html): string {
    $biz = (string) setting('business_name', 'Metro Valet Parking');
    $tagline = (string) setting('tagline', 'Our Experience Is Your Advantage');
    return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#F4F6F9;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="max-width:600px;margin:0 auto;background:#ffffff;">'
        . '<div style="background:#0A1A2F;color:#ffffff;padding:20px 24px;font-size:18px;font-weight:bold;">'
        . '<span style="color:#C9A227;">' . e($biz) . '</span></div>'
        . '<div style="padding:24px;color:#1B2430;font-size:14px;line-height:1.6;">' . $html . '</div>'
        . '<div style="padding:16px 24px;font-size:12px;color:#5B6774;border-top:1px solid #e5e9f0;">'
        . e($biz) . ' &mdash; ' . e($tagline) . '</div>'
        . '</div></body></html>';
}


/**
 * Send an email via SMTP with authentication.
 * Returns true on success, false on any failure (never throws).
 */
function send_via_smtp(string $to, string $subject, string $html, array $attachments = []): bool {
    $host = trim((string) setting('smtp_host', ''));
    if ($host === '') {
        return false;
    }
    $port = (int) setting('smtp_port', 587);
    $user = (string) setting('smtp_user', '');
    $pass = (string) setting('smtp_pass', '');
    $secure = strtolower(trim((string) setting('smtp_secure', 'tls'))); // tls|ssl|none
    $from = (string) setting('mail_from', 'info@developerkhaled.net');
    $biz = (string) setting('business_name', 'Metro Valet Parking');

    // Build the MIME message.
    $eol = "\r\n";
    $boundary = '==MV' . bin2hex(random_bytes(12)) . '==';
    $headers = 'From: ' . $biz . ' <' . $from . '>' . $eol
        . 'Reply-To: ' . $from . $eol
        . 'MIME-Version: 1.0' . $eol;
    if ($attachments === []) {
        $headers .= 'Content-Type: text/html; charset=UTF-8' . $eol
            . 'Content-Transfer-Encoding: 8bit' . $eol;
        $body = _mail_wrap($html);
    } else {
        $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . $eol;
        $body = '--' . $boundary . $eol
            . 'Content-Type: text/html; charset=UTF-8' . $eol
            . 'Content-Transfer-Encoding: 8bit' . $eol . $eol
            . _mail_wrap($html) . $eol;
        foreach ($attachments as $a) {
            $body .= '--' . $boundary . $eol
                . 'Content-Type: ' . ($a['type'] ?? 'application/octet-stream') . '; name="' . ($a['filename'] ?? 'file') . '"' . $eol
                . 'Content-Transfer-Encoding: base64' . $eol
                . 'Content-Disposition: attachment; filename="' . ($a['filename'] ?? 'file') . '"' . $eol . $eol
                . chunk_split(base64_encode($a['content'] ?? '')) . $eol;
        }
        $body .= '--' . $boundary . '--' . $eol;
    }
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    try {
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 20);
        if (!$fp) {
            return false;
        }
        $read = function () use ($fp) {
            $resp = '';
            while (($line = fgets($fp, 512)) !== false) {
                $resp .= $line;
                if (preg_match('/^\d{3} /', $line)) {
                    break;
                }
            }
            return $resp;
        };
        $cmd = function (string $c, string $expect = '250') use ($fp, $read): bool {
            fwrite($fp, $c . "\r\n");
            return strpos($read(), $expect) === 0;
        };
        $read(); // banner
        if (!$cmd('EHLO ' . gethostname(), '250')) {
            fclose($fp);
            return false;
        }
        if ($secure === 'tls') {
            fwrite($fp, "STARTTLS\r\n");
            if (strpos($read(), '220') !== 0) {
                fclose($fp);
                return false;
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                return false;
            }
            if (!$cmd('EHLO ' . gethostname(), '250')) {
                fclose($fp);
                return false;
            }
        }
        if ($user !== '') {
            fwrite($fp, "AUTH LOGIN\r\n");
            if (strpos($read(), '334') !== 0) {
                fclose($fp);
                return false;
            }
            if (!$cmd(base64_encode($user), '334')) {
                fclose($fp);
                return false;
            }
            if (!$cmd(base64_encode($pass), '235')) {
                fclose($fp);
                return false;
            }
        }
        if (!$cmd('MAIL FROM:<' . $from . '>', '250')) {
            fclose($fp);
            return false;
        }
        if (!$cmd('RCPT TO:<' . $to . '>', '250')) {
            fclose($fp);
            return false;
        }
        fwrite($fp, "DATA\r\n");
        if (strpos($read(), '354') !== 0) {
            fclose($fp);
            return false;
        }
        fwrite($fp, 'Subject: ' . $encoded_subject . $eol . $headers . $eol . $body . $eol . '.' . $eol);
        $ok = strpos($read(), '250') === 0;
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Send a raw branded HTML email via PHP mail(), with optional attachments.
 * $attachments: list of ['filename' => string, 'content' => string bytes, 'type' => mime].
 * Returns false on failure WITHOUT raising warnings.
 */
function send_mail_raw(string $to, string $subject, string $html, array $attachments = []): bool {
    // Prefer SMTP when configured — more reliable than mail() on shared hosting.
    if (trim((string) setting('smtp_host', '')) !== '') {
        return send_via_smtp($to, $subject, $html, $attachments);
    }
    $from = (string) setting('mail_from', 'info@developerkhaled.net');
    $biz = (string) setting('business_name', 'Metro Valet Parking');
    $body = _mail_wrap($html);
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    if ($attachments === []) {
        $headers = "MIME-Version: 1.0\r\n"
            . "Content-type: text/html; charset=UTF-8\r\n"
            . 'From: ' . $biz . ' <' . $from . ">\r\n"
            . 'Reply-To: ' . $from . "\r\n"
            . "X-Mailer: MetroValet/" . APP_VERSION . "\r\n";
        try {
            $ok = @mail($to, $encoded_subject, $body, $headers);
        } catch (Throwable $e) {
            $ok = false;
        }
        return (bool) $ok;
    }

    // Multipart message with attachments.
    $boundary = '==MV' . bin2hex(random_bytes(12)) . '==';
    $headers = "MIME-Version: 1.0\r\n"
        . 'From: ' . $biz . ' <' . $from . ">\r\n"
        . 'Reply-To: ' . $from . "\r\n"
        . "X-Mailer: MetroValet/" . APP_VERSION . "\r\n"
        . 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n";

    $msg = "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $body . "\r\n";
    foreach ($attachments as $a) {
        $fname = (string) ($a['filename'] ?? 'attachment');
        // Sanitize filename for the header.
        $fname = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fname);
        $mime = (string) ($a['type'] ?? 'application/octet-stream');
        $msg .= "--$boundary\r\n"
            . 'Content-Type: ' . $mime . '; name="' . $fname . "\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . 'Content-Disposition: attachment; filename="' . $fname . "\"\r\n\r\n"
            . chunk_split(base64_encode((string) ($a['content'] ?? ''))) . "\r\n";
    }
    $msg .= "--$boundary--\r\n";

    try {
        $ok = @mail($to, $encoded_subject, $msg, $headers);
    } catch (Throwable $e) {
        $ok = false;
    }
    return (bool) $ok;
}

/**
 * Send a notification_templates email.
 *
 * Loads the template by $key, replaces {{var}} placeholders (values are
 * HTML-escaped; put the <a href> in the template around {{pay_url}} etc.),
 * sends via send_mail_raw(), and ALWAYS inserts a notification_logs row.
 * If $dedupe_key was already logged, returns true WITHOUT resending.
 * $attachments: optional list for send_mail_raw() (see above).
 */
function send_template(string $key, string $to, array $vars, ?string $dedupe_key = null, array $attachments = []): bool {
    $db = db();

    if ($dedupe_key !== null) {
        $st = $db->prepare("SELECT id FROM notification_logs WHERE dedupe_key = ? LIMIT 1");
        $st->execute([$dedupe_key]);
        if ($st->fetch()) {
            return true; // already sent — do not resend
        }
    }

    $status = 'failed';
    $error = null;
    $subject = '';
    $body = '';
    try {
        $st = $db->prepare("SELECT subject, body FROM notification_templates WHERE key = ? AND active = 1");
        $st->execute([$key]);
        $tpl = $st->fetch();
        if (!$tpl) {
            throw new RuntimeException("Template '{$key}' not found or inactive.");
        }
        $subject = $tpl['subject'];
        $body = $tpl['body'];
        foreach ($vars as $k => $v) {
            $ph = '{{' . $k . '}}';
            $subject = str_replace($ph, (string) $v, $subject);
            $body = str_replace($ph, e((string) $v), $body);
        }
        if (!send_mail_raw($to, $subject, $body, $attachments)) {
            throw new RuntimeException('mail() returned false.');
        }
        $status = 'sent';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    try {
        $db->prepare(
            "INSERT INTO notification_logs (template_key, recipient, subject, body_hash, status, dedupe_key, error, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$key, $to, $subject, sha1($body), $status, $dedupe_key, $error, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        // Race on the UNIQUE dedupe_key: another process just logged it.
        if ($dedupe_key !== null && stripos($e->getMessage(), 'unique') !== false) {
            return true;
        }
    }

    return $status === 'sent';
}
