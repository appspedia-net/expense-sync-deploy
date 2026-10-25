<?php
/**
 * Outgoing e-mail (OTP codes).
 *
 * Uses PHP's mail() — on 20i shared hosting this hands the message to the
 * server's local MTA, which is the supported way to send from PHP there.
 * Every send attempt is also written to _private/logs/mail-*.log so a lost
 * message can be diagnosed. When config mail.driver = 'log' nothing is sent
 * (handy on a dev box); when debug=true the OTP code itself is logged too.
 *
 * Config keys: mail.driver ('mail'|'log'), mail.from, mail.from_name, mail.reply_to,
 * mail.sendmail_params (e.g. '-f bounce@imrashid.net'), debug.
 */

declare(strict_types=1);

namespace App\Services;

use Throwable;

class Mail
{
    /**
     * Send a plain-text (plus simple HTML alternative) message. Returns true when
     * mail() accepted the message. Never throws.
     */
    public static function send(string $to, string $subject, string $text, ?string $html = null): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            log_msg('mail', "REJECTED invalid recipient '{$to}'");
            return false;
        }

        $driver = (string) config('mail.driver', 'mail');
        $from = (string) config('mail.from', 'no-reply@imrashid.net');
        $fromName = (string) config('mail.from_name', 'Expense Manager');
        $replyTo = (string) config('mail.reply_to', $from);

        if ($driver === 'log') {
            log_msg('mail', "LOG-ONLY to={$to} subject=\"{$subject}\"\n" . $text);
            return true;
        }

        $boundary = 'b' . bin2hex(random_bytes(12));
        $headers = [
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'Reply-To: ' . $replyTo,
            'MIME-Version: 1.0',
            'X-Mailer: ExpenseManagerSync',
        ];

        if ($html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body = $text;
        } else {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$text}\r\n"
                  . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n"
                  . "--{$boundary}--\r\n";
        }

        $ok = false;
        try {
            $params = (string) config('mail.sendmail_params', '');
            $encodedSubject = self::encodeHeader($subject);
            $ok = $params !== ''
                ? @mail($to, $encodedSubject, $body, implode("\r\n", $headers), $params)
                : @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
        } catch (Throwable $e) {
            log_msg('mail', 'mail() threw: ' . $e->getMessage());
        }
        log_msg('mail', ($ok ? 'SENT' : 'FAILED') . " to={$to} subject=\"{$subject}\"");
        return (bool) $ok;
    }

    /** Send the 6-digit login code. */
    public static function sendOtp(string $to, string $code, int $minutes): bool
    {
        $app = (string) config('app_name', 'Expense Manager');
        $subject = "{$code} is your {$app} login code";
        $text = "Your {$app} login code is: {$code}\n\n"
              . "It expires in {$minutes} minutes. If you did not request this, you can ignore this e-mail.";
        $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:480px;margin:0 auto;padding:24px">'
              . '<h2 style="margin:0 0 12px">' . htmlspecialchars($app) . '</h2>'
              . '<p>Your login code is</p>'
              . '<p style="font-size:32px;letter-spacing:8px;font-weight:700;margin:8px 0 16px">' . htmlspecialchars($code) . '</p>'
              . '<p style="color:#666">It expires in ' . $minutes . ' minutes. If you did not request this, you can ignore this e-mail.</p>'
              . '</div>';
        if (config('debug', false)) {
            log_msg('mail', "DEBUG otp for {$to}: {$code}");
        }
        return self::send($to, $subject, $text, $html);
    }

    private static function encodeHeader(string $s): string
    {
        if (preg_match('/[^\x20-\x7e]/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        return str_replace(["\r", "\n"], '', $s);
    }
}
