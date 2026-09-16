<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\PartnerRequest;
use App\Mail\SendUserLoanInfoToPartner;
use App\Models\LinkAccount;
use App\Models\LoanApplication;
use App\Models\LoanStatus;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Exception;
use Throwable;

class PartnerController extends Controller
{
    // Add partner
    public function add_partner(PartnerRequest $request)
    {
        try
        {
            $data = $request->validated();
            if (isset($data['status'])) {
                $data['status'] = Partner::normalizeStatus($data['status']);
            }
            // External partners must not claim the Troosolar slug
            if (isset($data['slug']) && strtolower((string) $data['slug']) === Partner::SLUG_TROOSOLAR) {
                unset($data['slug']);
            }
            // Check for duplicate email
            if (!empty($data['email']) && Partner::where('email', $data['email'])->exists()) {
                return ResponseHelper::error('Email is already registered for a partner', 409);
            }
            $partner = Partner::create($data);
            return ResponseHelper::success($partner, 'Partner is added succesfully');
        }
        catch(Exception $ex)
        {
            return ResponseHelper::error('Partner is not added: ' . $ex->getMessage());
        }
    }

    // All partner
    public function all_partners()
    {
        try
        {
            Partner::ensureTroosolarPartner();

            $all_partners = Partner::orderByRaw(
                "CASE WHEN LOWER(COALESCE(slug, '')) = ? THEN 0 ELSE 1 END",
                [Partner::SLUG_TROOSOLAR]
            )->orderBy('name')->get();

           $data = $all_partners->map(function ($partner) {
            return [
                'id'=> $partner->id,
                'Partner name' => $partner->name,
                'Email' => $partner->email,
                'slug' => $partner->slug,
                'is_troosolar' => $partner->isTroosolar(),
                'No of Loans' => $partner->no_of_loans,
                'Amount' => $partner->amount,
                'Date Created' => $partner->created_at,
                'Status' => $partner->status,
            ];
        });
            return ResponseHelper::success($data, 'Partner is fetch succesfully');
        }
        catch(Exception $ex)
        {
            return ResponseHelper::error('Not fetch the partners');
        }
    }

    // update partner
    
     public function update_partner( PartnerRequest $request, $partner_id)
    {
        try
        {
            $partner = Partner::findOrFail($partner_id);
            $data = $request->validated();
            if (isset($data['status'])) {
                $data['status'] = Partner::normalizeStatus($data['status']);
            }

            // Keep Troosolar slug immutable; do not allow other partners to take it
            if ($partner->isTroosolar()) {
                unset($data['slug']);
            } elseif (isset($data['slug']) && strtolower((string) $data['slug']) === Partner::SLUG_TROOSOLAR) {
                unset($data['slug']);
            }

            // Check for duplicate email (exclude current partner)
            if (!empty($data['email']) && Partner::where('email', $data['email'])->where('id', '!=', $partner_id)->exists()) {
                return ResponseHelper::error('Email is already registered for another partner', 409);
            }

            $partner->update($data);
            return ResponseHelper::success($partner->fresh(), 'Partner is updated successfully');
        }
        catch (ModelNotFoundException $e) {
            return ResponseHelper::error('Partner not found', 404);
        }
        catch(Exception $ex)
        {
            return ResponseHelper::error('Failed to update partner: ' . $ex->getMessage());
        }
    } 

    // delete partner
    public function delete_partner($partner_id)
    {
        try
        {
            $delete_partner = Partner::findOrFail($partner_id);
            if ($delete_partner->isTroosolar()) {
                return ResponseHelper::error('Troosolar cannot be deleted. Set status to Inactive to hide it from the customer list.', 422);
            }
            $delete_partner->delete();
            return ResponseHelper::success('Partner is deleted successfully');
        }
        catch (ModelNotFoundException $e) {
            return ResponseHelper::error('Partner not found', 404);
        }
        catch(Exception $ex)
        {
            return ResponseHelper::error('Failed to delete partner: ' . $ex->getMessage());
        }
    }

