<?php

namespace App\Services\API;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellingPriceGroup;
use App\Services\BaseService;

class Tab3eenService extends BaseService
{
    public function list()
    {
        try {
            $tab3eenGroup = SellingPriceGroup::where('name', 'tab3een')->active()->first();

            if (!$tab3eenGroup) {
                return $this->error(__('message.Resource not found'), [], 404);
            }

            $categories = Category::where('show_in_tab3een', 1)
                ->where('category_type', 'product')
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->get();

            if ($categories->isEmpty()) {
                return collect();
            }

            $productsByCategory = Product::with([
                'variations.variation_location_details.location',
                'variations.group_prices' => function ($query) use ($tab3eenGroup) {
                    $query->where('price_group_id', $tab3eenGroup->id);
                },
                'media',
            ])
                ->where('show_in_tab3een', 1)
                ->whereIn('category_id', $categories->pluck('id'))
                ->active()
                ->productForSales()
                ->get()
                ->groupBy('category_id');

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

    private function formatProduct(Product $product, SellingPriceGroup $tab3eenGroup): array
    {
        $variations = $product->variations->map(function ($variation) use ($tab3eenGroup) {
            $groupPrice = $variation->group_prices->first();

            $qtyAvailable = $variation->variation_location_details
                ->filter(fn ($detail) => optional($detail->location)->is_active == 1)
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
