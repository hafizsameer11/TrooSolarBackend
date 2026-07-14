<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order confirmed</title>
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
        .footer { margin-top: 28px; padding-top: 20px; border-top: 1px solid #cbd5e1; font-size: 12px; color: #64748b; text-align: center; }
        .muted { color: #64748b; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        @include('emails.partials.brand_header', ['brandSubtitle' => 'Order Confirmation'])

        <h1>{{ \App\Support\MailBrand::heading('Your order is confirmed') }}</h1>

        <p>Hello {{ trim($user->first_name . ' ' . $user->sur_name) }},</p>

        <div class="message">
            <p>Thank you for shopping with Troosolar. We have received your order and our team will contact you with delivery updates.</p>
            <p>You can view your order details anytime in your account.</p>
        </div>

        @include('emails.partials.order_email_simple_order_box', ['order' => $order, 'orderView' => $orderView])

        <p>
            <a href="{{ $orderDetailUrl }}" class="btn" target="_blank" rel="noopener noreferrer">See order details</a>
        </p>
        <p style="font-size: 14px; color: #64748b;">
            Or copy this link into your browser:<br>
            <a href="{{ $orderDetailUrl }}" style="word-break: break-all; color: #273e8e;">{{ $orderDetailUrl }}</a>
        </p>

        @include('emails.partials.support_closing')
    </div>
</body>
</html>