    // send to partner user details (works for both loan and BNPL applications)
    public function sendToPartner(Request $request, string $userId)
    {
        try {
            $request->validate([
                'partner_id' => 'nullable|integer|exists:partners,id',
                'partner_ids' => 'nullable|array|min:1',
                'partner_ids.*' => 'integer|exists:partners,id',
                'loan_application_id' => 'nullable|integer|exists:loan_applications,id',
            ]);

            $partnerIds = [];
            if ($request->filled('partner_ids') && is_array($request->partner_ids)) {
                $partnerIds = array_values(array_unique(array_map('intval', $request->partner_ids)));
            } elseif ($request->filled('partner_id')) {
                $partnerIds = [(int) $request->partner_id];
            }

            if (empty($partnerIds)) {
                return ResponseHelper::error('Select at least one financing partner.', 422);
            }

            $user = User::findOrFail($userId);

            $baseQuery = LoanApplication::where('user_id', $userId);
            if ($request->filled('loan_application_id')) {
                $loanApplication = (clone $baseQuery)
                    ->where('id', (int) $request->loan_application_id)
                    ->with(['guarantor', 'mono'])
                    ->first();
                if (! $loanApplication) {
                    return ResponseHelper::error('Loan application not found for this user.', 404);
                }
            } else {
                $loanApplication = $baseQuery->with(['guarantor', 'mono'])->latest()->first();
            }

            if (! $loanApplication) {
                return ResponseHelper::error('No loan application found for this user.', 404);
            }

            $linkAccount = LinkAccount::where('user_id', $userId)->latest()->first();
            $sent = [];
            $skipped = [];
            $failed = [];
            $lastPartnerId = null;

            foreach ($partnerIds as $partnerId) {
                $partner = Partner::find($partnerId);
                if (! $partner) {
                    $failed[] = ['partner_id' => $partnerId, 'reason' => 'Partner not found'];
                    continue;
                }
                if ($partner->isTroosolar()) {
                    $skipped[] = ['partner_id' => $partnerId, 'reason' => 'Troosolar is excluded from partner email send'];
                    continue;
                }

                $partnerEmail = trim((string) ($partner->email ?? ''));
                if ($partnerEmail === '' || ! filter_var($partnerEmail, FILTER_VALIDATE_EMAIL)) {
                    $failed[] = [
                        'partner_id' => $partnerId,
                        'name' => $partner->name,
                        'reason' => 'No valid email address configured',
                    ];
                    continue;
                }

                try {
                    Mail::to($partnerEmail)->send(
                        new SendUserLoanInfoToPartner($user, $loanApplication, $partner, $linkAccount)
                    );
                    $sent[] = [
                        'partner_id' => (int) $partner->id,
                        'name' => $partner->name,
                        'email' => $partnerEmail,
                    ];
                    $lastPartnerId = (int) $partner->id;
                } catch (Throwable $mailEx) {
                    Log::error('Error sending email to one partner', [
                        'user_id' => $userId,
                        'partner_id' => $partnerId,
                        'message' => $mailEx->getMessage(),
                    ]);
                    $failed[] = [
                        'partner_id' => $partnerId,
                        'name' => $partner->name,
                        'reason' => $mailEx->getMessage(),
                    ];
                }
            }

            if (empty($sent)) {
                $msg = 'No emails were sent.';
                if (! empty($failed)) {
                    $msg .= ' '.($failed[0]['reason'] ?? 'Check partner email settings.');
                } elseif (! empty($skipped)) {
                    $msg .= ' Troosolar cannot be used for partner email send.';
                }

                return ResponseHelper::error($msg, 422);
            }

            $loanStatus = LoanStatus::where('loan_application_id', $loanApplication->id)->first();
            if ($loanStatus) {
                $loanStatus->update([
                    'send_status' => 'active',
                    'send_date' => now(),
                    'partner_id' => $lastPartnerId,
                ]);
            }

            $count = count($sent);

            return ResponseHelper::success([
                'user_id' => (int) $userId,
                'loan_application_id' => (int) $loanApplication->id,
                'partner_id' => $lastPartnerId,
                'sent' => $sent,
                'skipped' => $skipped,
                'failed' => $failed,
                'sent_count' => $count,
            ], $count === 1
                ? 'The email has been sent to the partner.'
                : "Emails have been sent to {$count} partners.");
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::error('User, partner, or application not found.', 404);
        } catch (Throwable $ex) {
            Log::error('Error sending email to partner', [
                'user_id' => $userId,
                'loan_application_id' => $request->input('loan_application_id'),
                'partner_id' => $request->input('partner_id'),
                'partner_ids' => $request->input('partner_ids'),
                'message' => $ex->getMessage(),
                'exception' => $ex::class,
                'file' => $ex->getFile(),
                'line' => $ex->getLine(),
            ]);

            return ResponseHelper::error(
                'The email could not be sent to the partner. '.$ex->getMessage(),
                500
            );
        }
    }

}
