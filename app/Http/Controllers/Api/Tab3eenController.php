<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tab3een\StoreTab3eenOrderRequest;
use App\Services\API\Tab3eenService;
use Illuminate\Http\JsonResponse;

class Tab3eenController extends Controller
{
    protected $service;

    public function __construct(Tab3eenService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $data = $this->service->list();

        if ($data instanceof JsonResponse) {
            return $data;
        }

        return $this->returnJSON(
            $data,
            __('message.Products have been retrieved successfully')
        );
    }

    public function show($id)
    {
        $data = $this->service->show($id);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        return $this->returnJSON(
            $data,
            __('message.Product has been showed successfully')
        );
    }

    public function store(StoreTab3eenOrderRequest $request)
    {
        $order = $this->service->store($request);

        if ($order instanceof JsonResponse) {
            return $order;
        }

        return $this->returnJSON($order, __('message.Order has been created successfully'));
    }
}
