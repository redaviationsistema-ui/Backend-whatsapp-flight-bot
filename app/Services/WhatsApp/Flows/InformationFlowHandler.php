<?php

namespace App\Services\WhatsApp\Flows;

use App\Models\WhatsAppConversation;

class InformationFlowHandler
{
    /** @return array{state:string,message:string} */
    public function start(WhatsAppConversation $conversation): array
    {
        $this->setActiveSection($conversation);

        return [
            'state' => 'INFO_MENU',
            'message' => $this->menuMessage(),
        ];
    }

    /** @return array{state:string,message:string} */
    public function handle(WhatsAppConversation $conversation, string $message): array
    {
        $normalized = $this->normalize($message);

        if ($this->wantsHuman($normalized)) {
            return ['state' => 'TRANSFER_TO_HUMAN', 'message' => 'Te conectaremos con un asesor para continuar con tu solicitud.'];
        }

        if ($this->wantsInfoMenu($normalized)) {
            return ['state' => 'INFO_MENU', 'message' => $this->menuMessage()];
        }

        if ($conversation->state === 'INFO_START' || $conversation->state === 'INFO_MENU') {
            return $this->handleMenuChoice($normalized);
        }

        return $this->handleTopicNavigation($normalized);
    }

    /** @return array{state:string,message:string} */
    public function cancel(): array
    {
        return [
            'state' => 'MAIN_MENU',
            'message' => 'Volvimos al menú principal.',
        ];
    }

    /** @return array{state:string,message:string} */
    private function handleMenuChoice(string $normalized): array
    {
        return match ($normalized) {
            '1' => ['state' => 'INFO_SERVICES', 'message' => $this->withNavigation($this->servicesMessage())],
            '2' => ['state' => 'INFO_FLEET', 'message' => $this->withNavigation($this->fleetMessage())],
            '3' => ['state' => 'INFO_DESTINATIONS', 'message' => $this->withNavigation($this->destinationsMessage())],
            '4' => ['state' => 'INFO_PARTS', 'message' => $this->withNavigation($this->partsMessage())],
            '5' => ['state' => 'INFO_ENGINES', 'message' => $this->withNavigation($this->enginesMessage())],
            '6' => ['state' => 'INFO_CONTACT', 'message' => $this->withNavigation($this->contactMessage())],
            '7' => ['state' => 'INFO_FAQ', 'message' => $this->withNavigation($this->faqMessage())],
            '8' => ['state' => 'MAIN_MENU', 'message' => 'Volvimos al menú principal.'],
            default => ['state' => 'INFO_MENU', 'message' => $this->menuMessage()],
        };
    }

    /** @return array{state:string,message:string} */
    private function handleTopicNavigation(string $normalized): array
    {
        return match ($normalized) {
            '1' => ['state' => 'INFO_MENU', 'message' => $this->menuMessage()],
            '2' => ['state' => 'MAIN_MENU', 'message' => 'Volvimos al menú principal.'],
            default => ['state' => 'INFO_MENU', 'message' => $this->menuMessage()],
        };
    }

    private function setActiveSection(WhatsAppConversation $conversation): void
    {
        $metadata = $conversation->metadata ?? [];
        $metadata['active_section'] = 'INFO';

        $conversation->update(['metadata' => $metadata]);
        $conversation->forceFill(['metadata' => $metadata]);
    }

    private function wantsInfoMenu(string $message): bool
    {
        return in_array($message, ['volver', 'atras', 'atrás', 'informacion', 'información'], true);
    }

    private function menuMessage(): string
    {
        return "Información\n\n¿Qué deseas consultar?\n\n1. Servicios\n2. Flota\n3. Destinos\n4. Partes y refacciones\n5. Motores\n6. Contacto\n7. Preguntas frecuentes\n8. Volver al menú principal";
    }

    private function withNavigation(string $message): string
    {
        return $message."\n\nEscribe:\n\n1. Volver a Información\n2. Menú principal";
    }

    private function servicesMessage(): string
    {
        return "Nuestros servicios incluyen:\n\n• Cotización de vuelos privados\n• Atención para partes y refacciones aeronáuticas\n• Solicitudes relacionadas con motores\n• Atención y soporte";
    }

    private function fleetMessage(): string
    {
        return "Contamos con opciones de aeronaves como:\n\n• Turbohélices\n• Jets ligeros\n• Jets medianos\n• Super midsize\n• Heavy jets\n• Ultra long range\n• Helicópteros\n\nLa disponibilidad depende de la ruta, fecha y operación.\n\nPara solicitar una cotización escribe \"menu\" y selecciona Cotización de vuelo.";
    }

    private function destinationsMessage(): string
    {
        return "Operamos cotizaciones para vuelos nacionales e internacionales, sujetos a:\n\n• disponibilidad de aeronave\n• alcance\n• condiciones del aeropuerto\n• permisos\n• restricciones operativas\n\nPara conocer opciones para una ruta específica, utiliza la sección Cotización de vuelo.";
    }

    private function partsMessage(): string
    {
        return "Podemos recibir solicitudes de partes y refacciones aeronáuticas.\n\nPara registrar una solicitud necesitaremos normalmente:\n\n• Número de parte (P/N)\n• Descripción\n• Cantidad\n• Condición requerida\n\nPara registrar una solicitud, vuelve al menú principal y selecciona Partes y refacciones.";
    }

    private function enginesMessage(): string
    {
        return "Podemos recibir solicitudes relacionadas con motores aeronáuticos para:\n\n• Compra\n• Venta\n• Reparación\n• Overhaul\n• Exchange\n• Inspección\n\nPara registrar una solicitud, vuelve al menú principal y selecciona Motores.";
    }

    private function contactMessage(): string
    {
        return "Puedes continuar por este mismo canal de WhatsApp.\n\nSi necesitas atención personalizada, escribe \"asesor\".";
    }

    private function faqMessage(): string
    {
        return "Preguntas frecuentes\n\n¿Los precios de vuelo son definitivos?\nNo. Los precios mostrados por el cotizador son aproximados y están sujetos a confirmación operativa.\n\n¿Puedo solicitar una parte aeronáutica?\nSí. Desde el menú principal selecciona Partes y refacciones.\n\n¿Puedo registrar una solicitud de motor?\nSí. Desde el menú principal selecciona Motores.\n\n¿Puedo hablar con una persona?\nSí. Escribe \"asesor\".";
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
