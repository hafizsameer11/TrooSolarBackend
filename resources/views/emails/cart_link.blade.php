<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $headline }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .container { background-color: #f5f7ff; border-radius: 12px; padding: 32px; margin: 20px 0; border: 1px solid #e2e8f0; overflow: hidden; }
        @include('emails.partials.brand_styles')
        .brand-header { margin: -32px -32px 24px -32px; }
        h1 { color: #273e8e; font-size: 22px; margin-top: 0; }
        .message { color: #444; margin: 20px 0; }
        .details { background: #fff; border-radius: 8px; padding: 16px 20px; margin: 16px 0; font-size: 14px; border: 1px solid #e2e8f0; }
        .details p { margin: 8px 0; }
        .item-block { border-bottom: 1px solid #e2e8f0; padding: 12px 0; }
        .item-block:last-child { border-bottom: none; }
        .item-name { font-weight: 600; color: #1e293b; margin: 0 0 6px 0; }
        .item-meta { margin: 2px 0; color: #64748b; font-size: 13px; }
        .total-row { margin-top: 16px; padding-top: 14px; border-top: 2px solid #e2e8f0; font-size: 16px; font-weight: 700; color: #273e8e; }
        .btn { display: inline-block; background-color: #273e8e; color: #ffffff !important; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: 600; margin: 16px 0; }
        .footer { margin-top: 28px; padding-top: 20px; border-top: 1px solid #cbd5e1; font-size: 12px; color: #64748b; text-align: center; }
        .muted { color: #64748b; font-size: 13px; }
        .admin-note { background: #e8eefb; color: #0f172a; border-left: 4px solid #273e8e; padding: 14px 16px; margin: 16px 0; border-radius: 8px; font-size: 14px; }
        .info-strip { background-color: #e8eefb; color: #1e293b; border-left: 4px solid #273e8e; padding: 12px 14px; margin: 16px 0; font-size: 14px; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="container">
        @include('emails.partials.brand_header', ['brandSubtitle' => $orderType === 'bnpl' ? \App\Support\MailBrand::BNPL_LABEL . ' Custom Order' : \App\Support\MailBrand::BUY_NOW_CUSTOM_ORDER_LABEL])

        <h1>{{ $headline }}</h1>

        <p>Hello {{ trim(($user->first_name ?? '') . ' ' . ($user->sur_name ?? '')) }},</p>

        <div class="message">
            @if($orderType === 'bnpl')
                <p>We have prepared a custom order for your <strong>{{ \App\Support\MailBrand::BNPL_LABEL }}</strong> application with the following items:</p>
            @else
                <p>We have prepared a custom order for you with the following items:</p>
            @endif
        </div>

        @if(!empty($customMessage))
            <div class="admin-note">
                <p style="margin: 0 0 8px 0;"><strong>Message from our team</strong></p>
                <div style="color: #0f172a;">{!! nl2br(e($customMessage)) !!}</div>
            </div>
        @endif

        @php
            $itemsCollection = is_array($cartItems) ? collect($cartItems) : $cartItems;
        @endphp

        <div class="details">
            @if($itemsCollection->count() > 0)
                @foreach($itemsCollection as $item)
                    @php
                        $itemable = $item->itemable ?? null;
                        $title = $itemable->title ?? $itemable->name ?? ('Item #' . ($item->itemable_id ?? $item->id ?? ''));
                        $qty = max(1, (int) ($item->quantity ?? 1));
                        $subtotal = (float) ($item->subtotal ?? 0);
                        $unitPrice = $qty > 0 ? ($subtotal / $qty) : (float) ($item->unit_price ?? 0);
                        $typeLabel = ($item->type ?? '') === 'bundle' ? 'Bundles' : 'Products';
                    @endphp
                    <div class="item-block">
                        <p class="item-name">{{ $title }}</p>
                        <p class="item-meta"><strong>Type:</strong> {{ $typeLabel }}</p>
                        <p class="item-meta"><strong>Quantity:</strong> {{ $qty }}</p>
                        <p class="item-meta"><strong>Unit Price:</strong> ₦{{ number_format($unitPrice, 2) }}</p>
                        <p class="item-meta"><strong>Subtotal:</strong> ₦{{ number_format($subtotal, 2) }}</p>
                    </div>
                @endforeach
            @endif

            @if(!empty($customItemsForDisplay))
                @foreach($customItemsForDisplay as $row)
                    @php
                        $nm = trim((string) ($row['name'] ?? ''));
                        $ds = trim((string) ($row['description'] ?? ''));
                        $pr = (float) ($row['price'] ?? 0);
                        $qt = max(1, (int) ($row['quantity'] ?? 1));
                        $ln = round($pr * $qt, 2);
                    @endphp
                    <div class="item-block">
                        <p class="item-name">{{ $nm }}</p>
                        <p class="item-meta"><strong>Type:</strong> Custom</p>
                        @if($ds !== '')
                            <p class="item-meta">{{ $ds }}</p>
                        @endif
                        <p class="item-meta"><strong>Quantity:</strong> {{ $qt }}</p>
                        <p class="item-meta"><strong>Unit Price:</strong> ₦{{ number_format($pr, 2) }}</p>
                        <p class="item-meta"><strong>Subtotal:</strong> ₦{{ number_format($ln, 2) }}</p>
                    </div>
                @endforeach
            @endif

            @if($itemsCollection->count() === 0 && empty($customItemsForDisplay))
                <p>See the message above for order details.</p>
            @endif

            <p class="total-row">Total: ₦{{ number_format((float) $summaryTotal, 2) }}</p>
        </div>

        <p class="message">
            @if($orderType === 'bnpl')
                Click the button below to continue your {{ \App\Support\MailBrand::BNPL_LABEL }} application with these items:
            @else
                Click the button below to open your {{ \App\Support\MailBrand::BUY_NOW_CUSTOM_ORDER_LABEL }} and proceed with checkout:
            @endif
        </p>

        <p>
            <a href="{{ $cartLink }}" class="btn" target="_blank" rel="noopener noreferrer">{{ $ctaLabel }}</a>
        </p>
        <p style="font-size: 14px; color: #64748b;">
            Or copy this link into your browser:<br>
            <a href="{{ $cartLink }}" style="word-break: break-all; color: #273e8e;">{{ $cartLink }}</a>
        </p>

        @if($orderType === 'bnpl')
            <p class="info-strip">
                <strong>{{ \App\Support\MailBrand::BNPL_LABEL }}:</strong> You will continue in the application flow (invoice, loan calculator, and application steps).
            </p>
        @endif

        @include('emails.partials.support_closing')
    </div>
</body>
</html>
