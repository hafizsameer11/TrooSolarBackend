<?php

namespace App\Mail;

use App\Models\LoanApplication;
use App\Models\User;
use App\Support\FrontendUrl;
use App\Support\MailBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BNPLStatusEmail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public LoanApplication $application;
    public string $status; // 'approved' | 'rejected' | 'counter_offer' | 'partner_offer'
    public string $continueUrl;
    public string $subjectLine;
    public string $headingText;
    public string $bodyText;
    public ?float $downPayment;
    public ?int $repaymentDuration;
    public ?float $loanAmount;

    /** @var array<string, mixed>|null */
    public ?array $partnerOffer = null;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, LoanApplication $application, string $status)
    {
        $this->user = $user;
        $this->application = $application;
        $this->status = $status;

        $this->continueUrl = FrontendUrl::bnplApplicationTrack($application->id);

        $this->downPayment = $application->mono ? (float) $application->mono->down_payment : null;
        $this->repaymentDuration = (int) $application->repayment_duration;
        $this->loanAmount = (float) $application->loan_amount;

        $bnpl = MailBrand::BNPL_LABEL;

        if ($status === 'approved') {
            $this->subjectLine = "Your {$bnpl} application has been approved – Troosolar";
            $this->headingText = MailBrand::heading('Congratulations! Your loan has been approved');
            $this->bodyText = "Your {$bnpl} application has been approved. Please complete your initial deposit to confirm your order and proceed with your purchase.";
        } elseif ($status === 'counter_offer') {
            $this->subjectLine = "You have a counter offer on your {$bnpl} application – Troosolar";
            $this->headingText = MailBrand::heading("Counter offer on your {$bnpl} application");
            $this->bodyText = "We have sent you a counter offer on your {$bnpl} application. Please log in to review the terms and accept or decline.";
        } elseif ($status === 'partner_offer') {
            $this->subjectLine = "Partner financing offer on your {$bnpl} application – Troosolar";
            $this->headingText = MailBrand::heading('Partner financing offer');
            $this->bodyText = "A financing partner has provided an offer for your {$bnpl} application. Please review the terms below and log in for more details. Supporting documents may be attached.";
            $this->partnerOffer = [
                'interest_rate' => $application->partner_offer_interest_rate !== null
                    ? (float) $application->partner_offer_interest_rate
                    : null,
                'initial_deposit' => $application->partner_offer_initial_deposit !== null
                    ? (float) $application->partner_offer_initial_deposit
                    : null,
                'admin_fees' => $application->partner_offer_admin_fees !== null
                    ? (float) $application->partner_offer_admin_fees
                    : null,
                'repayment_amount' => $application->partner_offer_repayment_amount !== null
                    ? (float) $application->partner_offer_repayment_amount
                    : null,
                'loan_amount' => $application->partner_offer_loan_amount !== null
                    ? (float) $application->partner_offer_loan_amount
                    : null,
                'tenor' => $application->partner_offer_tenor !== null
                    ? (int) $application->partner_offer_tenor
                    : null,
                'documents_count' => is_array($application->partner_offer_documents)
                    ? count($application->partner_offer_documents)
                    : 0,
            ];
            if ($this->partnerOffer['initial_deposit'] !== null) {
                $this->downPayment = $this->partnerOffer['initial_deposit'];
            }
            if ($this->partnerOffer['loan_amount'] !== null) {
                $this->loanAmount = $this->partnerOffer['loan_amount'];
            }
            if ($this->partnerOffer['tenor'] !== null) {
                $this->repaymentDuration = $this->partnerOffer['tenor'];
            }
        } elseif ($status === 'installation_date_rejected') {
            $this->subjectLine = 'Installation date – please choose another date – Troosolar';
            $this->headingText = MailBrand::heading('Installation date could not be confirmed');
            $this->bodyText = "Your requested installation date could not be confirmed. Please log in to your {$bnpl} loan page and book another date. Choose a date at least 72 hours from today; Sundays are not available.";
        } else {
            $this->subjectLine = "Update on your {$bnpl} application – Troosolar";
            $this->headingText = MailBrand::heading("Update on your {$bnpl} application");
            $this->bodyText = "We are unable to approve your {$bnpl} application at this time. Thank you for your interest in Troosolar.";
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: [config('mail.from.address')],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.bnpl_status',
        );
    }

    public function attachments(): array
    {
        if ($this->status !== 'partner_offer') {
            return [];
        }

        $docs = is_array($this->application->partner_offer_documents)
            ? $this->application->partner_offer_documents
            : [];
        $out = [];
        foreach ($docs as $doc) {
            try {
                $path = is_array($doc) ? ($doc['path'] ?? null) : (is_string($doc) ? $doc : null);
                $name = is_array($doc) ? ($doc['name'] ?? null) : null;
                if (! $path) {
                    continue;
                }
                $full = null;
                if (Storage::disk('public')->exists($path)) {
                    $full = Storage::disk('public')->path($path);
                } elseif (is_file(public_path($path))) {
                    $full = public_path($path);
                } elseif (is_file(storage_path('app/'.$path))) {
                    $full = storage_path('app/'.$path);
                }
                if ($full && is_readable($full)) {
                    $as = $name ?: ('partner_offer_'.basename($full));
                    $out[] = Attachment::fromPath($full)->as($as);
                }
            } catch (Throwable $e) {
                Log::warning('Skipped partner offer email attachment', [
                    'application_id' => $this->application->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $out;
    }
}
