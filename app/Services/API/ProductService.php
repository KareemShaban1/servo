<?php

namespace App\Services\API;

use App\Http\Resources\Category\CategoryResource;
use App\Http\Resources\Product\ProductCollection;
use App\Http\Resources\Product\ProductResource;
use App\Http\Resources\Product\ProductWithoutAuthCollection;
use App\Http\Resources\Product\ProductWithoutAuthResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Variation;
use Illuminate\Database\Eloquent\Builder;
use App\Services\BaseService;
use App\Traits\HelperTrait;
use App\Traits\UploadFileTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductService extends BaseService
{
    use UploadFileTrait, HelperTrait;
    /**
     * Get all products with filters and pagination for DataTables.
     */
    public function list(Request $request)
    {
        try {

            // Initialize the query with necessary relationships
            $query = Product::with([
                'media',
                'unit:id,actual_name,short_name',
                'brand:id,name',
                'category:id,name',
                'sub_category:id,name',
                'warranty:id,name,duration,duration_type'
            ])
                ->where('products.type', '!=', 'modifier')
                ->appBusinessId()
                ->productForSales()
                ->activeInApp();

            $this->applyProductListFilters($query, $request);

            $searchCategories = null;
            if ($request->filled('search')) {
                $categoryIds = (clone $query)
                    ->whereNotNull('category_id')
                    ->distinct()
                    ->pluck('category_id');

                if ($categoryIds->isNotEmpty()) {
                    $searchCategories = Category::whereIn('id', $categoryIds)->get();
                }
            }

            // Apply withTrashed logic if needed
            $query = $this->withTrashed($query, $request);

            // Apply pagination or fetch the data
            $products = $this->withPagination($query, $request);

            // Wrap the data in ProductCollection and apply withFullData() here
            $collection = (new ProductCollection($products))
                ->withFullData(!($request->full_data == 'false'));

            if ($searchCategories) {
                $collection->additional([
                    'categories' => $searchCategories->map(function ($category) {
                        return (new CategoryResource($category))->withFullData(false);
                    })->values(),
                ]);
            }

            return $collection;

        } catch (\Exception $e) {
            // Handle any exception that might occur
            return $this->handleException($e, __('message.Error happened while listing products'));
        }
    }

    /**
     * Apply shared list filters: category, search, in_stock, sorting.
     */
    private function applyProductListFilters(Builder $query, Request $request): void
    {
        if (!empty($request->category_id)) {
            $query->where('category_id', $request->category_id);
        }

        if (!empty($request->category_id) && !empty($request->sub_category_id)) {
            $query->where('category_id', $request->category_id)
                ->where('sub_category_id', $request->sub_category_id);
        }

        if ($request->has('in_stock')) {
            $inStock = filter_var($request->in_stock, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($inStock === true) {
                $query->whereHas('variations.variation_location_details', function ($q) {
                    $q->where('qty_available', '>', 0)
                        ->whereHas('location', function ($locationQuery) {
                            $locationQuery->where('is_active', 1);
                        });
                });
            } elseif ($inStock === false) {
                $query->whereDoesntHave('variations.variation_location_details', function ($q) {
                    $q->where('qty_available', '>', 0)
                        ->whereHas('location', function ($locationQuery) {
                            $locationQuery->where('is_active', 1);
                        });
                });
            }
        }

        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $tokens = array_filter(explode(' ', strtolower($searchTerm)));

            $query->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    $q->where(function ($innerQuery) use ($token) {
                        $innerQuery->where('name', 'like', '%' . $token . '%')
                            ->orWhere('sku', 'like', '%' . $token . '%')
                            ->orWhereHas('tags', function ($tagQuery) use ($token) {
                                $tagQuery->where('name', 'like', '%' . $token . '%');
                            });
                    });
                }
            });
        }

        $this->applyProductListSorting($query, $request);
    }

    /**
     * sort_by: name | price | created_at
     * sort: asc | desc (defaults to desc for created_at, asc for name/price)
     */
    private function applyProductListSorting(Builder $query, Request $request): void
    {
        $sortBy = $request->input('sort_by', 'created_at');
        $sort = strtolower($request->input('sort', 'desc'));

        if (!in_array($sort, ['asc', 'desc'], true)) {
            $sort = 'desc';
        }

        switch ($sortBy) {
            case 'name':
                $query->orderBy('products.name', $sort);
                break;
            case 'price':
                $priceSubquery = Variation::query()
                    ->selectRaw('MIN(sell_price_inc_tax)')
                    ->whereColumn('variations.product_id', 'products.id')
                    ->whereNull('variations.deleted_at');

                $query->orderBy($priceSubquery, $sort);
                break;
            case 'created_at':
                $query->orderBy('products.created_at', $sort);
                break;
            default:
                $query->latest();
                break;
        }
    }


    public function listWithoutAuth(Request $request)
    {
        try {

            // Initialize the query with necessary relationships
            $query = Product::with([
                'media',
                'unit:id,actual_name,short_name',
                'brand:id,name',
                'category:id,name',
                'sub_category:id,name',
                'warranty:id,name,duration,duration_type'
            ])
                ->where('products.type', '!=', 'modifier')
                // ->appBusinessId()
                ->productForSales()
                ->activeInApp()
                ->latest();

            // Exclude products with negative stock
            // $query->whereHas('variations.variation_location_details', function ($q) {
            //     $q->havingRaw('SUM(qty_available) >= 0');
            // });


            // Check if a category_id is passed and apply the filter
            if (!empty($request->category_id)) {
                $query->where('category_id', $request->category_id);
            }

            if (!empty($request->category_id) && !empty($request->sub_category_id)) {
                $query->where('category_id', $request->category_id)->
                    where('sub_category_id', $request->sub_category_id);
            }


            if ($request->filled('search')) {
                $searchTerm = $request->search;

                // Split the search term into tokens (words)
                $tokens = explode(' ', strtolower($searchTerm));

                $query->where(function ($q) use ($tokens) {
                    foreach ($tokens as $token) {
                        $q->where(function ($innerQuery) use ($token) {
                            $innerQuery->where('name', 'like', '%' . $token . '%')
                                ->orWhere('sku', 'like', '%' . $token . '%')
                                //    ->orWhere('description', 'like', '%' . $token . '%')
                                ->orWhereHas('tags', function ($tagQuery) use ($token) {
                                    $tagQuery->where('name', 'like', '%' . $token . '%');
                                });
                        });
                    }
                });
            }

            // if ($product->product_type == 'combo') {
            //     if ($check_qty) {
            //         $product->qty_available = $this->calculateComboQuantity($location_id, $product->combo_variations);
            //     }

            // Apply withTrashed logic if needed
            $query = $this->withTrashed($query, $request);

            // Apply pagination or fetch the data
            $products = $this->withPagination($query, $request);

            // Wrap the data in ProductCollection and apply withFullData() here
            return (new ProductWithoutAuthCollection($products))
                ->withFullData(!($request->full_data == 'false'));

        } catch (\Exception $e) {
            // Handle any exception that might occur
            return $this->handleException($e, __('message.Error happened while listing products'));
        }
    }


    public function show(Request $request , $id)
    {
        try {
            // Fetch product with relationships and filters
            $product = Product::with([
                'media',
                'unit:id,actual_name,short_name',
                'brand:id,name',
                'category:id,name',
                'sub_category:id,name',
                'warranty:id,name,duration,duration_type',
                'variations.variation_location_details.location' // Load variations and their stock locations
            ])
                ->where('type', '!=', 'modifier') // Simplified column reference
                ->appBusinessId() // Assuming this is a global scope or local query scope
                ->productForSales() // Assuming this is a local query scope
                ->activeInApp() // Assuming this is a local query scope
                ->find($id);

            // Check if product exists
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.Product not found'),
                ], 404);
            }

            // return new ProductResource($product);

            return (new ProductResource($product))->setVariationId($request->variationId);


            // // Return the product
            // return response()->json([
            //     'success' => true,
            //     'data' => $product,
            // ], 200);

        } catch (\Exception $e) {
            // Handle the exception
            return response()->json([
                'success' => false,
                'message' => __('messages.Error happened while showing Product'),
                'error' => $e->getMessage(), // Optional: Include error details for debugging
            ], 500);
        }
    }


    public function showWithoutAuth(Request $request , $id)
    {
        try {
            // Fetch product with relationships and filters
            $product = Product::with([
                'media',
                'unit:id,actual_name,short_name',
                'brand:id,name',
                'category:id,name',
                'sub_category:id,name',
                'warranty:id,name,duration,duration_type',
                'variations.variation_location_details.location' // Load variations and their stock locations
            ])
                ->where('type', '!=', 'modifier') // Simplified column reference
                // ->appBusinessId() // Assuming this is a global scope or local query scope
                ->productForSales() // Assuming this is a local query scope
                ->activeInApp() // Assuming this is a local query scope
                ->find($id);

            // Check if product exists
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.Product not found'),
                ], 404);
            }

            // return new ProductResource($product);

            return (new ProductWithoutAuthResource($product))->setVariationId($request->variationId);


            // // Return the product
            // return response()->json([
            //     'success' => true,
            //     'data' => $product,
            // ], 200);

        } catch (\Exception $e) {
            // Handle the exception
            return response()->json([
                'success' => false,
                'message' => __('messages.Error happened while showing Product'),
                'error' => $e->getMessage(), // Optional: Include error details for debugging
            ], 500);
        }
    }



    public function categoryProducts($request, $id)
    {

        try {
            $query = Product::appBusinessId()->where('category_id', $id);

            $query = $this->withTrashed($query, $request);

            // Apply pagination or fetch the data
            $products = $this->withPagination($query, $request);


            // Wrap the data in ProductCollection and apply withFullData() here
            return (new ProductCollection($products))
                ->withFullData(!($request->full_data == 'false'));

        } catch (\Exception $e) {
            return $this->handleException($e, __('message.Error happened while showing Product'));
        }
    }

    /**
     * Create a new Product.
     */
    public function store($data)
    {

        try {

            // First, create the Product without the image
            $product = Product::create($data);

            // Handle the main image and gallery uploads in a single helper function
            // $this->handleImages($request, 'image', 'Product', $product->id, $fileUploader);
            // $this->handleImages($request, 'gallery', 'Product', $product->id, $fileUploader);

            // Return the created Product
            return new ProductResource($product);


        } catch (\Exception $e) {
            return $this->handleException($e, __('message.Error happened while storing Product'));
        }
    }

    /**
     * Update the specified Product.
     */
    public function update($request, $product)
    {

        try {

            // Validate the request data
            $data = $request->validated();

            $product->update($data);

            return new ProductResource($product);


        } catch (\Exception $e) {
            return $this->handleException($e, __('message.Error happened while updating Product'));
        }
    }

    public function destroy($id)
    {
        try {

            $product = Product::find($id);

            if (!$product) {
                return null;
            }
            $product->delete();
            return $product;


        } catch (\Exception $e) {
            return $this->handleException($e, __('message.Error happened while deleting Product'));
        }
    }

    public function restore($id)
    {
        try {
            $product = Product::withTrashed()->findOrFail($id);
            $product->restore();
            return new ProductResource($product);
        } catch (\Exception $e) {
            return $this->handleException($e, __('message.Error happened while restoring Product'));
        }
    }

    public function forceDelete($id)
    {
        try {
            $product = Product::withTrashed()
                ->findOrFail($id);

            $product->forceDelete();
        } catch (\Exception $e) {
            return $this->handleException($e, __('message.Error happened while force deleting Product'));
        }
    }


    public function bulkDelete(mixed $ids)
    {
        try {
            $trashedRecords = Product::onlyTrashed()->whereIn('id', $ids)->get();

            if ($trashedRecords->isNotEmpty()) {
                Product::whereIn('id', $trashedRecords->pluck('id'))->forceDelete();
            }

            $nonTrashedIds = Product::whereIn('id', $ids)->get()->pluck('id');

            if ($nonTrashedIds->isNotEmpty()) {
                Product::whereIn('id', $nonTrashedIds)->delete();
            }

            return $ids;
        } catch (\Exception $e) {
            return $this->handleException($e, __('message.Error happened while deleting products'));
        }
    }
}
