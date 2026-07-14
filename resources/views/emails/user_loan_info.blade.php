<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $emailSubject ?? 'Loan application – Troosolar' }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; line-height: 1.5; color: #1f2937; max-width: 640px; margin: 0 auto; padding: 16px; background-color: #f3f4f6; }
        .wrap { background: #fff; border-radius: 12px; overflow: hidden; border: 1px solid #e5e7eb; }
        @include('emails.partials.brand_styles')
        .wrap .brand-header { margin: 0; padding: 20px 28px 16px; }
        .header { background: linear-gradient(135deg, #273e8e 0%, #1e3270 100%); color: #fff; padding: 24px 28px; }
        .header h1 { margin: 0 0 8px 0; font-size: 22px; font-weight: 700; }
        .header p { margin: 0; font-size: 14px; opacity: 0.92; }
        .body { padding: 24px 28px 28px; }
        .intro { font-size: 14px; color: #374151; margin-bottom: 24px; }
        .section { margin-bottom: 20px; border-radius: 10px; padding: 18px 20px; }
        .section-blue { background: linear-gradient(135deg, #eff6ff 0%, #eef2ff 100%); border: 1px solid #bfdbfe; }
        .section-white { background: #fff; border: 1px solid #e5e7eb; }
        .section-green { background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border: 1px solid #a7f3d0; }
        h2 { color: #273e8e; font-size: 16px; margin: 0 0 14px 0; font-weight: 700; }
        h3 { color: #1e3270; font-size: 13px; margin: 14px 0 8px 0; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
        .detail-table { display: table; width: 100%; border-collapse: collapse; }
        .detail-row { display: table-row; }
        .detail-label, .detail-value { display: table-cell; padding: 6px 0; font-size: 13px; vertical-align: top; }
        .detail-label { color: #6b7280; width: 42%; padding-right: 12px; }
        .detail-value { color: #111827; font-weight: 600; }
        .property-list { margin-top: 4px; }
        .property-line { font-size: 13px; margin: 8px 0; color: #111827; line-height: 1.55; }
        .property-label { color: #374151; font-weight: 600; }
        .status-pill { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #dbeafe; color: #1e40af; text-transform: capitalize; }
        .summary-card { background: #fff; border: 1px solid #d1fae5; border-radius: 8px; padding: 14px 16px; margin-bottom: 10px; }
        .summary-card table { width: 100%; border-collapse: collapse; }
        .summary-card td { font-size: 13px; vertical-align: middle; }
        .summary-label { color: #374151; padding-right: 12px; }
        .summary-label.bold { font-weight: 700; }
        .summary-value { text-align: right; font-size: 16px; color: #111827; white-space: nowrap; }
        .summary-value.bold { font-weight: 700; }
        .summary-value.highlight { color: #273e8e; font-weight: 700; }
        .items-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
        .items-table th { text-align: left; padding: 8px 10px; background: #f9fafb; color: #6b7280; font-weight: 600; border-bottom: 1px solid #e5e7eb; }
        .items-table td { padding: 10px; border-bottom: 1px solid #f3f4f6; color: #111827; }
        .items-table td.num { text-align: right; white-space: nowrap; }
        .muted { color: #9ca3af; font-style: italic; font-size: 13px; }
        .attachments { list-style: none; padding: 0; margin: 8px 0 0 0; }
        .attachments li { font-size: 13px; padding: 6px 0; color: #065f46; }
        .attachments li::before { content: "✓ "; font-weight: 700; }
        .footer { padding: 16px 28px 24px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280; text-align: center; background: #f9fafb; }
    </style>
</head>
<body>
@php
    $d = $viewData ?? [];
    $app = $d['application'] ?? [];
    $customer = $d['customer'] ?? [];
    $property = $d['property'] ?? [];
    $ordered = $d['ordered_items'] ?? ['lines' => [], 'display' => null];
    $loanSummary = $d['loan_summary'] ?? null;
    $credit = $d['credit_check'] ?? [];
    $beneficiary = $d['beneficiary'] ?? [];
    $guarantor = $d['guarantor'] ?? null;
    $kycAttachments = $d['kyc_attachments'] ?? [];
    $admin = $d['admin'] ?? [];
    $showMono = !empty($d['show_mono_section']);
    $monoSummary = $d['mono_summary'] ?? [];
    $currentPowerSources = $property['current_power_sources'] ?? $property['landmark'] ?? null;
@endphp

<div class="wrap">
    @include('emails.partials.brand_header')
    <div class="header">
        <h1>{{ \App\Support\MailBrand::heading('Loan application for credit evaluation') }}</h1>
        <p>Application #{{ $app['id'] ?? $loanApplication->id }} · Troosolar</p>
    </div>

    <div class="body">
        <p class="intro">
            Hello {{ $partner->name }},<br><br>
            Please find the customer and application details below for your credit evaluation.
            @if(count($kycAttachments) > 0)
                Supporting documents are attached to this email.
            @endif
        </p>

        {{-- Application overview --}}
        <div class="section section-blue">
            <h2>{{ \App\Support\MailBrand::heading('Application overview') }}</h2>
            <div class="detail-table">
                <div class="detail-row">
                    <div class="detail-label">Application ID</div>
                    <div class="detail-value">#{{ $app['id'] ?? '—' }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Status</div>
                    <div class="detail-value"><span class="status-pill">{{ $app['status'] ?? '—' }}</span></div>
                </div>
                @if(!empty($app['created_at']))
                <div class="detail-row">
                    <div class="detail-label">Application date</div>
                    <div class="detail-value">{{ $app['created_at'] }}</div>
                </div>
                @endif
                @if(!empty($app['loan_amount_formatted']) && empty($loanSummary))
                <div class="detail-row">
                    <div class="detail-label">Loan amount</div>
                    <div class="detail-value" style="color:#273e8e;">{{ $app['loan_amount_formatted'] }}</div>
                </div>
                @endif
                @if(!empty($app['repayment_duration']) && empty($loanSummary))
                <div class="detail-row">
                    <div class="detail-label">Repayment duration</div>
                    <div class="detail-value">{{ $app['repayment_duration'] }} months</div>
                </div>
                @endif
                @if(!empty($app['customer_type']))
                <div class="detail-row">
                    <div class="detail-label">Customer type</div>
                    <div class="detail-value">{{ $app['customer_type'] }}</div>
                </div>
                @endif
                @if(!empty($app['product_category']))
                <div class="detail-row">
                    <div class="detail-label">Product category</div>
                    <div class="detail-value">{{ $app['product_category'] }}</div>
                </div>
                @endif
                @if(!empty($app['prior_application_id']))
                <div class="detail-row">
                    <div class="detail-label">Re-application</div>
                    <div class="detail-value">From application #{{ $app['prior_application_id'] }}</div>
                </div>
                @endif
            </div>

            @if(!empty($ordered['display']))
            <div style="margin-top:14px;padding-top:14px;border-top:1px solid #bfdbfe;">
                <div class="detail-label" style="display:block;margin-bottom:4px;">Bundle / product ordered</div>
                <div class="detail-value" style="display:block;">{{ $ordered['display'] }}</div>
            </div>
            @endif
        </div>

        {{-- Customer --}}
        <div class="section section-white">
            <h2>{{ \App\Support\MailBrand::heading('Customer details') }}</h2>
            <div class="detail-table">
                @if(!empty($customer['full_name']))
                <div class="detail-row">
                    <div class="detail-label">Full name</div>
                    <div class="detail-value">{{ $customer['full_name'] }}</div>
                </div>
                @else
                <div class="detail-row">
                    <div class="detail-label">Name</div>
                    <div class="detail-value">{{ trim(($customer['first_name'] ?? '') . ' ' . ($customer['surname'] ?? '')) ?: '—' }}</div>
                </div>
                @endif
                <div class="detail-row">
                    <div class="detail-label">Email</div>
                    <div class="detail-value">{{ $customer['email'] ?? '—' }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Phone</div>
                    <div class="detail-value">{{ $customer['phone'] ?? '—' }}</div>
                </div>
                @if(!empty($customer['bvn']))
                <div class="detail-row">
                    <div class="detail-label">BVN</div>
                    <div class="detail-value">{{ $customer['bvn'] }}</div>
                </div>
                @endif
                @if(!empty($customer['social_media']))
                <div class="detail-row">
                    <div class="detail-label">Social media</div>
                    <div class="detail-value">{{ $customer['social_media'] }}</div>
                </div>
                @endif
            </div>
        </div>

        {{-- Ordered items table --}}
        @if(count($ordered['lines'] ?? []) > 0)
        <div class="section section-white">
            <h2>{{ \App\Support\MailBrand::heading('Product / bundle ordered') }}</h2>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Type</th>
                        <th style="text-align:center;">Qty</th>
                        <th style="text-align:right;">Unit price</th>
                        <th style="text-align:right;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ordered['lines'] as $line)
                    <tr>
                        <td><strong>{{ $line['title'] }}</strong></td>
                        <td>{{ $line['kind_label'] }}</td>
                        <td style="text-align:center;">{{ $line['quantity'] }}</td>
                        <td class="num">{{ $line['unit_price_formatted'] ?? '—' }}</td>
                        <td class="num">{{ $line['subtotal_formatted'] ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        {{-- Property --}}
        <div class="section section-white">
            <h2>{{ \App\Support\MailBrand::heading('Property') }}</h2>
            <div class="property-list">
                <p class="property-line">
                    <span class="property-label">State:</span>
                    {{ $property['state'] ?? '—' }}
                </p>
                <p class="property-line">
                    <span class="property-label">Address:</span>
                    {{ $property['address'] ?? '—' }}
                </p>
                <p class="property-line">
                    <span class="property-label">Current Power Sources:</span>
                    {{ $currentPowerSources ?? '—' }}
                </p>
                <p class="property-line">
                    <span class="property-label">Floors:</span>
                    {{ isset($property['floors']) && $property['floors'] !== '' ? $property['floors'] : '—' }}
                </p>
                <p class="property-line">
                    <span class="property-label">Rooms:</span>
                    {{ isset($property['rooms']) && $property['rooms'] !== '' ? $property['rooms'] : '—' }}
                </p>
                <p class="property-line">
                    <span class="property-label">Gated Estate:</span>
                    {{ !empty($property['is_gated_estate']) ? 'Yes' : 'No' }}
                </p>
                @if(!empty($property['is_gated_estate']))
                <p class="property-line">
                    <span class="property-label">Estate name:</span>
                    {{ $property['estate_name'] ?? '—' }}
                </p>
                <p class="property-line">
                    <span class="property-label">Estate address:</span>
                    {{ $property['estate_address'] ?? '—' }}
                </p>
                @endif
            </div>
        </div>

        {{-- Loan summary (matches admin BNPL view) --}}
        @if(!empty($loanSummary))
        <div class="section section-green">
            <h2 style="margin-bottom:16px;">{{ \App\Support\MailBrand::heading('Loan summary') }}</h2>
            @foreach($loanSummary['rows'] as $row)
            <div class="summary-card">
                <table>
                    <tr>
                        <td class="summary-label {{ !empty($row['bold']) ? 'bold' : '' }}">
                            {{ $row['num'] }}. {{ $row['label'] }}
                        </td>
                        <td class="summary-value {{ !empty($row['bold']) ? 'bold' : '' }} {{ $row['num'] === 5 ? 'highlight' : '' }}">
                            {{ $row['value_formatted'] }}
                        </td>
                    </tr>
                </table>
            </div>
            @endforeach
            <div class="summary-card" style="margin-bottom:0;">
                <table>
                    <tr>
                        <td class="summary-label">6. Loan Tenor</td>
                        <td class="summary-value highlight">{{ $loanSummary['tenor_label'] }}</td>
                    </tr>
                </table>
            </div>
        </div>
        @endif

        {{-- Credit check --}}
        <div class="section section-white">
            <h2>{{ \App\Support\MailBrand::heading('Credit check & identity') }}</h2>
            <div class="detail-table">
                <div class="detail-row">
                    <div class="detail-label">Credit check method</div>
                    <div class="detail-value">{{ $credit['method'] ?? '—' }}</div>
                </div>
                @if(!empty($credit['mono_credit_status']))
                <div class="detail-row">
                    <div class="detail-label">Mono credit status</div>
                    <div class="detail-value">{{ $credit['mono_credit_status'] }}</div>
                </div>
                @endif
            </div>

            @if($showMono && count($monoSummary) > 0)
            <h3>{{ \App\Support\MailBrand::heading('Mono calculation') }}</h3>
            <div class="detail-table">
                @foreach($monoSummary as $line)
                <div class="detail-row">
                    <div class="detail-label">{{ $line['label'] }}</div>
                    <div class="detail-value">{{ $line['value'] }}</div>
                </div>
                @endforeach
            </div>
            @endif

            <h3>{{ \App\Support\MailBrand::heading('Linked bank account') }}</h3>
            @if($linkAccount)
            <div class="detail-table">
                <div class="detail-row">
                    <div class="detail-label">Account number</div>
                    <div class="detail-value">{{ $linkAccount->account_number }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Account name</div>
                    <div class="detail-value">{{ $linkAccount->account_name }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Bank</div>
                    <div class="detail-value">{{ $linkAccount->bank_name }}</div>
                </div>
            </div>
            @else
            <p class="muted">No linked account on file.</p>
            @endif
        </div>

        {{-- Beneficiary --}}
        @if(!empty($beneficiary['name']) || !empty($beneficiary['email']) || !empty($beneficiary['phone']))
        <div class="section section-white">
            <h2>{{ \App\Support\MailBrand::heading('Beneficiary') }}</h2>
            <div class="detail-table">
                <div class="detail-row">
                    <div class="detail-label">Name</div>
                    <div class="detail-value">{{ $beneficiary['name'] ?? '—' }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Email</div>
                    <div class="detail-value">{{ $beneficiary['email'] ?? '—' }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Phone</div>
                    <div class="detail-value">{{ $beneficiary['phone'] ?? '—' }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Relationship</div>
                    <div class="detail-value">{{ $beneficiary['relationship'] ?? '—' }}</div>
                </div>
            </div>
        </div>
        @endif

        {{-- Guarantor --}}
        @if(!empty($guarantor))
        <div class="section section-white">
            <h2>{{ \App\Support\MailBrand::heading('Guarantor') }}</h2>
            <div class="detail-table">
                @if(!empty($guarantor['full_name']))
                <div class="detail-row">
                    <div class="detail-label">Full name</div>
                    <div class="detail-value">{{ $guarantor['full_name'] }}</div>
                </div>
                @endif
                @if(!empty($guarantor['email']))
                <div class="detail-row">
                    <div class="detail-label">Email</div>
                    <div class="detail-value">{{ $guarantor['email'] }}</div>
                </div>
                @endif
                @if(!empty($guarantor['phone']))
                <div class="detail-row">
                    <div class="detail-label">Phone</div>
                    <div class="detail-value">{{ $guarantor['phone'] }}</div>
                </div>
                @endif
                @if(!empty($guarantor['bvn']))
                <div class="detail-row">
                    <div class="detail-label">BVN</div>
                    <div class="detail-value">{{ $guarantor['bvn'] }}</div>
                </div>
                @endif
                @if(!empty($guarantor['relationship']))
                <div class="detail-row">
                    <div class="detail-label">Relationship</div>
                    <div class="detail-value">{{ $guarantor['relationship'] }}</div>
                </div>
                @endif
                @if(!empty($guarantor['status']))
                <div class="detail-row">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">{{ $guarantor['status'] }}</div>
                </div>
                @endif
                @if(!empty($guarantor['has_signed_form']))
                <div class="detail-row">
                    <div class="detail-label">Signed form</div>
                    <div class="detail-value" style="color:#065f46;">Attached to this email</div>
                </div>
                @endif
            </div>
        </div>
        @endif

        {{-- Attachments (no file paths) --}}
        @if(count($kycAttachments) > 0)
        <div class="section section-white">
            <h2>{{ \App\Support\MailBrand::heading('Documents attached') }}</h2>
            <ul class="attachments">
                @foreach($kycAttachments as $doc)
                <li>{{ $doc['label'] }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        {{-- Admin notes --}}
        @if(!empty($admin['notes']) || !empty($admin['counter_offer_min_deposit']) || !empty($admin['counter_offer_min_tenor']))
        <div class="section section-white">
            <h2>{{ \App\Support\MailBrand::heading('Admin notes') }}</h2>
            <div class="detail-table">
                @if(!empty($admin['notes']))
                <div class="detail-row">
                    <div class="detail-label">Notes</div>
                    <div class="detail-value">{{ $admin['notes'] }}</div>
                </div>
                @endif
                @if(!empty($admin['counter_offer_min_deposit']))
                <div class="detail-row">
                    <div class="detail-label">Counter-offer min deposit</div>
                    <div class="detail-value">{{ $admin['counter_offer_min_deposit'] }}</div>
                </div>
                @endif
                @if(!empty($admin['counter_offer_min_tenor']))
                <div class="detail-row">
                    <div class="detail-label">Counter-offer min tenor</div>
                    <div class="detail-value">{{ $admin['counter_offer_min_tenor'] }} months</div>
                </div>
                @endif
            </div>
        </div>
        @endif

        <p style="margin-top:8px;font-size:14px;">
            Thank you again for choosing Troosolar. If you need support, use the Support Section in your account.
        </p>
    </div>
</div>
</body>
</html>
