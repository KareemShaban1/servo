<?php

namespace App\Http\Controllers\ApplicationDashboard;

use App\Events\TransactionPaymentAdded;
use App\Exceptions\AdvanceBalanceNotAvailable;
use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\OrderTracking;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Services\ApplicationDashboard\OrderCancellationService;

use App\Services\FirebaseClientService;
use App\Utils\ModuleUtil;
use App\Utils\TransactionUtil;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;
use Yajra\DataTables\Facades\DataTables;

class OrderController extends Controller
{

    protected $moduleUtil;
    protected $transactionUtil;
    protected $orderCancellationService;


    public function __construct(
        ModuleUtil $moduleUtil,
        TransactionUtil $transactionUtil,
        OrderCancellationService $orderCancellationService

    ) {
        $this->moduleUtil = $moduleUtil;
        $this->transactionUtil = $transactionUtil;
        $this->orderCancellationService = $orderCancellationService;

    }

    public function index()
    {
        if (!auth()->user()->can('orders.view')) {
            abort(403, 'Unauthorized action.');
        }

        $order_status = request()->get('order_status');


        if (request()->ajax()) {
            $status = request()->get('status', 'all'); // Default to 'all' if not provided
            $startDate = request()->get('start_date');
            $endDate = request()->get('end_date');
            $search = request()->get('search')['value'];
            $businessLocation = request()->get('business_location');
            $deliveryName = request()->get('delivery_name');
            $paymentStatus = request()->get('payment_status', 'all');

            // Validate status
            $validStatuses = ['all', 'pending', 'processing', 'shipped', 'cancelled', 'completed'];
            if (!in_array($status, $validStatuses)) {
                $status = 'all';
            }

            // Fetch filtered data
            return $this->fetchOrders($status, $order_status, $startDate, $endDate, $search, $businessLocation, $deliveryName, $paymentStatus);
        }

        $business_locations = BusinessLocation::BusinessId()->active()->select('id', 'name')->get();

        return view('applicationDashboard.pages.orders.index', compact('order_status', 'business_locations'));
    }


