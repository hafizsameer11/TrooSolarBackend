<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand_id' => 'nullable|exists:brands,id',
            'title' => 'nullable|string|max:255',
            'featured_image' => 'nullable|image|mimes:jpg,jpeg,png|max:3048',
            'bundle_type' => 'nullable|string|max:255',
            'is_available' => 'nullable|boolean',
            'top_deal' => 'nullable|boolean',
            'is_most_popular' => 'nullable|boolean',
            'total_price' => 'nullable|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0',
            'discount_end_date' => 'nullable|date',

            'items' => 'nullable|array',
            'items.*' => 'nullable|exists:products,id',

            'items_detail' => 'nullable|array',
            'items_detail.*.product_id' => 'required_with:items_detail|exists:products,id',
            'items_detail.*.quantity' => 'nullable|integer|min:1',
            'items_detail.*.rate_override' => 'nullable|numeric|min:0',

            'materials_detail' => 'nullable|array',
            'materials_detail.*.material_id' => 'required_with:materials_detail|exists:materials,id',
            'materials_detail.*.quantity' => 'nullable|numeric|min:0',
            'materials_detail.*.rate_override' => 'nullable|numeric|min:0',

            'custom_services' => 'nullable|array',
            'custom_services.*.title' => 'required_with:custom_services|string|max:255',
            'custom_services.*.service_amount' => 'required_with:custom_services|numeric|min:0',
            'custom_services.*.quantity' => 'nullable|integer|min:1',
            'custom_services.*.unit' => 'nullable|string|max:32',
            'custom_services.*.quantity_applies' => 'nullable|boolean',
            'custom_services.*.flow_type' => 'nullable|string|in:buy_now,bnpl',

            'product_model' => 'nullable|string|max:65535',
            'system_capacity_display' => 'nullable|string|max:255',
            'detailed_description' => 'nullable|string|max:65535',
            'what_is_inside_bundle_text' => 'nullable|string|max:65535',
            'what_bundle_powers_text' => 'nullable|string|max:65535',
            'backup_time_description' => 'nullable|string|max:65535',

            'total_load' => 'nullable|string|max:255',
            'inver_rating' => 'nullable|string|max:255',
            'total_output' => 'nullable|string|max:255',

            'custom_appliances' => 'nullable', // JSON string or array
            'custom_appliances.*.name' => 'required_with:custom_appliances|string|max:255',
            'custom_appliances.*.wattage' => 'required_with:custom_appliances|numeric|min:0',
            'custom_appliances.*.quantity' => 'nullable|integer|min:1',
            'custom_appliances.*.estimated_daily_hours_usage' => 'nullable|numeric|min:0',

            // Accept any key-value pairs under specifications (user-defined dynamic fields)
            'specifications' => 'nullable|array',
            'specifications.*' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Prepare the data for validation (normalize custom_appliances from JSON string to array).
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('custom_appliances') && is_string($this->custom_appliances)) {
            $decoded = json_decode($this->custom_appliances, true);
            $this->merge(['custom_appliances' => is_array($decoded) ? $decoded : []]);
        }
        if ($this->filled('specifications') && is_string($this->specifications)) {
            $decoded = json_decode($this->specifications, true);
            $this->merge(['specifications' => is_array($decoded) ? $decoded : []]);
        }
        if ($this->filled('items_detail') && is_string($this->items_detail)) {
            $decoded = json_decode($this->items_detail, true);
            $this->merge(['items_detail' => is_array($decoded) ? $decoded : []]);
        }
        if ($this->filled('materials_detail') && is_string($this->materials_detail)) {
            $decoded = json_decode($this->materials_detail, true);
            $this->merge(['materials_detail' => is_array($decoded) ? $decoded : []]);
        }
        if ($this->has('custom_services') && is_string($this->custom_services)) {
            $decoded = json_decode($this->custom_services, true);
            $this->merge(['custom_services' => is_array($decoded) ? $decoded : []]);
        }
        if ($this->has('brand_id') && $this->input('brand_id') === '') {
            $this->merge(['brand_id' => null]);
        }

        foreach (['is_available', 'top_deal', 'is_most_popular'] as $key) {
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
