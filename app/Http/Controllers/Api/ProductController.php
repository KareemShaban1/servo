<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product;
use App\Services\API\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{


    protected $service;

    public function __construct(ProductService $service)
    {
        $this->service = $service;
    }

    /**
     * Display a listing of the products.
     */
    public function index(Request $request)
    {
        $products = $this->service->list($request);

        if ($products instanceof JsonResponse) {
            return $products;
        }

        return $products->additional(array_merge(
            $products->additional ?? [],
            [
                'code' => 200,
                'status' => 'success',
                'message' => __('message.Products have been retrieved successfully'),
            ]
        ));
    }

    public function indexWithoutAuth(Request $request)
    {

        $products = $this->service->listWithoutAuth($request);

        if ($products instanceof JsonResponse) {
            return $products;
        }

        return $products->additional(array_merge(
            $products->additional ?? [],
            [
                'code' => 200,
                'status' => 'success',
                'message' => __('message.Products have been retrieved successfully'),
            ]
        ));
    }

    /**
     * Store a newly created Product in storage.
     */
    public function store(StoreProductRequest $request)
    {
            $data = $request->validated();
            $product = $this->service->store( $data);

            if ($product instanceof JsonResponse) {
                return $product;
            }

            return $this->returnJSON($product, __('message.Product has been created successfully'));
    }

    /**
     * Display the specified Product.
     */
    public function show(Request $request, $id)
    {

        $product = $this->service->show( $request,$id);

        if ($product instanceof JsonResponse) {
            return $product;
        }

        return $this->returnJSON($product, __('message.Product has been showed successfully'));

    }


    public function showWithoutAuth(Request $request, $id)
    {

        $product = $this->service->showWithoutAuth( $request,$id);

        if ($product instanceof JsonResponse) {
            return $product;
        }

        return $this->returnJSON($product, __('message.Product has been showed successfully'));

    }

     /**
     * Display the specified Product.
     */
    public function categoryProducts(Request $request,$id)
    {

        $product = $this->service->categoryProducts( $request ,$id);

        if ($product instanceof JsonResponse) {
            return $product;
        }

        return $this->returnJSON($product, __('message.Product has been created successfully'));

    }

    /**
     * Update the specified Product in storage.
     */
    public function update(UpdateProductRequest $request, Product $product)
    {
            $product = $this->service->update($request,$product);

            if ($product instanceof JsonResponse) {
                return $product;
            }

            return $this->returnJSON($product, __('message.Product has been updated successfully'));

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $product = $this->service->destroy($id);

        if ($product instanceof JsonResponse) {
            return $product;
        }

        return $this->returnJSON($product, __('message.Product has been deleted successfully'));
    }

    public function restore($id)
    {
        $product = $this->service->restore($id);

        if ($product instanceof JsonResponse) {
            return $product;
        }

        return $this->returnJSON($product, __('message.Product has been restored successfully'));
    }

    public function forceDelete($id)
    {
        $product = $this->service->forceDelete($id);

        if ($product instanceof JsonResponse) {
            return $product;
        }

        return $this->returnJSON($product, __('message.Product has been force deleted successfully'));
    }

    public function bulkDelete(Request $request)
    {

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:products,id',
        ]);


        $product = $this->service->bulkDelete($request->ids);

        if ($product instanceof JsonResponse) {
            return $product;
        }

        return $this->returnJSON($product, __('message.Product has been deleted successfully.'));
    }
}
