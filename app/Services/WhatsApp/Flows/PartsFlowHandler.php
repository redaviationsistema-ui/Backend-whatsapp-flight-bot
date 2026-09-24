<?php

namespace App\Services\WhatsApp\Flows;

use App\Models\PartRequest;
use App\Models\WhatsAppConversation;

class PartsFlowHandler
{
    private const ContextKey = 'PARTS';

    /** @return array{state:string,message:string} */
    public function start(WhatsAppConversation $conversation): array
    {
        $this->putContext($conversation, []);

        return [
            'state' => 'PARTS_ASK_PART_NUMBER',
            'message' => "🔧 Partes y refacciones\n\n🔎 Por favor indícame el número de parte (P/N) que necesitas.",
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
            'PARTS_START' => $this->start($conversation),
            'PARTS_ASK_PART_NUMBER' => $this->capturePartNumber($conversation, $message),
            'PARTS_ASK_DESCRIPTION' => $this->captureDescription($conversation, $message),
            'PARTS_ASK_QUANTITY' => $this->captureQuantity($conversation, $normalized),
            'PARTS_ASK_CONDITION' => $this->captureCondition($conversation, $normalized),
            'PARTS_ASK_COMMENTS' => $this->captureComments($conversation, $message),
            'PARTS_SHOW_SUMMARY', 'PARTS_CONFIRM' => $this->handleSummaryChoice($conversation, $normalized),
            'PARTS_EDIT_FIELD' => $this->handleEditChoice($conversation, $normalized),
            'PARTS_EDIT_PART_NUMBER' => $this->capturePartNumber($conversation, $message),
            'PARTS_EDIT_DESCRIPTION' => $this->captureDescription($conversation, $message),
            'PARTS_EDIT_QUANTITY' => $this->captureQuantity($conversation, $normalized),
            'PARTS_EDIT_CONDITION' => $this->captureCondition($conversation, $normalized),
            'PARTS_EDIT_COMMENTS' => $this->captureComments($conversation, $message),
            'PARTS_COMPLETED' => ['state' => 'PARTS_COMPLETED', 'message' => '✅ Tu solicitud de parte ya fue registrada. Escribe "menu" para volver al menú principal.'],
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
            'message' => "✅ Solicitud de parte cancelada.\n\nVolvimos al menú principal.",
        ];
    }

    /** @return array{state:string,message:string} */
    private function capturePartNumber(WhatsAppConversation $conversation, string $message): array
    {
        $partNumber = strtoupper(trim($message));

        if ($partNumber === '') {
            return [
                'state' => $conversation->state,
                'message' => '🔎 Por favor indícame el número de parte (P/N) que necesitas.',
            ];
        }
        if (strlen($partNumber) > 120) {
            return [
                'state' => $conversation->state,
                'message' => '⚠️ El P/N es demasiado largo. Envíalo en máximo 120 caracteres.',
            ];
        }

        $this->mergeContext($conversation, ['part_number' => $partNumber]);

        return [
            'state' => $this->nextStateAfterCapture($conversation, 'PARTS_ASK_DESCRIPTION'),
            'message' => "📄 ¿Tienes una descripción de la pieza?\n\nSi no la tienes, escribe \"no\".",
        ];
    }

    /** @return array{state:string,message:string} */
    private function captureDescription(WhatsAppConversation $conversation, string $message): array
    {
        $description = $this->optionalText($message);
        if ($description !== null && strlen($description) > 1000) {
            return [
                'state' => $conversation->state,
                'message' => '⚠️ La descripción es demasiado larga. Envíala en máximo 1000 caracteres.',
            ];
        }
        $this->mergeContext($conversation, ['description' => $description]);

        return [
            'state' => $this->nextStateAfterCapture($conversation, 'PARTS_ASK_QUANTITY'),
            'message' => '📦 ¿Qué cantidad necesitas?',
        ];
    }

