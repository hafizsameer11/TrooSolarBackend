<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Mail\CartLinkEmail;
use App\Models\CartItem;
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

            // Build this custom order in isolation: replace the user's cart so the
            // email/checkout link only contains the items for THIS order (not prior ones).
            $cartItems = [];
            $errors = [];
            $token = null;

            DB::transaction(function () use (
                $items,
                $userId,
                $user,
                &$cartItems,
                &$errors,
                &$token
            ) {
                CartItem::where('user_id', $userId)->delete();

                foreach ($items as $item) {
                    $type = $item['type'];
                    $itemId = $item['id'];
                    $quantity = $item['quantity'] ?? 1;

                    if ($type === 'product') {
                        $product = Product::find($itemId);
                        if (!$product) {
                            $errors[] = "Product ID {$itemId} not found";
                            continue;
                        }
                        $price = $product->discount_price ?? $product->price ?? 0;

                        $cartItem = CartItem::create([
                            'user_id' => $userId,
                            'itemable_type' => Product::class,
                            'itemable_id' => $itemId,
                            'quantity' => $quantity,
                            'unit_price' => $price,
                            'subtotal' => $price * $quantity,
                        ]);
                        $cartItems[] = $cartItem;
                    } elseif ($type === 'bundle') {
                        $bundle = Bundles::find($itemId);
                        if (!$bundle) {
                            $errors[] = "Bundle ID {$itemId} not found";
                            continue;
                        }
                        $price = $bundle->discount_price ?? $bundle->total_price ?? 0;

                        $cartItem = CartItem::create([
                            'user_id' => $userId,
                            'itemable_type' => Bundles::class,
                            'itemable_id' => $itemId,
                            'quantity' => $quantity,
                            'unit_price' => $price,
                            'subtotal' => $price * $quantity,
                        ]);
                        $cartItems[] = $cartItem;
                    }
                }

                if (!empty($errors)) {
                    throw ValidationException::withMessages([
                        'items' => ['Some items could not be added: ' . implode(', ', $errors)],
                    ]);
                }

                // New token invalidates older custom-order email links for this user
                $token = Str::random(64);
                $user->update(['cart_access_token' => $token]);
            });

            $emailMessage = $data['email_message'] ?? null;
            if (count($customItems) > 0) {
                $customBlock = collect($customItems)->map(function ($row) {
                    $name = trim((string) ($row['name'] ?? ''));
                    $desc = trim((string) ($row['description'] ?? ''));
                    $price = (float) ($row['price'] ?? 0);
                    $qty = (int) ($row['quantity'] ?? 1);
                    $qty = $qty < 1 ? 1 : $qty;
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

            $cartSubtotal = collect($cartItems)->sum(fn ($row) => (float) ($row->subtotal ?? 0));
            $customSubtotal = collect($customItems)->reduce(function ($carry, $row) {
                $price = (float) ($row['price'] ?? 0);
                $qty = max(1, (int) ($row['quantity'] ?? 1));

                return $carry + ($price * $qty);
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
                    $customItems
                );
                $emailSent = $mailResult['sent'];
                $emailError = $mailResult['error'];
            }

            return ResponseHelper::success([
                'user_id' => $userId,
                'user_name' => $user->first_name . ' ' . $user->sur_name,
                'user_email' => $user->email,
                'order_type' => $orderType,
                'items_added' => count($cartItems),
                'cart_items' => collect($cartItems)->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'type' => class_basename($item->itemable_type),
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'subtotal' => $item->subtotal,
                    ];
                })->values(),
                'cart_link' => $cartLink,
                'email_sent' => $emailSent,
                'email_error' => $emailError,
            ], $emailSent || !$sendEmail
                ? 'Custom order created and user cart replaced successfully'
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

            // Batch load users for cart_access_token check
            $usersWithTokens = User::whereIn('id', $userIds)
                ->pluck('cart_access_token', 'id');

            // Format response with cart details
            $formattedData = $users->getCollection()->map(function ($user) use ($allCartItems, $usersWithTokens) {
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
                    'has_cart_access_token' => !empty($usersWithTokens[$user->id]),
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
            $cartItems = CartItem::with('itemable')
                ->where('user_id', $userId)
                ->get();

            if ($cartItems->isEmpty()) {
                return ResponseHelper::error('User cart is empty', 400);
            }

            // Generate new token
            $token = Str::random(64);
            $user->update(['cart_access_token' => $token]);

            $cartLink = $this->buildDashboardCustomOrderUrl($token, $data['order_type']);
            $summaryTotal = round((float) $cartItems->sum('subtotal'), 2);
            $mailResult = $this->sendCartLinkEmailToUser(
                $user,
                $cartItems->all(),
                $cartLink,
                $data['order_type'],
                $data['email_message'] ?? null,
                $summaryTotal,
                []
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
     * @param  array<int, CartItem>|\Illuminate\Support\Collection<int, CartItem>  $cartItems
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
            $ids = collect($cartItems)->pluck('id')->filter()->values();
            $itemsForMail = $ids->isNotEmpty()
                ? CartItem::with('itemable')->whereIn('id', $ids)->get()
                : collect();

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

