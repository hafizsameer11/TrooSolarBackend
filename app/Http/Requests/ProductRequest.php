<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Define common rules
        $rules = [
            'brand_id' => 'nullable|exists:brands,id',
            'discount_price' => 'nullable|numeric|min:0',
            'discount_end_date' => 'nullable|date',
            'stock' => 'nullable|string',
            'installation_price' => 'nullable|numeric|min:0',
            'top_deal' => 'boolean',
            'is_most_popular' => 'boolean',
            'installation_compulsory' => 'boolean',
            'is_available' => 'boolean',
            'description' => 'nullable|string',
            'specifications' => 'nullable|string',
            'featured_image' => 'nullable|image|mimes:jpg,jpeg,png,webp',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpg,jpeg,png,webp',
            'remove_featured_image' => 'nullable|boolean',
            'remove_image_ids' => 'nullable|array',
            'remove_image_ids.*' => 'integer',
            'product_details' => 'nullable|array',
            'product_details.*' => 'required|string',
        ];

        if ($this->isMethod('post')) {
            // Fields required on CREATE
            $rules = array_merge($rules, [
                'title' => 'required|string|max:255',
                'category_id' => 'required|exists:categories,id',
                'price' => 'required|numeric|min:0',
            ]);
        } else {
            // Fields optional on UPDATE
            $rules = array_merge($rules, [
                'title' => 'sometimes|required|string|max:255',
                'category_id' => 'sometimes|exists:categories,id',
                'price' => 'sometimes|numeric|min:0',
            ]);
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('brand_id') && $this->input('brand_id') === '') {
            $this->merge(['brand_id' => null]);
        }

        foreach (['top_deal', 'is_most_popular', 'installation_compulsory', 'is_available'] as $key) {
            if (!$this->has($key)) {
                continue;
            }
            $v = $this->input($key);
            if ($v === '1' || $v === 1 || $v === true || $v === 'true') {
                $this->merge([$key => true]);
            } elseif ($v === '0' || $v === 0 || $v === false || $v === 'false' || $v === '') {
                $this->merge([$key => false]);
            }
        }
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 'error',
                'data' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422)
        );
    }
}
