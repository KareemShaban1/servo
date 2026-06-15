<?php

namespace App\Http\Requests\Tab3een;

use Illuminate\Foundation\Http\FormRequest;

class StoreTab3eenOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.variation_id' => 'required|integer|exists:variations,id',
            'items.*.quantity' => 'required|integer|min:1',
        ];
    }
}
