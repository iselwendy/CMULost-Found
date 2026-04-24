<?php
/**
 * CMU Lost & Found — Gmail Mailer
 *
 * Wraps PHPMailer with Gmail SMTP credentials loaded from .env.
 * Drop-in replacement for the old sendSms() approach.
 *
 * Required .env keys:
 *   GMAIL_USER     — your Gmail address (e.g. cmulostfound@gmail.com)
 *   GMAIL_APP_PASS — 16-character App Password (not your regular password)
 *                    Generate one at: https://myaccount.google.com/apppasswords
 *                    (2FA must be enabled on the Gmail account first)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env if not already loaded
if (!isset($_ENV['GMAIL_USER'])) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
}

/**
 * Send a plain-text + HTML email via Gmail SMTP.
 *
 * @param  string $to_email   Recipient email address
 * @param  string $to_name    Recipient display name
 * @param  string $subject    Email subject line
 * @param  string $html_body  Full HTML body
 * @param  string $text_body  Plain-text fallback (auto-stripped from HTML if empty)
 * @return bool               True on success, false on failure
 */
function sendMail(
    string $to_email,
    string $to_name,
    string $subject,
    string $html_body,
    string $text_body = ''
): bool {
    $gmail_user = $_ENV['GMAIL_USER']     ?? '';
    $gmail_pass = $_ENV['GMAIL_APP_PASS'] ?? '';

    if (empty($gmail_user) || empty($gmail_pass)) {
        error_log('[Mailer] GMAIL_USER or GMAIL_APP_PASS not set in .env');
        return false;
    }

    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        error_log('[Mailer] Invalid recipient email: ' . $to_email);
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // ── Server settings ──────────────────────────────────────────────
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $gmail_user;
        $mail->Password   = $gmail_pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        // Suppress debug output in production
        $mail->SMTPDebug  = SMTP::DEBUG_OFF;

        // ── Sender & recipient ───────────────────────────────────────────
        $mail->setFrom($gmail_user, 'CMU Lost & Found');
        $mail->addAddress($to_email, $to_name);
        $mail->addReplyTo($gmail_user, 'CMU Student Affairs Office');

        // ── Content ──────────────────────────────────────────────────────
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = $text_body ?: strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html_body));

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('[Mailer] Failed to send email to ' . $to_email . ': ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Build the standard CMU Lost & Found HTML email wrapper.
 * Wrap your content block in this so all emails look consistent.
 *
 * @param  string $content   Inner HTML content (cards, paragraphs, etc.)
 * @return string            Full HTML email document
 */
function buildEmailTemplate(string $content): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CMU Lost & Found</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

          <!-- Header -->
          <tr>
            <td style="background:#003366;border-radius:16px 16px 0 0;padding:28px 36px;text-align:center;">
              <p style="margin:0;font-size:11px;color:#FFCC00;text-transform:uppercase;letter-spacing:.12em;font-weight:900;">
                City of Malabon University
              </p>
              <h1 style="margin:6px 0 0;font-size:22px;font-weight:900;color:#ffffff;letter-spacing:-.3px;">
                CMU Lost &amp; Found
              </h1>
              <p style="margin:4px 0 0;font-size:11px;color:#93c5fd;text-transform:uppercase;letter-spacing:.08em;">
                Student Affairs Office
              </p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="background:#ffffff;padding:36px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">
              {$content}
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0 0 16px 16px;
                        padding:20px 36px;text-align:center;">
              <p style="margin:0;font-size:11px;color:#94a3b8;">
                This is an automated message from the CMU Lost &amp; Found System.<br>
                Please do not reply to this email. For assistance, visit the Student Affairs Office.
              </p>
              <p style="margin:8px 0 0;font-size:10px;color:#cbd5e1;text-transform:uppercase;letter-spacing:.07em;">
                &copy; 2026 City of Malabon University &mdash; Campus Lost and Found System
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Pre-built: Match notification email (sent to the item owner).
 */
function sendMatchNotificationEmail(
    string $to_email,
    string $to_name,
    string $lost_item_title,
    string $found_location,
    int    $confidence
): bool {
    $subject = "🔍 Potential Match Found for Your Lost Item — {$lost_item_title}";

    $confidence_bar_color = $confidence >= 80 ? '#16a34a' : ($confidence >= 65 ? '#d97706' : '#64748b');
    $confidence_label     = $confidence >= 80 ? 'High Match' : ($confidence >= 65 ? 'Fair Match' : 'Possible Match');

    $content = <<<HTML
      <h2 style="margin:0 0 6px;font-size:20px;font-weight:900;color:#0f172a;">Good news, {$to_name}!</h2>
      <p style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.6;">
        Our matching engine has found a <strong>potential match</strong> for your lost item.
        Please visit the Student Affairs Office (SAO) to verify and claim it.
      </p>

      <!-- Match card -->
      <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:12px;padding:20px 24px;margin-bottom:24px;">
        <p style="margin:0 0 4px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#0369a1;">
          Your Lost Item
        </p>
        <p style="margin:0 0 16px;font-size:17px;font-weight:900;color:#0f172a;">{$lost_item_title}</p>

        <p style="margin:0 0 4px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#0369a1;">
          Found At
        </p>
        <p style="margin:0 0 16px;font-size:14px;color:#0f172a;font-weight:700;">{$found_location}</p>

        <p style="margin:0 0 6px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#0369a1;">
          AI Confidence Score
        </p>
        <div style="background:#e2e8f0;border-radius:99px;height:8px;margin-bottom:4px;">
          <div style="background:{$confidence_bar_color};height:8px;border-radius:99px;width:{$confidence}%;"></div>
        </div>
        <p style="margin:0;font-size:12px;font-weight:900;color:{$confidence_bar_color};">
          {$confidence}% — {$confidence_label}
        </p>
      </div>

      <!-- Steps -->
      <p style="margin:0 0 12px;font-size:13px;font-weight:900;color:#0f172a;text-transform:uppercase;letter-spacing:.05em;">
        Next Steps
      </p>
      <table cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:24px;">
        <tr>
          <td style="padding:8px 0;vertical-align:top;">
            <span style="display:inline-block;width:26px;height:26px;background:#003366;color:#FFCC00;
                          border-radius:50%;text-align:center;line-height:26px;font-size:11px;font-weight:900;
                          margin-right:12px;">1</span>
            <span style="font-size:13px;color:#334155;">Visit the <strong>Student Affairs Office (SAO)</strong> on campus.</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;vertical-align:top;">
            <span style="display:inline-block;width:26px;height:26px;background:#003366;color:#FFCC00;
                          border-radius:50%;text-align:center;line-height:26px;font-size:11px;font-weight:900;
                          margin-right:12px;">2</span>
            <span style="font-size:13px;color:#334155;">Bring a <strong>valid school ID</strong> for identity verification.</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;vertical-align:top;">
            <span style="display:inline-block;width:26px;height:26px;background:#003366;color:#FFCC00;
                          border-radius:50%;text-align:center;line-height:26px;font-size:11px;font-weight:900;
                          margin-right:12px;">3</span>
            <span style="font-size:13px;color:#334155;">A SAO officer will verify ownership and release the item to you.</span>
          </td>
        </tr>
      </table>

      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px 16px;margin-bottom:0;">
        <p style="margin:0;font-size:12px;color:#92400e;line-height:1.6;">
          <strong>⚠ Important:</strong> Items are held for a maximum of <strong>60 days</strong>.
          If unclaimed, they will be donated or disposed of per university policy.
          Please visit OSA as soon as possible.
        </p>
      </div>
HTML;

    return sendMail($to_email, $to_name, $subject, buildEmailTemplate($content));
}

/**
 * Pre-built: Report confirmation email (sent after a lost/found report is submitted).
 */
function sendReportConfirmationEmail(
    string $to_email,
    string $to_name,
    string $report_type,   // 'lost' | 'found'
    string $item_title,
    string $tracking_id
): bool {
    $is_found = $report_type === 'found';
    $subject  = $is_found
        ? "✅ Found Item Report Received — {$item_title}"
        : "📋 Lost Item Report Submitted — {$item_title}";

    $accent       = $is_found ? '#16a34a' : '#4f46e5';
    $accent_light = $is_found ? '#f0fdf4' : '#eef2ff';
    $accent_border= $is_found ? '#bbf7d0' : '#c7d2fe';
    $icon         = $is_found ? '🤝' : '🔍';
    $headline     = $is_found
        ? "Thank you for your honesty, {$to_name}!"
        : "Your lost item report has been posted, {$to_name}.";
    $message      = $is_found
        ? "Your found item report is now active. Please turn over the physical item to the <strong>Student Affairs Office (SAO)</strong> within 24 hours using the Turnover QR Code in your dashboard."
        : "We've posted your report to the public gallery and our matching engine is actively searching for a match. You will receive an email notification as soon as a match is found.";

    $content = <<<HTML
      <p style="margin:0 0 4px;font-size:28px;">{$icon}</p>
      <h2 style="margin:8px 0 6px;font-size:20px;font-weight:900;color:#0f172a;">{$headline}</h2>
      <p style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.6;">{$message}</p>

      <div style="background:{$accent_light};border:1.5px solid {$accent_border};border-radius:12px;padding:20px 24px;margin-bottom:24px;">
        <p style="margin:0 0 4px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:{$accent};">
          Item
        </p>
        <p style="margin:0 0 12px;font-size:16px;font-weight:900;color:#0f172a;">{$item_title}</p>
        <p style="margin:0 0 4px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:{$accent};">
          Tracking ID
        </p>
        <p style="margin:0;font-size:15px;font-weight:900;font-family:monospace;color:{$accent};">{$tracking_id}</p>
      </div>

      <p style="margin:0;font-size:13px;color:#64748b;text-align:center;">
        You can view your report status anytime in your
        <a href="#" style="color:#003366;font-weight:700;">CMU Lost &amp; Found Dashboard</a>.
      </p>
HTML;

    return sendMail($to_email, $to_name, $subject, buildEmailTemplate($content));
}

function sendTurnoverConfirmationEmail(
    string $to_email,
    string $to_name,
    string $item_title,
    string $tracking_id,
    string $shelf_location
): bool {
    $subject = "✅ Item Turnover Confirmed — {$item_title}";

    $content = <<<HTML
      <p style="margin:0 0 4px;font-size:28px;">📦</p>
      <h2 style="margin:8px 0 6px;font-size:20px;font-weight:900;color:#0f172a;">Turnover Received, {$to_name}!</h2>
      <p style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.6;">
        The Student Affairs Office has successfully received the item you surrendered.
        It is now in OSA custody and will be matched with its rightful owner.
      </p>

      <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:12px;padding:20px 24px;margin-bottom:24px;">
        <p style="margin:0 0 4px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#16a34a;">
          Item Surrendered
        </p>
        <p style="margin:0 0 12px;font-size:16px;font-weight:900;color:#0f172a;">{$item_title}</p>
        <p style="margin:0 0 4px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#16a34a;">
          Tracking ID
        </p>
        <p style="margin:0 0 12px;font-size:15px;font-weight:900;font-family:monospace;color:#16a34a;">{$tracking_id}</p>
      </div>

      <p style="margin:0 0 12px;font-size:13px;font-weight:900;color:#0f172a;text-transform:uppercase;letter-spacing:.05em;">
        What Happens Next
      </p>
      <table cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:24px;">
        <tr>
          <td style="padding:8px 0;vertical-align:top;">
            <span style="display:inline-block;width:26px;height:26px;background:#003366;color:#FFCC00;
                          border-radius:50%;text-align:center;line-height:26px;font-size:11px;font-weight:900;
                          margin-right:12px;">1</span>
            <span style="font-size:13px;color:#334155;">Our <strong>AI matching engine</strong> will search for a potential owner.</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;vertical-align:top;">
            <span style="display:inline-block;width:26px;height:26px;background:#003366;color:#FFCC00;
                          border-radius:50%;text-align:center;line-height:26px;font-size:11px;font-weight:900;
                          margin-right:12px;">2</span>
            <span style="font-size:13px;color:#334155;">If a match is found, the owner will be <strong>notified by email</strong>.</span>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0;vertical-align:top;">
            <span style="display:inline-block;width:26px;height:26px;background:#003366;color:#FFCC00;
                          border-radius:50%;text-align:center;line-height:26px;font-size:11px;font-weight:900;
                          margin-right:12px;">3</span>
            <span style="font-size:13px;color:#334155;">You can track the status in your <strong>CMU Lost &amp; Found Dashboard</strong>.</span>
          </td>
        </tr>
      </table>

      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px 16px;">
        <p style="margin:0;font-size:12px;color:#92400e;line-height:1.6;">
          <strong>Keep this email</strong> as proof that you have surrendered the item to OSA.
          Your Tracking ID <strong style="font-family:monospace;">{$tracking_id}</strong> is permanently recorded in our system.
        </p>
      </div>
HTML;

    return sendMail($to_email, $to_name, $subject, buildEmailTemplate($content));
}