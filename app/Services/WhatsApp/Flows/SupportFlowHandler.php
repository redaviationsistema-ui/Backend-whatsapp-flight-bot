<?php

namespace App\Services\WhatsApp\Flows;

use App\Models\SupportRequest;
use App\Models\WhatsAppConversation;

class SupportFlowHandler
{
    private const ContextKey = 'SUPPORT';

    /** @return array{state:string,message:string} */
    public function start(WhatsAppConversation $conversation): array
    {
        $this->putContext($conversation, []);

        return [
            'state' => 'SUPPORT_ASK_REASON',
            'message' => $this->reasonPrompt(),
        ];
    }

    /** @return array{state:string,message:string} */
    public function handle(WhatsAppConversation $conversation, string $message): array
    {
        $normalized = $this->normalize($message);

        if ($this->wantsHuman($normalized)) {
            return ['state' => 'TRANSFER_TO_HUMAN', 'message' => '👨‍💼 Te conectaremos con un asesor para continuar con tu solicitud.'];
        }

        return match ($conversation->state) {
            'SUPPORT_START' => $this->start($conversation),
            'SUPPORT_ASK_REASON' => $this->captureReason($conversation, $normalized),
            'SUPPORT_ASK_REFERENCE' => $this->captureReference($conversation, $message),
            'SUPPORT_ASK_DESCRIPTION' => $this->captureDescription($conversation, $message),
            'SUPPORT_ASK_PRIORITY' => $this->capturePriority($conversation, $normalized),
            'SUPPORT_ASK_COMMENTS' => $this->captureComments($conversation, $message),
            'SUPPORT_SHOW_SUMMARY', 'SUPPORT_CONFIRM' => $this->handleSummaryChoice($conversation, $normalized),
            'SUPPORT_EDIT_MENU' => $this->handleEditChoice($conversation, $normalized),
            'SUPPORT_EDIT_REASON' => $this->captureReason($conversation, $normalized),
            'SUPPORT_EDIT_REFERENCE' => $this->captureReference($conversation, $message),
            'SUPPORT_EDIT_DESCRIPTION' => $this->captureDescription($conversation, $message),
            'SUPPORT_EDIT_PRIORITY' => $this->capturePriority($conversation, $normalized),
            'SUPPORT_EDIT_COMMENTS' => $this->captureComments($conversation, $message),
            'SUPPORT_COMPLETED' => ['state' => 'SUPPORT_COMPLETED', 'message' => '✅ Tu solicitud de soporte ya fue registrada. Escribe "menu" para volver al menú principal.'],
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
            'message' => "✅ Solicitud de soporte cancelada.\n\nVolvimos al menú principal.",
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
            'state' => $this->nextStateAfterCapture($conversation, 'SUPPORT_ASK_REFERENCE'),
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
            'state' => $this->nextStateAfterCapture($conversation, 'SUPPORT_ASK_DESCRIPTION'),
            'message' => '🎧 Describe brevemente cómo podemos ayudarte.',
        ];
    }

    /** @return array{state:string,message:string} */
    private function captureDescription(WhatsAppConversation $conversation, string $message): array
    {
        $description = trim($message);

        if ($description === '') {
            return [
                'state' => $conversation->state,
                'message' => '🎧 Describe brevemente cómo podemos ayudarte.',
            ];
        }
        if (strlen($description) > 1000) {
            return [
                'state' => $conversation->state,
                'message' => '⚠️ La descripción es demasiado larga. Envíala en máximo 1000 caracteres.',
            ];
        }

        $this->mergeContext($conversation, ['description' => $description]);

        return [
            'state' => $this->nextStateAfterCapture($conversation, 'SUPPORT_ASK_PRIORITY'),
            'message' => $this->priorityPrompt(),
        ];
    }

