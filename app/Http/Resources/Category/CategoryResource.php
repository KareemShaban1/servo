<?php

namespace App\Http\Resources\Category;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    protected bool $withFullData = true;

    public function withFullData(bool $withFullData): self
    {
        $this->withFullData = $withFullData;

        return $this;
    }
    /**
     * @param $request The incoming HTTP request.
     * @return array<int|string, mixed>  The transformed array representation of the LaDivision collection.
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sort_order' => (int) ($this->sort_order ?? 0),
            'sub_categories' => $this->subcategories,
            $this->mergeWhen($this->withFullData, function () {
                return [
                    'description' => $this->description,
                    'image' => $this->image_url,
                    'short_code' => $this->short_code,
                    'category_type' => $this->category_type,
                ];
            }),
        ];


    }
}
