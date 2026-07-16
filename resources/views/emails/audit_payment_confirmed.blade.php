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
                        <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">
                            We have confirmed your payment receipt for your {{ $auditTypeTitle }} audit request. Your audit date and time are now confirmed.
                        </p>

                        @php
                            $auditDate = $auditRequest->approval_payment_date
                                ? $auditRequest->approval_payment_date->format('d M Y')
                                : null;
                            $auditTime = $auditRequest->approval_payment_time ?? null;
                            $paymentDate = $auditRequest->customer_payment_date
                                ? $auditRequest->customer_payment_date->format('d M Y')
                                : null;
                            $paymentTime = $auditRequest->customer_payment_time ?? null;
                        @endphp

                        <div style="margin:18px 0;padding:16px;border:2px solid #273e8e;border-radius:8px;background:#f5f7ff;">
                            <p style="margin:0 0 12px;font-size:16px;font-weight:bold;color:#273e8e;">Confirmed audit schedule</p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;line-height:1.6;">
                                <tr>
                                    <td style="padding:4px 0;color:#6b7280;width:140px;vertical-align:top;"><strong>Request ID</strong></td>
                                    <td style="padding:4px 0;color:#111827;">#{{ $auditRequest->id }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;color:#6b7280;vertical-align:top;"><strong>Audit type</strong></td>
                                    <td style="padding:4px 0;color:#111827;">{{ $auditTypeTitle }}</td>
                                </tr>
                                @if($auditDate)
                                    <tr>
                                        <td style="padding:4px 0;color:#6b7280;vertical-align:top;"><strong>Audit date</strong></td>
                                        <td style="padding:4px 0;color:#111827;">{{ $auditDate }}</td>
                                    </tr>
                                @endif
                                @if($auditTime)
                                    <tr>
                                        <td style="padding:4px 0;color:#6b7280;vertical-align:top;"><strong>Audit time</strong></td>
                                        <td style="padding:4px 0;color:#111827;">{{ $auditTime }}</td>
                                    </tr>
                                @endif
                                @if($paymentDate || $paymentTime)
                                    <tr>
                                        <td style="padding:4px 0;color:#6b7280;vertical-align:top;"><strong>Payment confirmed</strong></td>
                                        <td style="padding:4px 0;color:#111827;">{{ trim(($paymentDate ?: '').($paymentTime ? ' at '.$paymentTime : '')) }}</td>
                                    </tr>
                                @endif
                            </table>
                        </div>

                        <p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#374151;">
                            Our team will contact you if any additional information is required before the visit.
                        </p>

                        @include('emails.partials.support_closing')
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
