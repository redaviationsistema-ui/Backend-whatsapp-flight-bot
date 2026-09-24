<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Services\WhatsApp\Flows\FlightQuoteFlowHandler;

class WhatsAppChatbotService
{
    public function __construct(
        private readonly WhatsAppFlowRouter $flowRouter,
        private readonly FlightQuoteFlowHandler $flightQuoteFlowHandler,
    ) {}

    /** @return array{state:string,message:string} */
    public function handleIncomingMessage(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): array
    {
        return $this->flowRouter->route($conversation, $flightRequest, $message);
    }

    /** @return array{state:string,message:string} */
    public function handleFlightFlow(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): array
    {
        return $this->flightQuoteFlowHandler->handle($conversation, $flightRequest, $message);
    }

    public function isQuoteReady(WhatsAppFlightRequest $flightRequest): bool
    {
        return $this->flightQuoteFlowHandler->isQuoteReady($flightRequest);
    }

    public function summaryMessage(WhatsAppFlightRequest $flightRequest): string
    {
        return $this->flightQuoteFlowHandler->summaryMessage($flightRequest);
    }
}
