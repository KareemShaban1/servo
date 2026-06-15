<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Variation extends Model
{
    use SoftDeletes;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'variations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'product_id',
        'sub_sku',
        'product_variation_id',
        'woocommerce_variation_id',
        'variation_value_id',
        'default_purchase_price',
        'dpp_inc_tax',
        'profit_percent',
        'default_sell_price',
        'sell_price_inc_tax',
        'combo_variations'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'combo_variations' => 'array',
    ];

    protected $appends = ['code', 'total_qty_available', 'client_selling_group', 'client_selling_price'];


    public function product_variation()
    {
        return $this->belongsTo(\App\Models\ProductVariation::class);
    }

    public function product()
    {
        return $this->belongsTo(\App\Models\Product::class, 'product_id');
    }

    public function variation_value()
    {
        return $this->belongsTo(\App\Models\VariationValueTemplate::class, 'variation_value_id');
    }

    /**
     * Get the sell lines associated with the variation.
     */
    public function sell_lines()
    {
        return $this->hasMany(\App\Models\TransactionSellLine::class);
    }

    /**
     * Get the location wise details of the the variation.
     */
    public function variation_location_details()
    {
        // return $this->hasMany(\App\Models\VariationLocationDetails::class);
        return $this->hasMany(\App\Models\VariationLocationDetails::class)
            ->whereHas('location', function ($query) {
                $query->where('active_in_app', 1);
            });
    }

    /**
     * Get Selling price group prices.
     */
    public function group_prices()
    {
        return $this->hasMany(\App\Models\VariationGroupPrice::class, 'variation_id');
    }

    public function media()
    {
        return $this->morphMany(\App\Models\Media::class, 'model');
    }

    /**
     * Define the discounts relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function discounts()
    {
        return $this->belongsToMany(\App\Models\Discount::class, 'discount_variations', 'variation_id', 'discount_id');
    }



    public function getFullNameAttribute()
    {
        $name = $this->product->name;
        if ($this->product->type == 'variable') {
            $name .= ' - ' . $this->product_variation->name . ' - ' . $this->name;
        }
        $name .= ' (' . $this->sub_sku . ')';

        return $name;
    }


    /**
     * Accessor for the code attribute.
     *
     * @return string|null
     */
    public function getCodeAttribute()
    {
        // Check if the variation value exists and its name matches
        if ($this->variation_value && $this->variation_value->name === $this->name) {
            return $this->variation_value->code; // Return the code if conditions are met
        }

        return null; // Return null if the condition is not met
    }

    public function getTotalQtyAvailableAttribute()
    {
        // // Check if the variation has combo variations
        // if (!empty($this->combo_variations) && is_array($this->combo_variations)) {
        //     $total_qty = 0;

        //     // Iterate through each combo variation
        //     foreach ($this->combo_variations as $combo_variation) {
        //         // Find the corresponding variation by its ID
        //         $variation = self::find($combo_variation['variation_id']);

        //         if ($variation) {
        //             // Multiply the quantity available by the required quantity
        //             $total_qty += $variation->variation_location_details()->sum('qty_available') * $combo_variation['quantity'];
        //         }
        //     }

        //     return $total_qty;
        // }

        // If no combo variations, return the normal sum of qty_available
        return $this->variation_location_details()->sum('qty_available');
    }


    public function getClientSellingGroupAttribute()
    {
        if (!Auth::check() || !(Auth::user() instanceof Client)) {
            return null;
        }

        $client = Client::find(Auth::user()->id);
        return $client && isset($client->contact->customer_group->selling_price_group)
            ? $client->contact->customer_group->selling_price_group->name
            : null;
    }

    public function getClientSellingPriceAttribute()
    {
        if (!Auth::check() || !(Auth::user() instanceof Client)) {
            return null;
        }

        $client = Client::find(Auth::user()->id);

        if (!$client || !isset($client->contact->customer_group->selling_price_group)) {
            return null;
        }

        $selling_group_id = $client->contact->customer_group->selling_price_group->id;

        return DB::table('variation_group_prices')
            ->where('price_group_id', $selling_group_id)
            ->where('variation_id', $this->id)
            ->value('price_inc_tax'); // Use `value()` to get only the price field
    }

    public function getGuestSellingGroupAttribute()
    {

        // Guest fallback
        $guestGroup = DB::table('selling_price_groups')->where('name', 'guest')->first();

        return $guestGroup ? $guestGroup->name : 'Guest'; // Default name if not found
    }

    public function getGuestSellingPriceAttribute()
    {
        $sellingGroupId = null;

        // if (Auth::check()) {
        //     $client = Client::find(Auth::id());
        //     if ($client && isset($client->contact->customer_group->selling_price_group)) {
        //         $sellingGroupId = $client->contact->customer_group->selling_price_group->id;
        //     }
        // } else {
            // Fallback to 'guest' group
            $guestGroup = DB::table('selling_price_groups')->where('name', 'guest')->first();
            if ($guestGroup) {
                $sellingGroupId = $guestGroup->id;
            }
        // }

        if (!$sellingGroupId) {
            return "100.0"; // or a default price like 0
        }

        return DB::table('variation_group_prices')
            ->where('price_group_id', $sellingGroupId)
            ->where('variation_id', $this->id)
            ->value('price_inc_tax') ?? "100.0"; // or a default price
    }


}
