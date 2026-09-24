<?php

namespace App\Services\WhatsApp\Flows;

use App\Models\EngineRequest;
use App\Models\WhatsAppConversation;

class EngineFlowHandler
{
    private const ContextKey = 'ENGINES';

    /** @return array{state:string,message:string} */
    public function start(WhatsAppConversation $conversation): array
    {
        $this->putContext($conversation, []);

        return [
            'state' => 'ENGINE_ASK_MODEL',
            'message' => "⚙️ Motores\n\n🛩️ Indícame el modelo de motor que necesitas o deseas registrar.",
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
            'ENGINE_START' => $this->start($conversation),
            'ENGINE_ASK_MODEL' => $this->captureModel($conversation, $message),
            'ENGINE_ASK_PART_NUMBER' => $this->capturePartNumber($conversation, $message),
            'ENGINE_ASK_SERIAL_NUMBER' => $this->captureSerialNumber($conversation, $message),
            'ENGINE_ASK_CONDITION' => $this->captureCondition($conversation, $normalized),
            'ENGINE_ASK_SERVICE_TYPE' => $this->captureServiceType($conversation, $normalized),
            'ENGINE_ASK_COMMENTS' => $this->captureComments($conversation, $message),
            'ENGINE_SHOW_SUMMARY', 'ENGINE_CONFIRM' => $this->handleSummaryChoice($conversation, $normalized),
            'ENGINE_EDIT_FIELD' => $this->handleEditChoice($conversation, $normalized),
            'ENGINE_EDIT_MODEL' => $this->captureModel($conversation, $message),
            'ENGINE_EDIT_PART_NUMBER' => $this->capturePartNumber($conversation, $message),
            'ENGINE_EDIT_SERIAL_NUMBER' => $this->captureSerialNumber($conversation, $message),
            'ENGINE_EDIT_CONDITION' => $this->captureCondition($conversation, $normalized),
            'ENGINE_EDIT_SERVICE_TYPE' => $this->captureServiceType($conversation, $normalized),
            'ENGINE_EDIT_COMMENTS' => $this->captureComments($conversation, $message),
            'ENGINE_COMPLETED' => ['state' => 'ENGINE_COMPLETED', 'message' => '✅ Tu solicitud de motor ya fue registrada. Escribe "menu" para volver al menú principal.'],
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
            'message' => "✅ Solicitud de motor cancelada.\n\nVolvimos al menú principal.",
        ];
    }

    /** @return array{state:string,message:string} */
    private function captureModel(WhatsAppConversation $conversation, string $message): array
    {
        $engineModel = strtoupper(trim($message));

        if ($engineModel === '') {
            return [
                'state' => $conversation->state,
                'message' => '🛩️ Indícame el modelo de motor que necesitas o deseas registrar.',
            ];
        }
        if (strlen($engineModel) > 120) {
            return [
                'state' => $conversation->state,
                'message' => '⚠️ El modelo es demasiado largo. Envíalo en máximo 120 caracteres.',
            ];
        }

        $this->mergeContext($conversation, ['engine_model' => $engineModel]);

        return [
            'state' => $this->nextStateAfterCapture($conversation, 'ENGINE_ASK_PART_NUMBER'),
            'message' => "🔧 ¿Tienes el número de parte (P/N)?\n\nSi no lo tienes, escribe \"no\".",
        ];
    }

    /** @return array{state:string,message:string} */
    private function capturePartNumber(WhatsAppConversation $conversation, string $message): array
    {
        $this->mergeContext($conversation, ['part_number' => $this->optionalCode($message)]);

        return [
            'state' => $this->nextStateAfterCapture($conversation, 'ENGINE_ASK_SERIAL_NUMBER'),
            'message' => "📄 ¿Tienes el número de serie (S/N)?\n\nSi no lo tienes, escribe \"no\".",
        ];
    }

    /** @return array{state:string,message:string} */
    private function captureSerialNumber(WhatsAppConversation $conversation, string $message): array
    {
        $this->mergeContext($conversation, ['serial_number' => $this->optionalCode($message)]);

        return [
            'state' => $this->nextStateAfterCapture($conversation, 'ENGINE_ASK_CONDITION'),
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
            'state' => $this->nextStateAfterCapture($conversation, 'ENGINE_ASK_SERVICE_TYPE'),
            'message' => $this->serviceTypePrompt(),
        ];
    }

