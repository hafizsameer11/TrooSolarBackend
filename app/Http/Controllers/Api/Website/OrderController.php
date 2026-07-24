<?php

namespace App\Http\Controllers\Api\Website;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Wallet;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Bundles;
use App\Models\LoanApplication;
use App\Models\LoanCalculation;
use App\Helpers\ResponseHelper;
use App\Models\CartItem;
use App\Models\CheckoutSetting;
use App\Models\DeliveryAddress;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderDeliveredThankYouMail;
use App\Mail\OrderPlacedConfirmationMail;
use App\Mail\OrderStatusUpdatedMail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Exception;
use App\Models\ReferralSettings;
use App\Models\AuditRequest;
use App\Services\ReferralRewardService;
use App\Support\CheckoutPricing;

class OrderController extends Controller
{
    private function resolveCatalogUnitPrice($itemable): float
    {
        if ($itemable instanceof Product) {
            $discount = (float) ($itemable->discount_price ?? 0);
            $basePrice = (float) ($itemable->price ?? 0);
            return $discount > 0 ? $discount : max(0, $basePrice);
        }

        if ($itemable instanceof Bundles) {
            $discount = (float) ($itemable->discount_price ?? 0);
            $basePrice = (float) ($itemable->total_price ?? 0);
            return $discount > 0 ? $discount : max(0, $basePrice);
        }

        return (float) ($itemable->price ?? $itemable->total_price ?? 0);
    }

    private function applyOutrightDiscount(float $amount, ?float $percentage): float
    {
        $pct = max(0, (float) ($percentage ?? 0));
        if ($pct <= 0 || $amount <= 0) {
            return $amount;
        }
        return max(0, round($amount - (($amount * $pct) / 100), 2));
    }

    /**
     * Catalog items subtotal for Buy Now / invoice (before outright discount).
     * Never use grand total / total_price — that inflates discount %.
     */
    private function resolveBuyNowCatalogItemsSubtotal(
        Order $order,
        ?Bundles $bundle,
        ?Product $product,
        float $productLinesCatalogSum = 0.0,
    ): float {
        $referral = ReferralSettings::getSettings();
        $pct = max(0.0, (float) ($referral->outright_discount_percentage ?? 0));
        $storedAfter = Schema::hasColumn('orders', 'product_price') && $order->product_price !== null
            ? (float) $order->product_price
            : null;

        // 1) Bundle list / selling price (same source as checkout amount for choose-system).
        if ($bundle) {
            $bundleCatalog = $this->resolveCatalogUnitPrice($bundle);
            if ($bundleCatalog > 0.005) {
                return round($bundleCatalog, 2);
            }
        }

        // 2) Sum of product order lines (multi-product / custom-order Buy Now).
        // Must beat the single-$product fallback — otherwise Sub-Total only shows the first item.
        if ($productLinesCatalogSum > 0.005) {
            return round($productLinesCatalogSum, 2);
        }

        // 3) Standalone product catalog price (legacy single-product orders).
        if ($product) {
            $productCatalog = $this->resolveCatalogUnitPrice($product);
            if ($productCatalog > 0.005) {
                return round($productCatalog, 2);
            }
        }

        // 4) Back-calculate from stored after-discount price + configured outright %.
        if ($storedAfter !== null && $storedAfter > 0.005 && $pct > 0 && $pct < 100) {
            return round($storedAfter / (1 - ($pct / 100)), 2);
        }

        if ($storedAfter !== null && $storedAfter > 0.005) {
            return round($storedAfter, 2);
        }

        return 0.0;
    }

    /**
     * Resolve catalog subtotal, discount, fees, VAT, and grand total for order receipts / admin invoice.
     *
     * @return array<string, float|null>
     */
    private function resolveOrderPaymentBreakdown(Order $order, float $catalogItemsSubtotal): array
    {
        $orderType = strtolower((string) ($order->order_type ?? ''));
        $settings = CheckoutSetting::get(
            $orderType === 'shop'
                ? CheckoutSetting::CHANNEL_SHOP
                : CheckoutSetting::CHANNEL_BUY_NOW
        );
        $vatPct = (float) ($settings->vat_percentage ?? config('checkout.vat_percentage', 7.5));
        $insPct = (float) ($settings->insurance_fee_percentage ?? config('checkout.insurance_fee_percentage', 3));
        $referral = ReferralSettings::getSettings();
        $configuredDiscountPct = max(0.0, (float) ($referral->outright_discount_percentage ?? 0));

        $delivery = (float) ($order->delivery_fee ?? 0);
        $installation = (float) ($order->installation_price ?? 0);
        $insurance = (float) ($order->insurance_fee ?? 0);
        $material = Schema::hasColumn('orders', 'material_cost') ? (float) ($order->material_cost ?? 0) : 0.0;
        $inspection = Schema::hasColumn('orders', 'inspection_fee') ? (float) ($order->inspection_fee ?? 0) : 0.0;
        // Match Buy Now checkout: insurance is added after VAT, not inside the VAT base.
        $serviceFees = round($delivery + $installation + $material + $inspection, 2);
        $fees = round($serviceFees + $insurance, 2);

        $itemsAfter = $catalogItemsSubtotal;
        if (Schema::hasColumn('orders', 'product_price') && $order->product_price !== null) {
            $itemsAfter = (float) $order->product_price;
        }

        $vat = Schema::hasColumn('orders', 'vat_amount') ? (float) ($order->vat_amount ?? 0) : 0.0;
        $storedTotal = (float) ($order->total_price ?? 0);
        $discount = max(0.0, round($catalogItemsSubtotal - $itemsAfter, 2));
        $isBuyNow = strtolower((string) ($order->order_type ?? '')) === 'buy_now';

        // Buy Now receipts: mirror checkout Payment summary.
        if ($isBuyNow && $catalogItemsSubtotal > 0.005) {
            // Prefer configured outright % (usually 10%) when it matches stored product_price.
            if ($configuredDiscountPct > 0 && $configuredDiscountPct < 100) {
                $expectedAfter = round($catalogItemsSubtotal * (1 - ($configuredDiscountPct / 100)), 2);
                $expectedDiscount = round($catalogItemsSubtotal - $expectedAfter, 2);
                if (
                    $itemsAfter <= 0.005
                    || abs($itemsAfter - $expectedAfter) < max(1.0, $catalogItemsSubtotal * 0.01)
                    || $discount <= 0.005
                ) {
                    $itemsAfter = Schema::hasColumn('orders', 'product_price') && $order->product_price !== null
                        ? (float) $order->product_price
                        : $expectedAfter;
                    // If stored after-discount is within 1% of expected, use expected 10% labels.
                    if (abs($itemsAfter - $expectedAfter) < max(1.0, $catalogItemsSubtotal * 0.01)) {
                        $itemsAfter = $expectedAfter;
                        $discount = $expectedDiscount;
                    } else {
                        $discount = max(0.0, round($catalogItemsSubtotal - $itemsAfter, 2));
                    }
                } else {
                    $discount = max(0.0, round($catalogItemsSubtotal - $itemsAfter, 2));
                }
            }

            if ($discount <= 0.005 && $configuredDiscountPct > 0) {
                $discount = round($catalogItemsSubtotal * ($configuredDiscountPct / 100), 2);
                $itemsAfter = max(0.0, round($catalogItemsSubtotal - $discount, 2));
            }

            $sumBeforeVat = round($itemsAfter + $serviceFees, 2);
            if ($vat <= 0.005 && $sumBeforeVat > 0) {
                $vat = (float) CheckoutPricing::vatAmount($sumBeforeVat, $vatPct);
            }
            $grandTotal = $storedTotal > 0
                ? $storedTotal
                : round($sumBeforeVat + $vat + $insurance, 2);

            $discountPct = null;
            if ($discount > 0.005 && $catalogItemsSubtotal > 0) {
                $discountPct = round(100 * ($discount / $catalogItemsSubtotal), 2);
                // Snap to configured Buy Now outright % when within 0.5 points (avoids 19% UI noise).
                if ($configuredDiscountPct > 0 && abs($discountPct - $configuredDiscountPct) <= 0.5) {
                    $discountPct = round($configuredDiscountPct, 2);
                    $discount = round($catalogItemsSubtotal * ($configuredDiscountPct / 100), 2);
                    $itemsAfter = max(0.0, round($catalogItemsSubtotal - $discount, 2));
                    $sumBeforeVat = round($itemsAfter + $serviceFees, 2);
                    if ((float) ($order->vat_amount ?? 0) <= 0.005) {
                        $vat = (float) CheckoutPricing::vatAmount($sumBeforeVat, $vatPct);
                    }
                }
            }

            return [
                'catalog_items_subtotal' => round($catalogItemsSubtotal, 2),
                'outright_discount_amount' => $discount > 0.005 ? round($discount, 2) : 0.0,
                'outright_discount_percentage' => $discountPct,
                'items_subtotal_after_discount' => round($itemsAfter, 2),
                'delivery_fee' => $delivery,
                'installation_fee' => $installation,
                'insurance_fee' => $insurance,
                'material_cost' => $material,
                'inspection_fee' => $inspection,
                'fees_total' => $fees,
                'sum_before_vat' => $sumBeforeVat,
                'vat_amount' => round($vat, 2),
                'vat_percentage' => $vatPct,
                'insurance_fee_percentage' => $insPct,
                'grand_total' => round($grandTotal, 2),
            ];
        }

        $fees = round($delivery + $installation + $insurance + $material + $inspection, 2);
        $hasStoredProductPrice = Schema::hasColumn('orders', 'product_price') && $order->product_price !== null;
        $isShopOrder = strtolower((string) ($order->order_type ?? '')) === 'shop';

        // Shop cart orders: trust persisted product_price (after-discount items). Do not infer a phantom discount.
        if ($hasStoredProductPrice && ($isShopOrder || ! $isBuyNow)) {
            $itemsAfter = (float) $order->product_price;
            $discount = max(0.0, round($catalogItemsSubtotal - $itemsAfter, 2));
            if ($vat <= 0.005 && $itemsAfter > 0) {
                // Shop VAT is charged on discounted items only (not delivery/install).
                $expectedVat = (float) CheckoutPricing::vatAmount($itemsAfter, $vatPct);
                if ($storedTotal > 0) {
                    $expectedTotal = round($itemsAfter + $delivery + $installation + $inspection + $insurance + $expectedVat, 2);
                    if (abs($storedTotal - $expectedTotal) < 1.0) {
                        $vat = $expectedVat;
                    }
                } else {
                    $vat = $expectedVat;
                }
            }
            $sumBeforeVat = round($itemsAfter + $delivery + $installation + $material + $inspection, 2);
            $grandTotal = $storedTotal > 0
                ? $storedTotal
                : round($sumBeforeVat + $vat + $insurance, 2);
            $discountPct = null;
            if ($discount > 0.005 && $catalogItemsSubtotal > 0) {
                $discountPct = round(100 * ($discount / $catalogItemsSubtotal), 2);
                if ($configuredDiscountPct > 0 && abs($discountPct - $configuredDiscountPct) <= 0.5) {
                    $discountPct = round($configuredDiscountPct, 2);
                }
            }

            return [
                'catalog_items_subtotal' => round($catalogItemsSubtotal, 2),
                'outright_discount_amount' => $discount > 0.005 ? round($discount, 2) : 0.0,
                'outright_discount_percentage' => $discountPct,
                'items_subtotal_after_discount' => round($itemsAfter, 2),
                'delivery_fee' => $delivery,
                'installation_fee' => $installation,
                'insurance_fee' => $insurance,
                'material_cost' => $material,
                'inspection_fee' => $inspection,
                'fees_total' => round($delivery + $installation + $insurance + $material + $inspection, 2),
                'sum_before_vat' => $sumBeforeVat,
                'vat_amount' => round($vat, 2),
                'vat_percentage' => $vatPct,
                'insurance_fee_percentage' => $insPct,
                'grand_total' => round($grandTotal, 2),
            ];
        }

        if ($storedTotal > 0) {
            $postFees = round($storedTotal - $fees, 2);
            if ($postFees > 0) {
                $inferredAfter = round($postFees / (1 + ($vatPct / 100)), 2);
                $inferredVat = (float) CheckoutPricing::vatAmount($inferredAfter, $vatPct);
                if (abs($storedTotal - ($inferredAfter + $fees + $inferredVat)) < 1.0) {
                    if ($discount <= 0.005 || abs($itemsAfter - $catalogItemsSubtotal) < 0.01) {
                        $itemsAfter = $inferredAfter;
                        $discount = max(0.0, round($catalogItemsSubtotal - $itemsAfter, 2));
                    }
                    if ($vat <= 0.005) {
                        $vat = $inferredVat;
                    }
                } elseif ($discount <= 0.005 && $catalogItemsSubtotal > 0) {
                    $inferredAfter = round($storedTotal - $fees - $vat, 2);
                    if ($inferredAfter > 0 && $inferredAfter + 0.005 < $catalogItemsSubtotal) {
                        $itemsAfter = $inferredAfter;
                        $discount = round($catalogItemsSubtotal - $inferredAfter, 2);
                    }
                }
            }

            if ($vat <= 0.005 && $itemsAfter > 0) {
                $expectedVat = (float) CheckoutPricing::vatAmount($itemsAfter, $vatPct);
                if (abs($storedTotal - ($itemsAfter + $fees + $expectedVat)) < 1.0) {
                    $vat = $expectedVat;
                } elseif (abs($storedTotal - ($itemsAfter + $fees)) < 1.0 && $expectedVat > 0.005) {
                    // Legacy rows: total_price stored pre-VAT while checkout charged VAT on items.
                    $vat = $expectedVat;
                }
            }
        }

        $sumBeforeVat = round($itemsAfter + $fees, 2);
        $grandTotal = $storedTotal > 0
            ? $storedTotal
            : round($sumBeforeVat + $vat, 2);

        if ($storedTotal > 0 && $vat > 0.005 && abs($storedTotal - $sumBeforeVat) < 1.0) {
            $grandTotal = round($sumBeforeVat + $vat, 2);
        }

        $discountPct = null;
        if ($discount > 0.005 && $catalogItemsSubtotal > 0) {
            $discountPct = round(100 * ($discount / $catalogItemsSubtotal), 2);
        }

        return [
            'catalog_items_subtotal' => round($catalogItemsSubtotal, 2),
            'outright_discount_amount' => $discount > 0.005 ? round($discount, 2) : 0.0,
            'outright_discount_percentage' => $discountPct,
            'items_subtotal_after_discount' => round($itemsAfter, 2),
            'delivery_fee' => $delivery,
            'installation_fee' => $installation,
            'insurance_fee' => $insurance,
            'material_cost' => $material,
            'inspection_fee' => $inspection,
            'fees_total' => $fees,
            'sum_before_vat' => $sumBeforeVat,
            'vat_amount' => round($vat, 2),
            'vat_percentage' => $vatPct,
            'insurance_fee_percentage' => $insPct,
            'grand_total' => round($grandTotal, 2),
        ];
    }
    private function isAuthenticatedAdmin(): bool
    {
        $user = Auth::user();
        if (! $user || ! isset($user->role)) {
            return false;
        }

        return in_array(strtolower((string) $user->role), ['admin', 'superadmin', 'super_admin'], true);
    }

    /** Customer-facing label for order_status values. */
    private function humanizeOrderStatus(?string $status): string
    {
        $k = strtolower(trim((string) ($status ?? '')));
        if ($k === '') {
            return '—';
        }

        return match ($k) {
            'pending' => 'Pending',
            'processing' => 'Processing',
            'shipped' => 'Shipped',
            'delivered', 'completed' => 'Delivered',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
            default => ucfirst($k),
        };
    }

    /**
     * Notify the customer by email whenever the order status actually changes.
     */
    private function notifyCustomerOrderStatusChange(Order $order, ?string $previousStatus): void
    {
        $new = strtolower(trim((string) ($order->order_status ?? '')));
        $prev = strtolower(trim((string) ($previousStatus ?? '')));
        if ($new === '' || $new === $prev) {
            return;
        }

        $order->loadMissing('user');
        $user = $order->user;
        if (! $user || ! $user->email) {
            return;
        }

        try {
            $order->loadMissing(['user', 'items.itemable', 'deliveryAddress']);
            $orderView = $this->formatOrder($order->fresh(['user', 'items.itemable', 'deliveryAddress']), []);

            if (in_array($new, ['delivered', 'completed', 'complete'], true)) {
                Mail::to($user->email)->send(new OrderDeliveredThankYouMail($order, $user, $orderView));

                return;
            }

            $prevHuman = $this->humanizeOrderStatus($previousStatus);
            $newHuman = $this->humanizeOrderStatus($order->order_status);
            Mail::to($user->email)->send(new OrderStatusUpdatedMail($order, $user, $prevHuman, $newHuman, $orderView));
        } catch (\Throwable $e) {
            Log::error('Order status update email failed: '.$e->getMessage(), [
                'order_id' => $order->id,
            ]);
        }
    }

    /**
     * Send confirmation email when a cart order is placed (POST /orders).
     */
    private function notifyCustomerOrderPlaced(Order $order): void
    {
        $order->loadMissing('user');
        $user = $order->user;
        if (! $user || ! $user->email) {
            return;
        }

        try {
            $order->loadMissing(['items.itemable', 'deliveryAddress']);
            $orderView = $this->formatOrder($order->fresh(['items.itemable', 'deliveryAddress']), []);
            Mail::to($user->email)->send(new OrderPlacedConfirmationMail($order, $user, $orderView));
        } catch (\Throwable $e) {
            Log::error('Order placed confirmation email failed: '.$e->getMessage(), [
                'order_id' => $order->id,
            ]);
        }
    }

    /**
     * GET /api/orders
     * Returns orders for the authenticated user.
     */
    public function updateStatus($orderId, Request $request){
        $request->validate([
            'order_status' => 'required|string|in:pending,processing,shipped,delivered,cancelled,refunded,completed',
        ]);

        try {
            $order = Order::findOrFail($orderId);
            $previousStatus = $order->order_status;
            $order->order_status = $request->order_status;
            $order->save();
            $this->notifyCustomerOrderStatusChange($order, $previousStatus);

            return ResponseHelper::success('Order status updated successfully', 200);
        } catch (\Throwable $e) {
            Log::error("Order Update Status Error: {$e->getMessage()}");
            return ResponseHelper::error('Failed to update order status', 500);
        }
    }
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $isAdmin = $this->isAuthenticatedAdmin();

            // Build query based on user role
            $query = Order::with(['items.itemable', 'deliveryAddress', 'user:id,first_name,sur_name,email,phone']);
            
            if (!$isAdmin) {
                // Regular users only see their own orders
                $query->where('user_id', $user->id);
            }
            // Admin users see all orders (no where clause needed)