    /** @return array{state:string,message:string} */
    private function capturePriority(WhatsAppConversation $conversation, string $normalized): array
    {
        $priority = $this->parsePriority($normalized);

        if ($priority === null) {
            return [
                'state' => $conversation->state,
                'message' => "⚠️ Selecciona una prioridad válida.\n\n".$this->priorityPrompt(),
            ];
        }

        $this->mergeContext($conversation, ['priority' => $priority]);

        return [
            'state' => $this->nextStateAfterCapture($conversation, 'SUPPORT_ASK_COMMENTS'),
            'message' => "📄 ¿Deseas agregar algún comentario adicional?\n\nSi no, escribe \"no\".",
        ];
    }

    /** @return array{state:string,message:string} */
    private function captureComments(WhatsAppConversation $conversation, string $message): array
    {
        $comments = $this->optionalText($message);
        if ($comments !== null && strlen($comments) > 1000) {
            return [
                'state' => $conversation->state,
                'message' => '⚠️ Los comentarios son demasiado largos. Envíalos en máximo 1000 caracteres.',
            ];
        }

        $this->mergeContext($conversation, ['comments' => $comments]);

        return [
            'state' => 'SUPPORT_SHOW_SUMMARY',
            'message' => $this->summaryMessage($conversation),
        ];
    }

    /** @return array{state:string,message:string} */
    private function handleSummaryChoice(WhatsAppConversation $conversation, string $normalized): array
    {
        return match (true) {
            in_array($normalized, ['1', 'si', 'sí', 'confirmar', 'registrar'], true) => $this->confirm($conversation),
            in_array($normalized, ['2', 'editar'], true) => [
                'state' => 'SUPPORT_EDIT_MENU',
                'message' => "📋 ¿Qué deseas modificar?\n\n1. Motivo\n2. Referencia\n3. Descripción\n4. Prioridad\n5. Comentarios\n6. Volver",
            ],
            in_array($normalized, ['3', 'cancelar'], true) => $this->cancel($conversation),
            default => ['state' => 'SUPPORT_SHOW_SUMMARY', 'message' => $this->summaryMessage($conversation)],
        };
    }

    /** @return array{state:string,message:string} */
    private function handleEditChoice(WhatsAppConversation $conversation, string $normalized): array
    {
        return match ($normalized) {
            '1' => ['state' => 'SUPPORT_EDIT_REASON', 'message' => $this->reasonPrompt()],
            '2' => ['state' => 'SUPPORT_EDIT_REFERENCE', 'message' => "📄 ¿Tienes algún número de cotización, reserva, solicitud o referencia relacionada?\n\nSi no tienes, escribe \"no\"."],
            '3' => ['state' => 'SUPPORT_EDIT_DESCRIPTION', 'message' => '🎧 Describe brevemente cómo podemos ayudarte.'],
            '4' => ['state' => 'SUPPORT_EDIT_PRIORITY', 'message' => $this->priorityPrompt()],
            '5' => ['state' => 'SUPPORT_EDIT_COMMENTS', 'message' => "📄 ¿Deseas agregar algún comentario adicional?\n\nSi no, escribe \"no\"."],
            '6' => ['state' => 'SUPPORT_SHOW_SUMMARY', 'message' => $this->summaryMessage($conversation)],
            default => [
                'state' => 'SUPPORT_EDIT_MENU',
                'message' => "📋 ¿Qué deseas modificar?\n\n1. Motivo\n2. Referencia\n3. Descripción\n4. Prioridad\n5. Comentarios\n6. Volver",
            ],
        };
    }

