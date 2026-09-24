<?php

namespace App\Services\WhatsApp\Flows;

use App\Models\AdvisorRequest;
use App\Models\WhatsAppConversation;
use App\Services\WhatsApp\WhatsAppConversationService;

class AdvisorFlowHandler
{
    private const ContextKey = 'ADVISOR';

    public function __construct(private readonly WhatsAppConversationService $conversationService) {}

    /** @return array{state:string,message:string} */
    public function start(WhatsAppConversation $conversation): array
    {
        $this->putContext($conversation, []);

        return [
            'state' => 'ADVISOR_ASK_REASON',
            'message' => $this->reasonPrompt(),
        ];
    }

    /** @return array{state:string,message:string} */
    public function handle(WhatsAppConversation $conversation, string $message): array
    {
        $normalized = $this->normalize($message);

        return match ($conversation->state) {
            'ADVISOR_START' => $this->start($conversation),
            'ADVISOR_ASK_REASON' => $this->captureReason($conversation, $normalized),
            'ADVISOR_ASK_REFERENCE' => $this->captureReference($conversation, $message),
            'ADVISOR_ASK_COMMENTS' => $this->captureComments($conversation, $message),
            'ADVISOR_SHOW_SUMMARY', 'ADVISOR_CONFIRM' => $this->handleSummaryChoice($conversation, $normalized),
            'ADVISOR_EDIT_MENU' => $this->handleEditChoice($conversation, $normalized),
            'ADVISOR_EDIT_REASON' => $this->captureReason($conversation, $normalized),
            'ADVISOR_EDIT_REFERENCE' => $this->captureReference($conversation, $message),
            'ADVISOR_EDIT_COMMENTS' => $this->captureComments($conversation, $message),
            'ADVISOR_COMPLETED' => ['state' => 'TRANSFER_TO_HUMAN', 'message' => '👨‍💼 La conversación fue enviada a un asesor.'],
            default => $this->start($conversation),
        };
    }

    public function clearContext(WhatsAppConversation $conversation): void
    {
        $metadata = $conversation->metadata ?? [];
        unset($metadata['section_context'][self::ContextKey]);

        if (($metadata['section_context'] ?? []) === []) {
            unset($metadata['section_context']);
        }

        $conversation->update(['metadata' => $metadata === [] ? null : $metadata]);
        $conversation->forceFill(['metadata' => $metadata === [] ? null : $metadata]);
    }

    /** @return array{state:string,message:string} */
    public function cancel(WhatsAppConversation $conversation): array
    {
        $this->clearContext($conversation);

        return [
            'state' => 'MAIN_MENU',
            'message' => "✅ Solicitud de asesor cancelada.\n\nVolvimos al menú principal.",
        ];
    }

    /** @return array{state:string,message:string} */
    private function captureReason(WhatsAppConversation $conversation, string $normalized): array
    {
        $reason = $this->parseReason($normalized);

        if ($reason === null) {
            return [
                'state' => $conversation->state,
                'message' => "⚠️ Selecciona un motivo válido.\n\n".$this->reasonPrompt(),
            ];
        }

        $this->mergeContext($conversation, ['reason' => $reason]);

        return [
            'state' => $this->nextStateAfterCapture($conversation, 'ADVISOR_ASK_REFERENCE'),
            'message' => "📄 ¿Tienes algún número de cotización, reserva, solicitud o referencia relacionada?\n\nSi no tienes, escribe \"no\".",
        ];
    }

    /** @return array{state:string,message:string} */
    private function captureReference(WhatsAppConversation $conversation, string $message): array
    {
        $reference = $this->optionalText($message);
        if ($reference !== null && strlen($reference) > 120) {
            return [
                'state' => $conversation->state,
                'message' => '⚠️ La referencia es demasiado larga. Envíala en máximo 120 caracteres.',
            ];
        }

        $this->mergeContext($conversation, ['reference' => $reference]);

        return [
            'state' => $this->nextStateAfterCapture($conversation, 'ADVISOR_ASK_COMMENTS'),
            'message' => '💬 Cuéntanos brevemente qué necesitas del asesor.',
        ];
    }

    /** @return array{state:string,message:string} */
    private function captureComments(WhatsAppConversation $conversation, string $message): array
    {
        $comments = trim($message);

        if ($comments === '') {
            return [
                'state' => $conversation->state,
                'message' => '💬 Cuéntanos brevemente qué necesitas del asesor.',
            ];
        }
        if (strlen($comments) > 1000) {
            return [
                'state' => $conversation->state,
                'message' => '⚠️ Los comentarios son demasiado largos. Envíalos en máximo 1000 caracteres.',
            ];
        }

        $this->mergeContext($conversation, ['comments' => $comments]);

        return [
            'state' => 'ADVISOR_SHOW_SUMMARY',
            'message' => $this->summaryMessage($conversation),
        ];
    }

    /** @return array{state:string,message:string} */
    private function handleSummaryChoice(WhatsAppConversation $conversation, string $normalized): array
    {
        return match (true) {
            in_array($normalized, ['1', 'si', 'sí', 'confirmar', 'registrar'], true) => $this->confirm($conversation),
            in_array($normalized, ['2', 'editar'], true) => [
                'state' => 'ADVISOR_EDIT_MENU',
                'message' => "📋 ¿Qué deseas modificar?\n\n1. Motivo\n2. Referencia\n3. Comentarios\n4. Volver",
            ],
            in_array($normalized, ['3', 'cancelar'], true) => $this->cancel($conversation),
            default => ['state' => 'ADVISOR_SHOW_SUMMARY', 'message' => $this->summaryMessage($conversation)],
        };
    }

