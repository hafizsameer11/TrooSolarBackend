<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Mail\CartLinkEmail;
use App\Models\AuditRequest;
use App\Models\CartItem;
use App\Models\CustomOrderLink;
use App\Support\FrontendUrl;
use App\Models\Product;
use App\Models\Bundles;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminCartController extends Controller
{
    /**
     * Customer dashboard URL for admin-pushed cart / custom order.
     * Universal entry: /cart?token=…&type=buy_now|bnpl (Cart.jsx routes BNPL → /bnpl, Buy Now → checkout).
     */
    private function buildDashboardCustomOrderUrl(string $accessToken, string $orderType): string
    {
        return FrontendUrl::cartAccess($accessToken, $orderType);
    }

    /**
     * Create custom order for user
     * POST /api/admin/cart/create-custom-order
     */
    public function createCustomOrder(Request $request)
    {
        try {
            $data = $request->validate([
                'user_id' => 'required|exists:users,id',
                'audit_request_id' => 'required|exists:audit_requests,id',
                'order_type' => 'required|in:buy_now,bnpl',
                'items' => 'nullable|array',
                'items.*.type' => 'required_with:items|in:product,bundle',
                'items.*.id' => 'required_with:items|integer',
                'items.*.quantity' => 'nullable|integer|min:1',
                'custom_items' => 'nullable|array',
                'custom_items.*.name' => 'required_with:custom_items|string|max:255',
                'custom_items.*.description' => 'nullable|string|max:2000',
                'custom_items.*.price' => 'required_with:custom_items|numeric|min:0',
                'custom_items.*.quantity' => 'nullable|integer|min:1',
                'send_email' => 'nullable|boolean',
                'email_message' => 'nullable|string|max:10000',
            ]);

            $items = $data['items'] ?? [];
            $customItems = $data['custom_items'] ?? [];
            if (count($items) === 0 && count($customItems) === 0) {
                throw ValidationException::withMessages([
                    'items' => ['Add at least one product/bundle or one custom line item.'],
                ]);
            }

            $userId = $data['user_id'];
            $orderType = $data['order_type'];
            $user = User::findOrFail($userId);

            $auditRequest = AuditRequest::where('id', (int) $data['audit_request_id'])
                ->where('user_id', $userId)
                ->first();
            if (!$auditRequest) {
                throw ValidationException::withMessages([
                    'audit_request_id' => ['Selected audit request does not belong to this customer.'],
                ]);
            }

            // Each custom order gets its own token + item snapshot. Do not touch the
            // user's live shop cart — successive emails must not accumulate items.
            $snapshotItems = [];
            $errors = [];

            foreach ($items as $item) {
                $type = $item['type'];
                $itemId = (int) $item['id'];
                $quantity = max(1, (int) ($item['quantity'] ?? 1));

                if ($type === 'product') {
                    $product = Product::find($itemId);
                    if (!$product) {
                        $errors[] = "Product ID {$itemId} not found";
                        continue;
                    }
                    $price = (float) ($product->discount_price ?? $product->price ?? 0);
                    $snapshotItems[] = [
                        'type' => 'product',
                        'id' => $itemId,
                        'quantity' => $quantity,
                        'unit_price' => $price,
                        'subtotal' => round($price * $quantity, 2),
                    ];
                } elseif ($type === 'bundle') {
                    $bundle = Bundles::find($itemId);
                    if (!$bundle) {
                        $errors[] = "Bundle ID {$itemId} not found";
                        continue;
                    }
                    $price = (float) ($bundle->discount_price ?? $bundle->total_price ?? 0);
                    $snapshotItems[] = [
                        'type' => 'bundle',
                        'id' => $itemId,
                        'quantity' => $quantity,
                        'unit_price' => $price,
                        'subtotal' => round($price * $quantity, 2),
                    ];
                }
            }

            if (!empty($errors)) {
                throw ValidationException::withMessages([
                    'items' => ['Some items could not be added: ' . implode(', ', $errors)],
                ]);
            }

            $normalizedCustomItems = collect($customItems)->map(function ($row) {
                return [
                    'name' => trim((string) ($row['name'] ?? '')),
                    'description' => trim((string) ($row['description'] ?? '')),
                    'price' => (float) ($row['price'] ?? 0),
                    'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                ];
            })->values()->all();

            $token = Str::random(64);
            $link = CustomOrderLink::create([
                'user_id' => $userId,
                'audit_request_id' => $auditRequest->id,
                'token' => $token,
                'order_type' => $orderType,
                'items' => $snapshotItems,
                'custom_items' => $normalizedCustomItems ?: null,
                'created_by' => Auth::id(),
            ]);

            $cartItems = $link->resolveCartItems();

            $emailMessage = $data['email_message'] ?? null;
            if (count($normalizedCustomItems) > 0) {
                $customBlock = collect($normalizedCustomItems)->map(function ($row) {
                    $name = $row['name'];
                    $desc = $row['description'];
                    $price = $row['price'];
                    $qty = $row['quantity'];
                    $line = "\n\n--- Custom Product/Service ---\n{$name}";
                    if ($desc !== '') {
                        $line .= "\nDescription: {$desc}";
                    }
                    $lineTotal = round($price * $qty, 2);
                    $line .= "\nPrice: ₦".number_format($price, 2)." x {$qty} = ₦".number_format($lineTotal, 2);

                    return $line;
                })->implode('');
                $emailMessage = trim((string) $emailMessage).$customBlock;
            }

            $cartSubtotal = $cartItems->sum(fn ($row) => (float) ($row->subtotal ?? 0));
            $customSubtotal = collect($normalizedCustomItems)->reduce(function ($carry, $row) {
                return $carry + ((float) $row['price'] * (int) $row['quantity']);
            }, 0.0);
            $summaryTotal = round($cartSubtotal + $customSubtotal, 2);
            $cartLink = $this->buildDashboardCustomOrderUrl($token, $orderType);

            $sendEmail = (bool) ($data['send_email'] ?? true);
            $emailSent = false;
            $emailError = null;
            if ($sendEmail) {
                $mailResult = $this->sendCartLinkEmailToUser(
                    $user,
                    $cartItems,
                    $cartLink,
                    $orderType,
                    $emailMessage,
                    $summaryTotal,
                    $normalizedCustomItems
                );
                $emailSent = $mailResult['sent'];
                $emailError = $mailResult['error'];
            }

            return ResponseHelper::success([
                'user_id' => $userId,
                'user_name' => $user->first_name . ' ' . $user->sur_name,
                'user_email' => $user->email,
                'order_type' => $orderType,
                'custom_order_link_id' => $link->id,
                'items_added' => $cartItems->count(),
                'cart_items' => $cartItems->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'type' => $item->type,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'subtotal' => $item->subtotal,
                    ];
                })->values(),
                'cart_link' => $cartLink,
                'email_sent' => $emailSent,
                'email_error' => $emailError,
            ], $emailSent || !$sendEmail
                ? 'Custom order created successfully'
                : 'Custom order created but the notification email could not be sent');

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            Log::error('Error creating custom order: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            return ResponseHelper::error('Failed to create custom order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * List individual custom-order email links (one row per send).
     * GET /api/admin/cart/custom-orders
     */
    public function listCustomOrders(Request $request)
    {
        try {
            $perPage = max(1, min(100, (int) $request->get('per_page', 15)));
            $search = trim((string) $request->get('search', ''));
            $orderType = $request->get('order_type'); // buy_now|bnpl|null

            $query = CustomOrderLink::query()
                ->with([
                    'user:id,first_name,sur_name,email,phone',
                    'auditRequest',
                ])
                ->orderByDesc('id');

            if (in_array($orderType, ['buy_now', 'bnpl'], true)) {
                $query->where('order_type', $orderType);
            }

            if ($search !== '') {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('sur_name', 'like', "%{$search}%")
                        ->orWhereRaw("CONCAT(COALESCE(first_name,''),' ',COALESCE(sur_name,'')) like ?", ["%{$search}%"]);
                });
            }

            $paginator = $query->paginate($perPage);

            $formatted = $paginator->getCollection()->map(function (CustomOrderLink $link) {
                return $this->formatCustomOrderLinkForAdmin($link, $link->auditRequest);
            });

            return ResponseHelper::success([
                'data' => $formatted->values(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ], 'Custom orders retrieved successfully');
        } catch (Exception $e) {
            Log::error('Error listing custom orders: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve custom orders: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Show one custom-order link with items + linked audit for the order.
     * GET /api/admin/cart/custom-orders/{id}
     */
    public function showCustomOrder($id)
    {
        try {
            $link = CustomOrderLink::with([
                'user:id,first_name,sur_name,email,phone',
                'auditRequest',
            ])->findOrFail($id);

            return ResponseHelper::success(
                $this->formatCustomOrderLinkForAdmin($link, $link->auditRequest, true),
                'Custom order retrieved successfully'
            );
        } catch (Exception $e) {
            Log::error('Error showing custom order: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve custom order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Resend email for a specific custom-order link.
     * POST /api/admin/cart/custom-orders/{id}/resend
     */
    public function resendCustomOrderEmail(Request $request, $id)
    {
        try {
            $data = $request->validate([
                'order_type' => 'nullable|in:buy_now,bnpl',
                'email_message' => 'nullable|string|max:10000',
            ]);

            $link = CustomOrderLink::with('user')->findOrFail($id);
            $user = $link->user;
            if (!$user) {
                return ResponseHelper::error('User not found for this custom order', 404);
            }

            $orderType = $data['order_type'] ?? $link->order_type;
            $token = Str::random(64);
            $link->update([
                'token' => $token,
                'order_type' => $orderType,
            ]);

            $cartItems = $link->resolveCartItems();
            $customItems = is_array($link->custom_items) ? $link->custom_items : [];
            $cartSubtotal = $cartItems->sum(fn ($row) => (float) ($row->subtotal ?? 0));
            $customSubtotal = collect($customItems)->reduce(function ($carry, $row) {
                $price = (float) ($row['price'] ?? 0);
                $qty = max(1, (int) ($row['quantity'] ?? 1));

                return $carry + ($price * $qty);
            }, 0.0);
            $summaryTotal = round($cartSubtotal + $customSubtotal, 2);
            $cartLink = $this->buildDashboardCustomOrderUrl($token, $orderType);

            $mailResult = $this->sendCartLinkEmailToUser(
                $user,
                $cartItems,
                $cartLink,
                $orderType,
                $data['email_message'] ?? null,
                $summaryTotal,
                $customItems
            );

            if (!$mailResult['sent']) {
                return ResponseHelper::error(
                    'Failed to send email: ' . ($mailResult['error'] ?? 'Unknown mail error'),
                    500
                );
            }

            return ResponseHelper::success([
                'email' => $user->email,
                'cart_link' => $cartLink,
                'custom_order_link_id' => $link->id,
                'email_sent' => true,
            ], 'Custom order link email sent successfully');
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Error resending custom order email: ' . $e->getMessage());
            return ResponseHelper::error('Failed to send email: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCustomOrderLinkForAdmin(
        CustomOrderLink $link,
        ?AuditRequest $linkedAudit = null,
        bool $includeItems = false
    ): array {
        $user = $link->user;
        $items = $includeItems ? $link->resolveCartItems() : collect($link->items ?? []);
        $itemCount = $includeItems
            ? $items->count()
            : count($link->items ?? []);
        $catalogTotal = $includeItems
            ? (float) $items->sum(fn ($row) => (float) ($row->subtotal ?? 0))
            : (float) collect($link->items ?? [])->sum(fn ($row) => (float) ($row['subtotal'] ?? 0));
        $customItems = is_array($link->custom_items) ? $link->custom_items : [];
        $customTotal = (float) collect($customItems)->reduce(function ($carry, $row) {
            $price = (float) ($row['price'] ?? 0);
            $qty = max(1, (int) ($row['quantity'] ?? 1));

            return $carry + ($price * $qty);
        }, 0.0);
        $totalAmount = round($catalogTotal + $customTotal, 2);

        $payload = [
            'id' => $link->id,
            'order_type' => $link->order_type,
            'audit_request_id' => $link->audit_request_id,
            'created_at' => optional($link->created_at)?->format('Y-m-d H:i:s'),
            'item_count' => $itemCount + count($customItems),
            'catalog_item_count' => $itemCount,
            'custom_item_count' => count($customItems),
            'total_amount' => $totalAmount,
            'cart_link' => $this->buildDashboardCustomOrderUrl($link->token, $link->order_type),
            'user' => $user ? [
                'id' => $user->id,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->sur_name ?? '')),
                'email' => $user->email,
                'phone' => $user->phone,
            ] : null,
            // Linked audit for THIS custom order (not the user's latest)
            'audit_request' => $linkedAudit
                ? $this->formatLatestAuditForCustomOrder($linkedAudit)
                : null,
            'latest_audit_request' => $linkedAudit
                ? $this->formatLatestAuditForCustomOrder($linkedAudit)
                : null,
        ];

        if ($includeItems) {
            $payload['cart_items'] = $items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'itemable_id' => $item->itemable_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->subtotal,
                    'itemable' => $item->itemable,
                ];
            })->values();
            $payload['custom_items'] = $customItems;
            $payload['total_amount'] = $totalAmount;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLatestAuditForCustomOrder(AuditRequest $request): array
    {
        return array_merge($request->toBuyNowContext(), [
            'has_property_details' => !empty($request->property_address),
            'needs_admin_input' => $request->audit_type === 'commercial' && empty($request->property_address),
            'heading' => $this->auditRequestHeading($request),
        ]);
    }

    private function auditRequestHeading(AuditRequest $request): string
    {
        $type = $request->audit_type === 'commercial'
            ? 'Commercial / Industrial'
            : ($request->audit_subtype === 'office'
                ? 'Office'
                : ($request->audit_subtype === 'home' ? 'Home' : 'Home / Office'));
        $status = ucfirst((string) ($request->status ?? 'pending'));
        $date = optional($request->created_at)?->format('d/m/Y') ?: '—';
        $customerType = $request->resolvedCustomerType();
        $parts = ["#{$request->id}", $type, $status, $date];
        if ($customerType) {
            $parts[] = ucfirst($customerType);
        }
        if (!empty($request->property_state)) {
            $parts[] = $request->property_state;
        }

        return implode(' · ', $parts);
    }

    /**
     * Get products/bundles for selection
     * GET /api/admin/cart/products
     */
    public function getProducts(Request $request)
    {
        try {
            $categoryId = $request->get('category_id');
            $brandId = $request->get('brand_id');
            $type = $request->get('type', 'all'); // all, products, bundles

            $result = [];

            if ($type === 'all' || $type === 'products') {
                $productsQuery = Product::with(['category', 'brand']);

                if ($categoryId) {
                    $productsQuery->where('category_id', $categoryId);
                }
                if ($brandId) {
                    $productsQuery->where('brand_id', $brandId);
                }

                $products = $productsQuery->get()->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'type' => 'product',
                        'title' => $product->title,
                        'price' => $product->price,
                        'discount_price' => $product->discount_price,
                        'category' => $product->category->title ?? null,
                        'brand' => $product->brand->title ?? null,
                        'featured_image' => $product->featured_image_url ?? null,
                    ];
                });

                $result['products'] = $products;
            }

            if ($type === 'all' || $type === 'bundles') {
                $bundles = Bundles::all()->map(function ($bundle) {
                    return [
                        'id' => $bundle->id,
                        'type' => 'bundle',
                        'title' => $bundle->title,
                        'price' => $bundle->total_price,
                        'discount_price' => $bundle->discount_price,
                        'bundle_type' => $bundle->bundle_type,
                        'featured_image' => $bundle->featured_image_url ?? null,
                    ];
                });

                $result['bundles'] = $bundles;
            }

            return ResponseHelper::success($result, 'Products and bundles retrieved successfully');

        } catch (Exception $e) {
            Log::error('Error fetching products: ' . $e->getMessage());
            return ResponseHelper::error('Failed to fetch products', 500);
        }
    }

    /**
     * Get user's current cart
     * GET /api/admin/cart/user/{userId}
     */
    public function getUserCart($userId)
    {
        try {
            $user = User::findOrFail($userId);

            $cartItems = CartItem::with('itemable')
                ->where('user_id', $userId)
                ->get();

            return ResponseHelper::success([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->sur_name,
                    'email' => $user->email,
                ],
                'cart_items' => $cartItems,
                'total_items' => $cartItems->count(),
                'total_amount' => $cartItems->sum('subtotal'),
            ], 'User cart retrieved successfully');

        } catch (Exception $e) {
            Log::error('Error fetching user cart: ' . $e->getMessage());
            return ResponseHelper::error('Failed to fetch user cart', 500);
        }
    }

    /**
     * Remove item from user's cart (Admin)
     * DELETE /api/admin/cart/user/{userId}/item/{itemId}
     */
    public function removeCartItem($userId, $itemId)
    {
        try {
            $cartItem = CartItem::where('id', $itemId)
                ->where('user_id', $userId)
                ->firstOrFail();

            $cartItem->delete();

            return ResponseHelper::success(null, 'Item removed from cart successfully');

        } catch (Exception $e) {
            Log::error('Error removing cart item: ' . $e->getMessage());
            return ResponseHelper::error('Failed to remove cart item', 500);
        }
    }

    /**
     * Clear user's cart (Admin)
     * DELETE /api/admin/cart/user/{userId}/clear
     */
    public function clearUserCart($userId)
    {
        try {
            CartItem::where('user_id', $userId)->delete();

            return ResponseHelper::success(null, 'User cart cleared successfully');

        } catch (Exception $e) {
            Log::error('Error clearing user cart: ' . $e->getMessage());
            return ResponseHelper::error('Failed to clear user cart', 500);
        }
    }

    /**
     * Get all users with carts
     * GET /api/admin/cart/users-with-carts
     */
    public function getUsersWithCarts(Request $request)
    {
        try {
            // Get users who have cart items with aggregated cart data
            $query = User::select([
                'users.id',
                'users.first_name',
                'users.sur_name',
                'users.email',
                'users.phone',
                'users.created_at',
                DB::raw('COUNT(cart_items.id) as cart_item_count'),
                DB::raw('COALESCE(SUM(cart_items.subtotal), 0) as total_cart_amount'),
                DB::raw('MAX(cart_items.created_at) as last_cart_activity'),
                DB::raw('MAX(cart_items.updated_at) as last_cart_update'),
            ])
            ->leftJoin('cart_items', 'cart_items.user_id', '=', 'users.id')
            ->groupBy('users.id', 'users.first_name', 'users.sur_name', 'users.email', 'users.phone', 'users.created_at')
            ->havingRaw('COUNT(cart_items.id) > 0'); // Only users with cart items

            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('users.first_name', 'like', "%{$search}%")
                      ->orWhere('users.sur_name', 'like', "%{$search}%")
                      ->orWhere('users.email', 'like', "%{$search}%")
                      ->orWhere('users.phone', 'like', "%{$search}%");
                });
            }

            // Sort functionality
            $sortBy = $request->get('sort_by', 'last_cart_activity');
            $sortOrder = $request->get('sort_order', 'desc');
            
            $allowedSorts = ['name', 'email', 'cart_item_count', 'total_cart_amount', 'last_cart_activity', 'created_at'];
            if (!in_array($sortBy, $allowedSorts)) {
                $sortBy = 'last_cart_activity';
            }

            if ($sortBy === 'name') {
                $query->orderBy('users.first_name', $sortOrder)
                      ->orderBy('users.sur_name', $sortOrder);
            } elseif ($sortBy === 'email') {
                $query->orderBy('users.email', $sortOrder);
            } elseif ($sortBy === 'cart_item_count') {
                $query->orderBy('cart_item_count', $sortOrder);
            } elseif ($sortBy === 'total_cart_amount') {
                $query->orderBy('total_cart_amount', $sortOrder);
            } elseif ($sortBy === 'last_cart_activity') {
                $query->orderBy('last_cart_activity', $sortOrder);
            } else {
                $query->orderBy('users.created_at', $sortOrder);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $users = $query->paginate($perPage);

            // Get all user IDs for batch loading
            $userIds = $users->pluck('id');
            
            // Batch load cart items for all users
            $allCartItems = CartItem::with('itemable')
                ->whereIn('user_id', $userIds)
                ->get()
                ->groupBy('user_id');

            // Batch load custom-order link tokens (per-order email links)
            $usersWithCustomOrderLinks = CustomOrderLink::whereIn('user_id', $userIds)
                ->pluck('user_id')
                ->unique()
                ->flip();

            // Format response with cart details
            $formattedData = $users->getCollection()->map(function ($user) use ($allCartItems, $usersWithCustomOrderLinks) {
                // Get cart items for this user
                $cartItems = $allCartItems->get($user->id, collect());

                // Get item details
                $items = $cartItems->map(function ($item) {
                    $itemable = $item->itemable;
                    return [
                        'id' => $item->id,
                        'type' => $item->type, // product or bundle
                        'itemable_id' => $item->itemable_id,
                        'name' => $itemable ? (
                            $item->type === 'product' 
                                ? $itemable->title 
                                : $itemable->title
                        ) : 'Unknown Item',
                        'quantity' => $item->quantity,
                        'unit_price' => number_format((float) $item->unit_price, 2),
                        'subtotal' => number_format((float) $item->subtotal, 2),
                    ];
                })->values();

                return [
                    'id' => $user->id,
                    'name' => trim(($user->first_name ?? '') . ' ' . ($user->sur_name ?? '')),
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'cart_item_count' => (int) $user->cart_item_count,
                    'total_cart_amount' => number_format((float) $user->total_cart_amount, 2),
                    'last_cart_activity' => $user->last_cart_activity ? date('Y-m-d H:i:s', strtotime($user->last_cart_activity)) : null,
                    'last_cart_update' => $user->last_cart_update ? date('Y-m-d H:i:s', strtotime($user->last_cart_update)) : null,
                    'user_created_at' => $user->created_at ? $user->created_at->format('Y-m-d H:i:s') : null,
                    'cart_items' => $items,
                    'has_cart_access_token' => isset($usersWithCustomOrderLinks[$user->id]) || !empty($user->cart_access_token),
                ];
            });

            return ResponseHelper::success([
                'data' => $formattedData,
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'from' => $users->firstItem(),
                    'to' => $users->lastItem(),
                ],
            ], 'Users with carts retrieved successfully');

        } catch (Exception $e) {
            Log::error('Error fetching users with carts: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return ResponseHelper::error('Failed to retrieve users with carts: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Resend cart link email
     * POST /api/admin/cart/resend-email/{userId}
     */
    public function resendCartEmail(Request $request, $userId)
    {
        try {
            $data = $request->validate([
                'order_type' => 'required|in:buy_now,bnpl',
                'email_message' => 'nullable|string|max:10000',
            ]);

            $user = User::findOrFail($userId);

            // Prefer the latest isolated custom-order link (not the live shop cart).
            $link = CustomOrderLink::where('user_id', $userId)
                ->orderByDesc('id')
                ->first();

            if ($link) {
                $token = Str::random(64);
                $link->update([
                    'token' => $token,
                    'order_type' => $data['order_type'],
                ]);
                $cartItems = $link->resolveCartItems();
                $customItems = is_array($link->custom_items) ? $link->custom_items : [];
                $cartSubtotal = $cartItems->sum(fn ($row) => (float) ($row->subtotal ?? 0));
                $customSubtotal = collect($customItems)->reduce(function ($carry, $row) {
                    $price = (float) ($row['price'] ?? 0);
                    $qty = max(1, (int) ($row['quantity'] ?? 1));

                    return $carry + ($price * $qty);
                }, 0.0);
                $summaryTotal = round($cartSubtotal + $customSubtotal, 2);
                $cartLink = $this->buildDashboardCustomOrderUrl($token, $data['order_type']);
                $mailResult = $this->sendCartLinkEmailToUser(
                    $user,
                    $cartItems,
                    $cartLink,
                    $data['order_type'],
                    $data['email_message'] ?? null,
                    $summaryTotal,
                    $customItems
                );
            } else {
                // Legacy fallback: live cart + user cart_access_token
                $cartItems = CartItem::with('itemable')
                    ->where('user_id', $userId)
                    ->get();

                if ($cartItems->isEmpty()) {
                    return ResponseHelper::error('No custom order link or cart items found for this user', 400);
                }

                $token = Str::random(64);
                $user->update(['cart_access_token' => $token]);
                $cartLink = $this->buildDashboardCustomOrderUrl($token, $data['order_type']);
                $summaryTotal = round((float) $cartItems->sum('subtotal'), 2);
                $mailResult = $this->sendCartLinkEmailToUser(
                    $user,
                    $cartItems,
                    $cartLink,
                    $data['order_type'],
                    $data['email_message'] ?? null,
                    $summaryTotal,
                    []
                );
            }

            if (!$mailResult['sent']) {
                return ResponseHelper::error(
                    'Failed to send email: ' . ($mailResult['error'] ?? 'Unknown mail error'),
                    500
                );
            }

            return ResponseHelper::success([
                'email' => $user->email,
                'cart_link' => $cartLink,
                'email_sent' => true,
            ], 'Cart link email sent successfully');

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            Log::error('Error resending cart email: ' . $e->getMessage());
            return ResponseHelper::error('Failed to send email: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @param  array<int, mixed>|\Illuminate\Support\Collection<int, mixed>  $cartItems
     * @param  array<int, array<string, mixed>>  $customItems
     * @return array{sent: bool, error: ?string}
     */
    private function sendCartLinkEmailToUser(
        User $user,
        $cartItems,
        string $cartLink,
        string $orderType,
        ?string $emailMessage,
        float $summaryTotal,
        array $customItems = []
    ): array {
        if (empty(trim((string) $user->email))) {
            return ['sent' => false, 'error' => 'User has no email address on file'];
        }

        try {
            $itemsForMail = collect($cartItems)->values();

            // Legacy CartItem models: reload with relations for email display
            $modelIds = $itemsForMail
                ->filter(fn ($row) => $row instanceof CartItem)
                ->pluck('id')
                ->filter()
                ->values();
            if ($modelIds->isNotEmpty() && $itemsForMail->every(fn ($row) => $row instanceof CartItem)) {
                $itemsForMail = CartItem::with('itemable')->whereIn('id', $modelIds)->get();
            }

            Mail::to($user->email)->send(new CartLinkEmail(
                $user,
                $itemsForMail,
                $cartLink,
                $orderType,
                $emailMessage,
                $summaryTotal,
                $customItems
            ));

            Log::info('Cart link email sent', [
                'user_id' => $user->id,
                'email' => $user->email,
                'order_type' => $orderType,
            ]);

            return ['sent' => true, 'error' => null];
        } catch (Exception $e) {
            Log::error('Failed to send cart link email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }
}