            /** @var \Illuminate\Database\Eloquent\Collection $orders */
            $orders = $query->latest()->get();

            $summary = [
                'total_orders'     => $orders->count(),
                'pending_orders'   => $orders->where('order_status', 'pending')->count(),
                'completed_orders' => $orders->where('order_status', 'delivered')->count(),
                'user_type'        => $isAdmin ? 'admin' : 'user',
            ];

            $formatted = $orders->map(fn ($o) => $this->formatOrder($o, []))->all();

            return response()->json([
                'status'  => true,
                'summary' => $summary,
                'orders'  => $formatted,
                'message' => $isAdmin ? 'All orders fetched successfully for admin' : 'Orders fetched successfully',
            ]);
        } catch (\Throwable $e) {
            Log::error("Order Index Error: {$e->getMessage()}");
            return ResponseHelper::error('Failed to fetch orders', 500);
        }
    }

    /**
     * GET /api/orders/user/{userId}
     * Returns orders for a specific user id (admin/support usage).
     */
    public function forUser(int $userId)
    {
        try {
            // Add authorization here if needed (e.g., Gate/Policy)

            /** @var \Illuminate\Database\Eloquent\Collection $orders */
            $orders = Order::with(['items.itemable', 'deliveryAddress'])
                ->where('user_id', $userId)
                ->latest()
                ->get();

            $summary = [
                'total_orders'     => $orders->count(),
                'pending_orders'   => $orders->where('order_status', 'pending')->count(),
                'completed_orders' => $orders->where('order_status', 'delivered')->count(),
            ];

            $formatted = $orders->map(fn ($o) => $this->formatOrder($o))->all();

            return response()->json([
                'status'  => true,
                'summary' => $summary,
                'orders'  => $formatted,
                'message' => 'Orders fetched successfully for user '.$userId,
            ]);
        } catch (\Throwable $e) {
            Log::error("Order forUser Error: {$e->getMessage()}");
            return ResponseHelper::error('Failed to fetch user orders', 500);
        }
    }

    /**
     * POST /api/orders
     * Creates an order for the authenticated user.
     */
    public function store(StoreOrderRequest $request)
{
    $userId = auth()->id();
    $data   = $request->validated();

    return DB::transaction(function () use ($userId, $data) {
        // 1) Load cart
        $cartItems = CartItem::query()
            ->where('user_id', $userId)
            ->with('itemable') // Product|Bundles
            ->orderBy('id')
            ->get();

        if ($cartItems->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => ['Your cart is empty. Add items before placing an order.'],
            ]);
        }

        // 2) Optional: verify delivery address belongs to user
        $deliveryAddressId = $data['delivery_address_id'] ?? null;
        if ($deliveryAddressId) {
            $owned = DeliveryAddress::where('id', $deliveryAddressId)
                ->where('user_id', $userId)
                ->exists();
            if (! $owned) {
                throw ValidationException::withMessages([
                    'delivery_address_id' => ['Invalid delivery address.'],
                ]);
            }
        }

        $settings = CheckoutSetting::get(CheckoutSetting::CHANNEL_SHOP);
        $categoryFees = CheckoutPricing::shopCartCategoryFees($cartItems, $settings);
        $deliveryFee = (float) $categoryFees['delivery'];
        $installationFromProducts = (float) CheckoutPricing::installationTotalFromCartItems($cartItems);
        $installationAddon = (float) ($settings->installation_flat_addon ?? 0);
        if ($categoryFees['category_keys'] !== [] && (float) $categoryFees['installation'] > 0) {
            $installationSumFull = (float) $categoryFees['installation'];
        } else {
            $installationSumFull = $installationFromProducts + $installationAddon;
        }
        $includeInstallation = (bool) ($data['include_installation'] ?? false);
        $inspectionSum = (float) $categoryFees['inspection'];
        $insPct = (float) ($settings->insurance_fee_percentage ?? config('checkout.insurance_fee_percentage', 3));
        $vatPct = (float) ($settings->vat_percentage ?? config('checkout.vat_percentage', 7.5));
        $deliveryWindow = CheckoutPricing::deliveryWindow($settings);

        // 3) Create order shell
        $orderPayload = [
            'user_id' => $userId,
            'delivery_address_id' => $deliveryAddressId,
            'order_number' => strtoupper(Str::random(10)),
            'payment_method' => $data['payment_method'] ?? 'cash',
            'payment_status' => 'paid',
            'order_status' => 'pending',
            'note' => $data['note'] ?? null,
            'total_price' => 0,
        ];
        if (Schema::hasColumn('orders', 'estimated_delivery_from')) {
            $orderPayload['estimated_delivery_from'] = $deliveryWindow['estimated_from'];
            $orderPayload['estimated_delivery_to'] = $deliveryWindow['estimated_to'];
            $orderPayload['delivery_estimate_label'] = $deliveryWindow['label'];
        }
        if (Schema::hasColumn('orders', 'include_installation')) {
            $orderPayload['include_installation'] = $includeInstallation;
        }
        if (
            $includeInstallation
            && Schema::hasColumn('orders', 'installation_requested_date')
            && ! empty($data['installation_requested_date'] ?? null)
        ) {
            $orderPayload['installation_requested_date'] = $data['installation_requested_date'];
        }
        if (Schema::hasColumn('orders', 'order_type')) {
            $orderPayload['order_type'] = 'shop';
        }

        $order = Order::create($orderPayload);

        // 4) Create order items from cart rows
        $total            = 0;
        $primaryProductId = null;
        $primaryBundleId  = null;
        $orderPaymentMethod = strtolower((string) ($data['payment_method'] ?? ''));

        $referralCodeInput = trim((string) ($data['referral_code'] ?? ''));
        if ($referralCodeInput !== '') {
            $referrer = User::referrerForCheckoutCode($referralCodeInput, (int) $userId);
            if (! $referrer) {
                throw ValidationException::withMessages([
                    'referral_code' => ['This referral code is not valid.'],
                ]);
            }
        }

        // Shop cart orders do not use Buy Now outright discount (admin "Buy Now Discount").
        $outrightDiscountPercentage = 0.0;
        $applyOutrightDiscount = false;

        foreach ($cartItems as $ci) {
            $itemable = $ci->itemable; // Product|Bundles|null
            if (! $itemable) {
                // skip broken cart rows
                continue;
            }

            $fqcn = $itemable instanceof Product ? Product::class : Bundles::class;

            $catalogUnit = $this->resolveCatalogUnitPrice($itemable);
            $unit = (float) $catalogUnit;
            $qty      = max(1, (int) $ci->quantity);
            $subtotal = (float) round($unit * $qty, 2);

            if ($itemable instanceof Product) {
                $availableStock = (int) ($itemable->stock ?? 0);
                if ($availableStock <= 0) {
                    throw ValidationException::withMessages([
                        'stock' => ["{$itemable->title} is out of stock."],
                    ]);
                }
                if ($qty > $availableStock) {
                    throw ValidationException::withMessages([
                        'stock' => ["Only {$availableStock} unit(s) available for {$itemable->title}."],
                    ]);
                }
            }

            OrderItem::create([
                'order_id'      => $order->id,
                'itemable_type' => $fqcn,
                'itemable_id'   => $ci->itemable_id,
                'quantity'      => $qty,
                'unit_price'    => $unit,
                'subtotal'      => $subtotal,
            ]);

            // track first product/bundle for convenience fields
            if ($fqcn === Product::class && ! $primaryProductId) {
                $primaryProductId = $ci->itemable_id;
            }
            if ($fqcn === Bundles::class && ! $primaryBundleId) {
                $primaryBundleId = $ci->itemable_id;
            }

            if ($itemable instanceof Product) {
                $itemable->decrement('stock', $qty);
            }

            $total += $subtotal;
        }

        // Edge: if every row was invalid
        if ($total <= 0) {
            throw ValidationException::withMessages([
                'cart' => ['Your cart items are invalid. Please re-add them.'],
            ]);
        }

        // 5) Persist totals (+ delivery / optional installation + insurance % + VAT)
        $catalogItemsSubtotal = (float) $total;
        $outrightDiscountAmount = $applyOutrightDiscount && $outrightDiscountPercentage > 0
            ? round($catalogItemsSubtotal * ($outrightDiscountPercentage / 100), 2)
            : 0.0;
        $itemsSubtotalAfterDiscount = max(0, round($catalogItemsSubtotal - $outrightDiscountAmount, 2));

        $insuranceFee = $includeInstallation
            ? (float) CheckoutPricing::insuranceAmountFromPercent($catalogItemsSubtotal, 0.0, $insPct)
            : 0.0;
        $vatAmount = (float) CheckoutPricing::vatAmount((float) $itemsSubtotalAfterDiscount, $vatPct);
        $taxableBase = $itemsSubtotalAfterDiscount + $deliveryFee;
        if ($includeInstallation) {
            $taxableBase += $installationSumFull + $inspectionSum;
        }
        $orderTotal = round($taxableBase + $insuranceFee + $vatAmount, 2);

        $updatePayload = [
            'total_price' => $orderTotal,
            'product_id' => $primaryProductId,
            'bundle_id' => $primaryBundleId,
            'delivery_fee' => $deliveryFee,
            'installation_price' => $includeInstallation ? $installationSumFull : 0.0,
            'insurance_fee' => $insuranceFee,
        ];
        if (Schema::hasColumn('orders', 'product_price')) {
            // Persist after-discount items total so receipts match checkout (no phantom discount).
            $updatePayload['product_price'] = $itemsSubtotalAfterDiscount;
        }
        if (Schema::hasColumn('orders', 'inspection_fee')) {
            $updatePayload['inspection_fee'] = $includeInstallation ? $inspectionSum : 0.0;
        }
        if (Schema::hasColumn('orders', 'vat_amount')) {
            $updatePayload['vat_amount'] = $vatAmount;
        }
        $order->update($updatePayload);

        // 6) Clear cart
        CartItem::where('user_id', $userId)->delete();

        // Online (Flutterwave): payment already succeeded — persist transaction + referral rewards.
        if ($orderPaymentMethod === 'direct' && ! empty($data['flutterwave_transaction_id'] ?? null)) {
            $this->recordOrderPaymentTransactionAndReferral(
                $order->fresh(),
                (float) $orderTotal,
                (string) $data['flutterwave_transaction_id'],
                'direct'
            );
        }

        // 7) Load for response
        $order->load(['items.itemable', 'deliveryAddress', 'user:id,first_name,sur_name,email,phone']);

        // 8) Optional extras like installation/loan (your prior logic can stay)
        $extras = [];
        if ($order->payment_method === 'direct') {
            // No placeholder technician; optional date when column exists and is set
            if (Schema::hasColumn('orders', 'installation_requested_date') && $order->installation_requested_date) {
                $extras['installation'] = [
                    'installation_date' => $order->installation_requested_date instanceof \Carbon\CarbonInterface
                        ? $order->installation_requested_date->format('Y-m-d')
                        : (string) $order->installation_requested_date,
                ];
            }
        } elseif ($order->payment_method === 'loan') {
            $loan = LoanCalculation::where('user_id', $userId)->latest()->first();
            $application = LoanApplication::where('user_id', $userId)
                ->where('mono_loan_calculation', $loan?->id)
                ->latest()
                ->first();

            $installments = [];
            if ($loan?->monthly_payment && $loan?->repayment_date) {
                $installments[] = [
                    'installment_number' => 1,
                    'amount'             => $loan->monthly_payment,
                    'status'             => 'pending',
                    'due_date'           => $loan->repayment_date,
                ];
            }

            $extras['loan_details'] = [
                'loan_amount'         => $loan?->loan_amount,
                'product_amount'      => $loan?->product_amount,
                'repayment_duration'  => $loan?->repayment_duration,
                'monthly_payment'     => $loan?->monthly_payment,
                'interest_percentage' => $loan?->interest_percentage,
                'repayment_date'      => $loan?->repayment_date,
                'application'         => $application,
                'installments'        => $installments,
            ];
        }

        $response = $this->formatOrder($order, $extras);

        $this->notifyCustomerOrderPlaced($order);

        return ResponseHelper::success($response, 'Order placed successfully');
    });
}
    /**
     * GET /api/orders/{id}
     * Returns a single order for the authenticated user.
     */
    public function show($id)
    {
        try {
            $viewer = auth()->user();
            $isAdminViewer = $this->isAuthenticatedAdmin();

            $query = Order::with(['items.itemable', 'deliveryAddress', 'user:id,first_name,sur_name,email,phone']);
            
            if (! $isAdminViewer) {
                $query->where('user_id', $viewer->id);
            }
            
            $order = $query->findOrFail($id);

            $extras = [];
            // Buy Now: delivery row missing on relation but FK set (or legacy load)
            if (($order->order_type ?? null) === 'buy_now' && ! $order->deliveryAddress && $order->delivery_address_id) {
                $addr = DeliveryAddress::find($order->delivery_address_id);
                if ($addr) {
                    $extras['delivery_address'] = $addr;
                }
            }
            // BNPL orders: use application's property address when order has no delivery address
            if (($order->order_type ?? null) === 'bnpl' && !$order->deliveryAddress && $order->mono_calculation_id) {
                $bnplApplication = LoanApplication::where('mono_loan_calculation', $order->mono_calculation_id)
                    ->where('user_id', $order->user_id)
                    ->first();
                if ($bnplApplication && ($bnplApplication->property_address || $bnplApplication->property_state)) {
                    $extras['delivery_address'] = (object) [
                        'address' => $bnplApplication->property_address ?? '',
                        'state' => $bnplApplication->property_state ?? null,
                        'title' => 'BNPL delivery',
                        'phone_number' => $order->relationLoaded('user') && $order->user ? $order->user->phone : null,
                    ];
                }
            }
            if ($order->payment_method === 'direct') {
                if (Schema::hasColumn('orders', 'installation_requested_date') && $order->installation_requested_date) {
                    $extras['installation'] = [
                        'installation_date' => $order->installation_requested_date instanceof \Carbon\CarbonInterface
                            ? $order->installation_requested_date->format('Y-m-d')
                            : (string) $order->installation_requested_date,
                    ];
                }
            } elseif ($order->payment_method === 'loan') {
                $loan = LoanCalculation::where('user_id', auth()->id())->latest()->first();
                $application = LoanApplication::where('user_id', auth()->id())
                    ->where('mono_loan_calculation', $loan?->id)
                    ->latest()
                    ->first();

                $installments = [];
                if ($loan?->monthly_payment && $loan?->repayment_date) {
                    $installments[] = [
                        'installment_number' => 1,
                        'amount'             => $loan->monthly_payment,
                        'status'             => 'pending',
                        'due_date'           => $loan->repayment_date,
                    ];
                }

                $extras['loan_details'] = [
                    'loan_amount'         => $loan?->loan_amount,
                    'product_amount'      => $loan?->product_amount,
                    'repayment_duration'  => $loan?->repayment_duration,
                    'monthly_payment'     => $loan?->monthly_payment,
                    'interest_percentage' => $loan?->interest_percentage,
                    'repayment_date'      => $loan?->repayment_date,
                    'application'         => $application,
                    'installments'        => $installments,
                ];
            }

            if ($isAdminViewer && $viewer) {
                $extras['viewer_account'] = [
                    'id' => $viewer->id,
                    'first_name' => $viewer->first_name,
                    'sur_name' => $viewer->sur_name,
                    'email' => $viewer->email,
                ];
            }

            $response = $this->formatOrder($order, $extras);

            return ResponseHelper::success($response, 'Order fetched successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error("Order not found: {$e->getMessage()}");
            return ResponseHelper::error('Order not found', 404);
        } catch (\Exception $e) {
            Log::error("Order Show Error: {$e->getMessage()}");
            return ResponseHelper::error('Failed to fetch order details', 500);
        } catch (\Throwable $e) {
            Log::error("Critical Order Show Error: {$e->getMessage()}");
            return ResponseHelper::error('A critical error occurred while fetching order', 500);
        }
    }

    /**
     * DELETE /api/orders/{id}
     */
    public function destroy($id)
    {
        try {
            $order = Order::where('user_id', auth()->id())->findOrFail($id);
            $order->delete();

            return ResponseHelper::success('Order deleted successfully');
        } catch (\Throwable $e) {
            Log::error("Order Delete Error: {$e->getMessage()}");
            return ResponseHelper::error('Failed to delete order', 500);
        }
    }

    /* -------------------------- Helpers -------------------------- */

    private function formatOrder(Order $order, array $extras = []): array
    {
        $order->loadMissing(['items.itemable']);
        $items = $order->items->isNotEmpty()
            ? $order->items->map(fn ($i) => $this->formatOrderItem($i, $order))->all()
            : $this->buildSyntheticFormattedOrderItems($order);
        $totalPrice = (float) $order->total_price;
        // Amount-only checkout (no product_id / bundle_id) — still return one line for the dashboard
        if (count($items) === 0 && $totalPrice > 0) {
            $items = [[
                'itemable_type' => 'order',
                'itemable_id'   => null,
                'quantity'      => 1,
                'unit_price'    => (string) round($totalPrice, 2),
                'subtotal'      => (string) round($totalPrice, 2),
                'item'          => [
                    'id'               => null,
                    'title'            => 'Purchase',
                    'featured_image'   => null,
                ],
            ]];
        }
        $itemsSubtotalSum = array_sum(array_map(function ($i) {
            return (float) ($i['subtotal'] ?? 0);
        }, $items));

        // Receipt: pre-discount catalog subtotal vs charged (order-level discount, not per-line).
        $catalogItemsSubtotal = 0.0;
        $hasListPrices = false;
        foreach ($items as $i) {
            $qty = max(1, (int) ($i['quantity'] ?? 1));
            $sub = (float) ($i['subtotal'] ?? 0);
            $listUnit = isset($i['list_unit_price']) ? (float) $i['list_unit_price'] : 0.0;
            if ($listUnit > 0) {
                $hasListPrices = true;
                $catalogItemsSubtotal += round($listUnit * $qty, 2);
            } else {
                $catalogItemsSubtotal += $sub;
            }
        }
        $onlineCheckoutDiscount = 0.0;
        if ($hasListPrices) {
            $onlineCheckoutDiscount = max(0.0, round($catalogItemsSubtotal - $itemsSubtotalSum, 2));
        }
        if (
            $onlineCheckoutDiscount <= 0.005
            && Schema::hasColumn('orders', 'product_price')
            && $order->product_price !== null
            && $catalogItemsSubtotal > 0
        ) {
            $discountedProducts = (float) ($order->product_price ?? 0);
            $storedDiscount = max(0.0, round($catalogItemsSubtotal - $discountedProducts, 2));
            if ($storedDiscount > 0.005) {
                $onlineCheckoutDiscount = $storedDiscount;
                $itemsSubtotalAfterDiscount = $discountedProducts;
            }
        }
        if (
            $onlineCheckoutDiscount <= 0.005
            && ($order->order_type ?? null) === 'buy_now'
            && Schema::hasColumn('orders', 'product_price')
            && $catalogItemsSubtotal > 0
        ) {
            $discountedProducts = (float) ($order->product_price ?? 0);
            $buyNowDiscount = max(0.0, round($catalogItemsSubtotal - $discountedProducts, 2));
            if ($buyNowDiscount > 0.005) {
                $onlineCheckoutDiscount = $buyNowDiscount;
            }
        }

        $itemsSubtotalAfterDiscount = $itemsSubtotalSum;
        if ($onlineCheckoutDiscount > 0.005) {
            $itemsSubtotalAfterDiscount = max(0.0, round($catalogItemsSubtotal - $onlineCheckoutDiscount, 2));
        }

        $vatAmount = Schema::hasColumn('orders', 'vat_amount') ? (float) ($order->vat_amount ?? 0) : 0.0;
        $orderTypeForVat = strtolower((string) ($order->order_type ?? ''));
        $settingsForVat = CheckoutSetting::get(
            $orderTypeForVat === 'shop'
                ? CheckoutSetting::CHANNEL_SHOP
                : CheckoutSetting::CHANNEL_BUY_NOW
        );
        $vatPctDisplay = (float) ($settingsForVat->vat_percentage ?? config('checkout.vat_percentage', 7.5));

        $outrightDiscountPct = null;
        if ($catalogItemsSubtotal > 0.005) {
            $breakdown = $this->resolveOrderPaymentBreakdown($order, $catalogItemsSubtotal);
            $orderType = strtolower((string) ($order->order_type ?? ''));
            if ($orderType === 'buy_now' || $orderType === 'shop') {
                $onlineCheckoutDiscount = (float) $breakdown['outright_discount_amount'];
                $itemsSubtotalAfterDiscount = (float) $breakdown['items_subtotal_after_discount'];
                $vatAmount = (float) $breakdown['vat_amount'];
                $outrightDiscountPct = $breakdown['outright_discount_percentage'];
            } elseif ($vatAmount <= 0.005 && (float) $breakdown['vat_amount'] > 0.005) {
                $vatAmount = (float) $breakdown['vat_amount'];
            }
        }

        if ($outrightDiscountPct === null && $onlineCheckoutDiscount > 0.005 && $catalogItemsSubtotal > 0) {
            $outrightDiscountPct = round(100 * ($onlineCheckoutDiscount / $catalogItemsSubtotal), 2);
        }

        $baseData = [
            'id'               => $order->id,
            'order_number'     => $order->order_number,
            'order_status'     => $order->order_status,
            'payment_status'   => $order->payment_status,
            'payment_method'   => $order->payment_method,
            'note'             => $order->note,
            'total_price'      => $order->total_price,
            'product_id'       => $order->product_id,
            'bundle_id'        => $order->bundle_id,
            'created_at'       => optional($order->created_at)->format('Y-m-d H:i:s'),
            'delivery_address' => $order->relationLoaded('deliveryAddress') ? $order->deliveryAddress : null,
            'items'            => $items,
            'items_subtotal'   => round($itemsSubtotalAfterDiscount, 2),
            'catalog_items_subtotal' => $catalogItemsSubtotal > 0.005 ? round($catalogItemsSubtotal, 2) : null,
            'online_checkout_discount_amount' => $onlineCheckoutDiscount > 0.005 ? round($onlineCheckoutDiscount, 2) : null,
            'outright_discount_percentage' => $outrightDiscountPct,
            'order_type'       => $order->order_type ?? null,
            'product_price'    => Schema::hasColumn('orders', 'product_price') ? $order->product_price : null,
            'material_cost'    => Schema::hasColumn('orders', 'material_cost') ? $order->material_cost : null,
            'inspection_fee'   => Schema::hasColumn('orders', 'inspection_fee') ? $order->inspection_fee : null,
            'delivery_fee'     => $order->delivery_fee,
            'insurance_fee'    => $order->insurance_fee,
            'installation_price' => $order->installation_price,
            'include_installation' => (bool) ($order->include_installation ?? false),
            'vat_amount'       => $vatAmount,
            'vat_percentage'   => $vatPctDisplay,
            'estimated_delivery_from' => optional($order->estimated_delivery_from)->format('Y-m-d'),
            'estimated_delivery_to' => optional($order->estimated_delivery_to)->format('Y-m-d'),
            'delivery_estimate_label' => $order->delivery_estimate_label,
            'customer_type' => Schema::hasColumn('orders', 'customer_type') ? ($order->customer_type ?? null) : null,
            'installer_choice' => Schema::hasColumn('orders', 'installer_choice') ? ($order->installer_choice ?? null) : null,
            'property_floors' => Schema::hasColumn('orders', 'property_floors') ? $order->property_floors : null,
            'property_rooms' => Schema::hasColumn('orders', 'property_rooms') ? $order->property_rooms : null,
            'is_gated_estate' => Schema::hasColumn('orders', 'is_gated_estate') ? $order->is_gated_estate : null,
            'estate_name' => Schema::hasColumn('orders', 'estate_name') ? ($order->estate_name ?? null) : null,
            'estate_address' => Schema::hasColumn('orders', 'estate_address') ? ($order->estate_address ?? null) : null,
            'audit_request_id' => Schema::hasColumn('orders', 'audit_request_id') ? ($order->audit_request_id ?? null) : null,
        ];

        // Only for orders linked to an audit (custom-order checkout) — do not override normal Buy Now.
        if (!empty($order->audit_request_id)) {
            $auditContext = null;
            if ($order->relationLoaded('auditRequest') && $order->auditRequest) {
                $auditContext = $order->auditRequest;
            } else {
                $auditContext = AuditRequest::query()->find($order->audit_request_id);
            }
            if ($auditContext) {
                $baseData['audit_request'] = $auditContext->toBuyNowContext();
                $auditCustomerType = $auditContext->resolvedCustomerType();
                if ($auditCustomerType) {
                    $baseData['customer_type'] = $auditCustomerType;
                }
            }
        }

        if (Schema::hasColumn('orders', 'installation_requested_date')) {
            $baseData['installation_requested_date'] = $order->installation_requested_date
                ? ($order->installation_requested_date instanceof \Carbon\CarbonInterface
                    ? $order->installation_requested_date->format('Y-m-d')
                    : (string) $order->installation_requested_date)
                : null;
        }

        // Order owner (always when user is loaded) — My Orders / order detail must not use the viewer's profile
        if ($order->relationLoaded('user') && $order->user) {
            $baseData['user_info'] = [
                'id' => $order->user->id,
                'name' => trim(($order->user->first_name ?? '').' '.($order->user->sur_name ?? '')),
                'first_name' => $order->user->first_name,
                'sur_name' => $order->user->sur_name,
                'email' => $order->user->email,
                'phone' => $order->user->phone,
            ];
        }

        return array_merge($baseData, $extras);
    }

    /**
     * Legacy Buy Now orders often have product_id/bundle_id on orders but no order_items rows.
     * Build one display line so GET /orders/{id} shows the real bundle/product name and matches order total.
     */
    private function buildSyntheticFormattedOrderItems(Order $order): array
    {
        $total = (float) ($order->total_price ?? 0);
        if ($total <= 0) {
            return [];
        }

        if ($order->bundle_id) {
            $bundle = Bundles::with('bundleItems.product')->find($order->bundle_id);
            if ($bundle) {
                $fake = new OrderItem([
                    'order_id'      => $order->id,
                    'itemable_type' => Bundles::class,
                    'itemable_id'   => $bundle->id,
                    'quantity'      => 1,
                    'unit_price'    => number_format($total, 2, '.', ''),
                    'subtotal'      => number_format($total, 2, '.', ''),
                ]);
                $fake->setRelation('itemable', $bundle);

                return [$this->formatOrderItem($fake, $order)];
            }
        }

        if ($order->product_id) {
            $product = Product::find($order->product_id);
            if ($product) {
                $fake = new OrderItem([
                    'order_id'      => $order->id,
                    'itemable_type' => Product::class,
                    'itemable_id'   => $product->id,
                    'quantity'      => 1,
                    'unit_price'    => number_format($total, 2, '.', ''),
                    'subtotal'      => number_format($total, 2, '.', ''),
                ]);
                $fake->setRelation('itemable', $product);

                return [$this->formatOrderItem($fake, $order)];
            }
        }

        return [];
    }

    private function resolvePublicImageUrl(?string $featured): ?string
    {
        if ($featured === null || trim($featured) === '') {
            return null;
        }

        $featured = trim($featured);
        if (Str::startsWith($featured, ['http://', 'https://'])) {
            return $featured;
        }

        if (Str::startsWith($featured, '/storage/')) {
            return url($featured);
        }

        if (Str::startsWith($featured, 'storage/')) {
            return url('/'.$featured);
        }

        if (Str::startsWith($featured, '/')) {
            return url($featured);
        }

        return url(\Illuminate\Support\Facades\Storage::url($featured));
    }

    private function formatOrderItem(OrderItem $item, ?Order $order = null): array
    {
        $itemable = $item->itemable; // Product | Bundles | null

        // Resolve image with fallback (bundle → first product’s image)
        $featured = null;
        if ($itemable) {
            $featured = $this->resolvePublicImageUrl(
                $itemable->featured_image_url ?? ($itemable->featured_image ?? null)
            );

            if (!$featured && $itemable instanceof Bundles) {
                $itemable->loadMissing('bundleItems.product.images');
                $firstProduct = optional($itemable->bundleItems->first())->product;
                if ($firstProduct) {
                    $featured = $this->resolvePublicImageUrl(
                        $firstProduct->featured_image_url ?? ($firstProduct->featured_image ?? null)
                    );
                    if (!$featured) {
                        $firstImage = $firstProduct->images->first();
                        $featured = $this->resolvePublicImageUrl($firstImage->image ?? null);
                    }
                }
            }

            if (!$featured && $itemable instanceof Product) {
                $itemable->loadMissing('images');
                $firstImage = $itemable->images->first();
                $featured = $this->resolvePublicImageUrl($firstImage->image ?? null);
            }
        }

        $title = $itemable ? ($itemable->title ?? $itemable->name ?? null) : null;
        $subtitle = null;
        if ($itemable instanceof Bundles) {
            $subtitle = $itemable->product_model ?? null;
            if ($subtitle && $title && trim((string) $subtitle) === trim((string) $title)) {
                $subtitle = null;
            }
        }

        $qty = max(1, (int) ($item->quantity ?? 1));
        $catalogUnit = $itemable ? $this->resolveCatalogUnitPrice($itemable) : 0.0;
        $unit = (float) ($item->unit_price ?? 0);
        $subtotal = (float) ($item->subtotal ?? 0);

        if ($unit <= 0 && $itemable) {
            $unit = $catalogUnit;
        }
        $unitRounded = round($unit, 2);
        if ($subtotal <= 0 && $unitRounded > 0) {
            $subtotal = round($unitRounded * $qty, 2);
        }

        $paymentMethod = $order ? strtolower((string) ($order->payment_method ?? '')) : '';
        $isOutrightCheckout = in_array($paymentMethod, ['direct', 'cash'], true);
        $showReferralList = $isOutrightCheckout
            && $itemable
            && $catalogUnit > 0
            && $catalogUnit > $unitRounded + 0.005;

        $row = [
            'itemable_type' => strtolower(class_basename($item->itemable_type)), // "product" | "bundles"
            'itemable_id'   => $item->itemable_id,
            'quantity'      => $item->quantity,
            'unit_price'    => $unitRounded,
            'subtotal'      => round($subtotal, 2),
            'item'          => $itemable ? [
                'id'             => $itemable->id,
                'title'          => $title,
                'subtitle'       => $subtitle,
                'featured_image' => $featured,
                'featured_image_url' => $featured,
            ] : null,
        ];

        if ($showReferralList) {
            $row['list_unit_price'] = round($catalogUnit, 2);
            $derivedPct = $catalogUnit > 0
                ? round(100 * (1 - min($unitRounded, $catalogUnit) / $catalogUnit), 2)
                : 0.0;
            $row['referral_outright_discount_percent'] = $derivedPct;
        }

        return $row;
    }

    /**
     * Store a completed payment transaction and apply referral rewards (cart + legacy confirm flows).
     */
    private function recordOrderPaymentTransactionAndReferral(Order $order, float $amount, string $txId, string $type): Transaction
    {
        $title = match ($type) {
            'audit' => 'Audit Payment',
            'wallet' => 'Order Payment - Wallet',
            default => 'Order Payment - Direct',
        };

        $transaction = Transaction::create([
            'user_id' => $order->user_id,
            'amount' => $amount,
            'tx_id' => $txId,
            'title' => $title,
            'type' => 'outgoing',
            'method' => $type === 'wallet' ? 'Wallet' : 'Direct',
            'status' => 'Completed',
            'transacted_at' => now(),
        ]);

        $isBuyNowOrder = (($order->order_type ?? null) === 'buy_now');
        if ($isBuyNowOrder) {
            $rewardBase = Schema::hasColumn('orders', 'product_price')
                ? (float) ($order->product_price ?? $order->total_price ?? 0)
                : (float) ($order->total_price ?? 0);
            app(ReferralRewardService::class)->award(Auth::user(), $rewardBase, 'buy_now_completed', $order);
        }

        return $transaction;
    }

    public function paymentConfirmation(Request $request)
{
    try {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'orderId' => 'required|integer|exists:orders,id',
            'txId' => 'required|string',
            'type' => 'required|in:direct,audit,wallet',
            'installation_requested_date' => 'nullable|date|date_format:Y-m-d',
        ]);

        $amount = $request->amount;
        $tx_id = $request->txId;
        $orderId = $request->orderId;
        $type = $request->type;

    if($type=="wallet"){
        //check does user have that much loan
        $wallet=Wallet::where('user_id',Auth::user()->id)->first();
        if($amount < $wallet->loan_balance){
            //process the payment
            $wallet->loan_balance=$wallet->loan_balance-$amount;
            $wallet->save();
            $tx_id=date('ymdhis').rand(1000,9999);
        }else{
            return ResponseHelper::error("you don't have that much loan");
        }
    }

    $order=Order::where('id',$orderId)->first();
    if(!$order){
        return ResponseHelper::error("order does not found");
        }

        // Verify order belongs to authenticated user
        if($order->user_id != Auth::id()){
            return ResponseHelper::error("Unauthorized access to order", 403);
    }

        $wasUnpaid = strtolower((string) ($order->payment_status ?? '')) !== 'paid';

    $order->payment_status="paid";
        if (Schema::hasColumn('orders', 'total_price')) {
            $order->total_price = (float) $amount;
        }
        if ($request->filled('installation_requested_date') && Schema::hasColumn('orders', 'installation_requested_date')) {
            $order->installation_requested_date = $request->installation_requested_date;
        }
    $order->save();

        $transaction = $this->recordOrderPaymentTransactionAndReferral(
            $order,
            (float) $amount,
            (string) $tx_id,
            $type
        );

        // Buy Now creates the order at checkout (pending), then confirms payment here.
        // Cart shop orders already email on POST /orders — only notify when payment newly succeeds.
        if ($wasUnpaid) {
            $this->notifyCustomerOrderPlaced($order->fresh());
        }

        return ResponseHelper::success([
            'order_id' => $order->id,
            'payment_status' => 'confirmed',
            'transaction_id' => $tx_id,
            'amount' => (float)$amount,
            'type' => $type,
            'confirmed_at' => now()->toIso8601String(),
            'transaction' => $transaction
        ], "payment confirmed");

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);
    } catch (Exception $e) {
        Log::error('Payment Confirmation Error: ' . $e->getMessage());
        return ResponseHelper::error('Failed to confirm payment: ' . $e->getMessage(), 500);
    }
}

    /**
     * POST /api/orders/checkout
     * Buy Now checkout - Calculate invoice with optional fees
     */
    public function checkout(Request $request)
    {
        try {
            // Dynamic validation based on product_category
            $validationRules = [
                'product_id' => 'nullable|exists:products,id',
                'product_ids' => 'nullable|array',
                'product_ids.*' => 'integer|exists:products,id',
                'bundle_id' => 'nullable|exists:bundles,id',
                'amount' => 'nullable|numeric|min:0',
                'customer_type' => 'nullable|in:residential,sme,commercial',
                'product_category' => 'nullable|string',
                'include_insurance' => 'nullable|boolean',
                'include_installation_material' => 'nullable|boolean',
                'include_inspection' => 'nullable|boolean',
                'state_id' => 'nullable|exists:states,id',
                'delivery_location_id' => 'nullable|exists:delivery_locations,id',
                'add_ons' => 'nullable|array',
                'add_ons.*' => 'exists:add_ons,id',
                'audit_type' => 'nullable|in:home-office,commercial',
                'audit_request_id' => 'nullable|exists:audit_requests,id',
                'property_state' => 'nullable|string',
                'property_address' => 'nullable|string',
                'property_floors' => 'nullable|integer',
                'property_rooms' => 'nullable|integer',
                'is_gated_estate' => 'nullable|boolean',
                'estate_name' => 'nullable|string|max:255',
                'estate_address' => 'nullable|string',
                'contact_name' => 'nullable|string|max:255',
                'contact_phone' => 'nullable|string|max:50',
            ];

            // Check if this is an audit order before validation
            $isAuditOrder = $request->has('product_category') && $request->product_category === 'audit';
            
            // installer_choice is required only for non-audit orders
            if (!$isAuditOrder) {
                $validationRules['installer_choice'] = 'nullable|in:troosolar,own';
            } else {
                $validationRules['installer_choice'] = 'nullable|in:troosolar,own';
            }

            $data = $request->validate($validationRules);
            if (!$isAuditOrder && empty($data['installer_choice'])) {
                $data['installer_choice'] = 'troosolar';
            }

            // Custom-order email links only: when the client sends audit_request_id,
            // fill customer type / empty property fields from that audit. Do not apply
            // this to the normal Buy Now flow.
            if (!$isAuditOrder && !empty($data['audit_request_id'])) {
                $auditForCheckout = AuditRequest::query()
                    ->where('id', (int) $data['audit_request_id'])
                    ->where('user_id', Auth::id())
                    ->first();
                if ($auditForCheckout) {
                    $auditCustomerType = $auditForCheckout->resolvedCustomerType();
                    if ($auditCustomerType) {
                        $data['customer_type'] = $auditCustomerType;
                    }
                    if (empty($data['product_category']) && !empty($auditForCheckout->product_category)) {
                        $data['product_category'] = $auditForCheckout->product_category;
                    }
                    foreach ([
                        'property_state' => 'property_state',
                        'property_address' => 'property_address',
                        'property_floors' => 'property_floors',
                        'property_rooms' => 'property_rooms',
                        'estate_name' => 'estate_name',
                        'estate_address' => 'estate_address',
                        'contact_name' => 'contact_name',
                        'contact_phone' => 'contact_phone',
                    ] as $dataKey => $auditKey) {
                        $current = $data[$dataKey] ?? null;
                        $fromAudit = $auditForCheckout->{$auditKey} ?? null;
                        if (($current === null || $current === '') && $fromAudit !== null && $fromAudit !== '') {
                            $data[$dataKey] = $fromAudit;
                        }
                    }
                    if (!array_key_exists('is_gated_estate', $data) || $data['is_gated_estate'] === null) {
                        if ($auditForCheckout->is_gated_estate !== null) {
                            $data['is_gated_estate'] = (bool) $auditForCheckout->is_gated_estate;
                        }
                    }
                }
            }
            
            // For audit orders, skip installer_choice requirement
            if ($isAuditOrder) {
                // Link to audit request if provided
                $auditRequestId = $request->input('audit_request_id');
                $auditRequest = null;
                
                if ($auditRequestId) {
                    $auditRequest = \App\Models\AuditRequest::where('id', $auditRequestId)
                        ->where('user_id', Auth::id())
                        ->first();
                    if (!$auditRequest) {
                        return ResponseHelper::error('Invalid audit request ID', 422);
                    }
                }
                
                // Calculate audit fee based on property details
                $auditFee = $this->calculateAuditFee($auditRequest, $data);
                
                // Prepare audit order data - check if columns exist
                $auditOrderData = [
                    'user_id' => Auth::id(),
                    'total_price' => $auditFee,
                    'payment_status' => 'pending',
                    'order_status' => 'pending',
                    'payment_method' => 'direct',
                ];

                // Add optional columns only if they exist
                if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'order_type')) {
                    $auditOrderData['order_type'] = 'audit_only';
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'audit_request_id') && $auditRequestId) {
                    $auditOrderData['audit_request_id'] = $auditRequestId;
                }

                // Create audit order
                $order = Order::create($auditOrderData);
                
                // Link order to audit request
                if ($auditRequestId) {
                    \App\Models\AuditRequest::where('id', $auditRequestId)->update(['order_id' => $order->id]);
                }
                
                return ResponseHelper::success([
                    'order_id' => $order->id,
                    'audit_fee' => $auditFee,
                    'total' => $auditFee,
                    'order_type' => 'audit',
                    'audit_type' => $data['audit_type'] ?? ($auditRequest ? $auditRequest->audit_type : null),
                    'audit_request_id' => $auditRequestId,
                    'created_at' => $order->created_at->toIso8601String(),
                ], 'Audit order created successfully');
            }

            $productPrice = 0;
            $catalogTotalBeforeDiscount = 0.0;
            $product = null;
            $bundle = null;
            $multiProductLines = [];

            // Get product price from product_id, product_ids[], bundle_id, or amount
            $productId = isset($data['product_id']) && ! empty($data['product_id']) ? (int) $data['product_id'] : null;
            $bundleId = isset($data['bundle_id']) && ! empty($data['bundle_id']) ? (int) $data['bundle_id'] : null;
            $amount = isset($data['amount']) && $data['amount'] !== '' && $data['amount'] !== null
                ? (float) $data['amount']
                : null;

            $productIds = [];
            if ($request->filled('product_ids')) {
                $rawIds = $request->input('product_ids');
                $productIds = is_array($rawIds) ? $rawIds : [$rawIds];
                $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
            }

            if (count($productIds) > 0) {
                if ($productId || $bundleId) {
                    return ResponseHelper::error('Provide either product_ids or a single product_id/bundle_id, not both.', 422);
                }

                $multiProductLines = $this->resolveMultiProductCheckoutLines($productIds);
                if ($multiProductLines === null) {
                    return ResponseHelper::error('One or more selected products could not be found.', 422);
                }

                $catalogTotalBeforeDiscount = round(array_sum(array_column($multiProductLines, 'line_total')), 2);
                $productPrice = $catalogTotalBeforeDiscount;
            } elseif ($productId) {
                $product = Product::findOrFail($productId);
                $productDiscount = (float) ($product->discount_price ?? 0);
                $productPrice = $productDiscount > 0
                    ? $productDiscount
                    : (float) ($product->price ?? 0);
                $catalogTotalBeforeDiscount = $productPrice;
            } elseif ($bundleId) {
                $bundle = Bundles::with('bundleMaterials.material')->findOrFail($bundleId);
                $bundleDiscount = (float) ($bundle->discount_price ?? 0);
                $productPrice = $bundleDiscount > 0
                    ? $bundleDiscount
                    : (float) ($bundle->total_price ?? 0);
                // Use amount from request when bundle price is missing/zero (e.g. from bundle detail flow)
                if ($productPrice <= 0 && $amount !== null) {
                    $productPrice = (float) $amount;
                }
                $catalogTotalBeforeDiscount = $productPrice;
            } elseif ($amount !== null) {
                $productPrice = (float) $amount;
                $catalogTotalBeforeDiscount = $productPrice;
            } else {
                return ResponseHelper::error('Either product_id, product_ids, bundle_id, or amount is required. Please provide one of them in your request.', 422);
            }

            $itemsSubtotal = round((float) $catalogTotalBeforeDiscount, 2);

            $settings = ReferralSettings::getSettings();
            $outrightDiscountPercentage = (float) ($settings->outright_discount_percentage ?? 0);
            $outrightDiscountAmount = 0.0;
            if ($outrightDiscountPercentage > 0 && $itemsSubtotal > 0) {
                $outrightDiscountAmount = round(($itemsSubtotal * $outrightDiscountPercentage) / 100, 2);
            }
            $itemsSubtotalAfterDiscount = max(0, round($itemsSubtotal - $outrightDiscountAmount, 2));

            // Delivery / installation: admin checkout settings, bundle materials, or non-legacy location/state — never hardcoded ₦25k/₦50k.
            $checkoutSettings = CheckoutSetting::get(CheckoutSetting::CHANNEL_BUY_NOW);
            $resolvedFees = CheckoutPricing::resolveBuyNowCheckoutFees(
                $bundle,
                isset($data['delivery_location_id']) ? (int) $data['delivery_location_id'] : null,
                isset($data['state_id']) ? (int) $data['state_id'] : null,
                $checkoutSettings,
                $data['product_category'] ?? null,
            );
            $deliveryFee = $resolvedFees['delivery_fee'];
            $installationFee = $resolvedFees['installation_fee'];
            $inspectionFeeFromBundle = $resolvedFees['inspection_fee_from_bundle'];

            $installerChoice = $data['installer_choice'] ?? null;
            $includeInstallationMaterial = (bool) ($data['include_installation_material'] ?? false);
            $bundleCustomFees = CheckoutPricing::resolveBundleInvoiceFeesFromCustomServices(
                $bundle,
                'buy_now',
                $installerChoice ?? 'troosolar',
                $includeInstallationMaterial
            );

            if ($bundle) {
                // Bundle fees come only from Bundle Mgt -> Invoice fees, never global/state checkout settings.
                $deliveryFee = $bundleCustomFees['delivery_fee'];
                $installationFee = $bundleCustomFees['installation_fee'];
                $inspectionFeeFromBundle = $bundleCustomFees['inspection_fee'];
            } else {
                if ($bundleCustomFees['delivery_fee'] > 0) {
                    $deliveryFee = $bundleCustomFees['delivery_fee'];
                }
                if ($bundleCustomFees['installation_fee'] > 0) {
                    $installationFee = $bundleCustomFees['installation_fee'];
                }
                if ($bundleCustomFees['inspection_fee'] > 0) {
                    $inspectionFeeFromBundle = $bundleCustomFees['inspection_fee'];
                }
            }

            // Calculate fees
            $materialCost = $bundle
                ? $bundleCustomFees['material_cost']
                : 0.0;
            $productCategory = $data['product_category'] ?? null;
            // Product-only (battery/inverter/panels): fees from checkout settings by category.
            if (! $bundle) {
                $feeCategoryKeys = $this->resolveProductFeeCategoryKeys($productIds, $productCategory);
                if ($feeCategoryKeys === [] && $productId) {
                    $singleProduct = $product ?? Product::with('category')->find($productId);
                    $inferred = CheckoutSetting::inferProductFeeCategory($singleProduct);
                    if ($inferred) {
                        $feeCategoryKeys = [$inferred];
                    }
                }

                // Sum per product category — do not use state/location delivery defaults here.
                $deliveryFee = $checkoutSettings->sumProductCategoryFees(
                    $feeCategoryKeys,
                    'delivery',
                    $productCategory
                );

                $sumInstallation = $checkoutSettings->sumProductCategoryFees(
                    $feeCategoryKeys,
                    'installation',
                    $productCategory
                );
                $sumInspection = $checkoutSettings->sumProductCategoryFees(
                    $feeCategoryKeys,
                    'inspection',
                    $productCategory
                );
                $sumMaterials = $checkoutSettings->sumProductCategoryFees(
                    $feeCategoryKeys,
                    'materials',
                    $productCategory
                );

                if ($installerChoice === 'troosolar') {
                    $installationFee = $sumInstallation;
                    $inspectionFeeFromBundle = $sumInspection;
                    $materialCost = $sumMaterials;
                } elseif ($includeInstallationMaterial) {
                    $installationFee = 0.0;
                    $materialCost = $sumMaterials;
                    $inspectionFeeFromBundle = 0.0;
                } else {
                    $installationFee = 0.0;
                    $materialCost = 0.0;
                    $inspectionFeeFromBundle = 0.0;
                }
            } elseif ($installerChoice !== 'troosolar') {
                // Non product-only own installer: never charge TrooSolar installation/inspection.
                $installationFee = 0.0;
                $inspectionFeeFromBundle = 0.0;
            }
            $inspectionFee = $inspectionFeeFromBundle;
            $insuranceFee = 0;
            $addOnsTotal = 0;
            $addOns = [];

            // Insurance fee: % of catalog items subtotal (before outright discount), not after discount.
            $insPct = (float) ($checkoutSettings->insurance_fee_percentage ?? config('checkout.insurance_fee_percentage', 3));
            if ($data['include_insurance'] ?? false) {
                $insuranceFee = round($itemsSubtotal * ($insPct / 100), 2);
            }

            // Calculate add-ons total
            if (isset($data['add_ons']) && is_array($data['add_ons']) && count($data['add_ons']) > 0) {
                $addOnsList = \App\Models\AddOn::whereIn('id', $data['add_ons'])
                    ->where('is_active', true)
                    ->get();
                
                foreach ($addOnsList as $addOn) {
                    $addOnPrice = $addOn->price;
                    // If price is 0, it might be calculated (like insurance)
                    if ($addOnPrice == 0 && strtolower($addOn->title) == 'insurance') {
                        $addOnPrice = round($itemsSubtotal * ($insPct / 100), 2);
                    }
                    $addOnsTotal += $addOnPrice;
                    $addOns[] = [
                        'id' => $addOn->id,
                        'title' => $addOn->title,
                        'price' => $addOnPrice,
                        'quantity' => 1
                    ];
                }
            }

            $serviceFeesTotal = round(
                $installationFee + $materialCost + $deliveryFee + $inspectionFee + $addOnsTotal,
                2
            );
            $totalAmount = round($itemsSubtotalAfterDiscount + $serviceFeesTotal, 2);

            $vatPct = (float) ($checkoutSettings->vat_percentage ?? config('checkout.vat_percentage', 7.5));
            $vatAmount = (float) CheckoutPricing::vatAmount((float) $totalAmount, $vatPct);
            $grandTotal = round($totalAmount + $vatAmount + $insuranceFee, 2);

            // Calculate product breakdown (inverter, panels, batteries)
            $_bundleLineItems = null;
            if (count($multiProductLines) > 0) {
                $productBreakdown = $this->calculateMultiProductBreakdown($multiProductLines, $itemsSubtotal, $_bundleLineItems);
            } else {
                $productBreakdown = $this->calculateProductBreakdown($product, $bundle, $itemsSubtotal, $_bundleLineItems);
            }

            $productLineItems = array_map(static function (array $line) {
                return [
                    'product_id' => $line['product']->id,
                    'description' => (string) ($line['product']->title ?? 'Product'),
                    'quantity' => (int) $line['quantity'],
                    'unit' => 'Nos',
                    'rate' => round((float) $line['unit_price'], 2),
                    'total_cost' => round((float) $line['line_total'], 2),
                ];
            }, $multiProductLines);

            if (count($productLineItems) === 0 && $product && $productId) {
                $productLineItems[] = [
                    'product_id' => $product->id,
                    'description' => (string) ($product->title ?? 'Product'),
                    'quantity' => 1,
                    'unit' => 'Nos',
                    'rate' => round($catalogTotalBeforeDiscount, 2),
                    'total_cost' => round($catalogTotalBeforeDiscount, 2),
                ];
            }

            if (count($productLineItems) === 0) {
                if ($bundle) {
                    $bundleOrderLines = $this->buildBundleOrderListLineItems($bundle, $installerChoice, 'buy_now');
                    if (count($bundleOrderLines) > 0) {
                        $productLineItems = $bundleOrderLines;
                    }
                }
                if (count($productLineItems) === 0) {
                    $productLineItems = $this->productLineItemsFromBreakdown($productBreakdown);
                }
            }

            // Prepare order data - check if columns exist before including them
            $orderData = [
                'user_id' => Auth::id(),
                'product_id' => $productId,
                'bundle_id' => $bundleId,
                'total_price' => $grandTotal,
                'payment_status' => 'pending',
                'order_status' => 'pending',
                'payment_method' => 'direct',
            ];

            // Add optional columns only if they exist in the database
            $columnsToCheck = [
                'order_type' => 'buy_now',
                'product_price' => $itemsSubtotalAfterDiscount,
                'installation_price' => $installationFee,
                'material_cost' => $materialCost,
                'delivery_fee' => $deliveryFee,
                'inspection_fee' => $inspectionFee,
                'insurance_fee' => $insuranceFee,
                'vat_amount' => $vatAmount,
                'customer_type' => $data['customer_type'] ?? null,
                'installer_choice' => $installerChoice,
                'property_floors' => isset($data['property_floors']) ? (int) $data['property_floors'] : null,
                'property_rooms' => isset($data['property_rooms']) ? (int) $data['property_rooms'] : null,
                'is_gated_estate' => array_key_exists('is_gated_estate', $data)
                    ? filter_var($data['is_gated_estate'], FILTER_VALIDATE_BOOLEAN)
                    : null,
                'estate_name' => (isset($data['estate_name']) && trim((string) $data['estate_name']) !== '')
                    ? trim((string) $data['estate_name'])
                    : null,
                'estate_address' => (isset($data['estate_address']) && trim((string) $data['estate_address']) !== '')
                    ? trim((string) $data['estate_address'])
                    : null,
            ];

            foreach ($columnsToCheck as $column => $value) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('orders', $column)) {
                    $orderData[$column] = $value;
                }
            }

            if (
                !$isAuditOrder
                && !empty($data['audit_request_id'])
                && \Illuminate\Support\Facades\Schema::hasColumn('orders', 'audit_request_id')
            ) {
                $orderData['audit_request_id'] = (int) $data['audit_request_id'];
            }

            // Persist installation / delivery site from Buy Now flow (dashboard sends property_* + contact_*)
            $propAddr = trim((string) ($data['property_address'] ?? ''));
            $propState = trim((string) ($data['property_state'] ?? ''));
            $contactPhone = trim((string) ($data['contact_phone'] ?? ''));
            $contactName = trim((string) ($data['contact_name'] ?? ''));
            if ($propAddr !== '' || $propState !== '' || $contactPhone !== '' || $contactName !== '') {
                $user = Auth::user();
                $deliveryAddress = DeliveryAddress::create([
                    'user_id' => Auth::id(),
                    'phone_number' => $contactPhone !== '' ? $contactPhone : ($user->phone ?? ''),
                    'title' => $contactName !== '' ? $contactName : 'Installation site',
                    'address' => $propAddr !== '' ? $propAddr : ($propState !== '' ? $propState : ''),
                    'state' => $propState !== '' ? $propState : null,
                ]);
                if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'delivery_address_id')) {
                    $orderData['delivery_address_id'] = $deliveryAddress->id;
                }
            }

            // Create order record for Buy Now
            $order = Order::create($orderData);

            // Line items for My Orders / GET /orders/{id} (legacy orders had empty order_items)
            if (count($multiProductLines) > 0) {
                foreach ($multiProductLines as $line) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'itemable_type' => Product::class,
                        'itemable_id' => $line['product']->id,
                        'quantity' => (int) $line['quantity'],
                        'unit_price' => round((float) $line['unit_price'], 2),
                        'subtotal' => round((float) $line['line_total'], 2),
                    ]);
                }
            } elseif ($productId && $product) {
                OrderItem::create([
                    'order_id'      => $order->id,
                    'itemable_type' => Product::class,
                    'itemable_id'   => $product->id,
                    'quantity'      => 1,
                    'unit_price'    => round($itemsSubtotal, 2),
                    'subtotal'      => round($itemsSubtotal, 2),
                ]);
            } elseif ($bundleId && $bundle) {
                OrderItem::create([
                    'order_id'      => $order->id,
                    'itemable_type' => Bundles::class,
                    'itemable_id'   => $bundle->id,
                    'quantity'      => 1,
                    'unit_price'    => round($itemsSubtotal, 2),
                    'subtotal'      => round($itemsSubtotal, 2),
                ]);
            }

            $invoice = [
                'order_id' => $order->id,
                'product_price' => $itemsSubtotalAfterDiscount,
                'items_subtotal_before_discount' => $itemsSubtotal,
                'outright_discount_percentage' => $outrightDiscountPercentage,
                'outright_discount_amount' => $outrightDiscountAmount,
                'product_breakdown' => $productBreakdown,
                'product_line_items' => $productLineItems,
                'installation_fee' => $installationFee,
                'material_cost' => $materialCost,
                'delivery_fee' => $deliveryFee,
                'inspection_fee' => $inspectionFee,
                'insurance_fee' => $insuranceFee,
                'add_ons_total' => $addOnsTotal,
                'add_ons' => $addOns,
                'total_before_vat' => $totalAmount,
                'total_amount' => $totalAmount,
                'vat_amount' => $vatAmount,
                'vat_percentage' => $vatPct,
                'insurance_fee_percentage' => $insPct,
                'total' => $grandTotal,
                'order_type' => 'buy_now',
                'installer_choice' => $installerChoice,
                'note' => ($installerChoice === 'troosolar') 
                    ? 'Installation fees may change after site inspection. Any difference will be updated and shared with you for a one-off payment before installation.'
                    : null,
            ];

            return ResponseHelper::success($invoice, 'Invoice calculated successfully');

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Checkout Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to calculate invoice: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/orders/buy-now
     * Get all Buy Now orders (Admin only)
     */
    public function getBuyNowOrders(Request $request)
    {
        try {
            $query = Order::with(['items.itemable', 'deliveryAddress', 'bundle', 'product', 'user:id,first_name,sur_name,email,phone'])
                ->where('order_type', 'buy_now');

            // Filter by status
            if ($request->has('status')) {
                $query->where('order_status', $request->status);
            }

            // Search by user name or email
            if ($request->has('search')) {
                $search = $request->search;
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('sur_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $orders = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return ResponseHelper::success($orders, 'Buy Now orders retrieved successfully');
        } catch (Exception $e) {
            Log::error('Buy Now Orders Admin Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve Buy Now orders', 500);
        }
    }

    /**
     * GET /api/admin/orders/buy-now/{id}
     * Get single Buy Now order (Admin only)
     */
    public function getBuyNowOrder($id)
    {
        try {
            $order = Order::with([
                'items.itemable',
                'deliveryAddress',
                'bundle',
                'product',
                'user:id,first_name,sur_name,email,phone',
                'auditRequest',
            ])
                ->where('order_type', 'buy_now')
                ->findOrFail($id);

            return ResponseHelper::success(
                $this->formatOrder($order, ['user' => $order->user]),
                'Buy Now order retrieved successfully'
            );
        } catch (Exception $e) {
            Log::error('Buy Now Order Admin Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve Buy Now order', 500);
        }
    }

    /**
     * PUT /api/admin/orders/buy-now/{id}/status
     * Update Buy Now order status (Admin only)
     */
    public function updateBuyNowOrderStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'order_status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded,completed',
                'admin_notes' => 'nullable|string|max:1000',
            ]);

            $order = Order::where('order_type', 'buy_now')->findOrFail($id);
            $previousStatus = $order->order_status;
            $order->order_status = $request->order_status;

            // Only set admin_notes if column exists and value is provided
            if ($request->has('admin_notes') && Schema::hasColumn('orders', 'admin_notes')) {
                $order->admin_notes = $request->admin_notes;
            }

            $order->save();
            $this->notifyCustomerOrderStatusChange($order, $previousStatus);

            return ResponseHelper::success($order, 'Buy Now order status updated successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Buy Now Order Status Update Error: ' . $e->getMessage(), [
                'order_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return ResponseHelper::error('Failed to update Buy Now order status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/orders/bnpl
     * Get all BNPL orders (Admin only)
     */
    public function getBnplOrders(Request $request)
    {
        try {
            $query = Order::with([
                'items.itemable',
                'deliveryAddress',
                'user:id,first_name,sur_name,email,phone',
                'monoCalculation',
                'loanApplication:id,user_id,mono_loan_calculation,customer_type,product_category,property_state,property_address,property_landmark,property_floors,property_rooms,is_gated_estate,estate_name,estate_address,credit_check_method,mono_account_id,mono_customer_id,mono_credit_status,mono_can_afford,mono_monthly_payment_kobo,mono_credit_report,bank_statement_path,live_photo_path,social_media_handle,repayment_duration,loan_amount,order_items_snapshot,loan_plan_snapshot,created_at',
            ]);
            
            // BNPL orders: either have order_type='bnpl' OR have mono_calculation_id (for backward compatibility)
            // This handles cases where order_type column exists but might be NULL for older orders
            if (Schema::hasColumn('orders', 'order_type')) {
                $query->where(function($q) {
                    $q->where('order_type', 'bnpl')
                      ->orWhere(function($subQ) {
                          // Include orders with mono_calculation_id that don't have order_type set to buy_now or audit_only
                          $subQ->whereNotNull('mono_calculation_id')
                               ->where(function($typeQ) {
                                   $typeQ->whereNull('order_type')
                                         ->orWhereNotIn('order_type', ['buy_now', 'audit_only']);
                               });
                      });
                });
            } else {
                // Fallback: BNPL orders have mono_calculation_id
                $query->whereNotNull('mono_calculation_id');
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('order_status', $request->status);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->user_id);
            }

            // Search by user name or email
            if ($request->has('search')) {
                $search = $request->search;
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('sur_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $orders = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return ResponseHelper::success($orders, 'BNPL orders retrieved successfully');
        } catch (Exception $e) {
            Log::error('BNPL Orders Admin Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return ResponseHelper::error('Failed to retrieve BNPL orders: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/orders/bnpl/{id}
     * Get single BNPL order (Admin only)
     */
    public function getBnplOrder($id)
    {
        try {
            $query = Order::with([
                'items.itemable',
                'bundle',
                'product',
                'deliveryAddress',
                'user',
                'auditRequest',
                'monoCalculation.loanInstallments.transaction.user',
                'monoCalculation.loanRepayments.user',
                'loanApplication:id,user_id,mono_loan_calculation,customer_type,product_category,property_state,property_address,property_landmark,property_floors,property_rooms,is_gated_estate,estate_name,estate_address,credit_check_method,mono_account_id,mono_customer_id,mono_credit_status,mono_can_afford,mono_monthly_payment_kobo,mono_credit_report,bank_statement_path,live_photo_path,social_media_handle,repayment_duration,loan_amount,order_items_snapshot,loan_plan_snapshot,created_at',
            ]);
            
            // BNPL orders: either have order_type='bnpl' OR have mono_calculation_id
            if (Schema::hasColumn('orders', 'order_type')) {
                $query->where(function($q) {
                    $q->where('order_type', 'bnpl')
                      ->orWhere(function($subQ) {
                          $subQ->whereNotNull('mono_calculation_id')
                               ->where(function($typeQ) {
                                   $typeQ->whereNull('order_type')
                                         ->orWhereNotIn('order_type', ['buy_now', 'audit_only']);
                               });
                      });
                });
            } else {
                $query->whereNotNull('mono_calculation_id');
            }
            
            $order = $query->findOrFail($id);

            // Fallback for legacy BNPL orders where order_items table is empty:
            // derive order items from loan_application.order_items_snapshot.
            if ($order->items()->count() === 0 && $order->loanApplication && is_array($order->loanApplication->order_items_snapshot)) {
                $snapshot = $order->loanApplication->order_items_snapshot;

                $bundleIds = collect($snapshot)
                    ->where('itemable_type', Bundles::class)
                    ->pluck('itemable_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $productIds = collect($snapshot)
                    ->where('itemable_type', Product::class)
                    ->pluck('itemable_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $bundleMap = !empty($bundleIds)
                    ? Bundles::whereIn('id', $bundleIds)->get()->keyBy('id')
                    : collect();
                $productMap = !empty($productIds)
                    ? Product::whereIn('id', $productIds)->get()->keyBy('id')
                    : collect();

                $derivedItems = collect($snapshot)->map(function ($row, $idx) use ($bundleMap, $productMap) {
                    $itemableType = $row['itemable_type'] ?? null;
                    $itemableId = $row['itemable_id'] ?? null;
                    $qty = (int) ($row['quantity'] ?? 1);
                    $unitPrice = (float) ($row['unit_price'] ?? 0);
                    $subtotal = (float) ($row['subtotal'] ?? ($unitPrice * $qty));

                    $name = "Item " . ($idx + 1);
                    $type = null;

                    if ($itemableType === Bundles::class && $itemableId && $bundleMap->has($itemableId)) {
                        $bundle = $bundleMap->get($itemableId);
                        $name = $bundle->title ?? $bundle->name ?? $name;
                        $type = 'bundle';
                    } elseif ($itemableType === Product::class && $itemableId && $productMap->has($itemableId)) {
                        $product = $productMap->get($itemableId);
                        $name = $product->title ?? $product->name ?? $name;
                        $type = 'product';
                    }

                    return [
                        'id' => null,
                        'name' => $name,
                        'title' => $name,
                        'type' => $type,
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'subtotal' => $subtotal,
                        'itemable_type' => $itemableType,
                        'itemable_id' => $itemableId,
                    ];
                })->values();

                $order->setRelation('items', $derivedItems);
            }

            $repaymentExtras = $this->buildAdminBnplRepaymentPayload($order);
            $payload = array_merge($order->toArray(), $repaymentExtras);
            if ($order->deliveryAddress) {
                $payload['delivery_address'] = $this->formatDeliveryAddressForApi($order->deliveryAddress, $order->user);
            }
            $payload = $this->mergeBnplLoanApplicationEstateFallback($order, $payload);

            return ResponseHelper::success($payload, 'BNPL order retrieved successfully');
        } catch (Exception $e) {
            Log::error('BNPL Order Admin Error: ' . $e->getMessage(), [
                'order_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return ResponseHelper::error('Failed to retrieve BNPL order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Fill loan_application.estate_* from linked audit request when missing (legacy rows or duplicate loan_application).
     */
    private function mergeBnplLoanApplicationEstateFallback(Order $order, array $payload): array
    {
        if (empty($payload['loan_application']) || ! is_array($payload['loan_application'])) {
            return $payload;
        }
        $la = &$payload['loan_application'];
        $audit = $order->auditRequest;
        if (! $audit && $order->audit_request_id) {
            $audit = AuditRequest::query()->find($order->audit_request_id);
        }
        if (! $audit) {
            return $payload;
        }
        if (empty($la['estate_name']) && ! empty($audit->estate_name)) {
            $la['estate_name'] = $audit->estate_name;
        }
        if (empty($la['estate_address']) && ! empty($audit->estate_address)) {
            $la['estate_address'] = $audit->estate_address;
        }

        return $payload;
    }

    /**
     * Admin BNPL order detail: repayment schedule, summary, and history for tracking.
     */
    private function buildAdminBnplRepaymentPayload(Order $order): array
    {
        $mono = $order->monoCalculation;
        $orderUser = $order->user;
        $orderCustomerName = $orderUser
            ? trim((string) ($orderUser->first_name ?? '').' '.(string) ($orderUser->sur_name ?? ''))
            : null;

        if (! $mono) {
            return [
                'repayment_schedule' => [],
                'repayment_summary' => [
                    'total_installments' => 0,
                    'paid_installments' => 0,
                    'pending_installments' => 0,
                    'overdue_installments' => 0,
                    'total_amount' => 0.0,
                    'paid_amount' => 0.0,
                    'pending_amount' => 0.0,
                    'overdue_amount' => 0.0,
                    'order_customer_id' => $order->user_id,
                    'order_customer_name' => $orderCustomerName,
                    'order_customer_email' => $orderUser?->email,
                ],
                'repayment_history' => [],
                'loan_details' => null,
            ];
        }

        $mono->loadMissing(['loanInstallments.transaction.user', 'loanRepayments.user']);

        $sortedInstallments = $mono->loanInstallments->sortBy(function ($inst) {
            return $inst->payment_date ? $inst->payment_date->timestamp : 0;
        })->values();

        $installments = [];
        foreach ($sortedInstallments as $installment) {
            $paymentDate = $installment->payment_date;
            $paidAt = $installment->paid_at;
            $trans = $installment->transaction;
            $transactedAt = $trans ? $trans->transacted_at : null;
            $payer = $trans && $trans->relationLoaded('user') ? $trans->user : null;
            if ($trans && ! $payer && $trans->user_id) {
                $trans->loadMissing('user');
                $payer = $trans->user;
            }
            $payerName = $payer
                ? trim((string) ($payer->first_name ?? '').' '.(string) ($payer->sur_name ?? ''))
                : null;
            $paidByLabel = $installment->status === 'paid'
                ? ($payerName ?: $orderCustomerName ?: ($orderUser ? 'Customer #'.$orderUser->id : 'Customer'))
                : null;

            $isOverdue = $paymentDate && $paymentDate->lt(now()) && $installment->status !== 'paid';

            $installmentData = [
                'id' => $installment->id,
                'installment_number' => $installment->installment_number ?? null,
                'amount' => (float) $installment->amount,
                'payment_date' => $paymentDate ? $paymentDate->format('Y-m-d') : null,
                'status' => $installment->status,
                'paid_at' => $paidAt ? $paidAt->format('Y-m-d H:i:s') : null,
                'is_overdue' => $isOverdue,
                'computed_status' => $installment->computed_status,
                'paid_by_display' => $paidByLabel,
                'transaction' => $trans ? [
                    'id' => $trans->id,
                    'tx_id' => $trans->tx_id,
                    'method' => $trans->method,
                    'amount' => (float) $trans->amount,
                    'transacted_at' => $transactedAt ? $transactedAt->format('Y-m-d H:i:s') : null,
                    'user_id' => $trans->user_id,
                    'payer_name' => $payerName,
                    'payer_email' => $payer?->email,
                ] : null,
            ];
            $installments[] = $installmentData;
        }

        $totalInstallments = count($installments);
        $paidInstallments = count(array_filter($installments, fn ($i) => $i['status'] === 'paid'));
        $pendingInstallments = count(array_filter($installments, fn ($i) => $i['status'] !== 'paid'));
        $overdueInstallments = count(array_filter($installments, fn ($i) => $i['is_overdue'] === true));
        $totalAmount = array_sum(array_column($installments, 'amount'));
        $paidAmount = array_sum(array_column(array_filter($installments, fn ($i) => $i['status'] === 'paid'), 'amount'));
        $pendingAmount = $totalAmount - $paidAmount;
        $overdueAmount = array_sum(array_column(array_filter($installments, fn ($i) => $i['is_overdue'] === true), 'amount'));

        $repayments = [];
        $repaymentRows = $mono->loanRepayments->sortByDesc(function ($r) {
            return $r->created_at ? $r->created_at->timestamp : 0;
        })->values();
        foreach ($repaymentRows as $repayment) {
            $ru = $repayment->relationLoaded('user') ? $repayment->user : null;
            if (! $ru && $repayment->user_id) {
                $repayment->loadMissing('user');
                $ru = $repayment->user;
            }
            $repPayerName = $ru
                ? trim((string) ($ru->first_name ?? '').' '.(string) ($ru->sur_name ?? ''))
                : null;
            $repayments[] = [
                'id' => $repayment->id,
                'amount' => (float) $repayment->amount,
                'status' => $repayment->status,
                'created_at' => $repayment->created_at->format('Y-m-d H:i:s'),
                'user_id' => $repayment->user_id,
                'payer_name' => $repPayerName ?: ($orderCustomerName ?: null),
                'payer_email' => $ru?->email,
            ];
        }

        return [
            'repayment_schedule' => $installments,
            'repayment_summary' => [
                'total_installments' => $totalInstallments,
                'paid_installments' => $paidInstallments,
                'pending_installments' => $pendingInstallments,
                'overdue_installments' => $overdueInstallments,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'pending_amount' => $pendingAmount,
                'overdue_amount' => $overdueAmount,
                'order_customer_id' => $order->user_id,
                'order_customer_name' => $orderCustomerName,
                'order_customer_email' => $orderUser?->email,
            ],
            'repayment_history' => $repayments,
            'loan_details' => [
                'loan_amount' => (float) ($mono->loan_amount ?? 0),
                'down_payment' => (float) ($mono->down_payment ?? 0),
                'total_amount' => (float) ($mono->total_amount ?? 0),
                'repayment_duration' => $mono->repayment_duration,
                'interest_rate' => $mono->interest_rate,
            ],
        ];
    }

    /**
     * Resolve bundle/product for invoice & summary when order.bundle_id / product_id are empty
     * (common for BNPL: bundle stored on polymorphic order_items or loan_application.order_items_snapshot).
     *
     * @return array{0: ?Product, 1: ?Bundles}
     */
    private function resolveOrderBundleAndProductForInvoice(Order $order): array
    {
        $product = $order->product_id ? $order->product : null;
        $bundle = $order->bundle_id ? $order->bundle : null;

        $bundleRelations = ['bundleItems.product.category', 'customServices', 'bundleMaterials.material'];

        if ($bundle) {
            $bundle->loadMissing($bundleRelations);
        }
        if ($product) {
            $product->loadMissing('category');
        }
        if ($bundle || $product) {
            return [$product, $bundle];
        }

        $order->loadMissing(['items.itemable']);
        foreach ($order->items as $orderItem) {
            $itemable = $orderItem->itemable;
            if ($itemable instanceof Bundles) {
                $itemable->loadMissing($bundleRelations);

                return [null, $itemable];
            }
            if ($itemable instanceof Product) {
                $itemable->loadMissing('category');

                return [$itemable, null];
            }
        }

        $order->loadMissing('loanApplication');
        $application = $order->loanApplication;
        if ($application && is_array($application->order_items_snapshot)) {
            foreach ($application->order_items_snapshot as $row) {
                $type = (string) ($row['itemable_type'] ?? '');
                $oid = $row['itemable_id'] ?? null;
                if ($oid === null || $type === '') {
                    continue;
                }
                if (class_exists($type) && is_a($type, Bundles::class, true)) {
                    $b = Bundles::with($bundleRelations)->find((int) $oid);
                    if ($b) {
                        return [null, $b];
                    }
                }
                if (class_exists($type) && is_a($type, Product::class, true)) {
                    $p = Product::with('category')->find((int) $oid);
                    if ($p) {
                        return [$p, null];
                    }
                }
            }
        }

        return [null, null];
    }

    /**
     * API: show customer name as site contact when delivery row title is the BNPL placeholder.
     */
    private function formatDeliveryAddressForApi(?DeliveryAddress $address, ?User $user): ?array
    {
        if (! $address) {
            return null;
        }
        $data = $address->toArray();
        $customerName = $user ? trim((string) ($user->first_name ?? '').' '.(string) ($user->sur_name ?? '')) : '';
        $title = trim((string) ($data['title'] ?? ''));
        if ($customerName !== '' && ($title === '' || strcasecmp($title, 'BNPL delivery') === 0)) {
            $data['title'] = $customerName;
        }

        return $data;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OrderItem>|\Illuminate\Database\Eloquent\Collection<int, OrderItem>  $orderItems
     * @return array<int, array{name: string, description: string, quantity: int, price: float, type?: string}>
     */
    private function buildOrderSummaryItemsFromOrderItems($orderItems, string $checkoutFlow = 'buy_now', ?string $installerChoice = null): array
    {
        $items = [];

        foreach ($orderItems as $orderItem) {
            $itemable = $orderItem->itemable;
            if (! $itemable) {
                continue;
            }

            if ($itemable instanceof Bundles) {
                $itemable->loadMissing(['bundleItems.product.category', 'customServices', 'bundleMaterials.material']);
                $bundleOrderListItems = $this->buildOrderSummaryItemsFromBundleOrderList($itemable, $installerChoice, $checkoutFlow);
                if (count($bundleOrderListItems) > 0) {
                    $orderQty = max(1, (int) ($orderItem->quantity ?? 1));
                    if ($orderQty > 1) {
                        foreach ($bundleOrderListItems as &$bundleLine) {
                            $bundleLine['quantity'] = (int) $bundleLine['quantity'] * $orderQty;
                        }
                        unset($bundleLine);
                    }
                    array_push($items, ...$bundleOrderListItems);

                    continue;
                }

                $bundleItems = $itemable->bundleItems;
                if ($bundleItems->isNotEmpty()) {
                    foreach ($bundleItems as $bundleRow) {
                        if (! $bundleRow->product) {
                            continue;
                        }
                        $product = $bundleRow->product;
                        $productDetails = [];
                        try {
                            if (method_exists($product, 'details') && $product->details) {
                                $productDetails = $product->details->pluck('detail')->toArray();
                            }
                        } catch (\Exception $e) {
                            Log::warning('Error getting product details: '.$e->getMessage());
                        }

                        $items[] = [
                            'name' => $product->title ?? 'Unknown Product',
                            'description' => ! empty($productDetails)
                                ? implode(', ', $productDetails)
                                : ($product->title ?? 'No description'),
                            'quantity' => (int) ($bundleRow->quantity ?? 1) * max(1, (int) ($orderItem->quantity ?? 1)),
                            'price' => $this->resolveCatalogUnitPrice($product),
                            'type' => 'product',
                        ];
                    }

                    continue;
                }

                $desc = trim((string) ($itemable->product_model ?? ''));
                if ($desc === '') {
                    $desc = trim((string) ($itemable->what_is_inside_bundle_text ?? $itemable->detailed_description ?? ''));
                }
                $items[] = [
                    'name' => $itemable->title ?? 'Bundle',
                    'description' => $desc !== '' ? $desc : ($itemable->title ?? 'Bundle'),
                    'quantity' => max(1, (int) ($orderItem->quantity ?? 1)),
                    'price' => (float) ($orderItem->unit_price ?? 0) > 0
                        ? (float) $orderItem->unit_price
                        : $this->resolveCatalogUnitPrice($itemable),
                    'type' => 'bundle',
                ];

                continue;
            }

            if ($itemable instanceof Product) {
                $productDetails = [];
                try {
                    if (method_exists($itemable, 'details') && $itemable->details) {
                        $productDetails = $itemable->details->pluck('detail')->toArray();
                    }
                } catch (\Exception $e) {
                    Log::warning('Error getting product details: '.$e->getMessage());
                }

                $qty = max(1, (int) ($orderItem->quantity ?? 1));
                $unit = (float) ($orderItem->unit_price ?? 0);
                if ($unit <= 0) {
                    $unit = $this->resolveCatalogUnitPrice($itemable);
                }

                $items[] = [
                    'name' => $itemable->title ?? 'Unknown Product',
                    'description' => ! empty($productDetails)
                        ? implode(', ', $productDetails)
                        : ($itemable->title ?? 'No description'),
                    'quantity' => $qty,
                    'price' => round($unit, 2),
                    'type' => 'product',
                ];
            }
        }

        return $items;
    }

    /**
     * @return array<int, array{name: string, description: string, quantity: int, price: float, type?: string}>
     */
    private function buildOrderSummaryItemsFromLoanSnapshot(Order $order): array
    {
        $order->loadMissing('loanApplication');
        $application = $order->loanApplication;
        if (! $application || ! is_array($application->order_items_snapshot)) {
            return [];
        }

        $snapshot = $application->order_items_snapshot;
        $bundleIds = collect($snapshot)
            ->where('itemable_type', Bundles::class)
            ->pluck('itemable_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $productIds = collect($snapshot)
            ->where('itemable_type', Product::class)
            ->pluck('itemable_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $bundleMap = ! empty($bundleIds)
            ? Bundles::with(['bundleItems.product', 'customServices', 'bundleMaterials.material'])->whereIn('id', $bundleIds)->get()->keyBy('id')
            : collect();
        $productMap = ! empty($productIds)
            ? Product::whereIn('id', $productIds)->get()->keyBy('id')
            : collect();

        $items = [];
        foreach ($snapshot as $row) {
            $itemableType = $row['itemable_type'] ?? null;
            $itemableId = $row['itemable_id'] ?? null;
            $qty = max(1, (int) ($row['quantity'] ?? 1));
            $unitPrice = (float) ($row['unit_price'] ?? 0);
            $subtotal = (float) ($row['subtotal'] ?? ($unitPrice * $qty));

            if ($itemableType === Bundles::class && $itemableId && $bundleMap->has($itemableId)) {
                $bundle = $bundleMap->get($itemableId);
                $fakeItem = new OrderItem([
                    'quantity' => $qty,
                    'unit_price' => $unitPrice > 0 ? $unitPrice : $subtotal,
                    'subtotal' => $subtotal > 0 ? $subtotal : $unitPrice * $qty,
                ]);
                $fakeItem->setRelation('itemable', $bundle);
                $bundleComponentItems = $this->buildOrderSummaryItemsFromOrderItems(collect([$fakeItem]), 'bnpl');
                if (! empty($bundleComponentItems)) {
                    $items = array_merge($items, $bundleComponentItems);
                    continue;
                }
            }

            $name = 'Item';
            $type = null;
            if ($itemableType === Bundles::class && $itemableId && $bundleMap->has($itemableId)) {
                $bundle = $bundleMap->get($itemableId);
                $name = $bundle->title ?? $bundle->name ?? $name;
                $type = 'bundle';
            } elseif ($itemableType === Product::class && $itemableId && $productMap->has($itemableId)) {
                $product = $productMap->get($itemableId);
                $name = $product->title ?? $product->name ?? $name;
                $type = 'product';
            }

            $price = $unitPrice > 0 ? $unitPrice : ($qty > 0 ? round($subtotal / $qty, 2) : $subtotal);
            $items[] = [
                'name' => $name,
                'description' => $name,
                'quantity' => $qty,
                'price' => round($price, 2),
                'type' => $type,
            ];
        }

        return $items;
    }

    /**
     * Classify a bundle catalog line using category + product title (categories are often missing or generic).
     */
    private function classifyInvoiceBundleLineType(string $categoryTitle, string $productTitle): string
    {
        $h = strtolower(trim($categoryTitle.' '.$productTitle));
        if ($h === '') {
            return 'other';
        }
        // Battery before inverter so titles like "battery module" are not classified as inverter
        if (str_contains($h, 'battery')
            || str_contains($h, 'lifepo')
            || str_contains($h, 'lipo')
            || str_contains($h, 'li-ion')
            || str_contains($h, 'lithium')) {
            return 'batteries';
        }
        if (str_contains($h, 'inverter')
            || str_contains($h, 'all-in-one')
            || str_contains($h, 'all in one')
            || str_contains($h, 'hybrid')) {
            return 'inverter';
        }
        if (preg_match('/\b(solar[-\s]?)?panel(s)?\b/', $h)
            || preg_match('/\b(pv|photovoltaic)\b/', $h)
            || str_contains($h, 'monofacial')
            || str_contains($h, 'bifacial')
            || str_contains($h, 'mono perc')
            || str_contains($h, 'polycrystalline')) {
            return 'panels';
        }

        return 'other';
    }

    private const BUNDLE_OL_PREFIX = '[OL]';

    private const BUNDLE_OL_VIS_TROO = '[OL:TROOSOLAR]';

    private const BUNDLE_OL_VIS_OWN = '[OL:OWN]';

    private function isBundleOrderListServiceTitle(string $title): bool
    {
        return str_starts_with($title, self::BUNDLE_OL_PREFIX)
            || str_starts_with($title, self::BUNDLE_OL_VIS_TROO)
            || str_starts_with($title, self::BUNDLE_OL_VIS_OWN);
    }

    private function parseBundleOrderListVisibility(string $title): string
    {
        if (str_starts_with($title, self::BUNDLE_OL_VIS_TROO)) {
            return 'troosolar';
        }
        if (str_starts_with($title, self::BUNDLE_OL_VIS_OWN)) {
            return 'own';
        }

        return 'both';
    }

    private function stripBundleOrderListPrefix(string $title): string
    {
        if (str_starts_with($title, self::BUNDLE_OL_VIS_TROO)) {
            return trim(substr($title, strlen(self::BUNDLE_OL_VIS_TROO)));
        }
        if (str_starts_with($title, self::BUNDLE_OL_VIS_OWN)) {
            return trim(substr($title, strlen(self::BUNDLE_OL_VIS_OWN)));
        }
        if (str_starts_with($title, self::BUNDLE_OL_PREFIX)) {
            return trim(substr($title, strlen(self::BUNDLE_OL_PREFIX)));
        }

        return trim($title);
    }

    private function parseBundleLineQuantityApplies(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }

        return ! in_array(strtolower(trim((string) $value)), ['false', '0', 'no', 'nil', 'n/a', 'na', 'not_applicable', 'not applicable'], true);
    }

    private function bundleOrderListVisibleForInstaller(string $visibility, ?string $installerChoice): bool
    {
        if ($installerChoice === null) {
            return true;
        }
        if ($visibility === 'troosolar') {
            return $installerChoice !== 'own';
        }
        if ($visibility === 'own') {
            return $installerChoice === 'own';
        }

        return true;
    }

    /**
     * Resolve installer for order-list / invoice visibility (matches Buy Now checkout filtering).
     */
    private function resolveOrderInstallerChoice(?Order $order): ?string
    {
        if (! $order) {
            return null;
        }

        if (Schema::hasColumn('orders', 'installer_choice')) {
            $raw = strtolower(trim((string) ($order->installer_choice ?? '')));
            if ($raw === 'own' || $raw === 'troosolar') {
                return $raw;
            }
        }

        $orderType = strtolower(trim((string) ($order->order_type ?? '')));
        $isBuyNowLike = $orderType === 'buy_now'
            || (
                $orderType === ''
                && strtolower(trim((string) ($order->payment_method ?? ''))) === 'direct'
                && empty($order->mono_calculation_id)
            );

        // Legacy Buy Now rows before installer_choice was persisted / order_type missing.
        if ($isBuyNowLike) {
            $install = (float) ($order->installation_price ?? 0);
            $inspect = Schema::hasColumn('orders', 'inspection_fee')
                ? (float) ($order->inspection_fee ?? 0)
                : 0.0;
            if ($install <= 0.005 && $inspect <= 0.005) {
                return 'own';
            }

            return 'troosolar';
        }

        return null;
    }

    /**
     * Hide Installation Material Cost order-list rows when they should not appear for this order.
     * Order-list material rows are catalog lines (bundle [OL] items), separate from the payment
     * summary material_cost fee — do not hide Troosolar installer rows based on orders.material_cost.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function filterBundleOrderListLinesForOrder(array $lines, ?Order $order, ?string $installerChoice): array
    {
        return array_values(array_filter($lines, static function (array $line) use ($installerChoice) {
            $desc = strtolower(trim((string) ($line['description'] ?? $line['name'] ?? '')));
            $isMaterialLine = str_contains($desc, 'installation material')
                || $desc === 'material cost'
                || $desc === 'installation materials cost';

            if (! $isMaterialLine) {
                return true;
            }

            // Own installer never sees Troosolar-only material order-list rows.
            if ($installerChoice === 'own') {
                return false;
            }

            // Troosolar installer: keep the row when it has a catalog amount on the line.
            $lineAmount = max(
                (float) ($line['rate'] ?? 0),
                (float) ($line['price'] ?? 0),
                (float) ($line['total_cost'] ?? 0)
            );

            return $lineAmount > 0.005;
        }));
    }

    private function resolveBundleCheckoutFlow(?Order $order = null, ?string $explicit = null): string
    {
        if ($explicit !== null && in_array($explicit, ['buy_now', 'bnpl'], true)) {
            return $explicit;
        }
        if ($order !== null && ($order->order_type ?? null) === 'bnpl') {
            return 'bnpl';
        }

        return 'buy_now';
    }

    private function customServiceMatchesCheckoutFlow($service, string $checkoutFlow): bool
    {
        if (! Schema::hasColumn('custom_services', 'flow_type')) {
            return $checkoutFlow === 'buy_now';
        }

        $svcFlow = $service->flow_type ?? 'buy_now';

        return in_array($svcFlow, ['buy_now', 'bnpl'], true)
            ? $svcFlow === $checkoutFlow
            : $checkoutFlow === 'buy_now';
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\CustomService>
     */
    private function bundleCustomServicesForCheckoutFlow(Bundles $bundle, string $checkoutFlow)
    {
        $checkoutFlow = $this->resolveBundleCheckoutFlow(null, $checkoutFlow);
        $all = $bundle->customServices()->orderBy('id')->get();
        $scoped = $all->filter(fn ($service) => $this->customServiceMatchesCheckoutFlow($service, $checkoutFlow));

        if ($checkoutFlow === 'bnpl' && $scoped->isEmpty()) {
            return $all->filter(fn ($service) => $this->customServiceMatchesCheckoutFlow($service, 'buy_now'));
        }

        return $scoped;
    }

    /**
     * Real bundle order-list rows (custom [OL] services and/or bundle products) for invoices and summaries.
     *
     * @return array<int, array{product_id: null, description: string, quantity: int, unit: string, rate: float, total_cost: float}>
     */
    private function buildBundleOrderListLineItems(Bundles $bundle, ?string $installerChoice = null, string $checkoutFlow = 'buy_now'): array
    {
        $checkoutFlow = $this->resolveBundleCheckoutFlow(null, $checkoutFlow);
        $bundle->loadMissing(['bundleItems.product', 'customServices', 'bundleMaterials.material']);

        $customOrderItems = [];
        foreach ($this->bundleCustomServicesForCheckoutFlow($bundle, $checkoutFlow) as $service) {
            $rawTitle = (string) ($service->title ?? '');
            if (! $this->isBundleOrderListServiceTitle($rawTitle)) {
                continue;
            }
            $visibility = $this->parseBundleOrderListVisibility($rawTitle);
            if (! $this->bundleOrderListVisibleForInstaller($visibility, $installerChoice)) {
                continue;
            }
            $qtyApplies = $this->parseBundleLineQuantityApplies($service->quantity_applies ?? true);
            $qty = max(1, (int) ($service->quantity ?? 1));
            $unit = (string) ($service->unit ?? 'Nos');
            $customOrderItems[] = [
                'description' => $this->stripBundleOrderListPrefix($rawTitle),
                'quantity' => $qty,
                'unit' => $unit,
                'quantity_applies' => $qtyApplies,
                'rate' => (float) ($service->service_amount ?? 0),
            ];
        }

        $productRows = [];
        foreach ($bundle->bundleItems ?? [] as $bundleItem) {
            $product = $bundleItem->product;
            $name = $product?->title;
            if (! $name) {
                continue;
            }
            $rateOverride = (float) ($bundleItem->rate_override ?? 0);
            $rate = $rateOverride > 0 ? $rateOverride : $this->resolveCatalogUnitPrice($product);
            $productRows[] = [
                'description' => (string) $name,
                'quantity' => max(1, (int) ($bundleItem->quantity ?? 1)),
                'unit' => 'Nos',
                'quantity_applies' => true,
                'rate' => $rate,
            ];
        }

        if (count($productRows) === 0 && trim((string) ($bundle->product_model ?? '')) !== '') {
            foreach (array_filter(array_map('trim', explode('/', (string) $bundle->product_model))) as $part) {
                $productRows[] = [
                    'description' => $part,
                    'quantity' => 1,
                    'unit' => 'Nos',
                    'quantity_applies' => true,
                    'rate' => 0.0,
                ];
            }
        }

        if (count($productRows) === 0) {
            foreach ($bundle->bundleMaterials ?? [] as $bm) {
                $material = $bm->material;
                if (! $material) {
                    continue;
                }
                $name = trim((string) ($material->name ?? $material->title ?? ''));
                if ($name === '' || $this->isBundleFeeMaterialName($name) || ! $this->isBundleProductMaterialName($name)) {
                    continue;
                }
                $rate = (float) ($bm->rate_override ?? $material->selling_rate ?? $material->rate ?? 0);
                $productRows[] = [
                    'description' => $name,
                    'quantity' => max(1, (int) ($bm->quantity ?? 1)),
                    'unit' => 'Nos',
                    'quantity_applies' => true,
                    'rate' => $rate,
                ];
            }
        }

        $orderListSource = count($customOrderItems) > 0 ? $customOrderItems : $productRows;

        if (count($orderListSource) === 0) {
            $orderListSource = $this->parseWhatIsInsideBundleTextLines($bundle->what_is_inside_bundle_text ?? null);
        }

        if (count($orderListSource) === 0) {
            $orderListSource = $this->parseSystemCapacityDisplayProductLines($bundle->system_capacity_display ?? null);
        }

        if (count($orderListSource) === 0) {
            $bundleRate = $this->resolveCatalogUnitPrice($bundle);
            $bundleTitle = trim((string) ($bundle->title ?? $bundle->name ?? ''));
            if ($bundleTitle !== '' || $bundleRate > 0) {
                $orderListSource[] = [
                    'description' => $bundleTitle !== '' ? $bundleTitle : 'Solar bundle',
                    'quantity' => 1,
                    'unit' => 'Lots',
                    'quantity_applies' => false,
                    'rate' => $bundleRate,
                ];
            }
        }

        return $this->formatBundleOrderListRows($orderListSource);
    }

    private function isBundleFeeMaterialName(string $name): bool
    {
        $n = strtolower($name);

        return str_contains($n, 'installation fee')
            || str_contains($n, 'delivery fee')
            || str_contains($n, 'inspection fee');
    }

    private function isBundleProductMaterialName(string $name): bool
    {
        $n = strtolower(trim($name));
        if ($n === '') {
            return false;
        }

        return str_contains($n, 'inverter')
            || str_contains($n, 'battery')
            || str_contains($n, 'solar panel');
    }

    /**
     * Parse admin "what is inside" text (newline list) into order-list rows.
     *
     * @return array<int, array{description: string, quantity: int, unit: string, quantity_applies: bool, rate: float}>
     */
    private function parseWhatIsInsideBundleTextLines(?string $text): array
    {
        $text = trim((string) $text);
        if ($text === '' || ! str_contains($text, "\n")) {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || strlen($line) > 240) {
                continue;
            }

            if (preg_match('/^(\d+)\s+units?\s+of\s+(.+)$/i', $line, $matches)) {
                $rows[] = [
                    'description' => trim($matches[2]),
                    'quantity' => max(1, (int) $matches[1]),
                    'unit' => 'Nos',
                    'quantity_applies' => true,
                    'rate' => 0.0,
                ];

                continue;
            }

            $rows[] = [
                'description' => $line,
                'quantity' => 1,
                'unit' => 'Nos',
                'quantity_applies' => true,
                'rate' => 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array{description: string, quantity: int, unit: string, quantity_applies: bool, rate: float}>
     */
    private function parseSystemCapacityDisplayProductLines(?string $text): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return [];
        }

        $text = preg_replace('/\s*-\s*[^-]+$/', '', $text) ?? $text;
        $parts = preg_split('/\s*,\s*|\s*&\s*/', $text) ?: [];
        $rows = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || ! $this->isBundleProductMaterialName($part)) {
                continue;
            }
            $rows[] = [
                'description' => $part,
                'quantity' => 1,
                'unit' => 'Nos',
                'quantity_applies' => true,
                'rate' => 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{description: string, quantity: int, unit: string, quantity_applies: bool, rate: float}>  $rows
     * @return array<int, array{product_id: null, description: string, quantity: int, unit: string, rate: float, total_cost: float}>
     */
    private function formatBundleOrderListRows(array $rows): array
    {
        $formatted = [];

        foreach ($rows as $item) {
            $unit = (string) ($item['unit'] ?? 'Nos');
            $qtyApplies = (bool) ($item['quantity_applies'] ?? true);
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $multiplier = $qtyApplies ? ($unit === 'Lots' ? 1 : $qty) : 1;
            $displayQty = $qtyApplies ? ($unit === 'Lots' ? 1 : $qty) : 1;
            $rate = round((float) ($item['rate'] ?? 0), 2);
            $formatted[] = [
                'product_id' => null,
                'description' => (string) ($item['description'] ?? 'Item'),
                'quantity' => $displayQty,
                'unit' => $unit,
                'rate' => $rate,
                'total_cost' => round($rate * $multiplier, 2),
            ];
        }

        return $formatted;
    }

    /**
     * Invoice product rows for bundle / BNPL orders (order list, snapshot, or bundle title).
     *
     * @return array<int, array{product_id: null, description: string, quantity: int, unit: string, rate: float, total_cost: float}>
     */
    private function buildInvoiceProductLineItemsFromOrder(Order $order, ?Bundles $bundle): array
    {
        $checkoutFlow = $this->resolveBundleCheckoutFlow($order);
        $installerChoice = $this->resolveOrderInstallerChoice($order);
        if ($bundle) {
            $lines = $this->buildBundleOrderListLineItems($bundle, $installerChoice, $checkoutFlow);
            $lines = $this->filterBundleOrderListLinesForOrder($lines, $order, $installerChoice);
            if (count($lines) > 0) {
                return $lines;
            }
        }

        $summaryItems = $this->buildOrderSummaryItemsFromLoanSnapshot($order);
        if (count($summaryItems) === 0) {
            return [];
        }

        $rows = [];
        foreach ($summaryItems as $item) {
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $rate = round((float) ($item['price'] ?? 0), 2);
            $rows[] = [
                'product_id' => null,
                'description' => (string) ($item['name'] ?? $item['description'] ?? 'Item'),
                'quantity' => $qty,
                'unit' => 'Nos',
                'rate' => $rate,
                'total_cost' => round($rate * $qty, 2),
            ];
        }

        return $this->filterBundleOrderListLinesForOrder($rows, $order, $installerChoice);
    }

    /**
     * @param  array<int, array{description?: string}>  $rows
     */
    private function isGenericProductBreakdownLineItems(array $rows): bool
    {
        if (count($rows) === 0) {
            return false;
        }

        $generic = ['solar inverter', 'solar panels', 'battery', 'batteries'];
        $matched = 0;

        foreach ($rows as $row) {
            $desc = strtolower(trim((string) ($row['description'] ?? '')));
            foreach ($generic as $label) {
                if ($desc === $label || str_starts_with($desc, $label)) {
                    $matched++;
                    break;
                }
            }
        }

        return $matched === count($rows) && $matched <= 3;
    }

    /**
     * @return array<int, array{name: string, description: string, quantity: int, price: float, type: string}>
     */
    private function buildOrderSummaryItemsFromBundleOrderList(Bundles $bundle, ?string $installerChoice = null, string $checkoutFlow = 'buy_now'): array
    {
        $items = [];
        foreach ($this->buildBundleOrderListLineItems($bundle, $installerChoice, $checkoutFlow) as $line) {
            $items[] = [
                'name' => (string) ($line['description'] ?? 'Item'),
                'description' => (string) ($line['description'] ?? 'Item'),
                'quantity' => (int) ($line['quantity'] ?? 1),
                'price' => (float) ($line['rate'] ?? 0),
                'type' => 'product',
            ];
        }

        return $items;
    }

    /**
     * Calculate product breakdown (inverter, panels, batteries) and optional per-line invoice rows.
     *
     * @param  array<int, array{type: string, description: string, quantity: int, price: float}>|null  $bundleLineItemsOut
     */
    private function calculateProductBreakdown($product, $bundle, $totalPrice, ?array &$bundleLineItemsOut = null, string $checkoutFlow = 'buy_now'): array
    {
        if ($bundleLineItemsOut !== null) {
            $bundleLineItemsOut = [];
        }

        $breakdown = [
            'solar_inverter' => ['quantity' => 0, 'price' => 0, 'description' => ''],
            'solar_panels' => ['quantity' => 0, 'price' => 0, 'description' => ''],
            'batteries' => ['quantity' => 0, 'price' => 0, 'description' => ''],
        ];

        $totalPrice = (float) $totalPrice;
        $hasBuiltLineItems = false;

        if ($bundle) {
            $lines = [];

            try {
                $bundleItems = $bundle->bundleItems()->with('product.category')->get();

                foreach ($bundleItems as $item) {
                    if (! $item || ! $item->product) {
                        continue;
                    }
                    $category = $item->product->category;
                    $categoryName = $category ? strtolower((string) ($category->title ?? '')) : '';
                    $productTitle = (string) ($item->product->title ?? '');
                    $productDiscount = (float) ($item->product->discount_price ?? 0);
                    $baseUnit = $productDiscount > 0
                        ? $productDiscount
                        : (float) ($item->product->price ?? 0);
                    $rateOverride = (float) ($item->rate_override ?? 0);
                    $unitPrice = $rateOverride > 0 ? $rateOverride : $baseUnit;
                    $qty = max(1, (int) ($item->quantity ?? 1));
                    $lineTotal = round($unitPrice * $qty, 2);
                    $type = $this->classifyInvoiceBundleLineType($categoryName, $productTitle);

                    $lines[] = [
                        'type' => $type,
                        'description' => $productTitle !== '' ? $productTitle : 'Component',
                        'quantity' => $qty,
                        'catalog_total' => $lineTotal,
                    ];
                }
            } catch (\Exception $e) {
                Log::warning('Error processing bundle items: ' . $e->getMessage());
            }

            $lineCount = count($lines);

            if ($lineCount > 0 && $totalPrice > 0) {
                $catalogSum = round(array_sum(array_column($lines, 'catalog_total')), 2);

                if ($catalogSum > 0) {
                    foreach ($lines as $i => $ln) {
                        $lines[$i]['scaled_price'] = round($totalPrice * ($ln['catalog_total'] / $catalogSum), 2);
                    }
                } else {
                    $each = round($totalPrice / $lineCount, 2);
                    foreach ($lines as $i => $ln) {
                        $lines[$i]['scaled_price'] = $each;
                    }
                    $sumLines = round($each * $lineCount, 2);
                    $driftEq = round($totalPrice - $sumLines, 2);
                    if (abs($driftEq) >= 0.01) {
                        $lines[$lineCount - 1]['scaled_price'] = round($lines[$lineCount - 1]['scaled_price'] + $driftEq, 2);
                    }
                }

                $scaledSum = round(array_sum(array_column($lines, 'scaled_price')), 2);
                $drift = round($totalPrice - $scaledSum, 2);
                if (abs($drift) >= 0.01 && $lineCount > 0) {
                    $lines[0]['scaled_price'] = round($lines[0]['scaled_price'] + $drift, 2);
                }

                $bucketInv = 0.0;
                $bucketPan = 0.0;
                $bucketBat = 0.0;
                $qInv = $qPan = $qBat = 0;
                $dInv = $dPan = $dBat = '';

                foreach ($lines as $ln) {
                    $p = (float) $ln['scaled_price'];
                    $q = (int) $ln['quantity'];
                    $d = (string) $ln['description'];
                    switch ($ln['type']) {
                        case 'inverter':
                            $bucketInv += $p;
                            $qInv += $q;
                            $dInv = $dInv !== '' ? $dInv : $d;
                            break;
                        case 'panels':
                            $bucketPan += $p;
                            $qPan += $q;
                            $dPan = $dPan !== '' ? $dPan : $d;
                            break;
                        case 'batteries':
                            $bucketBat += $p;
                            $qBat += $q;
                            $dBat = $dBat !== '' ? $dBat : $d;
                            break;
                    }
                }

                $breakdown['solar_inverter'] = [
                    'quantity' => $bucketInv > 0 ? max(1, $qInv) : 0,
                    'price' => round($bucketInv, 2),
                    'description' => $bucketInv > 0 ? ($dInv !== '' ? $dInv : 'Solar Inverter') : '',
                ];
                $breakdown['solar_panels'] = [
                    'quantity' => $bucketPan > 0 ? max(1, $qPan) : 0,
                    'price' => round($bucketPan, 2),
                    'description' => $bucketPan > 0 ? ($dPan !== '' ? $dPan : 'Solar Panels') : '',
                ];
                $breakdown['batteries'] = [
                    'quantity' => $bucketBat > 0 ? max(1, $qBat) : 0,
                    'price' => round($bucketBat, 2),
                    'description' => $bucketBat > 0 ? ($dBat !== '' ? $dBat : 'Batteries') : '',
                ];

                if ($bundleLineItemsOut !== null) {
                    foreach ($lines as $ln) {
                        $bundleLineItemsOut[] = [
                            'type' => $ln['type'],
                            'description' => $ln['description'],
                            'quantity' => $ln['quantity'],
                            'price' => round((float) $ln['scaled_price'], 2),
                        ];
                    }
                    $hasBuiltLineItems = true;
                }
            } else {
                $orderListLines = $this->buildBundleOrderListLineItems($bundle, null, $checkoutFlow);
                if (count($orderListLines) > 0) {
                    if ($bundleLineItemsOut !== null) {
                        foreach ($orderListLines as $row) {
                            $bundleLineItemsOut[] = [
                                'type' => 'other',
                                'description' => (string) ($row['description'] ?? 'Item'),
                                'quantity' => (int) ($row['quantity'] ?? 1),
                                'price' => round((float) ($row['total_cost'] ?? 0), 2),
                            ];
                        }
                    }
                } else {
                    $bundleTitle = trim((string) ($bundle->title ?? $bundle->name ?? ''));
                    $breakdown['solar_inverter'] = [
                        'quantity' => 1,
                        'price' => round($totalPrice, 2),
                        'description' => $bundleTitle !== '' ? $bundleTitle : 'Bundle',
                    ];
                }
            }
        } elseif ($product) {
            try {
                $category = $product->category;
                $categoryName = $category ? strtolower((string) ($category->title ?? '')) : '';
                $productTitle = (string) ($product->title ?? '');
                $lineType = $this->classifyInvoiceBundleLineType($categoryName, $productTitle);

                if ($lineType === 'inverter') {
                    $breakdown['solar_inverter'] = [
                        'quantity' => 1,
                        'price' => round($totalPrice, 2),
                        'description' => $product->title ?? 'Solar Inverter',
                    ];
                } elseif ($lineType === 'panels') {
                    $breakdown['solar_panels'] = [
                        'quantity' => 1,
                        'price' => round($totalPrice, 2),
                        'description' => $product->title ?? 'Solar Panels',
                    ];
                } elseif ($lineType === 'batteries') {
                    $breakdown['batteries'] = [
                        'quantity' => 1,
                        'price' => round($totalPrice, 2),
                        'description' => $product->title ?? 'Batteries',
                    ];
                } else {
                    $breakdown['solar_inverter'] = ['quantity' => 1, 'price' => round($totalPrice * 0.40, 2), 'description' => 'Solar Inverter'];
                    $breakdown['solar_panels'] = ['quantity' => 1, 'price' => round($totalPrice * 0.35, 2), 'description' => 'Solar Panels'];
                    $breakdown['batteries'] = ['quantity' => 1, 'price' => round($totalPrice * 0.25, 2), 'description' => 'Batteries'];
                }

                if ($bundleLineItemsOut !== null && $totalPrice > 0) {
                    $bundleLineItemsOut[] = [
                        'type' => $lineType,
                        'description' => $productTitle !== '' ? $productTitle : 'Product',
                        'quantity' => 1,
                        'price' => round($totalPrice, 2),
                    ];
                    $hasBuiltLineItems = true;
                }
            } catch (\Exception $e) {
                Log::warning('Error processing product: ' . $e->getMessage());
            }
        }

        // Legacy estimate only when we did not build real bundle lines and buckets are empty
        if (
            ! $hasBuiltLineItems
            && (float) $breakdown['solar_inverter']['price'] == 0
            && (float) $breakdown['solar_panels']['price'] == 0
            && (float) $breakdown['batteries']['price'] == 0
            && $totalPrice > 0
            && ! $bundle
            && ! $product
        ) {
            $breakdown['solar_inverter'] = ['quantity' => 1, 'price' => round($totalPrice * 0.40, 2), 'description' => 'Solar Inverter'];
            $breakdown['solar_panels'] = ['quantity' => 1, 'price' => round($totalPrice * 0.35, 2), 'description' => 'Solar Panels'];
            $breakdown['batteries'] = ['quantity' => 1, 'price' => round($totalPrice * 0.25, 2), 'description' => 'Batteries'];
        }

        return $breakdown;
    }

    /**
     * @param  array{solar_inverter: array, solar_panels: array, batteries: array}  $breakdown
     * @return array<int, array{product_id: null, description: string, quantity: int, unit: string, rate: float, total_cost: float}>
     */
    private function productLineItemsFromBreakdown(array $breakdown): array
    {
        $rows = [];
        $buckets = [
            'solar_inverter' => 'Solar Inverter',
            'batteries' => 'Battery',
            'solar_panels' => 'Solar Panels',
        ];

        foreach ($buckets as $key => $fallback) {
            $bucket = $breakdown[$key] ?? null;
            $price = (float) ($bucket['price'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            $qty = max(1, (int) ($bucket['quantity'] ?? 1));
            $rows[] = [
                'product_id' => null,
                'description' => (string) (($bucket['description'] ?? '') !== '' ? $bucket['description'] : $fallback),
                'quantity' => $qty,
                'unit' => 'Nos',
                'rate' => round($price / $qty, 2),
                'total_cost' => round($price, 2),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, string>
     */
    private function resolveProductFeeCategoryKeys(array $productIds, ?string $fallbackCategory = null): array
    {
        $keys = [];

        foreach ($productIds as $productId) {
            $product = Product::with('category')->find($productId);
            $inferred = CheckoutSetting::inferProductFeeCategory($product);
            if ($inferred) {
                $keys[] = $inferred;
            }
        }

        $keys = array_values(array_unique($keys));
        if ($keys === [] && $fallbackCategory) {
            $fallback = trim((string) $fallbackCategory);
            if ($fallback !== '') {
                $keys = [$fallback];
            }
        }

        return $keys;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array{product: Product, quantity: int, unit_price: float, line_total: float}>|null
     */
    private function resolveMultiProductCheckoutLines(array $productIds): ?array
    {
        $lines = [];

        foreach ($productIds as $productId) {
            $product = Product::find($productId);
            if (! $product) {
                return null;
            }

            $quantity = 1;
            $unitPrice = $this->resolveCatalogUnitPrice($product);
            $lineTotal = round($unitPrice * $quantity, 2);

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<int, array{product: Product, quantity: int, unit_price: float, line_total: float}>  $multiProductLines
     * @param  array<int, array{type: string, description: string, quantity: int, price: float}>|null  $bundleLineItemsOut
     */
    private function calculateMultiProductBreakdown(array $multiProductLines, float $chargedTotal, ?array &$bundleLineItemsOut = null): array
    {
        if ($bundleLineItemsOut !== null) {
            $bundleLineItemsOut = [];
        }

        $breakdown = [
            'solar_inverter' => ['quantity' => 0, 'price' => 0, 'description' => ''],
            'solar_panels' => ['quantity' => 0, 'price' => 0, 'description' => ''],
            'batteries' => ['quantity' => 0, 'price' => 0, 'description' => ''],
        ];

        $catalogTotal = round(array_sum(array_column($multiProductLines, 'line_total')), 2);
        $chargedTotal = (float) $chargedTotal;

        foreach ($multiProductLines as $line) {
            $product = $line['product'];
            $catalogLine = (float) $line['line_total'];
            $scaledPrice = $catalogTotal > 0
                ? round($chargedTotal * ($catalogLine / $catalogTotal), 2)
                : round($chargedTotal / max(1, count($multiProductLines)), 2);

            $singleBundleItems = null;
            $singleBreakdown = $this->calculateProductBreakdown($product, null, $scaledPrice, $singleBundleItems);

            foreach (['solar_inverter', 'solar_panels', 'batteries'] as $bucket) {
                if ((float) ($singleBreakdown[$bucket]['price'] ?? 0) <= 0) {
                    continue;
                }

                $breakdown[$bucket]['quantity'] += (int) ($singleBreakdown[$bucket]['quantity'] ?? 0);
                $breakdown[$bucket]['price'] = round((float) $breakdown[$bucket]['price'] + (float) $singleBreakdown[$bucket]['price'], 2);
                if ($breakdown[$bucket]['description'] === '' && ! empty($singleBreakdown[$bucket]['description'])) {
                    $breakdown[$bucket]['description'] = (string) $singleBreakdown[$bucket]['description'];
                }
            }

            if ($bundleLineItemsOut !== null) {
                $bundleLineItemsOut[] = [
                    'type' => 'product',
                    'description' => (string) ($product->title ?? 'Product'),
                    'quantity' => (int) $line['quantity'],
                    'price' => $scaledPrice,
                ];
            }
        }

        return $breakdown;
    }

    /**
     * GET /api/orders/{id}/summary
     * Get order summary with item details, appliances, backup time
     */
    public function getOrderSummary($id)
    {
        try {
            $isAdmin = $this->isAuthenticatedAdmin();

            // Build query - admins can view any order, users can only view their own
            $query = Order::with([
                'product.category',
                'bundle.bundleItems.product.category',
                'user',
                'deliveryAddress',
                'items.itemable',
                'loanApplication',
                'auditRequest',
            ])
                ->where('id', $id);

            if (!$isAdmin) {
                $query->where('user_id', Auth::id());
            }

            $order = $query->first();

            if (!$order) {
                Log::warning('Order Summary - Order not found', [
                    'order_id' => $id,
                    'user_id' => Auth::id(),
                    'is_admin' => $isAdmin
                ]);
                return ResponseHelper::error('Order not found', 404);
            }

            [$resolvedProduct, $resolvedBundle] = $this->resolveOrderBundleAndProductForInvoice($order);

            $items = [];
            $appliances = null;
            $backupTime = null;

            $order->loadMissing(['items.itemable']);
            $installerChoice = $this->resolveOrderInstallerChoice($order);
            $persistedItems = $this->buildOrderSummaryItemsFromOrderItems(
                $order->items,
                $this->resolveBundleCheckoutFlow($order),
                $installerChoice
            );
            $persistedItems = $this->filterBundleOrderListLinesForOrder($persistedItems, $order, $installerChoice);

            try {
                if (count($persistedItems) > 0) {
                    $items = $persistedItems;
                    foreach ($order->items as $orderItem) {
                        if ($orderItem->itemable instanceof Bundles) {
                            $bundle = $orderItem->itemable;
                            if (isset($bundle->total_output) && $bundle->total_output) {
                                $backupTime = $this->calculateBackupTime($bundle->total_output, $bundle->total_load ?? 1000);
                            }
                            break;
                        }
                    }
                } elseif ($resolvedBundle) {
                    $bundle = $resolvedBundle;
                    $bundle->loadMissing(['bundleItems.product.category', 'customServices', 'bundleMaterials.material']);
                    $items = $this->buildOrderSummaryItemsFromBundleOrderList(
                        $bundle,
                        $installerChoice,
                        $this->resolveBundleCheckoutFlow($order)
                    );
                    $items = $this->filterBundleOrderListLinesForOrder($items, $order, $installerChoice);

                    if (count($items) === 0) {
                        $bundleItems = $bundle->bundleItems()->with('product.category')->get();

                        foreach ($bundleItems as $item) {
                            if ($item->product) {
                                $productDetails = [];
                                try {
                                    if (method_exists($item->product, 'details') && $item->product->details) {
                                        $productDetails = $item->product->details->pluck('detail')->toArray();
                                    }
                                } catch (\Exception $e) {
                                    Log::warning('Error getting product details: '.$e->getMessage());
                                }

                                $items[] = [
                                    'name' => $item->product->title ?? 'Unknown Product',
                                    'description' => ! empty($productDetails) ? implode(', ', $productDetails) : ($item->product->title ?? 'No description'),
                                    'quantity' => $item->quantity ?? 1,
                                    'price' => $this->resolveCatalogUnitPrice($item->product),
                                    'type' => 'product',
                                ];
                            }
                        }
                    }

                    if (isset($bundle->total_output) && $bundle->total_output) {
                        $backupTime = $this->calculateBackupTime($bundle->total_output, $bundle->total_load ?? 1000);
                    }
                } elseif ($resolvedProduct) {
                    $product = $resolvedProduct;
                    $productDetails = [];
                    try {
                        if (method_exists($product, 'details') && $product->details) {
                            $productDetails = $product->details->pluck('detail')->toArray();
                        }
                    } catch (\Exception $e) {
                        Log::warning('Error getting product details: '.$e->getMessage());
                    }

                    $items[] = [
                        'name' => $product->title ?? 'Unknown Product',
                        'description' => ! empty($productDetails) ? implode(', ', $productDetails) : ($product->title ?? 'No description'),
                        'quantity' => 1,
                        'price' => $this->resolveCatalogUnitPrice($product),
                        'type' => 'product',
                    ];
                } else {
                    $items = $this->buildOrderSummaryItemsFromLoanSnapshot($order);
                }
            } catch (\Exception $e) {
                Log::error('Error processing order items: '.$e->getMessage(), [
                    'order_id' => $order->id,
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            if (count($items) === 0) {
                $appliances = 'Standard household appliances';
                $backupTime = '8-12 hours (depending on usage)';
            }

            // Bundle linked but no component rows — still show the selected bundle as one line
            if ($resolvedBundle && count($items) === 0) {
                $bundle = $resolvedBundle;
                $pp = Schema::hasColumn('orders', 'product_price') ? (float) ($order->product_price ?? 0) : 0;
                if ($pp <= 0) {
                    $bundleDiscount = (float) ($bundle->discount_price ?? 0);
                    $basePrice = (float) ($bundle->total_price ?? 0);
                    $pp = $bundleDiscount > 0 ? $bundleDiscount : $basePrice;
                }
                if ($pp <= 0) {
                    $pp = (float) ($order->total_price ?? 0);
                }
                $desc = trim((string) ($bundle->product_model ?? ''));
                if ($desc === '') {
                    $desc = trim((string) ($bundle->what_is_inside_bundle_text ?? $bundle->detailed_description ?? ''));
                }
                $items[] = [
                    'name' => $bundle->title ?? 'Bundle',
                    'description' => $desc !== '' ? $desc : ($bundle->title ?? 'Bundle'),
                    'quantity' => 1,
                    'price' => round($pp, 2),
                ];
            }

            $installationRequested = null;
            if (Schema::hasColumn('orders', 'installation_requested_date') && $order->installation_requested_date) {
                $installationRequested = $order->installation_requested_date instanceof \Carbon\CarbonInterface
                    ? $order->installation_requested_date->format('Y-m-d')
                    : (string) $order->installation_requested_date;
            }

            $auditContext = null;
            if (!empty($order->audit_request_id)) {
                if ($order->relationLoaded('auditRequest') && $order->auditRequest) {
                    $auditContext = $order->auditRequest;
                } else {
                    $auditContext = AuditRequest::query()->find($order->audit_request_id);
                }
            }
            $customerType = Schema::hasColumn('orders', 'customer_type') ? ($order->customer_type ?? null) : null;
            if ($auditContext && $auditContext->resolvedCustomerType()) {
                $customerType = $auditContext->resolvedCustomerType();
            }

            return ResponseHelper::success([
                'order_id' => $order->id,
                'order_number' => $order->order_number ?? null,
                'order_type' => $order->order_type ?? null,
                'items' => $items,
                'appliances' => $appliances,
                'backup_time' => $backupTime,
                'total_price' => $order->total_price ?? 0,
                'product_price' => Schema::hasColumn('orders', 'product_price') ? (float) ($order->product_price ?? 0) : null,
                'installation_fee' => Schema::hasColumn('orders', 'installation_price') ? (float) ($order->installation_price ?? 0) : null,
                'material_cost' => Schema::hasColumn('orders', 'material_cost') ? (float) ($order->material_cost ?? 0) : null,
                'delivery_fee' => Schema::hasColumn('orders', 'delivery_fee') ? (float) ($order->delivery_fee ?? 0) : null,
                'inspection_fee' => Schema::hasColumn('orders', 'inspection_fee') ? (float) ($order->inspection_fee ?? 0) : null,
                'insurance_fee' => Schema::hasColumn('orders', 'insurance_fee') ? (float) ($order->insurance_fee ?? 0) : null,
                'vat_amount' => Schema::hasColumn('orders', 'vat_amount') ? (float) ($order->vat_amount ?? 0) : null,
                'bundle_title' => $resolvedBundle?->title ?? $order->bundle?->title,
                'product_title' => $resolvedProduct?->title ?? $order->product?->title,
                'product_category' => $order->loanApplication?->product_category
                    ?? ($auditContext?->product_category),
                'customer_type' => $customerType,
                'audit_request' => $auditContext?->toBuyNowContext(),
                'installer_choice' => Schema::hasColumn('orders', 'installer_choice') ? ($order->installer_choice ?? null) : null,
                'property_floors' => Schema::hasColumn('orders', 'property_floors') ? $order->property_floors : null,
                'property_rooms' => Schema::hasColumn('orders', 'property_rooms') ? $order->property_rooms : null,
                'is_gated_estate' => Schema::hasColumn('orders', 'is_gated_estate') ? $order->is_gated_estate : null,
                'estate_name' => Schema::hasColumn('orders', 'estate_name') ? ($order->estate_name ?? null) : null,
                'estate_address' => Schema::hasColumn('orders', 'estate_address') ? ($order->estate_address ?? null) : null,
                'delivery_address' => $this->formatDeliveryAddressForApi($order->deliveryAddress, $order->user),
                'installation_requested_date' => $installationRequested,
            ], 'Order summary retrieved successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Order Summary - Model not found: ' . $e->getMessage());
            return ResponseHelper::error('Order not found', 404);
        } catch (\Exception $e) {
            Log::error('Order Summary Error: ' . $e->getMessage(), [
                'order_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return ResponseHelper::error('Failed to retrieve order summary: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Calculate audit fee based on property details
     */
    private function calculateAuditFee($auditRequest, $data)
    {
        // Base audit fee
        $baseFee = 50000; // ₦50,000 base fee
        
        // If amount is explicitly provided, use it
        if (isset($data['amount']) && $data['amount'] > 0) {
            return (float) $data['amount'];
        }
        
        // Get property details from audit request or data
        $floors = $auditRequest ? $auditRequest->property_floors : ($data['property_floors'] ?? 1);
        $rooms = $auditRequest ? $auditRequest->property_rooms : ($data['property_rooms'] ?? 1);
        $auditType = $auditRequest ? $auditRequest->audit_type : ($data['audit_type'] ?? 'home-office');
        
        // Calculate fee based on property size
        // Base fee + (floors * 5000) + (rooms * 2000)
        $sizeFee = ($floors * 5000) + ($rooms * 2000);
        
        // Commercial audits cost more
        if ($auditType === 'commercial') {
            $baseFee = 100000; // ₦100,000 base for commercial
            $sizeFee = ($floors * 10000) + ($rooms * 5000); // Higher multiplier for commercial
        }
        
        $totalFee = $baseFee + $sizeFee;
        
        // Cap at reasonable maximum
        $maxFee = $auditType === 'commercial' ? 500000 : 200000;
        
        return min($totalFee, $maxFee);
    }

    /**
     * Calculate backup time based on system output and load
     */
    private function calculateBackupTime($output, $load)
    {
        if ($load <= 0) $load = 1000; // Default load
        
        // Simple calculation: hours = (battery_capacity * efficiency) / load
        // Assuming battery capacity is roughly 70% of output
        $batteryCapacity = $output * 0.7;
        $efficiency = 0.85; // 85% efficiency
        $hours = ($batteryCapacity * $efficiency) / $load;
        
        if ($hours >= 12) {
            return '12+ hours';
        } elseif ($hours >= 8) {
            return '8-12 hours';
        } elseif ($hours >= 6) {
            return '6-8 hours';
        } else {
            return '4-6 hours';
        }
    }

    /**
     * GET /api/orders/{id}/invoice-details
     * Get detailed invoice breakdown
     */
    public function getInvoiceDetails($id)
    {
        try {
            $query = Order::with([
                'product.category',
                'bundle.bundleItems.product.category',
                'bundle.customServices',
                'bundle.bundleMaterials.material',
                'deliveryAddress',
                'user',
                'items.itemable',
                'loanApplication',
            ])
                ->where('id', $id);

            if (! $this->isAuthenticatedAdmin()) {
                $query->where('user_id', Auth::id());
            }

            $order = $query->first();

            if (!$order) {
                return ResponseHelper::error('Order not found', 404);
            }

            [$product, $bundle] = $this->resolveOrderBundleAndProductForInvoice($order);
            $invoiceBundle = $bundle;

            $order->loadMissing(['items.itemable']);
            $productOrderItems = $order->items->filter(fn ($row) => $row->itemable instanceof Product)->values();

            $productLinesCatalogSum = 0.0;
            $productLineItems = [];
            if ($productOrderItems->count() > 0) {
                foreach ($productOrderItems as $orderItem) {
                    $qty = max(1, (int) ($orderItem->quantity ?? 1));
                    $catalogUnit = $orderItem->itemable
                        ? $this->resolveCatalogUnitPrice($orderItem->itemable)
                        : (float) ($orderItem->unit_price ?? 0);
                    $lineTotal = round($catalogUnit * $qty, 2);
                    $productLinesCatalogSum += $lineTotal;
                    $productLineItems[] = [
                        'product_id' => $orderItem->itemable_id,
                        'description' => (string) ($orderItem->itemable->title ?? 'Product'),
                        'quantity' => $qty,
                        'unit' => 'Nos',
                        'rate' => round($catalogUnit, 2),
                        'total_cost' => $lineTotal,
                    ];
                }
                $productLinesCatalogSum = round($productLinesCatalogSum, 2);
            }

            // Bundles: catalog = bundle selling price (not material line sum, not grand total).
            // Products: sum of catalog product lines. Never use order.total_price as subtotal.
            $catalogItemsSubtotal = $this->resolveBuyNowCatalogItemsSubtotal(
                $order,
                $bundle,
                $product,
                // Only use product-line sum when this is not a bundle order.
                $bundle ? 0.0 : $productLinesCatalogSum
            );

            $paymentBreakdown = $this->resolveOrderPaymentBreakdown($order, $catalogItemsSubtotal);
            $itemsSubtotalAfterDiscount = (float) $paymentBreakdown['items_subtotal_after_discount'];
            $outrightDiscountAmount = (float) $paymentBreakdown['outright_discount_amount'];

            if ($invoiceBundle) {
                $invoiceBundle->loadMissing(['bundleItems.product', 'customServices', 'bundleMaterials.material']);
            }

            $bundleOrderLines = $this->buildInvoiceProductLineItemsFromOrder($order, $invoiceBundle);
            if (count($bundleOrderLines) > 0) {
                $productLineItems = $bundleOrderLines;
            } elseif ($this->isGenericProductBreakdownLineItems($productLineItems)) {
                $productLineItems = [];
            }

            $bundleLineItems = [];
            $invoiceCheckoutFlow = $this->resolveBundleCheckoutFlow($order);
            $breakdownBase = $catalogItemsSubtotal > 0.005
                ? $catalogItemsSubtotal
                : (float) ($order->product_price ?? 0);
            if ($productOrderItems->count() > 1 && ! $invoiceBundle) {
                $multiLines = [];
                foreach ($productOrderItems as $orderItem) {
                    $qty = max(1, (int) ($orderItem->quantity ?? 1));
                    $catalogUnit = $orderItem->itemable
                        ? $this->resolveCatalogUnitPrice($orderItem->itemable)
                        : (float) ($orderItem->unit_price ?? 0);
                    $multiLines[] = [
                        'product' => $orderItem->itemable,
                        'quantity' => $qty,
                        'unit_price' => $catalogUnit,
                        'line_total' => round($catalogUnit * $qty, 2),
                    ];
                }
                $productBreakdown = $this->calculateMultiProductBreakdown($multiLines, $breakdownBase, $bundleLineItems);
            } elseif ($productOrderItems->count() === 1 && ! $invoiceBundle) {
                $only = $productOrderItems->first();
                $product = $only->itemable;
                $productBreakdown = $this->calculateProductBreakdown(
                    $product,
                    null,
                    $breakdownBase,
                    $bundleLineItems,
                    $invoiceCheckoutFlow
                );
            } else {
                $productBreakdown = $this->calculateProductBreakdown(
                    $product,
                    $invoiceBundle,
                    $breakdownBase,
                    $bundleLineItems,
                    $invoiceCheckoutFlow
                );
            }

            $hasRealProductLines = count($productLineItems) > 0
                && ! $this->isGenericProductBreakdownLineItems($productLineItems);
            if ($hasRealProductLines) {
                $productBreakdown = [
                    'solar_inverter' => ['quantity' => 0, 'price' => 0, 'description' => ''],
                    'solar_panels' => ['quantity' => 0, 'price' => 0, 'description' => ''],
                    'batteries' => ['quantity' => 0, 'price' => 0, 'description' => ''],
                ];
            }

            $installationRequested = null;
            if (Schema::hasColumn('orders', 'installation_requested_date') && $order->installation_requested_date) {
                $installationRequested = $order->installation_requested_date instanceof \Carbon\CarbonInterface
                    ? $order->installation_requested_date->format('Y-m-d')
                    : (string) $order->installation_requested_date;
            }

            return ResponseHelper::success([
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'bundle_title' => $invoiceBundle?->title ?? $order->bundle?->title,
                'product_title' => $product?->title ?? $order->product?->title,
                'delivery_address' => $this->formatDeliveryAddressForApi($order->deliveryAddress, $order->user),
                'installation_requested_date' => $installationRequested,
                'customer_type' => Schema::hasColumn('orders', 'customer_type') ? ($order->customer_type ?? null) : null,
                'installer_choice' => $this->resolveOrderInstallerChoice($order),
                'invoice' => [
                    'solar_inverter' => $productBreakdown['solar_inverter'],
                    'solar_panels' => $productBreakdown['solar_panels'],
                    'batteries' => $productBreakdown['batteries'],
                    'bundle_line_items' => $bundleLineItems,
                    'product_line_items' => $productLineItems,
                    'material_cost' => $paymentBreakdown['material_cost'],
                    'installation_fee' => $paymentBreakdown['installation_fee'],
                    'delivery_fee' => $paymentBreakdown['delivery_fee'],
                    'inspection_fee' => $paymentBreakdown['inspection_fee'],
                    'insurance_fee' => $paymentBreakdown['insurance_fee'],
                    'insurance_fee_percentage' => $paymentBreakdown['insurance_fee_percentage'] ?? null,
                    'items_subtotal_before_discount' => $paymentBreakdown['catalog_items_subtotal'],
                    'outright_discount_amount' => $outrightDiscountAmount > 0.005 ? $outrightDiscountAmount : null,
                    'outright_discount_percentage' => $paymentBreakdown['outright_discount_percentage'],
                    'subtotal' => $itemsSubtotalAfterDiscount,
                    'sum_before_vat' => $paymentBreakdown['sum_before_vat'],
                    'vat_amount' => (float) $paymentBreakdown['vat_amount'] > 0.005 ? $paymentBreakdown['vat_amount'] : null,
                    'vat_percentage' => $paymentBreakdown['vat_percentage'],
                    'grand_total' => $paymentBreakdown['grand_total'],
                    'total' => $paymentBreakdown['grand_total'],
                    'installer_choice' => $this->resolveOrderInstallerChoice($order),
                ],
            ], 'Invoice details retrieved successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Invoice Details Error - Order not found: ' . $e->getMessage());
            return ResponseHelper::error('Order not found', 404);
        } catch (\Exception $e) {
            Log::error('Invoice Details Error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            return ResponseHelper::error('Failed to retrieve invoice details: ' . $e->getMessage(), 500);
        }
    }
}