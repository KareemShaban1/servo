<?php

namespace App\Models;

use App\Scopes\BusinessIdScope;
use App\Traits\Filter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Product extends Model
{
    use Filter;
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    protected $appends = ['image_url'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['name','business_id','type','unit_id','sub_unit_ids','brand_id',
    'category_id','sub_category_id','tax','tax_type','enable_stock','alert_quantity','sku',
    'barcode_type','expiry_period','expiry_period_type','enable_sr_no','weight',
    'product_custom_field1','product_custom_field2','product_custom_field3','product_custom_field4',
    'image','product_description','warranties','created_by','warranty_id','is_inactive','not_for_selling',
    'active_in_app','show_in_tab3een'];



    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'sub_unit_ids' => 'array',
    ];


    // protected static function booted()
    // {
    //     static::addGlobalScope(new BusinessIdScope);
       
    // }
    
    /**
     * Get the products image.
     *
     * @return string
     */
    public function getImageUrlAttribute()
    {
        if (!empty($this->image)) {
            $image_url = asset('/uploads/img/' . rawurlencode($this->image));
        } else {
            $image_url = asset('/img/default.png');
        }
        return $image_url;
    }

    /**
    * Get the products image path.
    *
    * @return string
    */
    public function getImagePathAttribute()
    {
        if (!empty($this->image)) {
            $image_path = public_path('uploads') . '/' . config('constants.product_img_path') . '/' . $this->image;
        } else {
            $image_path = null;
        }
        return $image_path;
    }

    public function product_variations()
    {
        return $this->hasMany(\App\Models\ProductVariation::class);
    }

    public function product_variation_locations()
    {
        return $this->hasMany(\App\Models\VariationLocationDetails::class,'product_id')->with('location');
    }
    
    /**
     * Get the brand associated with the product.
     */
    public function brand()
    {
        return $this->belongsTo(\App\Models\Brand::class);
    }

    /**
    * Get the unit associated with the product.
    */
    public function unit()
    {
        return $this->belongsTo(\App\Models\Unit::class);
    }
    /**
     * Get category associated with the product.
     */
    public function category()
    {
        return $this->belongsTo(\App\Models\Category::class);
    }
    /**
     * Get sub-category associated with the product.
     */
    public function sub_category()
    {
        return $this->belongsTo(\App\Models\Category::class, 'sub_category_id', 'id');
    }
    
    /**
     * Get the brand associated with the product.
     */
    public function product_tax()
    {
        return $this->belongsTo(\App\Models\TaxRate::class, 'tax', 'id');
    }

    /**
     * Get the variations associated with the product.
     */
    public function variations()
    {
        return $this->hasMany(\App\Models\Variation::class)->with('variation_location_details','variation_value');
    }


    /**
     * If product type is modifier get products associated with it.
     */
    public function modifier_products()
    {
        return $this->belongsToMany(\App\Models\Product::class, 'res_product_modifier_sets', 'modifier_set_id', 'product_id');
    }

    /**
     * If product type is modifier get products associated with it.
     */
    public function modifier_sets()
    {
        return $this->belongsToMany(\App\Models\Product::class, 'res_product_modifier_sets', 'product_id', 'modifier_set_id');
    }

    /**
     * Get the purchases associated with the product.
     */
    public function purchase_lines()
    {
        return $this->hasMany(\App\Models\PurchaseLine::class);
    }
 
    /**
     * Scope a query to only include active products.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('products.is_inactive', 0);
    }

     /**
     * Scope a query to only include active products.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBusinessId($query)
    {

        return $query->where('products.business_id', 1);
    }

    public function scopeAppBusinessId($query)
    {
        $client = Client::find(Auth::user()->id);
        return $query->where('products.business_id', $client->contact->business_id);
    }

    /**
     * Scope a query to only include inactive products.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInactive($query)
    {
        return $query->where('products.is_inactive', 1);
    }

    /**
     * Scope a query to only include products for sales.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProductForSales($query)
    {
        return $query->where('not_for_selling', 0);
    }

    /**
     * Scope a query to only include products for sales.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActiveInApp($query)
    {
        return $query->where('active_in_app', 1);
    }

    /**
     * Scope a query to only include products not for sales.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProductNotForSales($query)
    {
        return $query->where('not_for_selling', 1);
    }

    

    public function product_locations()
    {
        return $this->belongsToMany(\App\Models\BusinessLocation::class, 'product_locations', 'product_id', 'location_id');
    }

    public function tags()
    {
        return $this->belongsToMany(\App\Models\Tag::class, 'products_tags', 'product_id', 'tag_id');
    }
    

    /**
     * Scope a query to only include products available for a location.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForLocation($query, $location_id)
    {
        return $query->where(function ($q) use ($location_id) {
            $q->whereHas('product_locations', function ($query) use ($location_id) {
                $query->where('product_locations.location_id', $location_id);
            });
        });
    }

    /**
     * Get warranty associated with the product.
     */
    public function warranty()
    {
        return $this->belongsTo(\App\Models\Warranty::class);
    }

    public function media()
    {
        return $this->morphMany(\App\Models\Media::class, 'model');
    }


    public function getCurrentStockAttribute()
{
    return $this->variations->sum('variation_location_details.qty_available');
}

public function getMaxPriceAttribute()
{
    return $this->variations->max('sell_price_inc_tax');
}

public function getMinPriceAttribute()
{
    return $this->variations->min('sell_price_inc_tax');
}

public function getMaxPurchasePriceAttribute()
{
    return $this->variations->max('dpp_inc_tax');
}

public function getMinPurchasePriceAttribute()
{
    return $this->variations->min('dpp_inc_tax');
}


}