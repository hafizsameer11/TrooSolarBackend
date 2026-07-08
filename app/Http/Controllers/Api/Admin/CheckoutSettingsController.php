<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\CheckoutSetting;
use App\Support\CheckoutPricing;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckoutSettingsController extends Controller
{
    private function formatSettingsPayload(CheckoutSetting $s): array
    {
        $window = CheckoutPricing::deliveryWindow($s);
        $categoryDefs = CheckoutSetting::productCategoryDefinitions();

        return [
            'delivery_fee' => (int) $s->delivery_fee,
            'category_delivery_fees' => $s->normalizedCategoryDeliveryFees(),
            'category_installation_fees' => $s->normalizedCategoryInstallationFees(),
            'category_materials_fees' => $s->normalizedCategoryMaterialsFees(),
            'category_inspection_fees' => $s->normalizedCategoryInspectionFees(),
            'own_installer_include_inspection' => (bool) ($s->own_installer_include_inspection ?? false),
            'product_categories' => $categoryDefs,
            'delivery_min_working_days' => (int) $s->delivery_min_working_days,
            'delivery_max_working_days' => (int) $s->delivery_max_working_days,
            /** @deprecated legacy flat NGN; prefer insurance_fee_percentage */
            'insurance_fee' => (int) $s->insurance_fee,
            'vat_percentage' => (float) ($s->vat_percentage ?? config('checkout.vat_percentage', 7.5)),
            'insurance_fee_percentage' => (float) ($s->insurance_fee_percentage ?? config('checkout.insurance_fee_percentage', 3)),
            'installation_flat_addon' => (int) ($s->installation_flat_addon ?? 0),
            'installation_materials_cost' => (int) ($s->installation_materials_cost ?? 0),
            'installation_schedule_working_days' => (int) $s->installation_schedule_working_days,
            'installation_description' => (string) ($s->installation_description ?? ''),
            'preview' => [
                'delivery_estimate_label' => $window['label'],
                'delivery_estimated_from' => $window['estimated_from'],
                'delivery_estimated_to' => $window['estimated_to'],
                'installation_estimated_date' => CheckoutPricing::installationEstimatedDate($s),
            ],
        ];
    }

    private function normalizeCategoryFeePayload(array $incoming, array $categoryKeys): array
    {
        $normalized = [];
        foreach ($categoryKeys as $key) {
            if (array_key_exists($key, $incoming)) {
                $normalized[$key] = max(0, (int) $incoming[$key]);
            }
        }

        return $normalized;
    }

    /**
     * GET /api/admin/checkout-settings
     */
    public function show()
    {
        try {
            $s = CheckoutSetting::get();

            return ResponseHelper::success(
                $this->formatSettingsPayload($s),
                'Checkout settings retrieved successfully'
            );
        } catch (Exception $e) {
            Log::error('Checkout settings show: '.$e->getMessage());

            return ResponseHelper::error('Failed to retrieve checkout settings', 500);
        }
    }

    /**
     * PUT /api/admin/checkout-settings
     */
    public function update(Request $request)
    {
        try {
            $categoryKeys = collect(CheckoutSetting::productCategoryDefinitions())
                ->pluck('key')
                ->filter()
                ->all();

            $request->validate([
                'delivery_fee' => 'nullable|integer|min:0|max:100000000',
                'category_delivery_fees' => 'nullable|array',
                'category_delivery_fees.*' => 'nullable|integer|min:0|max:100000000',
                'category_installation_fees' => 'nullable|array',
                'category_installation_fees.*' => 'nullable|integer|min:0|max:100000000',
                'category_materials_fees' => 'nullable|array',
                'category_materials_fees.*' => 'nullable|integer|min:0|max:100000000',
                'category_inspection_fees' => 'nullable|array',
                'category_inspection_fees.*' => 'nullable|integer|min:0|max:100000000',
                'own_installer_include_inspection' => 'nullable|boolean',
                'delivery_min_working_days' => 'nullable|integer|min:1|max:90',
                'delivery_max_working_days' => 'nullable|integer|min:1|max:90',
                'insurance_fee' => 'nullable|integer|min:0|max:100000000',
                'vat_percentage' => 'nullable|numeric|min:0|max:100',
                'insurance_fee_percentage' => 'nullable|numeric|min:0|max:100',
                'installation_flat_addon' => 'nullable|integer|min:0|max:100000000',
                'installation_materials_cost' => 'nullable|integer|min:0|max:100000000',
                'installation_schedule_working_days' => 'nullable|integer|min:1|max:90',
                'installation_description' => 'nullable|string|max:5000',
            ]);

            $s = CheckoutSetting::get();
            if ($request->has('delivery_fee')) {
                $s->delivery_fee = (int) $request->delivery_fee;
            }
            if ($request->has('category_delivery_fees') && is_array($request->category_delivery_fees)) {
                $s->category_delivery_fees = $this->normalizeCategoryFeePayload(
                    $request->category_delivery_fees,
                    $categoryKeys
                );
            }
            if ($request->has('category_installation_fees') && is_array($request->category_installation_fees)) {
                $s->category_installation_fees = $this->normalizeCategoryFeePayload(
                    $request->category_installation_fees,
                    $categoryKeys
                );
            }
            if ($request->has('category_materials_fees') && is_array($request->category_materials_fees)) {
                $s->category_materials_fees = $this->normalizeCategoryFeePayload(
                    $request->category_materials_fees,
                    $categoryKeys
                );
            }
            if ($request->has('category_inspection_fees') && is_array($request->category_inspection_fees)) {
                $s->category_inspection_fees = $this->normalizeCategoryFeePayload(
                    $request->category_inspection_fees,
                    $categoryKeys
                );
            }
            if ($request->has('own_installer_include_inspection')) {
                $s->own_installer_include_inspection = (bool) $request->boolean('own_installer_include_inspection');
            }
            if ($request->has('delivery_min_working_days')) {
                $s->delivery_min_working_days = (int) $request->delivery_min_working_days;
            }
            if ($request->has('delivery_max_working_days')) {
                $s->delivery_max_working_days = (int) $request->delivery_max_working_days;
            }
            if ($request->has('insurance_fee')) {
                $s->insurance_fee = (int) $request->insurance_fee;
            }
            if ($request->has('vat_percentage')) {
                $s->vat_percentage = (float) $request->vat_percentage;
            }
            if ($request->has('insurance_fee_percentage')) {
                $s->insurance_fee_percentage = (float) $request->insurance_fee_percentage;
            }
            if ($request->has('installation_flat_addon')) {
                $s->installation_flat_addon = (int) $request->installation_flat_addon;
            }
            if ($request->has('installation_materials_cost')) {
                $s->installation_materials_cost = (int) $request->installation_materials_cost;
            }
            if ($request->has('installation_schedule_working_days')) {
                $s->installation_schedule_working_days = (int) $request->installation_schedule_working_days;
            }
            if ($request->has('installation_description')) {
                $s->installation_description = $request->installation_description;
            }
            if ($s->delivery_max_working_days < $s->delivery_min_working_days) {
                $s->delivery_max_working_days = $s->delivery_min_working_days;
            }
            $s->save();

            return ResponseHelper::success(
                $this->formatSettingsPayload($s),
                'Checkout settings updated successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Checkout settings update: '.$e->getMessage());

            return ResponseHelper::error('Failed to update checkout settings', 500);
        }
    }
}
