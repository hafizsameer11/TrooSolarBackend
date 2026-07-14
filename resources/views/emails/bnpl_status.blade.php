<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .container { background-color: #f8f9fa; border-radius: 12px; padding: 32px; margin: 20px 0; border: 1px solid #e2e8f0; overflow: hidden; }
        @include('emails.partials.brand_styles')
        .brand-header { margin: -32px -32px 24px -32px; }
        h2 { color: #273e8e; margin-top: 0; }
        .message { color: #444; margin: 20px 0; }
        .btn { display: inline-block; background-color: #273e8e; color: #fff !important; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: 600; margin: 16px 0; }
        .btn:hover { background-color: #1a2b6b; }
        .details { background: #fff; border-radius: 8px; padding: 16px; margin: 16px 0; font-size: 14px; }
        .details p { margin: 8px 0; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        @include('emails.partials.brand_header', ['brandSubtitle' => \App\Support\MailBrand::BNPL_LABEL])

        <h2>{{ $headingText }}</h2>

        <p>Hello {{ trim($user->first_name . ' ' . $user->sur_name) }},</p>

        <div class="message">
            {{ $bodyText }}
        </div>

        @if($status === 'approved')
            <div class="details">
                <p><strong>Loan amount:</strong> ₦{{ number_format($loanAmount, 2) }}</p>
                @if($downPayment !== null)
                    <p><strong>Initial deposit required:</strong> ₦{{ number_format($downPayment, 2) }}</p>
                @endif
                @if($repaymentDuration > 0)
                    <p><strong>Repayment period:</strong> {{ $repaymentDuration }} months</p>
                @endif
            </div>
            <p>
                <a href="{{ $continueUrl }}" class="btn" target="_blank" rel="noopener noreferrer">Continue to complete your order</a>
            </p>
            <p style="font-size: 14px; color: #666;">
                Or copy this link into your browser:<br>
                <a href="{{ $continueUrl }}" style="word-break: break-all; color: #273e8e;">{{ $continueUrl }}</a>
            </p>
        @elseif($status === 'counter_offer')
            <p>
                <a href="{{ $continueUrl }}" class="btn" target="_blank" rel="noopener noreferrer">Review counter offer</a>
            </p>
            <p style="font-size: 14px; color: #666;">
                Or copy this link into your browser:<br>
                <a href="{{ $continueUrl }}" style="word-break: break-all; color: #273e8e;">{{ $continueUrl }}</a>
            </p>
        @endif

        @include('emails.partials.support_closing')
    </div>
</body>
</html>
