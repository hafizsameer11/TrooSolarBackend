<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ \App\Support\MailBrand::BNPL_LABEL }} Application Submitted</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; background: #f3f4f6; }
        .container { background: #f5f7ff; border-radius: 12px; padding: 32px; border: 1px solid #e2e8f0; overflow: hidden; }
        @include('emails.partials.brand_styles')
        .brand-header { margin: -32px -32px 24px -32px; }
        h2 { color: #273e8e; margin-top: 0; }
        .btn { display: inline-block; background: #273e8e; color: #fff !important; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="container">
        @include('emails.partials.brand_header', ['brandSubtitle' => \App\Support\MailBrand::BNPL_LABEL . ' Application'])

        <h2>{{ \App\Support\MailBrand::heading('Application submitted') }}</h2>
        <p>Hello {{ trim(($user->first_name ?? '') . ' ' . ($user->sur_name ?? '')) ?: 'Customer' }},</p>

        <p>Your {{ \App\Support\MailBrand::BNPL_LABEL }} application has been submitted successfully.</p>

        <p>
            <strong>Application ID:</strong> #{{ $application->id }}<br>
            <strong>Status:</strong> {{ strtoupper($application->status ?? 'pending') }}
        </p>

        <p>Our team will review your application and share feedback within 24–72 hours.</p>

        <p>
            <a href="{{ $applicationUrl }}" class="btn">Track your application</a>
        </p>
        <p style="font-size: 13px; color: #64748b; word-break: break-all;">{{ $applicationUrl }}</p>

        @include('emails.partials.support_closing')
    </div>
</body>
</html>
