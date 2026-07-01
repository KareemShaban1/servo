<?php

namespace App\Services\API;

use App\Http\Requests\Tab3een\StoreTab3eenOrderRequest;
use App\Models\ApplicationSettings;
use App\Models\BusinessLocation;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Client;
use App\Models\InvoiceScheme;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellingPriceGroup;
use App\Models\Variation;
use App\Notifications\OrderCreatedNotification;
use App\Services\BaseService;
use App\Utils\BusinessUtil;
use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Tab3eenService extends BaseService
{
    protected $productUtil;
    protected $moduleUtil;
    protected $transactionUtil;
    protected $orderTrackingService;
    protected $businessUtil;
    protected $quantityTransferService;

    public function __construct(
        ProductUtil $productUtil,
        TransactionUtil $transactionUtil,
        ModuleUtil $moduleUtil,
        OrderTrackingService $orderTrackingService,
        BusinessUtil $businessUtil,
        QuantityTransferService $quantityTransferService
    ) {
        $this->productUtil = $productUtil;
        $this->transactionUtil = $transactionUtil;
        $this->moduleUtil = $moduleUtil;
        $this->orderTrackingService = $orderTrackingService;
        $this->businessUtil = $businessUtil;
        $this->quantityTransferService = $quantityTransferService;
    }

    public function list()
    {
        try {
            $tab3eenGroup = SellingPriceGroup::where('name', 'tab3een')->active()->first();
            if (!$tab3eenGroup) {
                return $this->error(__('message.Resource not found'), [], 404);
            }

            $products = Product::with([
                'variations.variation_location_details.location',
                'variations.group_prices' => function ($query) use ($tab3eenGroup) {
                    $query->where('price_group_id', $tab3eenGroup->id);
                },
                'media',
            ])
                ->where('show_in_tab3een', 1)
                ->where('active_in_app', 1)
                ->where('not_for_selling', 0)
                ->active()
                ->productForSales()
                ->get();

            if ($products->isEmpty()) {
                return collect();
            }

            $productsByCategory = $products->groupBy(function (Product $product) {
                return !empty($product->sub_category_id) ? $product->sub_category_id : $product->category_id;
            });

            $categoryIds = $productsByCategory->keys()->filter()->unique()->values();

            $categories = Category::whereIn('id', $categoryIds)
                ->where('category_type', 'product')
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->get()
                ->sortBy(function (Category $category) use ($categoryIds) {
                    return $categoryIds->search($category->id);
                })
                ->values();

            return $categories->map(function (Category $category) use ($productsByCategory, $tab3eenGroup) {
                $products = $productsByCategory->get($category->id, collect());

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'sort_order' => (int) ($category->sort_order ?? 0),
                    'image' => $category->image_url,
                    'products' => $products->map(function (Product $product) use ($tab3eenGroup) {
                        return $this->formatProduct($product, $tab3eenGroup);
                    })->values()->all(),
                ];
            })
            ->filter(function (array $category) {
                return !empty($category['products']);
            })
            ->values();

        } catch (\Exception $e) {
            return $this->handleException($e, __('message.Error happened while listing products'));
        }
    }

    public function show($id)
    {
        try {
            $tab3eenGroup = SellingPriceGroup::where('name', 'tab3een')->active()->first();
            if (!$tab3eenGroup) {
                return $this->error(__('message.Resource not found'), [], 404);
            }

            $product = Product::with([
                'variations.variation_location_details.location',
                'variations.group_prices' => function ($query) use ($tab3eenGroup) {
                    $query->where('price_group_id', $tab3eenGroup->id);
                },
                'media',
                'brand:id,name',
                'category:id,name,image,sort_order',
                'sub_category:id,name,image,sort_order',
            ])
                ->where('id', $id)
                ->where('show_in_tab3een', 1)
                ->where('active_in_app', 1)
                ->where('not_for_selling', 0)
                ->active()
                ->productForSales()
                ->first();

            if (!$product) {
                return $this->error(__('message.Resource not found'), [], 404);
            }

            return $this->formatProductDetails($product, $tab3eenGroup);

        } catch (\Exception $e) {
            return $this->handleException($e, __('message.Error happened while showing Product'));
        }
    }

    /**
     * Create a Tab3een order using the configured integration client.
     *
     * @return array|\Illuminate\Http\JsonResponse
     */
    public function store(StoreTab3eenOrderRequest $request)
    {
        try {
            $clientId = (int) config('tab3een.client_id');
            if (!$clientId) {
                return $this->error(__('message.Resource not found'), ['client_id' => ['TAB3EEN_CLIENT_ID is not configured.']], 500);
            }

            $tab3eenGroup = SellingPriceGroup::where('name', 'tab3een')->active()->first();
            if (!$tab3eenGroup) {
                return $this->error(__('message.Resource not found'), [], 404);
            }

            $carts = $this->buildOrderCarts($request->input('items'), $tab3eenGroup->id);
            if ($carts instanceof JsonResponse) {
                return $carts;
            }

            if ($carts->isEmpty()) {
                return $this->returnJSON([], __('message.Cart is empty'));
            }

            $client = Client::with('contact')->findOrFail($clientId);
            $locationId = $this->resolveBusinessLocationId($client);
            if (!$locationId) {
                return $this->error(__('message.Resource not found'), [
                    'client_id' => ['Tab3een client has no valid business location.'],
                ], 422);
            }

            $invoiceScheme = $this->resolveInvoiceScheme($client->contact->business_id, $locationId);
            if (!$invoiceScheme) {
                return $this->error(__('message.Resource not found'), [
                    'invoice_scheme' => ['No invoice scheme configured for this business.'],
                ], 422);
            }

            $client->business_location_id = $locationId;

            DB::beginTransaction();

            $subTotal = $carts->sum('total');
            $orderTotal = $carts->sum('total');

            $shippingCostStatus = ApplicationSettings::where('key', 'order_shipping_cost_status')->value('value');

            if (!isset($shippingCostStatus) || $shippingCostStatus) {
                $clientShippingCost = $client->shipping_cost;
            } else {
                $clientShippingCost = 0;
            }
            $orderTotal += $clientShippingCost;

            $order = Order::create([
                'client_id' => $clientId,
                'sub_total' => $subTotal,
                'total' => $orderTotal,
                'payment_method' => 'Cash on delivery',
                'order_type' => 'order',
                'shipping_cost' => $clientShippingCost ?? 0,
                'business_location_id' => $locationId,
            ]);

            $this->orderTrackingService->store($order, 'pending');

            foreach ($carts as $cart) {
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $cart->product_id,
                    'variation_id' => $cart->variation_id,
                    'quantity' => $cart->quantity,
                    'price' => $cart->price,
                    'discount' => $cart->discount ?? 0,
                    'sub_total' => $cart->quantity * $cart->price,
                ]);

                $this->quantityTransferService->handleQuantityTransfer($cart, $client, $order, $orderItem);
            }

            $this->makeSell($order, $client, $carts, $locationId, $invoiceScheme->id);

            DB::commit();

            $admins = $this->moduleUtil->get_admins($client->contact->business_id);
            $users = $this->moduleUtil->getBusinessUsers($client->contact->business_id, $order);

            \Notification::send($admins, new OrderCreatedNotification($order));
            \Notification::send($users, new OrderCreatedNotification($order));

            return $this->formatOrderResponse($order);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Error in Tab3een store method: " . $e->getMessage());
            return $this->handleException($e, __('message.Error happened while storing Order'));
        }
    }

    /**
     * @param  array  $items
     * @param  int  $tab3eenGroupId
     * @return \Illuminate\Support\Collection|\Illuminate\Http\JsonResponse
     */
    private function buildOrderCarts(array $items, int $tab3eenGroupId)
    {
        $carts = new Collection();

        foreach ($items as $item) {
            $product = Product::where('id', $item['product_id'])
                ->where('show_in_tab3een', 1)
                ->where('active_in_app', 1)
                ->where('not_for_selling', 0)
                ->active()
                ->productForSales()
                ->first();

            if (!$product) {
                return $this->error(__('message.Resource not found'), [
                    'product_id' => [__('message.Product is not available in Tab3een.')],
                ], 422);
            }

            $variation = Variation::with(['variation_location_details.location', 'group_prices'])
                ->where('id', $item['variation_id'])
                ->where('product_id', $product->id)
                ->first();

            if (!$variation) {
                return $this->error(__('message.Resource not found'), [
                    'variation_id' => [__('message.Product variation not found.')],
                ], 422);
            }

            $groupPrice = $variation->group_prices
                ->firstWhere('price_group_id', $tab3eenGroupId);

            if (!$groupPrice || $groupPrice->price_inc_tax === null) {
                return $this->error(__('message.Resource not found'), [
                    'variation_id' => [__('message.Tab3een price is not configured for this product.')],
                ], 422);
            }

            $quantity = (int) $item['quantity'];
            $availableStock = $variation->variation_location_details->sum('qty_available');

            if ($quantity > $availableStock) {
                return $this->error(__('message.Quantity exceeds available stock'), [], 400);
            }

            $price = (float) $groupPrice->price_inc_tax;
            $cart = new Cart([
                'product_id' => $product->id,
                'variation_id' => $variation->id,
                'quantity' => $quantity,
                'price' => $price,
                'discount' => 0,
                'discount_type' => null,
                'total' => $quantity * $price,
            ]);
            $cart->setRelation('product', $product);
            $cart->setRelation('variation', $variation);

            $carts->push($cart);
        }

        return $carts;
    }

    private function formatOrderResponse(Order $order): array
    {
        $order->load(['orderItems.product', 'orderTracking']);

        return [
            'id' => $order->id,
            'order_uuid' => $order->order_uuid,
            'number' => $order->number,
            'sub_total' => (string) $order->sub_total,
            'total' => (string) $order->total,
            'shipping_cost' => $order->shipping_cost,
            'payment_method' => $order->payment_method,
            'order_status' => ucfirst($order->order_status ?? 'pending'),
            'created_at' => $order->created_at,
            'items' => $order->orderItems->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'variation_id' => $item->variation_id,
                    'product_name' => optional($item->product)->name,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'sub_total' => $item->sub_total,
                ];
            })->values()->all(),
        ];
    }

    private function resolveBusinessLocationId(Client $client): ?int
    {
        $businessId = $client->contact->business_id ?? null;
        if (!$businessId) {
            return null;
        }

        if (!empty($client->business_location_id)) {
            $location = BusinessLocation::where('business_id', $businessId)
                ->where('id', $client->business_location_id)
                ->first();

            if ($location) {
                return (int) $location->id;
            }
        }

        $location = BusinessLocation::where('business_id', $businessId)->first();

        return $location ? (int) $location->id : null;
    }

    private function resolveInvoiceScheme(int $businessId, int $locationId): ?InvoiceScheme
    {
        $location = BusinessLocation::where('business_id', $businessId)
            ->where('id', $locationId)
            ->first();

        if ($location && !empty($location->invoice_scheme_id)) {
            $scheme = InvoiceScheme::where('business_id', $businessId)
                ->where('id', $location->invoice_scheme_id)
                ->first();

            if ($scheme) {
                return $scheme;
            }
        }

        return InvoiceScheme::where('business_id', $businessId)
            ->where('is_default', 1)
            ->first()
            ?? InvoiceScheme::where('business_id', $businessId)->first();
    }

    private function makeSell($order, $client, $carts, int $locationId, int $invoiceSchemeId)
    {
        $is_direct_sale = true;

        try {
            $transactionData = [
                'business_id' => $client->contact->business_id,
                'location_id' => $locationId,
                'invoice_scheme_id' => $invoiceSchemeId,
                'order_id' => $order->id,
                'final_total' => $order->total,
                'type' => 'sell',
                'status' => 'final',
                'payment_status' => 'paid',
                'contact_id' => $client->contact_id,
                'transaction_date' => now(),
                'tax_amount' => '0.0000',
                'created_by' => 1,
                'discount_amount' => 0,
                'shipping_charges' => $order->shipping_cost,
            ];

            $cartsArray = $carts->map(function ($cart) {
                return [
                    'unit_price_inc_tax' => $cart->price,
                    'quantity' => $cart->quantity,
                    'modifier_price' => $cart->modifier_price ?? [],
                    'modifier_quantity' => $cart->modifier_quantity ?? [],
                ];
            })->toArray();

            $discount = [
                'discount_type' => 'fixed',
                'discount_amount' => 0,
            ];
            $tax_id = 1;

            $invoice_total = $this->productUtil->calculateInvoiceTotal($cartsArray, $tax_id, $discount);
            $invoice_total['total_before_tax'] = $order->total;
            $transactionData['invoice_total'] = $invoice_total;

            $business_id = $client->contact->business_id;
            $user_id = 1;

            DB::beginTransaction();

            $transactionData['transaction_date'] = Carbon::now();

            $transaction = $this->transactionUtil->createSellTransaction($business_id, $transactionData, $invoice_total, $user_id);

            $products = $carts->map(function ($cart) {
                return [
                    'product_id' => $cart->product_id,
                    'variation_id' => $cart->variation_id,
                    'quantity' => $cart->quantity,
                    'price' => $cart->price,
                    'discount' => $cart->discount,
                    'line_discount_type' => $cart->discount_type,
                    'line_discount_amount' => $cart->discount,
                    'enable_stock' => 1,
                    'unit_price' => $cart->price,
                    'item_tax' => 0,
                    'tax_id' => null,
                    'unit_price_inc_tax' => $cart->price,
                ];
            })->toArray();

            $this->transactionUtil->createOrUpdateSellLines($transaction, $products, $locationId);

            if (!$transaction->is_suspend && !empty($transactionData['payment']) && !$is_direct_sale) {
                $this->transactionUtil->createOrUpdatePaymentLines($transaction, $transactionData['payment']);
            }

            if ($transactionData['status'] == 'final') {
                $payment_status = $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);
                $transaction->payment_status = $payment_status;

                $business_details = $this->businessUtil->getDetails($business_id);
                $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings, true);

                $business = [
                    'id' => $business_id,
                    'accounting_method' => 'fifo',
                    'location_id' => $locationId,
                    'pos_settings' => $pos_settings,
                ];

                try {
                    $this->transactionUtil->mapPurchaseSell($business, $transaction->sell_lines, 'purchase');
                } catch (\Throwable $e) {
                    \Log::warning('Failed to map purchase-sell lines: ' . $e->getMessage() . ' Line: ' . $e->getLine());
                }
            }

            $this->transactionUtil->activityLog(
                $transaction,
                'added',
                null,
                ['order_number' => $order->number, 'client' => $client->contact->name]
            );

            DB::commit();

            return [
                'success' => true,
                'message' => trans('sale.pos_sale_added'),
                'transaction' => $transaction,
            ];

        } catch (\Exception $e) {
            \Log::error("Error in Tab3een makeSell: " . $e->getMessage() . " Line:" . $e->getLine());
            DB::rollBack();
            return $this->handleException($e, __('message.Error happened while making sale'));
        }
    }

    private function formatProductDetails(Product $product, SellingPriceGroup $tab3eenGroup): array
    {
        $details = $this->formatProduct($product, $tab3eenGroup);
        $category = $product->sub_category ?? $product->category;

        return array_merge($details, [
            'current_stock' => collect($details['variations'])->sum('total_qty_available'),
	  'warranties' => $product->warranties ?? null,
            'brand' => $product->brand ? [
                'id' => $product->brand->id,
                'name' => $product->brand->name,
            ] : null,
            'category' => $category ? [
                'id' => $category->id,
                'name' => $category->name,
                'image' => $category->image_url,
            ] : null,
            'media' => $product->media->map(function ($media) {
                return [
                    'id' => $media->id,
                    'display_url' => $media->display_url,
                ];
            })->values()->all(),
        ]);
    }

    private function formatProduct(Product $product, SellingPriceGroup $tab3eenGroup): array
    {
        $variations = $product->variations->map(function ($variation) use ($tab3eenGroup) {
            $groupPrice = $variation->group_prices->first();

            $qtyAvailable = $variation->variation_location_details
                ->filter(function ($detail) {
                    return optional($detail->location)->is_active == 1;
                })
                ->sum('qty_available');

            return [
                'id' => $variation->id,
                'name' => $variation->name,
                'sku' => $variation->sub_sku,
                'total_qty_available' => (int) $qtyAvailable,
                'selling_price_group' => $tab3eenGroup->name,
                'price' => $groupPrice ? (float) $groupPrice->price_inc_tax : null,
            ];
        })->values()->all();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->product_description,
            'image_url' => $product->image_url,
            'type' => $product->type,
            'variations' => $variations,
        ];
    }
}