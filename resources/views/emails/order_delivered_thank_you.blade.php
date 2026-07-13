<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ \App\Support\MailBrand::heading('Your order has been delivered') }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .container { background-color: #f5f7ff; border-radius: 12px; padding: 32px; margin: 20px 0; border: 1px solid #e2e8f0; overflow: hidden; }
        @include('emails.partials.brand_styles')
        .brand-header { margin: -32px -32px 24px -32px; }
        h1 { color: #273e8e; font-size: 22px; margin-top: 0; }
        .message { color: #444; margin: 20px 0; }
        .details { background: #fff; border-radius: 8px; padding: 16px 20px; margin: 16px 0; font-size: 14px; border: 1px solid #e2e8f0; }
        .details p { margin: 8px 0; }
        .item-list { margin: 12px 0 0 0; padding-left: 0; list-style: none; }
        .item-list li { margin: 6px 0; padding-left: 0; }
        .btn { display: inline-block; background-color: #273e8e; color: #fff !important; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: 600; margin: 16px 0; }
        .btn-secondary { background-color: #fff; color: #273e8e !important; border: 2px solid #273e8e; }
        .footer { margin-top: 28px; padding-top: 20px; border-top: 1px solid #cbd5e1; font-size: 12px; color: #64748b; text-align: center; }
        .muted { color: #64748b; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        @include('emails.partials.brand_header', ['brandSubtitle' => 'Order Delivered'])

        <h1>{{ \App\Support\MailBrand::heading('Thank you — your order is delivered') }}</h1>

        <p>Hello {{ trim($user->first_name . ' ' . $user->sur_name) }},</p>

        <div class="message">
            <p>Great news: your Troosolar order has been marked as <strong>delivered</strong>. We hope everything arrived safely and that you are happy with your purchase.</p>
            <p>If you have a moment, we would love to hear from you. Your feedback helps other customers and helps us improve.</p>
        </div>

        @include('emails.partials.order_email_simple_order_box', ['order' => $order, 'orderView' => $orderView])

        <p>
            <a href="{{ $dashboardOrdersUrl }}" class="btn" target="_blank" rel="noopener noreferrer">Open My orders &amp; leave a review</a>
        </p>
        <p style="font-size: 14px; color: #64748b;">
            Or copy this link into your browser:<br>
            <a href="{{ $dashboardOrdersUrl }}" style="word-break: break-all; color: #273e8e;">{{ $dashboardOrdersUrl }}</a>
        </p>

        <p class="message" style="font-size: 14px;">
            Thank you again for choosing Troosolar. If you need support, reply to this email or use the Help section in your account.
        </p>

        <div class="footer">
            <p>This message was sent because your order status was updated to delivered. Please do not reply if this email was unexpected — contact support instead.</p>
        </div>
    </div>
</body>
</html>