    /** @return array{state:string,message:string} */
    private function captureQuantity(WhatsAppConversation $conversation, string $normalized): array
    {
        $quantity = $this->parseQuantity($normalized);

        if ($quantity === null) {
            return [
                'state' => $conversation->state,
                'message' => '⚠️ Indícame una cantidad válida, mínimo 1.',
            ];
        }

        $this->mergeContext($conversation, ['quantity' => $quantity]);

        return [
            'state' => $this->nextStateAfterCapture($conversation, 'PARTS_ASK_CONDITION'),
            'message' => $this->conditionPrompt(),
        ];
    }

    /** @return array{state:string,message:string} */
    private function captureCondition(WhatsAppConversation $conversation, string $normalized): array
    {
        $condition = $this->parseCondition($normalized);

        if ($condition === null) {
            return [
                'state' => $conversation->state,
                'message' => "⚠️ Selecciona una condición válida.\n\n".$this->conditionPrompt(),
            ];
        }

        $this->mergeContext($conversation, ['condition' => $condition]);

        return [
            'state' => $this->nextStateAfterCapture($conversation, 'PARTS_ASK_COMMENTS'),
            'message' => "📄 ¿Deseas agregar algún comentario?\n\nSi no, escribe \"no\".",
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
            'state' => 'PARTS_SHOW_SUMMARY',
            'message' => $this->summaryMessage($conversation),
        ];
    }

    /** @return array{state:string,message:string} */
    private function handleSummaryChoice(WhatsAppConversation $conversation, string $normalized): array
    {
        return match (true) {
            in_array($normalized, ['1', 'si', 'sí', 'confirmar', 'registrar'], true) => $this->confirm($conversation),
            in_array($normalized, ['2', 'editar'], true) => [
                'state' => 'PARTS_EDIT_FIELD',
                'message' => "📋 ¿Qué deseas modificar?\n\n1. Número de parte\n2. Descripción\n3. Cantidad\n4. Condición\n5. Comentarios\n6. Volver",
            ],
            in_array($normalized, ['3', 'cancelar'], true) => $this->cancel($conversation),
            default => ['state' => 'PARTS_SHOW_SUMMARY', 'message' => $this->summaryMessage($conversation)],
        };
    }

    /** @return array{state:string,message:string} */
    private function handleEditChoice(WhatsAppConversation $conversation, string $normalized): array
    {
        return match ($normalized) {
            '1' => ['state' => 'PARTS_EDIT_PART_NUMBER', 'message' => '🔎 Indícame el nuevo número de parte (P/N).'],
            '2' => ['state' => 'PARTS_EDIT_DESCRIPTION', 'message' => "📄 ¿Tienes una descripción de la pieza?\n\nSi no la tienes, escribe \"no\"."],
            '3' => ['state' => 'PARTS_EDIT_QUANTITY', 'message' => '📦 ¿Qué cantidad necesitas?'],
            '4' => ['state' => 'PARTS_EDIT_CONDITION', 'message' => $this->conditionPrompt()],
            '5' => ['state' => 'PARTS_EDIT_COMMENTS', 'message' => "📄 ¿Deseas agregar algún comentario?\n\nSi no, escribe \"no\"."],
            '6' => ['state' => 'PARTS_SHOW_SUMMARY', 'message' => $this->summaryMessage($conversation)],
            default => [
                'state' => 'PARTS_EDIT_FIELD',
                'message' => "📋 ¿Qué deseas modificar?\n\n1. Número de parte\n2. Descripción\n3. Cantidad\n4. Condición\n5. Comentarios\n6. Volver",
            ],
        };
    }