    /** @return array{state:string,message:string} */
    private function captureServiceType(WhatsAppConversation $conversation, string $normalized): array
    {
        $serviceType = $this->parseServiceType($normalized);

        if ($serviceType === null) {
            return [
                'state' => $conversation->state,
                'message' => "⚠️ Selecciona un tipo de solicitud válido.\n\n".$this->serviceTypePrompt(),
            ];
        }

        $this->mergeContext($conversation, ['service_type' => $serviceType]);

        return [
            'state' => $this->nextStateAfterCapture($conversation, 'ENGINE_ASK_COMMENTS'),
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
            'state' => 'ENGINE_SHOW_SUMMARY',
            'message' => $this->summaryMessage($conversation),
        ];
    }

    /** @return array{state:string,message:string} */
    private function handleSummaryChoice(WhatsAppConversation $conversation, string $normalized): array
    {
        return match (true) {
            in_array($normalized, ['1', 'si', 'sí', 'confirmar', 'registrar'], true) => $this->confirm($conversation),
            in_array($normalized, ['2', 'editar'], true) => [
                'state' => 'ENGINE_EDIT_FIELD',
                'message' => "📋 ¿Qué deseas modificar?\n\n1. Modelo\n2. Número de parte\n3. Número de serie\n4. Condición\n5. Tipo de solicitud\n6. Comentarios\n7. Volver",
            ],
            in_array($normalized, ['3', 'cancelar'], true) => $this->cancel($conversation),
            default => ['state' => 'ENGINE_SHOW_SUMMARY', 'message' => $this->summaryMessage($conversation)],
        };
    }

    /** @return array{state:string,message:string} */
    private function handleEditChoice(WhatsAppConversation $conversation, string $normalized): array
    {
        return match ($normalized) {
            '1' => ['state' => 'ENGINE_EDIT_MODEL', 'message' => '🛩️ Indícame el modelo de motor.'],
            '2' => ['state' => 'ENGINE_EDIT_PART_NUMBER', 'message' => "🔧 ¿Tienes el número de parte (P/N)?\n\nSi no lo tienes, escribe \"no\"."],
            '3' => ['state' => 'ENGINE_EDIT_SERIAL_NUMBER', 'message' => "📄 ¿Tienes el número de serie (S/N)?\n\nSi no lo tienes, escribe \"no\"."],
            '4' => ['state' => 'ENGINE_EDIT_CONDITION', 'message' => $this->conditionPrompt()],
            '5' => ['state' => 'ENGINE_EDIT_SERVICE_TYPE', 'message' => $this->serviceTypePrompt()],
            '6' => ['state' => 'ENGINE_EDIT_COMMENTS', 'message' => "📄 ¿Deseas agregar algún comentario?\n\nSi no, escribe \"no\"."],
            '7' => ['state' => 'ENGINE_SHOW_SUMMARY', 'message' => $this->summaryMessage($conversation)],
            default => [
                'state' => 'ENGINE_EDIT_FIELD',
                'message' => "📋 ¿Qué deseas modificar?\n\n1. Modelo\n2. Número de parte\n3. Número de serie\n4. Condición\n5. Tipo de solicitud\n6. Comentarios\n7. Volver",
            ],
        };
    }

    /** @return array{state:string,message:string} */
    private function confirm(WhatsAppConversation $conversation): array
    {
        $context = $this->context($conversation);
        $engineRequestId = $context['engine_request_id'] ?? null;

        if (! $engineRequestId) {
            $engineRequest = EngineRequest::query()->create([
                'whats_app_conversation_id' => $conversation->id,
                'whats_app_contact_id' => $conversation->whats_app_contact_id,
                'engine_model' => $context['engine_model'],
                'part_number' => $context['part_number'] ?? null,
                'serial_number' => $context['serial_number'] ?? null,
                'condition' => $context['condition'],
                'service_type' => $context['service_type'],
                'comments' => $context['comments'] ?? null,
                'status' => EngineRequest::StatusNueva,
            ]);
            $engineRequestId = $engineRequest->id;
            $this->mergeContext($conversation, ['engine_request_id' => $engineRequestId]);
        }

        return [
            'state' => 'ENGINE_COMPLETED',
            'message' => "✅ Listo. Registramos tu solicitud de motor con ID {$engineRequestId}.\n\n👨‍💼 Un asesor podrá darle seguimiento.\n\nEscribe \"menu\" para volver al menú principal.",
        ];
    }

