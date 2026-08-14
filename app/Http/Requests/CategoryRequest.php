<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'nullable|string|max:255',
            'icon' => 'nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048',
            'show_on_store' => 'nullable|boolean',
            'status' => 'nullable|string|max:50',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('show_on_store')) {
            $this->merge([
                'show_on_store' => filter_var($this->input('show_on_store'), FILTER_VALIDATE_BOOLEAN),
            ]);

            return;
        }

        if ($this->filled('status')) {
            $status = strtolower(trim((string) $this->input('status')));
            $this->merge([
                'show_on_store' => in_array($status, ['active', '1', 'true', 'yes'], true),
            ]);
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
