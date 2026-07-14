<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OTP Code</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .container { background-color: #f5f7ff; border-radius: 12px; padding: 32px; margin: 20px 0; border: 1px solid #e2e8f0; overflow: hidden; }
        @include('emails.partials.brand_styles')
        .brand-header { margin: -32px -32px 24px -32px; }
        .otp-code { background-color: #fff; border: 2px solid #273e8e; border-radius: 8px; padding: 20px; text-align: center; font-size: 32px; font-weight: bold; color: #273e8e; letter-spacing: 8px; margin: 20px 0; }
        .message { color: #666; margin: 20px 0; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #999; text-align: center; }
        h2 { color: #273e8e; margin-top: 0; }
    </style>
</head>
<body>
    <div class="container">
        @include('emails.partials.brand_header', ['brandSubtitle' => 'Verification'])

        <h2>{{ \App\Support\MailBrand::heading('Your OTP code') }}</h2>

        @if(isset($first_name) && !empty($first_name))
            <p>Hi {{ $first_name }},</p>
        @endif
        
        @if(isset($customMessage) && !empty($customMessage))
            <div class="message">
                {{ $customMessage }}
            </div>
        @else
            <p>Please use the following OTP code to complete your email verification:</p>
        @endif
        
        <div class="otp-code">
            {{ $otpCode }}
        </div>
        
        <p style="color: #999; font-size: 12px;">
            This code will expire in 10 minutes. Please do not share this code with anyone.
        </p>

        @include('emails.partials.support_closing')
    </div>
</body>
</html>
