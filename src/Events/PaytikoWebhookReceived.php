<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko\Events;

use Asciisd\CashierPaytiko\DataObjects\PaytikoWebhookData;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaytikoWebhookReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly PaytikoWebhookData $webhookData,
        public readonly array $rawPayload,
    ) {}
}
