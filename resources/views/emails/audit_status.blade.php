<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fb;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
                <tr>
                    <td style="background:#ffffff;padding:20px 24px 0;text-align:center;border-bottom:3px solid #273e8e;">
                        <img src="{{ \App\Support\MailBrand::logoUrl() }}" alt="Troosolar" width="260" style="max-width:260px;width:100%;height:auto;display:block;margin:0 auto 12px;" />
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px;">
                        <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;color:#111827;">{{ $headingText }}</h1>
                        <p style="margin:0 0 10px;font-size:15px;line-height:1.6;">Hello {{ trim(($user->first_name ?? '').' '.($user->sur_name ?? '')) ?: 'Customer' }},</p>
                        <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">{{ $bodyText }}</p>

                        <div style="margin:18px 0;padding:14px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa;">
                            <p style="margin:0 0 8px;font-size:14px;"><strong>Request ID:</strong> #{{ $auditRequest->id }}</p>
                            <p style="margin:0 0 8px;font-size:14px;"><strong>Status:</strong> {{ ucfirst($status) }}</p>
                            <p style="margin:0;font-size:14px;"><strong>Audit type:</strong> {{ $auditTypeTitle }}</p>
                        @if($status === 'rejected' && !empty($auditRequest->admin_notes))
                            <p style="margin:12px 0 0;font-size:14px;line-height:1.5;color:#374151;"><strong>Note:</strong> {{ $auditRequest->admin_notes }}</p>
                        @endif
                        </div>

                        @if($status === 'approved')
                            @php
                                $paymentDate = $auditRequest->approval_payment_date
                                    ? $auditRequest->approval_payment_date->format('d M Y')
                                    : null;
                                $paymentTime = $auditRequest->approval_payment_time ?? null;
                                $paymentAmount = $auditRequest->approval_payment_amount ?? null;
                                $paymentAccount = $auditRequest->approval_payment_account_details ?? null;
                            @endphp
                            @if($paymentDate || $paymentTime || $paymentAmount || $paymentAccount)
                                <div style="margin:18px 0;padding:16px;border:2px solid #273e8e;border-radius:8px;background:#f5f7ff;">
                                    <p style="margin:0 0 12px;font-size:16px;font-weight:bold;color:#273e8e;">Audit payment instructions</p>
                                    <p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:#374151;">
                                        Please pay the audit fee using the details below. After payment, reply to this email with your proof of payment so we can confirm your slot.
                                    </p>
                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;line-height:1.6;">
                                        @if($paymentDate)
                                            <tr>
                                                <td style="padding:4px 0;color:#6b7280;width:140px;vertical-align:top;"><strong>Date</strong></td>
                                                <td style="padding:4px 0;color:#111827;">{{ $paymentDate }}</td>
                                            </tr>
                                        @endif
                                        @if($paymentTime)
                                            <tr>
                                                <td style="padding:4px 0;color:#6b7280;vertical-align:top;"><strong>Time</strong></td>
                                                <td style="padding:4px 0;color:#111827;">{{ $paymentTime }}</td>
                                            </tr>
                                        @endif
                                        @if($paymentAmount !== null && $paymentAmount !== '')
                                            <tr>
                                                <td style="padding:4px 0;color:#6b7280;vertical-align:top;"><strong>Payment amount</strong></td>
                                                <td style="padding:4px 0;color:#111827;font-weight:bold;">₦{{ number_format((float) $paymentAmount, 2) }}</td>
                                            </tr>
                                        @endif
                                        @if($paymentAccount)
                                            <tr>
                                                <td style="padding:4px 0;color:#6b7280;vertical-align:top;"><strong>Account details</strong></td>
                                                <td style="padding:4px 0;color:#111827;white-space:pre-wrap;">{{ $paymentAccount }}</td>
                                            </tr>
                                        @endif
                                    </table>
                                </div>
                            @endif
                            @if(!empty($auditRequest->admin_notes))
                                <p style="margin:0 0 14px;font-size:14px;line-height:1.5;color:#374151;"><strong>Additional note:</strong> {{ $auditRequest->admin_notes }}</p>
                            @endif
                        @endif

                        <p style="margin:0;font-size:14px;line-height:1.6;color:#6b7280;">
                            Thank you again for choosing Troosolar. If you need support, use the Support Section in your account.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