    /** @return array{state:string,message:string} */
    private function confirm(WhatsAppConversation $conversation): array
    {
        $context = $this->context($conversation);
        $partRequestId = $context['part_request_id'] ?? null;

        if (! $partRequestId) {
            $partRequest = PartRequest::query()->create([
                'whats_app_conversation_id' => $conversation->id,
                'whats_app_contact_id' => $conversation->whats_app_contact_id,
                'part_number' => $context['part_number'],
                'description' => $context['description'] ?? null,
                'quantity' => $context['quantity'],
                'condition' => $context['condition'],
                'comments' => $context['comments'] ?? null,
                'status' => PartRequest::StatusNueva,
            ]);
            $partRequestId = $partRequest->id;
            $this->mergeContext($conversation, ['part_request_id' => $partRequestId]);
        }

        return [
            'state' => 'PARTS_COMPLETED',
            'message' => "✅ Listo. Registramos tu solicitud de parte con ID {$partRequestId}.\n\n👨‍💼 Un asesor podrá darle seguimiento.\n\nEscribe \"menu\" para volver al menú principal.",
        ];
    }

    private function nextStateAfterCapture(WhatsAppConversation $conversation, string $defaultState): string
    {
        return str_starts_with($conversation->state, 'PARTS_EDIT_') ? 'PARTS_SHOW_SUMMARY' : $defaultState;
    }

    private function conditionPrompt(): string
    {
        return "📦 ¿Qué condición requieres?\n\n1. New\n2. New Surplus\n3. Overhauled\n4. Serviceable\n5. As Removed\n6. Exchange\n7. Cualquier condición disponible";
    }

    private function summaryMessage(WhatsAppConversation $conversation): string
    {
        $context = $this->context($conversation);

        return "📋 Resumen de solicitud de parte\n\n"
            .'P/N: '.$context['part_number']."\n"
            .'Descripción: '.($context['description'] ?: 'Sin descripción')."\n"
            .'Cantidad: '.$context['quantity']."\n"
            .'Condición: '.$this->conditionLabel($context['condition'])."\n"
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
        $metadata['active_section'] = 'PARTS';
        $metadata['section_context'] = $metadata['section_context'] ?? [];
        $metadata['section_context'][self::ContextKey] = $context;

        $conversation->update(['metadata' => $metadata]);
        $conversation->forceFill(['metadata' => $metadata]);
    }

    private function optionalText(string $message): ?string
    {
        $text = trim($message);

        return in_array($this->normalize($text), ['no', 'ninguno', 'ninguna', 'sin comentarios', 'sin descripcion', 'sin descripción'], true) ? null : $text;
    }

    private function parseQuantity(string $normalized): ?int
    {
        $words = [
            'uno' => 1,
            'una' => 1,
            'dos' => 2,
            'tres' => 3,
            'cuatro' => 4,
            'cinco' => 5,
            'seis' => 6,
            'siete' => 7,
            'ocho' => 8,
            'nueve' => 9,
            'diez' => 10,
        ];
        $quantity = filter_var($normalized, FILTER_VALIDATE_INT);

        if ($quantity === false) {
            $quantity = $words[$normalized] ?? null;
        }

        return is_int($quantity) && $quantity >= 1 ? $quantity : null;
    }

    private function parseCondition(string $normalized): ?string
    {
        return match ($normalized) {
            '1', 'new', 'nuevo', 'nueva' => 'NEW',
            '2', 'new surplus', 'surplus' => 'NEW_SURPLUS',
            '3', 'overhauled', 'reparado', 'overhaul' => 'OVERHAULED',
            '4', 'serviceable', 'serviciable' => 'SERVICEABLE',
            '5', 'as removed', 'as-removed', 'removido' => 'AS_REMOVED',
            '6', 'exchange', 'intercambio' => 'EXCHANGE',
            '7', 'any', 'cualquier condicion', 'cualquier condición', 'cualquiera' => 'ANY',
            default => null,
        };
    }

    private function conditionLabel(string $condition): string
    {
        return match ($condition) {
            'NEW' => 'New',
            'NEW_SURPLUS' => 'New Surplus',
            'OVERHAULED' => 'Overhauled',
            'SERVICEABLE' => 'Serviceable',
            'AS_REMOVED' => 'As Removed',
            'EXCHANGE' => 'Exchange',
            'ANY' => 'Cualquier condición disponible',
            default => $condition,
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