    /** @return array{state:string,message:string} */
    private function handleEditChoice(WhatsAppConversation $conversation, string $normalized): array
    {
        return match ($normalized) {
            '1' => ['state' => 'ADVISOR_EDIT_REASON', 'message' => $this->reasonPrompt()],
            '2' => ['state' => 'ADVISOR_EDIT_REFERENCE', 'message' => "📄 ¿Tienes algún número de cotización, reserva, solicitud o referencia relacionada?\n\nSi no tienes, escribe \"no\"."],
            '3' => ['state' => 'ADVISOR_EDIT_COMMENTS', 'message' => '💬 Cuéntanos brevemente qué necesitas del asesor.'],
            '4' => ['state' => 'ADVISOR_SHOW_SUMMARY', 'message' => $this->summaryMessage($conversation)],
            default => [
                'state' => 'ADVISOR_EDIT_MENU',
                'message' => "📋 ¿Qué deseas modificar?\n\n1. Motivo\n2. Referencia\n3. Comentarios\n4. Volver",
            ],
        };
    }

    /** @return array{state:string,message:string} */
    private function confirm(WhatsAppConversation $conversation): array
    {
        $context = $this->context($conversation);
        $advisorRequestId = $context['advisor_request_id'] ?? null;

        if (! $advisorRequestId) {
            $advisorRequest = AdvisorRequest::query()->create([
                'whats_app_conversation_id' => $conversation->id,
                'whats_app_contact_id' => $conversation->whats_app_contact_id,
                'reason' => $context['reason'],
                'reference' => $context['reference'] ?? null,
                'comments' => $context['comments'],
                'status' => AdvisorRequest::StatusNueva,
                'transferred_at' => now(),
            ]);
            $advisorRequestId = $advisorRequest->id;
            $this->mergeContext($conversation, ['advisor_request_id' => $advisorRequestId]);
        }

        $conversation->update(['state' => 'ADVISOR_COMPLETED']);
        $conversation->forceFill(['state' => 'ADVISOR_COMPLETED']);
        $this->conversationService->transferToHuman($conversation);

        return [
            'state' => 'TRANSFER_TO_HUMAN',
            'message' => "✅ Listo. Registramos tu solicitud con ID {$advisorRequestId}.\n\n👨‍💼 La conversación fue enviada a un asesor.",
        ];
    }

    private function nextStateAfterCapture(WhatsAppConversation $conversation, string $defaultState): string
    {
        return str_starts_with($conversation->state, 'ADVISOR_EDIT_') ? 'ADVISOR_SHOW_SUMMARY' : $defaultState;
    }

    private function reasonPrompt(): string
    {
        return "👨‍💼 Hablar con un asesor\n\n¿Sobre qué tema necesitas ayuda?\n\n1. Cotización de vuelo\n2. Reserva\n3. Pago\n4. Contrato / documento\n5. Partes y refacciones\n6. Motores\n7. Soporte técnico\n8. Otro";
    }

    private function summaryMessage(WhatsAppConversation $conversation): string
    {
        $context = $this->context($conversation);

        return "📋 Resumen para asesor\n\n"
            .'Motivo: '.$this->reasonLabel($context['reason'])."\n"
            .'Referencia: '.($context['reference'] ?: '—')."\n"
            .'Comentarios: '.$context['comments']."\n\n"
            ."¿Deseas solicitar atención de un asesor?\n\n1. Sí\n2. Editar\n3. Cancelar";
    }

    /** @return array<string, mixed> */
    private function context(WhatsAppConversation $conversation): array
    {
        return $conversation->metadata['section_context'][self::ContextKey] ?? [];
    }

    /** @param array<string, mixed> $values */
    private function mergeContext(WhatsAppConversation $conversation, array $values): void
    {
        $this->putContext($conversation, [...$this->context($conversation), ...$values]);
    }

    /** @param array<string, mixed> $context */
    private function putContext(WhatsAppConversation $conversation, array $context): void
    {
        $metadata = $conversation->metadata ?? [];
        $metadata['active_section'] = self::ContextKey;
        $metadata['section_context'] = $metadata['section_context'] ?? [];
        $metadata['section_context'][self::ContextKey] = $context;

        $conversation->update(['metadata' => $metadata]);
        $conversation->forceFill(['metadata' => $metadata]);
    }

    private function optionalText(string $message): ?string
    {
        $text = trim($message);

        return in_array($this->normalize($text), ['no', 'ninguno', 'ninguna', 'n/a'], true) ? null : $text;
    }

    private function parseReason(string $normalized): ?string
    {
        return match ($normalized) {
            '1', 'cotizacion de vuelo', 'cotización de vuelo', 'cotizacion', 'vuelo' => 'FLIGHT_QUOTE',
            '2', 'reserva' => 'RESERVATION',
            '3', 'pago' => 'PAYMENT',
            '4', 'contrato', 'documento', 'contrato documento' => 'DOCUMENT',
            '5', 'partes', 'refacciones', 'partes y refacciones' => 'PARTS',
            '6', 'motores', 'motor' => 'ENGINES',
            '7', 'soporte tecnico', 'soporte técnico', 'tecnico', 'técnico' => 'TECHNICAL_SUPPORT',
            '8', 'otro', 'otra' => 'OTHER',
            default => null,
        };
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'FLIGHT_QUOTE' => 'Cotización de vuelo',
            'RESERVATION' => 'Reserva',
            'PAYMENT' => 'Pago',
            'DOCUMENT' => 'Contrato / documento',
            'PARTS' => 'Partes y refacciones',
            'ENGINES' => 'Motores',
            'TECHNICAL_SUPPORT' => 'Soporte técnico',
            'OTHER' => 'Otro',
            default => $reason,
        };
    }

    private function normalize(string $message): string
    {
        return trim(str($message)->lower()->ascii()->squish()->toString());
    }
}
