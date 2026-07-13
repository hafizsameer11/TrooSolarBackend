<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderDeliveredThankYouMail extends Mailable
{
    use Queueable, SerializesModels;

    public Order $order;

    public User $user;

    /**
     * Formatted order payload from OrderController::formatOrder() (all line items + totals).
     *
     * @var array<string, mixed>
     */
    public array $orderView;

    /** Link to dashboard → More → My orders (leave a review). */
    public string $dashboardOrdersUrl;

    /**
     * @param  array<string, mixed>  $orderView
     */
    public function __construct(Order $order, User $user, array $orderView)
    {
        $this->order = $order;
        $this->user = $user;
        $this->orderView = $orderView;
        $this->dashboardOrdersUrl = rtrim((string) config('app.frontend_url', 'https://app.troosolar.io'), '/')
            .'/more?section=myOrders&orderId='.$order->id;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your order has been delivered – thank you from Troosolar',
            replyTo: [config('mail.from.address')],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order_delivered_thank_you',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
