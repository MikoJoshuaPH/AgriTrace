<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * SMTP email through PHPMailer (Gmail by default: smtp.gmail.com:587 with STARTTLS).
 * Load with $this->call->library('Mailer').
 */
class Mailer
{
    public function is_configured()
    {
        return config_item('mail_username') !== '' && config_item('mail_password') !== '';
    }

    /**
     * @param string|array $to        address or list of addresses
     * @param array        $attachments list of ['name' => ..., 'content' => ..., 'type' => ...]
     * @throws RuntimeException when sending fails
     */
    public function send($to, $subject, $html, $text = '', array $attachments = [], $replyTo = null)
    {
        if (!$this->is_configured()) {
            throw new RuntimeException('Email is not configured. Set MAIL_USERNAME and MAIL_PASSWORD in .env.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = config_item('mail_host');
            $mail->Port       = (int) config_item('mail_port');
            $mail->SMTPAuth   = true;
            $mail->Username   = config_item('mail_username');
            $mail->Password   = config_item('mail_password');
            $encryption = strtolower((string) config_item('mail_encryption'));
            if ($encryption === 'ssl' || $encryption === 'smtps' || $mail->Port === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls' || $encryption === 'starttls' || $mail->Port === 587) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                throw new RuntimeException('Unsupported MAIL_ENCRYPTION value. Use tls or ssl.');
            }
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 15;
            // Certificate checks stay on. MAIL_VERIFY_PEER=false is only for a local test relay with a self-signed certificate.
            $verify = (bool) config_item('mail_verify_peer');
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify]];

            $mail->setFrom(config_item('mail_from'), config_item('mail_from_name'));
            foreach ((array) $to as $address) {
                $mail->addAddress($address);
            }
            if (is_string($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($replyTo);
            }
            foreach ($attachments as $file) {
                $mail->addStringAttachment($file['content'], $file['name'], 'base64', $file['type'] ?? 'application/octet-stream');
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text !== '' ? $text : strip_tags($html);
            $mail->send();
            return true;
        } catch (MailException $e) {
            throw new RuntimeException('The email could not be sent: ' . $mail->ErrorInfo);
        }
    }

    /** HTML wrapper shared by all AgriTrace emails. */
    public function layout($title, $body_html)
    {
        return '<div style="font-family:Arial,Helvetica,sans-serif;background:#f4f7f5;padding:24px">'
            . '<div style="max-width:560px;margin:auto;background:#ffffff;border-radius:12px;padding:28px;color:#1e293b">'
            . '<h2 style="color:#10b981;margin:0 0 16px">' . htmlspecialchars($title) . '</h2>'
            . $body_html
            . '<p style="color:#64748b;font-size:12px;margin-top:28px">AgriTrace — farm-to-table traceability.</p>'
            . '</div></div>';
    }

    public function otp_email($name, $code)
    {
        $body = '<p>Hi ' . htmlspecialchars($name) . ',</p>'
            . '<p>Your AgriTrace sign-in code is:</p>'
            . '<p style="font-size:30px;letter-spacing:6px;font-weight:bold;margin:12px 0">' . htmlspecialchars($code) . '</p>'
            . '<p>The code expires in 10 minutes. If you did not try to sign in, change your password right away.</p>';
        return $this->layout('Your sign-in code', $body);
    }

    public function reset_email($name, $link)
    {
        $body = '<p>Hi ' . htmlspecialchars($name) . ',</p>'
            . '<p>We received a request to reset your AgriTrace password.</p>'
            . '<p><a href="' . htmlspecialchars($link) . '" style="background:#10b981;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Reset password</a></p>'
            . '<p>This link expires in 30 minutes. If you did not request it, you can ignore this email.</p>';
        return $this->layout('Reset your password', $body);
    }

    /** @param array $order  order row (with items and trace codes) */
    public function receipt_email($name, array $order)
    {
        $rows = '';
        foreach ($order['items'] as $item) {
            $rows .= '<tr><td style="padding:6px 0">' . htmlspecialchars($item['product_name']) . '</td>'
                . '<td style="padding:6px 0;text-align:center">' . htmlspecialchars((string) $item['quantity']) . ' ' . htmlspecialchars($item['unit']) . '</td>'
                . '<td style="padding:6px 0;text-align:right">PHP ' . number_format($item['unit_price'] * $item['quantity'], 2) . '</td>'
                . '<td style="padding:6px 0;text-align:right;font-family:monospace">' . htmlspecialchars($item['trace_code'] ?? '') . '</td></tr>';
        }
        $body = '<p>Hi ' . htmlspecialchars($name) . ', thank you for your order.</p>'
            . '<p><b>Receipt #' . (int) $order['order_id'] . '</b> &middot; ' . htmlspecialchars($order['paid_at'] ?? $order['created_at']) . '</p>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px">'
            . '<tr style="color:#64748b"><th align="left">Product</th><th>Qty</th><th align="right">Amount</th><th align="right">Trace code</th></tr>'
            . $rows . '</table>'
            . '<p style="font-size:18px;margin-top:16px"><b>Total paid: PHP ' . number_format($order['total_amount'], 2) . '</b></p>'
            . '<p>Scan the QR code on your product page or use the trace code above to see the farm, the farmer and your order history.</p>';
        return $this->layout('Your AgriTrace receipt', $body);
    }

    public function report_email($summary_html)
    {
        return $this->layout('AgriTrace sales report', $summary_html);
    }

    public function complaint_email($name, $email, $message)
    {
        $body = '<p>A user submitted a complaint through the AgriTrace Help Center.</p>'
            . '<p><b>Name:</b> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<b>Email:</b> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><b>Complaint:</b></p>'
            . '<div style="white-space:pre-wrap;border-left:3px solid #10b981;padding:10px 14px;background:#f8fafc">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
        return $this->layout('New user complaint', $body);
    }
}
