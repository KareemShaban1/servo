<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
}