    private function fetchOrders($status, $order_status, $startDate = null, $endDate = null, $search = null, $businessLocation = null, $deliveryName = null, $paymentStatus = null)
    {
        $business_id = request()->session()->get('user.business_id');
        $user_locations = Auth::user()->permitted_locations();

        $query = Order::with([
            'client.contact',
            'businessLocation',
            'relatedOrders' => function ($query) {
                $query->whereIn('order_type', ['refund_orders', 'transfer_orders']);
            },
            'transaction' => function ($query) {
                $query->where('type', 'sell'); // Filter transactions with type 'sell'
            }
        ])
            ->select([
                'orders.id',
                'orders.number',
                'orders.order_type',
                'orders.client_id',
                'orders.business_location_id',
                'orders.payment_method',
                'orders.order_status',
                'orders.payment_status',
                'orders.shipping_cost',
                'orders.sub_total',
                'orders.total',
                'orders.user_id',
                'orders.created_at'
            ])
            ->where('orders.order_type', 'order');

        $user = Auth::user();

        if (!$user->isSuperAdmin()) {
            // If the user does not have permission to view all orders, filter by user_id
            if (!$user->hasPermissionTo('essentials.view_all_orders')) {
                $query->where(function ($subQuery) use ($user) {
                    $subQuery->whereNull('orders.user_id') // Include orders where user_id is null
                        ->orWhere('orders.user_id', $user->id); // Include only orders assigned to the user
                });
            }
        }


        $query = $query->latest();


        // Apply filters as before
        if ($status !== 'all') {
            $query->where('orders.order_status', $status);
        }


        if ($order_status && $order_status !== 'all') {
            $query->where('orders.order_status', $order_status);
        }

        if ($businessLocation) {
            $query->where('orders.business_location_id', $businessLocation);
        }

        if ($paymentStatus && $paymentStatus !== 'all') {
            $query->where('orders.payment_status', $paymentStatus);
        }

        if ($user_locations !== "all") {
            $query->whereIn('orders.business_location_id', $user_locations);
        }

        if ($deliveryName) {
            $query->whereHas('deliveries.contact', function ($query) use ($deliveryName) {
                $query->where('contacts.name', 'like', "%{$deliveryName}%");
            });
        }

        if ($startDate && $endDate) {
            if ($startDate === $endDate) {
                $query->whereDate('orders.created_at', $startDate);
            } else {
                $endDate = Carbon::parse($endDate)->endOfDay();
                $query->whereBetween('orders.created_at', [$startDate, $endDate]);
            }
        }

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('orders.id', 'like', "%{$search}%")
                    ->orWhere('orders.number', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($query) use ($search) {
                        $query->where('clients.location', 'like', "%{$search}%");
                    })
                    ->orWhereHas('client.contact', function ($query) use ($search) {
                        $query->where('contacts.name', 'like', "%{$search}%")
                            ->orWhere('contacts.mobile', 'like', "%{$search}%");
                    });
            });
        }

        return $this->formatDatatableResponse($query);
    }

    private function formatDatatableResponse($query)
    {
        return Datatables::of($query)
            ->addColumn('invoice_no', function ($order) {
                if ($order->transaction) {
                    return $order->transaction->invoice_no ?? 'N/A';
                }
            })
            ->addColumn('business_location_name', function ($order) {
                if ($order->businessLocation) {
                    return $order->businessLocation->name ?? 'N/A';
                }
            })
            ->addColumn('client_contact_name', function ($order) {
                try {
                    if ($order->client && $order->client->contact) {
                        return $order->client->contact->name ?? 'N/A';
                    }
                    return 'N/A';
                } catch (\Exception $e) {
                    \Log::error('Error getting client contact name: ' . $e->getMessage());
                    return 'Error';
                }
            })
            ->addColumn('client_address', function ($order) {
                try {
                    if ($order->client) {
                        return $order->client->location ?? 'N/A';
                    }
                    return 'N/A';
                } catch (\Exception $e) {
                    \Log::error('Error getting client contact name: ' . $e->getMessage());
                    return 'Error';
                }
            })

            ->addColumn('client_contact_mobile', function ($order) {
                try {
                    if ($order->client && $order->client->contact) {
                        return $order->client->contact->mobile ?? 'N/A';
                    }
                    return 'N/A';
                } catch (\Exception $e) {
                    \Log::error('Error getting client contact mobile: ' . $e->getMessage());
                    return 'Error';
                }
            })
            ->filterColumn('client_contact_name', function ($query, $keyword) {
                $query->whereHas('client', function ($query) use ($keyword) {
                    $query->whereHas('contact', function ($query) use ($keyword) {
                        $query->where('contacts.name', 'like', "%{$keyword}%");
                    });
                });
            })
            ->filterColumn('client_address', function ($query, $keyword) {
                $query->whereHas('client', function ($q) use ($keyword) {
                    $q->where('location', 'like', "%{$keyword}%");
                });
            })
            ->filterColumn('client_contact_mobile', function ($query, $keyword) {
                $query->whereHas('client', function ($query) use ($keyword) {
                    $query->whereHas('contact', function ($query) use ($keyword) {
                        $query->where('contacts.mobile', 'like', "%{$keyword}%");
                    });
                });
            })
            ->addColumn('has_delivery', function ($order) {
                return $order->has_delivery; // Add the delivery status here
            })
            ->addColumn('delivery_name', function ($order) {
                if ($order->has_delivery) {
                    // Assuming `deliveries` is a relationship on the Order model
                    return $order->deliveries->pluck('contact.name')->implode(', ') ?: __('lang_v1.delivery_assigned');
                }
            })
            ->addColumn('related_orders', function ($order) {
                return $order->relatedOrders->map(function ($relatedOrder) {
                    return [
                        'id' => $relatedOrder->id,
                        'type' => $relatedOrder->order_type,
                        'total' => $relatedOrder->total
                    ];
                });
            })
            ->addColumn('related_orders_count', function ($order) {
                return $order->relatedOrders->count();
            })
            ->rawColumns(['related_orders']) // Use raw HTML if necessary
            ->make(true);
    }

    public function getRelatedOrders($id)
    {
        if (!auth()->user()->can('orders.view')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            // Fetch the parent order
            $parentOrder = Order::findOrFail($id);

            // Query related orders based on the same client ID (or your custom logic)
            $query = Order::with([
                'client.contact',
                'businessLocation',
                'transaction' => function ($query) {
                    $query->where('type', 'sell_return'); // Filter transactions with type 'sell'
                }
            ])
                ->select([
                    'orders.id',
                    'orders.number',
                    'orders.order_type',
                    'orders.client_id',
                    'orders.business_location_id',
                    'orders.payment_method',
                    'orders.order_status',
                    'orders.payment_status',
                    'orders.shipping_cost',
                    'orders.sub_total',
                    'orders.total',
                    'orders.created_at'
                ])
                ->where('client_id', $parentOrder->client_id)
                ->where('id', '!=', $id) // Exclude the parent order itself
                ->where('parent_order_id', $id)
                ->whereIn('order_type', ['order_refund', 'order_transfer'])
                ->latest();

            return $this->formatDatatableResponse($query);
        }

        abort(404, 'Invalid Request.');
    }



    // public function changeOrderStatus($orderId)
    // {
    //     if (!auth()->user()->can('orders.changeStatus')) {
    //         abort(403, 'Unauthorized action.');
    //     }

    //     $status = request()->input('order_status');

    //     // Begin a database transaction
    //     DB::beginTransaction();

    //     try {
    //         $order = Order::findOrFail($orderId);

    //         // Check if an OrderTracking already exists for the order
    //         $orderTracking = OrderTracking::firstOrNew(['order_id' => $order->id]);

    //         $deliveryOrder = DeliveryOrder::where('order_id', $orderId)->first();
    //         $delivery = $deliveryOrder ? Delivery::find($deliveryOrder->delivery_id) : null;

    //         // Set the tracking status timestamp based on the status provided
    //         switch ($status) {

    //             case 'pending':
    //                 if ($order->status !== 'cancelled' || $order->status !== 'completed'
    //                 || $order->status !== 'pending') {

    //                     $orderTracking->pending_at = now();
    //                     $this->moduleUtil->activityLog($order, 'change_status', null, [
    //                         'order_number' => $order->number,
    //                         'status' => 'pending'
    //                     ]);
    //                     break;
    //                 }

    //             case 'processing':
    //                 if (
    //                     $order->status !== 'cancelled' || $order->status !== 'completed'
    //                     || $order->status !== 'processing'
    //                 ) {

    //                     $order->user_id = Auth::user()->id;
    //                     $orderTracking->processing_at = now();
    //                     // Send and store push notification
    //                     app(FirebaseClientService::class)->sendAndStoreNotification(
    //                         $order->client->id,
    //                         $order->client->fcm_token,
    //                         'Order Status Changed',
    //                         'Your order has been processed successfully (Order ID: #' . $order->id . ').',
    //                         [
    //                             'order_id' => $order->id,
    //                             'status' => $status
    //                         ]
    //                     );
    //                     $this->moduleUtil->activityLog($order, 'change_status', null, [
    //                         'order_number' => $order->number,
    //                         'status' => 'processing'
    //                     ]);
    //                     break;
    //                 }

    //             case 'shipped':
    //                 if (
    //                     $order->status !== 'cancelled' || $order->status !== 'completed' ||
    //                     $order->status !== 'shipped'
    //                 ) {

    //                     $order->user_id = Auth::user()->id;
    //                     if ($delivery) {
    //                         $this->updateDeliveryBalance($order, $delivery);
    //                     }
    //                     // Send and store push notification
    //                     app(FirebaseClientService::class)->sendAndStoreNotification(
    //                         $order->client->id,
    //                         $order->client->fcm_token,
    //                         'Order Status Changed',
    //                         'Your order has been shipped successfully (Order ID: #' . $order->id . ').',
    //                         [
    //                             'order_id' => $order->id,
    //                             'status' => $status
    //                         ]
    //                     );
    //                     $orderTracking->shipped_at = now();
    //                     $this->moduleUtil->activityLog($order, 'change_status', null, [
    //                         'order_number' => $order->number,
    //                         'status' => 'shipped'
    //                     ]);
    //                     break;

    //                 }
    //             case 'cancelled':
    //                 if ($order->status !== 'cancelled' || $order->status !== 'completed') {

    //                     $orderTracking->cancelled_at = now();
    //                     $this->moduleUtil->activityLog($order, 'change_status', null, [
    //                         'order_number' => $order->number,
    //                         'status' => 'cancelled'
    //                     ]);
    //                     $cancellationData = [
    //                         'order_id' => $order->id,
    //                         'reason' => 'erp cancellation'
    //                     ];

    //                     if ($order->order_status === 'pending' || $order->order_status === 'processing') {
    //                         $this->orderCancellationService->makeOrderCancellation($cancellationData);
    //                     }
    //                     break;
    //                 }

    //             case 'completed':
    //                 if ($order->status !== 'completed' || $order->status !== 'cancelled') {
    //                     $orderTracking->completed_at = now();

    //                     // Send and store push notification
    //                     app(FirebaseClientService::class)->sendAndStoreNotification(
    //                         $order->client->id,
    //                         $order->client->fcm_token,
    //                         'Order Status Changed',
    //                         'Your order has been completed successfully (Order ID: #' . $order->id . ').',
    //                         [
    //                             'order_id' => $order->id,
    //                             'status' => $status
    //                         ]
    //                     );
    //                     $this->moduleUtil->activityLog($order, 'change_status', null, [
    //                         'order_number' => $order->number,
    //                         'status' => 'completed'
    //                     ]);
    //                     break;

    //                 }

    //             default:
    //                 throw new \InvalidArgumentException("Invalid status: $status");
    //         }

    //         // Save the order and tracking record (it will either update or create)
    //         $order->order_status = $status;
    //         $order->save();
    //         $orderTracking->save();

    //         // Commit the transaction
    //         DB::commit();

    //         return response()->json(['success' => true, 'message' => 'Order status updated successfully.']);
    //     } catch (\Exception $e) {
    //         // Rollback the transaction if anything fails
    //         DB::rollBack();

    //         \Log::info('change order status', [$e]);
    //         return response()->json(['success' => false, 'message' => 'Failed to update order status.', 'error' => $e->getMessage()], 500);
    //     }
    // }


    public function changeOrderStatus($orderId)
    {
        if (!auth()->user()->can('orders.changeStatus')) {
            abort(403, 'Unauthorized action.');
        }

        $status = request()->input('order_status');

        DB::beginTransaction();

        try {
            $order = Order::findOrFail($orderId);
            $previousStatus = $order->status;

            $orderTracking = OrderTracking::firstOrNew(['order_id' => $order->id]);
            $deliveryOrder = DeliveryOrder::where('order_id', $orderId)->first();
            $delivery = $deliveryOrder ? Delivery::find($deliveryOrder->delivery_id) : null;

            if (!in_array($status, ['pending', 'processing', 'shipped', 'cancelled', 'completed'])) {
                throw new \InvalidArgumentException("Invalid status: $status");
            }

            if (in_array($previousStatus, ['cancelled', 'completed']) || $previousStatus === $status) {
                return response()->json(['success' => false, 'message' => 'Order status cannot be changed from current state.'], 400);
            }

            // Handle each status
            switch ($status) {
                case 'pending':
                    $orderTracking->pending_at = now();
                    break;

                case 'processing':
                    $orderTracking->processing_at = now();
                    $order->user_id = auth()->id();
                    // $this->sendNotification($order, 'processed');
                    break;

                case 'shipped':
                    $orderTracking->shipped_at = now();
                    $order->user_id = auth()->id();
                    if ($delivery) {
                        $this->updateDeliveryBalance($order, $delivery);
                    }
                    // $this->sendNotification($order, 'shipped');
                    break;

                case 'cancelled':
                    $orderTracking->cancelled_at = now();
                    if (in_array($order->order_status, ['pending', 'processing'])) {
                        $this->orderCancellationService->makeOrderCancellation([
                            'order_id' => $order->id,
                            'reason' => 'erp cancellation'
                        ]);
                    }
                    break;

                case 'completed':
                    $orderTracking->completed_at = now();
                    // $this->sendNotification($order, 'completed');
                    break;
            }

            // Save and log
            $order->order_status = $status;
            $order->save();
            $orderTracking->save();

            $this->moduleUtil->activityLog($order, 'change_status', null, [
                'order_number' => $order->number,
                'status' => $status
            ]);

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Order status updated successfully.']);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to change order status', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Failed to update order status.', 'error' => $e->getMessage()], 500);
        }
    }


    private function sendNotification(Order $order, string $status)
    {
        $statusText = ucfirst($status);
        app(FirebaseClientService::class)->sendAndStoreNotification(
            $order->client->id,
            $order->client->fcm_token,
            'Order Status Changed',
            "Your order has been {$statusText} successfully (Order ID: #{$order->id}).",
            [
                'order_id' => $order->id,
                'status' => $status
            ]
        );
    }



    public function changePaymentStatus($orderId)
    {
        if (!auth()->user()->can('orders.changePayment')) {
            abort(403, 'Unauthorized action.');
        }
        $status = request()->input('payment_status'); // Retrieve status from the request

        $order = Order::findOrFail($orderId);
        $order->payment_status = $status;
        $order->save();

        $deliveryOrder = DeliveryOrder::where('order_id', $orderId)->first();
        $delivery = Delivery::find($deliveryOrder->delivery_id);

        $transaction = Transaction::
            where('type', 'sell')->
            where('location_id', $order->business_location_id)->
            where('order_id', $order->id)
            ->first();

        // $payment_types = ['cash' => __('lang_v1.cash'), 'card' => __('lang_v1.card'), 'cheque' => __('lang_v1.cheque'), 'bank_transfer' => __('lang_v1.bank_transfer'), 'other' => __('lang_v1.other')];

        $salePaymentData = [
            'transaction_id' => $transaction->id,
            'business_id' => $order->client->contact->business_id,
            'amount' => $order->total,
            'business_location_id' => $order->business_location_id,
            'method' => 'cash',
            'note' => ''
        ];
        switch ($status) {
            case 'pending':
                $this->moduleUtil->activityLog($order, 'change_payment_status', null, ['order_number' => $order->number, 'status' => 'pending']);
                break;
            case 'paid':
                $this->moduleUtil->activityLog($order, 'change_payment_status', null, ['order_number' => $order->number, 'status' => 'paid']);
                if ($deliveryOrder) {
                    $deliveryOrder->payment_status = 'paid';
                    $deliveryOrder->save();

                    if ($delivery && $delivery->contact) {
                        $delivery->contact->balance += $order->total;
                        $delivery->contact->save();
                    }
                }
                $this->makeSalePayment($salePaymentData);
                $this->moduleUtil->activityLog(
                    $order,
                    'change_payment_status',
                    null,
                    ['order_number' => $order->number, 'status' => 'paid', 'order_type', $order->order_type]
                );
                break;
            case 'failed':
                $this->moduleUtil->activityLog($order, 'change_payment_status', null, ['order_number' => $order->number, 'status' => 'failed']);
                $deliveryOrder->payment_status = 'not_paid';
                $deliveryOrder->save();
                break;
            default:
                throw new \InvalidArgumentException("Invalid status: $status");
        }

        return response()->json(['success' => true, 'message' => 'Order Payment status updated successfully.']);
    }


    public function getOrderDetails($orderId)
    {
        // Fetch activity logs related to the order
        $activityLogs = Activity::with(['subject'])
            // ->leftJoin('users as u', 'u.id', '=', 'activity_log.causer_id')
            ->leftJoin('users as u', function ($join) {
                $join->on('u.id', '=', 'activity_log.causer_id')
                    ->where('activity_log.causer_type', '=', 'App\Models\User');
            })
            ->leftJoin('clients as c', function ($join) {
                $join->on('c.id', '=', 'activity_log.causer_id')
                    ->where('activity_log.causer_type', '=', 'App\Models\Client');
            })
            ->leftJoin('deliveries as d', function ($join) {
                $join->on('d.id', '=', 'activity_log.causer_id')
                    ->where('activity_log.causer_type', '=', 'App\Models\Delivery');
            })
            ->leftJoin('contacts as contact', function ($join) {
                $join->on('contact.id', '=', 'c.contact_id')
                    ->orOn('contact.id', '=', 'd.contact_id');
            })
            ->where('subject_type', 'App\Models\Order')
            ->where('subject_id', $orderId)
            ->select(
                'activity_log.*',
                DB::raw("
            CASE 
                WHEN u.id IS NOT NULL THEN CONCAT(COALESCE(u.surname, ''), ' ', COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''), ' (user)')
                WHEN c.id IS NOT NULL THEN CONCAT(COALESCE(contact.name, ''), ' (client)')
                WHEN d.id IS NOT NULL THEN CONCAT(COALESCE(contact.name, ''), ' (delivery)')
                ELSE 'Unknown'
            END as created_by
        ")
            )
            ->get();

        // Fetch the order along with related data
        $order = Order::with([
            'client.contact',
            'businessLocation',
            'orderCancellation',
            'orderItems',
            'delivery',
            'transaction' => function ($query) {
                $query->where('type', 'sell'); // Filter transactions with type 'sell'
            }
        ])->find($orderId);

        if ($order) {
            // Iterate through each order item and check for refund details
            foreach ($order->orderItems as $item) {
                // Check if there are any records in the order_refund table for this order item
                $refund = OrderRefund::where('order_item_id', $item->id)->get();

                $refund_amount = $refund->sum('amount') ?? 0;
                // Calculate the difference between the order item quantity and the refunded amount
                $item->remaining_quantity = $item->quantity - $refund_amount;
            }

            // Return the order details and activity logs
            return response()->json([
                'success' => true,
                'order' => $order,
                'activityLogs' => $activityLogs,
            ]);
        }

        // If the order is not found
        return response()->json([
            'success' => false,
            'message' => 'Order not found'
        ]);
    }

    /**
     * Update the delivery contact balance based on the order total.
     *
     * @param Order $order
     * @return void
     */
    private function updateDeliveryBalance($order, $delivery)
    {
        Log::info($delivery);

        if ($delivery && $delivery->contact) {
            $delivery->contact->balance -= $order->total;
            $delivery->contact->save();
        }

        Log::info("balance updated");

    }


    protected function makeSalePayment($salePaymentData)
    {
        try {
            $business_id = $salePaymentData['business_id'];
            $transaction_id = $salePaymentData['transaction_id'];
            $transaction = Transaction::where('business_id', $business_id)->with(['contact'])->findOrFail($transaction_id);

            $location = BusinessLocation::find($salePaymentData['business_location_id']);
            $transaction_before = $transaction->replicate();

            if ($transaction->payment_status != 'paid') {
                // $inputs = $request->only(['amount', 'method', 'note', 'card_number', 'card_holder_name',
                // 'card_transaction_number', 'card_type', 'card_month', 'card_year', 'card_security',
                // 'cheque_number', 'bank_account_number']);
                $salePaymentData['paid_on'] = Carbon::now();
                $salePaymentData['transaction_id'] = $transaction->id;
                $salePaymentData['amount'] = $this->transactionUtil->num_uf($salePaymentData['amount']);
                // $inputs['amount'] = $this->transactionUtil->num_uf($inputs['amount']);
                $salePaymentData['created_by'] = 1;
                $salePaymentData['payment_for'] = $transaction->contact_id;

                // $salePaymentData['account_id'] =2;
                if (!empty($location->default_payment_accounts)) {
                    $default_payment_accounts = json_decode(
                        $location->default_payment_accounts,
                        true
                    );
                    // Check for cash account and set account_id
                    if (!empty($default_payment_accounts['cash']['is_enabled']) && !empty($default_payment_accounts['cash']['account'])) {
                        $salePaymentData['account_id'] = $default_payment_accounts['cash']['account'] ?? 1;
                    }
                }


                $prefix_type = 'purchase_payment';
                if (in_array($transaction->type, ['sell', 'sell_return'])) {
                    $prefix_type = 'sell_payment';
                } elseif (in_array($transaction->type, ['expense', 'expense_refund'])) {
                    $prefix_type = 'expense_payment';
                }

                DB::beginTransaction();

                $ref_count = $this->transactionUtil->setAndGetReferenceCount($prefix_type);
                //Generate reference number
                $salePaymentData['payment_ref_no'] = $this->transactionUtil->generateReferenceNumber($prefix_type, $ref_count);

                //Pay from advance balance
                $payment_amount = $salePaymentData['amount'];
                // $contact_balance = !empty($transaction->contact) ? $transaction->contact->balance : 0;
                // if ($inputs['method'] == 'advance' && $inputs['amount'] > $contact_balance) {
                //     throw new AdvanceBalanceNotAvailable(__('lang_v1.required_advance_balance_not_available'));
                // }

                \Log::info('salePaymentData', [$salePaymentData]);

                if (!empty($salePaymentData['amount'])) {
                    $tp = TransactionPayment::create($salePaymentData);
                    $salePaymentData['transaction_type'] = $transaction->type;
                    event(new TransactionPaymentAdded($tp, $salePaymentData));
                }

                //update payment status
                $payment_status = $this->transactionUtil->updatePaymentStatus($transaction_id, $transaction->final_total);
                $transaction->payment_status = $payment_status;

                $this->transactionUtil->activityLog($transaction, 'payment_edited', $transaction_before);

                DB::commit();
            }

            $output = [
                'success' => true,
                'msg' => __('purchase.payment_added_success')
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            $msg = __('messages.something_went_wrong');


            $output = [
                'success' => false,
                'msg' => $msg
            ];
        }

        return redirect()->back()->with(['status' => $output]);
    }


    public function getOrderStatistics(Request $request)
    {
        // Validate the request
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        // Get filters from the request
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Base query for orders
        $baseQuery = Order::query();

        // Apply date filters if provided
        // if ($startDate && $endDate) {
        //     $baseQuery->whereBetween('created_at', [$startDate, $endDate]);
        // }

        if ($startDate && $endDate) {
            if ($startDate === $endDate) {
                $baseQuery->whereDate('created_at', $startDate);
            } else {
                $endDate = Carbon::parse($endDate)->endOfDay();
                $baseQuery->whereBetween('created_at', [$startDate, $endDate]);
            }
        }

        // Get total orders count and total amount (completed orders)
        $totalOrdersCount = (clone $baseQuery)
            ->where('order_type', 'order')
            // ->where('order_status', 'completed')
            ->count();
        $totalOrdersAmount = (clone $baseQuery)
            ->where('order_type', 'order')
            // ->where('order_status', 'completed')
            ->sum('total');

        // Get total orders count and total amount (completed orders)
        $totalCompletedPaidOrdersCount = (clone $baseQuery)->
            where('order_status', 'completed')
            ->where('order_type', 'order')
            ->where('payment_status', 'paid')
            ->count();
        $totalCompletedPaidOrdersAmount = (clone $baseQuery)->
            where('order_status', 'completed')
            ->where('order_type', 'order')
            ->where('payment_status', 'paid')
            ->sum('total');

        $totalCompletedNotPaidOrdersCount = (clone $baseQuery)
            ->where('order_status', '<>', 'cancelled')
            ->where('payment_status', '<>', 'paid')
            ->where('order_type', 'order')
            ->count();
        $totalCompletedNotPaidOrdersAmount = (clone $baseQuery)
            ->where('order_status', '<>', 'cancelled')
            ->where('payment_status', '<>', 'paid')
            ->where('order_type', 'order')
            ->sum('total');

        // Get orders with type 'order_refund' (completed orders)
        $refundOrdersCount = (clone $baseQuery)
            ->where('order_type', 'order_refund')
            ->where('order_status', 'completed')
            ->where('payment_status', 'paid')
            ->count();
        $refundOrdersAmount = (clone $baseQuery)
            ->where('order_type', 'order_refund')
            ->where('payment_status', 'paid')
            ->where('order_status', 'completed')
            ->sum('total');

        // Get orders with type 'order_transfer' (completed orders)
        $transferOrdersCount = (clone $baseQuery)->where('order_type', 'order_transfer')
            ->where('order_status', 'completed')->count();
        $transferOrdersAmount = (clone $baseQuery)->where('order_type', 'order_transfer')
            ->where('order_status', 'completed')->sum('total');

        // Get orders with cancellation status
        $cancelledOrdersCount = (clone $baseQuery)->where('order_status', 'cancelled')->count();
        $cancelledOrdersAmount = (clone $baseQuery)->where('order_status', 'cancelled')->sum('total');

        // Calculate net total after decreasing refund and cancellation orders
        $netTotalAmount = $totalCompletedPaidOrdersAmount - $refundOrdersAmount;
        // Return the statistics as JSON
        return response()->json([
            'success' => true,
            'data' => [
                'total_orders_count' => $totalOrdersCount,
                'total_orders_amount' => $totalOrdersAmount,
                'total_completed_paid_orders_count' => $totalCompletedPaidOrdersCount,
                'total_completed_paid_orders_amount' => $totalCompletedPaidOrdersAmount,
                'total_completed_not_paid_orders_count' => $totalCompletedNotPaidOrdersCount,
                'total_completed_not_paid_orders_amount' => $totalCompletedNotPaidOrdersAmount,
                'refund_orders_count' => $refundOrdersCount,
                'refund_orders_amount' => $refundOrdersAmount,
                'transfer_orders_count' => $transferOrdersCount,
                'transfer_orders_amount' => $transferOrdersAmount,
                'cancelled_orders_count' => $cancelledOrdersCount,
                'cancelled_orders_amount' => $cancelledOrdersAmount,
                'net_total_amount' => $netTotalAmount, // Add net total amount

            ],
        ]);
    }

}