    /** @return array{state:string,message:string} */
    private function confirm(WhatsAppConversation $conversation): array
    {
        $context = $this->context($conversation);
        $supportRequestId = $context['support_request_id'] ?? null;

        if (! $supportRequestId) {
            $supportRequest = SupportRequest::query()->create([
                'whats_app_conversation_id' => $conversation->id,
                'whats_app_contact_id' => $conversation->whats_app_contact_id,
                'reason' => $context['reason'],
                'reference' => $context['reference'] ?? null,
                'description' => $context['description'],
                'priority' => $context['priority'],
                'comments' => $context['comments'] ?? null,
                'status' => SupportRequest::StatusNueva,
            ]);
            $supportRequestId = $supportRequest->id;
            $this->mergeContext($conversation, ['support_request_id' => $supportRequestId]);
        }

        return [
            'state' => 'SUPPORT_COMPLETED',
            'message' => "✅ Listo. Registramos tu solicitud de soporte con ID {$supportRequestId}.\n\n🎧 Nuestro equipo podrá darle seguimiento.\n\nEscribe \"menu\" para volver al menú principal.",
        ];
    }

    private function nextStateAfterCapture(WhatsAppConversation $conversation, string $defaultState): string
    {
        return str_starts_with($conversation->state, 'SUPPORT_EDIT_') ? 'SUPPORT_SHOW_SUMMARY' : $defaultState;
    }

    private function reasonPrompt(): string
    {
        return "🎧 Atención / soporte\n\n¿Con qué necesitas ayuda?\n\n1. Cotización de vuelo\n2. Reserva\n3. Pago\n4. Contrato / documento\n5. Problema técnico\n6. Partes o motores\n7. Otro";
    }

    private function priorityPrompt(): string
    {
        return "⚠️ ¿Qué tan urgente es tu solicitud?\n\n1. Normal\n2. Alta\n3. Urgente";
    }

    private function summaryMessage(WhatsAppConversation $conversation): string
    {
        $context = $this->context($conversation);

        return "📋 Resumen de solicitud de soporte\n\n"
            .'Motivo: '.$this->reasonLabel($context['reason'])."\n"
            .'Referencia: '.($context['reference'] ?: '—')."\n"
            .'Descripción: '.$context['description']."\n"
            .'Prioridad: '.$this->priorityLabel($context['priority'])."\n"
            .'Comentarios: '.($context['comments'] ?: 'Sin comentarios')."\n\n"
            ."¿Deseas registrar esta solicitud?\n\n1. Sí\n2. Editar\n3. Cancelar";
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

        return in_array($this->normalize($text), ['no', 'ninguno', 'ninguna', 'sin comentarios', 'n/a'], true) ? null : $text;
    }

    private function parseReason(string $normalized): ?string
    {
        return match ($normalized) {
            '1', 'cotizacion de vuelo', 'cotización de vuelo', 'cotizacion', 'vuelo' => 'FLIGHT_QUOTE',
            '2', 'reserva' => 'RESERVATION',
            '3', 'pago' => 'PAYMENT',
            '4', 'contrato', 'documento', 'contrato documento' => 'DOCUMENT',
            '5', 'problema tecnico', 'problema técnico', 'tecnico', 'técnico' => 'TECHNICAL',
            '6', 'partes', 'motores', 'partes o motores' => 'PARTS_ENGINES',
            '7', 'otro', 'otra' => 'OTHER',
            default => null,
        };
    }

    private function parsePriority(string $normalized): ?string
    {
        return match ($normalized) {
            '1', 'normal' => 'NORMAL',
            '2', 'alta', 'high' => 'HIGH',
            '3', 'urgente', 'urgent' => 'URGENT',
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
            'TECHNICAL' => 'Problema técnico',
            'PARTS_ENGINES' => 'Partes o motores',
            'OTHER' => 'Otro',
            default => $reason,
        };
    }

    private function priorityLabel(string $priority): string
    {
        return match ($priority) {
            'NORMAL' => 'Normal',
            'HIGH' => 'Alta',
            'URGENT' => 'Urgente',
            default => $priority,
        };
    }

    private function wantsHuman(string $message): bool
    {
        return str_contains($message, 'asesor') || str_contains($message, 'humano') || str_contains($message, 'agente');
    }

    private function normalize(string $message): string
    {
        return trim(str($message)->lower()->ascii()->squish()->toString());
    }
}
