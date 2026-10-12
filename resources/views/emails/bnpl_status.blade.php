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

        @if(!empty($adminNotes))
            <div class="details" style="border-left: 4px solid #273e8e;">
                <p style="margin:0 0 6px 0;"><strong>Admin note</strong></p>
                <p style="margin:0; white-space: pre-wrap;">{{ $adminNotes }}</p>
            </div>
        @endif

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
        @elseif($status === 'payment_complete')
            <div class="details">
                <p><strong>Loan amount:</strong> ₦{{ number_format($loanAmount, 2) }}</p>
                @if($repaymentDuration > 0)
                    <p><strong>Repayment period:</strong> {{ $repaymentDuration }} months</p>
                @endif
                <p><strong>Status:</strong> Payment Complete</p>
            </div>
            <p>
                <a href="{{ $continueUrl }}" class="btn" target="_blank" rel="noopener noreferrer">View your loan details</a>
            </p>
            <p style="font-size: 14px; color: #666;">
                Or copy this link into your browser:<br>
                <a href="{{ $continueUrl }}" style="word-break: break-all; color: #273e8e;">{{ $continueUrl }}</a>
            </p>
        @elseif($status === 'partner_offer')
            @if(!empty($partnerOffer))
            <div class="details">
                <p style="margin-bottom:12px;"><strong>Partner offer terms</strong></p>
                @if($partnerOffer['interest_rate'] !== null)
                    <p><strong>Interest rate:</strong> {{ rtrim(rtrim(number_format($partnerOffer['interest_rate'], 4, '.', ''), '0'), '.') }}%</p>
                @endif
                @if($partnerOffer['initial_deposit'] !== null)
                    @php
                        $depositPct = $partnerOffer['deposit_percent'] ?? null;
                        $depositPctLabel = null;
                        if ($depositPct !== null && (float) $depositPct > 0) {
                            $pct = (float) $depositPct;
                            $depositPctLabel = abs($pct - round($pct)) < 0.05
                                ? (string) round($pct)
                                : rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
                        }
                    @endphp
                    <p><strong>Initial deposit{{ $depositPctLabel ? ' ('.$depositPctLabel.'%)' : '' }}:</strong> ₦{{ number_format($partnerOffer['initial_deposit'], 2) }}</p>
                @endif
                @if($partnerOffer['admin_fees'] !== null)
                    <p><strong>Admin fees:</strong> ₦{{ number_format($partnerOffer['admin_fees'], 2) }}</p>
                @endif
                @if($partnerOffer['loan_amount'] !== null)
                    <p><strong>Loan amount:</strong> ₦{{ number_format($partnerOffer['loan_amount'], 2) }}</p>
                @endif
                @if($partnerOffer['repayment_amount'] !== null)
                    <p><strong>Repayment amount:</strong> ₦{{ number_format($partnerOffer['repayment_amount'], 2) }}</p>
                @endif
                @if($partnerOffer['monthly_repayment'] !== null)
                    <p><strong>Monthly repayment amount:</strong> ₦{{ number_format($partnerOffer['monthly_repayment'], 2) }}</p>
                @endif
                @if(!empty($partnerOffer['tenor']))
                    <p><strong>Tenor:</strong> {{ $partnerOffer['tenor'] }} {{ $partnerOffer['tenor'] == 1 ? 'month' : 'months' }}</p>
                @endif
                @if(!empty($partnerOffer['documents_count']))
                    <p><strong>Supporting documents:</strong> {{ $partnerOffer['documents_count'] }} attached to this email</p>
                @endif
            </div>
            @endif
            <p>
                <a href="{{ $continueUrl }}" class="btn" target="_blank" rel="noopener noreferrer">Review partner offer</a>
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