    private function nextStateAfterCapture(WhatsAppConversation $conversation, string $defaultState): string
    {
        return str_starts_with($conversation->state, 'ENGINE_EDIT_') ? 'ENGINE_SHOW_SUMMARY' : $defaultState;
    }

    private function conditionPrompt(): string
    {
        return "⚙️ ¿Cuál es la condición del motor?\n\n1. New\n2. Overhauled\n3. Serviceable\n4. As Removed\n5. Core\n6. Unknown";
    }

    private function serviceTypePrompt(): string
    {
        return "🔧 ¿Qué tipo de solicitud deseas registrar?\n\n1. Compra\n2. Venta\n3. Reparación\n4. Overhaul\n5. Exchange\n6. Inspección\n7. Otro";
    }

    private function summaryMessage(WhatsAppConversation $conversation): string
    {
        $context = $this->context($conversation);

        return "📋 Resumen de solicitud de motor\n\n"
            .'Modelo: '.$context['engine_model']."\n"
            .'P/N: '.($context['part_number'] ?: '—')."\n"
            .'S/N: '.($context['serial_number'] ?: '—')."\n"
            .'Condición: '.$this->conditionLabel($context['condition'])."\n"
            .'Solicitud: '.$this->serviceTypeLabel($context['service_type'])."\n"
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

    private function optionalCode(string $message): ?string
    {
        $text = trim($message);
        if (strlen($text) > 120) {
            return null;
        }

        return in_array($this->normalize($text), ['no', 'no tengo', 'desconocido', 'n/a'], true) ? null : strtoupper($text);
    }

    private function optionalText(string $message): ?string
    {
        $text = trim($message);

        return in_array($this->normalize($text), ['no', 'ninguno', 'ninguna', 'sin comentarios'], true) ? null : $text;
    }

    private function parseCondition(string $normalized): ?string
    {
        return match ($normalized) {
            '1', 'new', 'nuevo' => 'NEW',
            '2', 'overhauled', 'overhaul', 'reparado' => 'OVERHAULED',
            '3', 'serviceable', 'sv' => 'SERVICEABLE',
            '4', 'as removed', 'as-removed', 'ar' => 'AS_REMOVED',
            '5', 'core' => 'CORE',
            '6', 'unknown', 'desconocido' => 'UNKNOWN',
            default => null,
        };
    }

    private function parseServiceType(string $normalized): ?string
    {
        return match ($normalized) {
            '1', 'compra', 'comprar' => 'BUY',
            '2', 'venta', 'vender' => 'SELL',
            '3', 'reparacion', 'reparación', 'reparar' => 'REPAIR',
            '4', 'overhaul' => 'OVERHAUL',
            '5', 'exchange' => 'EXCHANGE',
            '6', 'inspeccion', 'inspección' => 'INSPECTION',
            '7', 'otro', 'otra' => 'OTHER',
            default => null,
        };
    }

    private function conditionLabel(string $condition): string
    {
        return match ($condition) {
            'NEW' => 'New',
            'OVERHAULED' => 'Overhauled',
            'SERVICEABLE' => 'Serviceable',
            'AS_REMOVED' => 'As Removed',
            'CORE' => 'Core',
            'UNKNOWN' => 'Unknown',
            default => $condition,
        };
    }

    private function serviceTypeLabel(string $serviceType): string
    {
        return match ($serviceType) {
            'BUY' => 'Compra',
            'SELL' => 'Venta',
            'REPAIR' => 'Reparación',
            'OVERHAUL' => 'Overhaul',
            'EXCHANGE' => 'Exchange',
            'INSPECTION' => 'Inspección',
            'OTHER' => 'Otro',
            default => $serviceType,
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
