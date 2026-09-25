<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Services\WhatsApp\Flows\AdvisorFlowHandler;
use App\Services\WhatsApp\Flows\EngineFlowHandler;
use App\Services\WhatsApp\Flows\FlightQuoteFlowHandler;
use App\Services\WhatsApp\Flows\InformationFlowHandler;
use App\Services\WhatsApp\Flows\PartsFlowHandler;
use App\Services\WhatsApp\Flows\SupportFlowHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppFlowRouter
{
    public const MainMenu = 'MAIN_MENU';

    public const ConfirmSectionChange = 'CONFIRM_SECTION_CHANGE';

    public const SectionFlight = 'FLIGHT';

    public const SectionParts = 'PARTS';

    public const SectionEngines = 'ENGINES';

    public const SectionSupport = 'SUPPORT';

    public const SectionInfo = 'INFO';

    public const SectionAdvisor = 'ADVISOR';

    public function __construct(
        private readonly FlightQuoteFlowHandler $flightQuoteFlowHandler,
        private readonly PartsFlowHandler $partsFlowHandler,
        private readonly EngineFlowHandler $engineFlowHandler,
        private readonly SupportFlowHandler $supportFlowHandler,
        private readonly InformationFlowHandler $informationFlowHandler,
        private readonly AdvisorFlowHandler $advisorFlowHandler,
    ) {}

    /** @var array<int, string> */
    private const FlightStates = [
        'ASK_ORIGIN',
        'ASK_DESTINATION',
        'ASK_DEPARTURE_DATE',
        'ASK_DEPARTURE_TIME',
        'ASK_TRIP_TYPE',
        'ASK_RETURN_DATE',
        'ASK_RETURN_TIME',
        'ASK_LEGS',
        'ASK_PASSENGERS',
        'ASK_AIRCRAFT_PREFERENCE',
        'ASK_OTHER_SERVICES',
        'ASK_NAME',
        'ASK_EMAIL',
        'ASK_COMPANY',
        'ASK_BUDGET',
        'ASK_NOTES',
        'SHOW_SUMMARY',
        'CONFIRM_REQUEST',
        'EDIT_FIELD',
        'SEARCH_FLIGHTS',
        'SHOW_RESULTS',
        'SELECT_AIRCRAFT',
        'CREATE_QUOTE',
        'FINISHED',
        'CANCELLED',
        'ASK_CATERING',
        'ASK_WIFI',
        'ASK_LUGGAGE',
        'ASK_PETS',
        'ASK_GROUND_TRANSPORT',
        'STATE_THAT_NO_LONGER_EXISTS',
    ];

    /** @return array{state:string,message:string} */
    public function route(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $normalized = $this->normalize($message);

        if ($this->shouldRecoverInvalidState($conversation)) {
            Log::warning('invalid_state_recovered', [
                'conversation_id' => $conversation->id,
                'state' => $conversation->state,
                'active_section' => $this->activeSection($conversation),
            ]);
            $this->setActiveSection($conversation, null);

            return ['state' => self::MainMenu, 'message' => $this->mainMenuMessage()];
        }

        if ($this->isMenuCommand($normalized)) {
            $this->clearPendingSectionChange($conversation);
            if ($this->activeSection($conversation) === self::SectionParts || str_starts_with($conversation->state, 'PARTS_')) {
                $this->partsFlowHandler->clearContext($conversation);
            }
            if ($this->activeSection($conversation) === self::SectionEngines || str_starts_with($conversation->state, 'ENGINE_')) {
                $this->engineFlowHandler->clearContext($conversation);
            }
            if ($this->activeSection($conversation) === self::SectionSupport || str_starts_with($conversation->state, 'SUPPORT_')) {
                $this->supportFlowHandler->clearContext($conversation);
            }
            if ($this->activeSection($conversation) === self::SectionAdvisor || str_starts_with($conversation->state, 'ADVISOR_')) {
                $this->advisorFlowHandler->clearContext($conversation);
            }
            $this->setActiveSection($conversation, null);

            return ['state' => self::MainMenu, 'message' => $this->mainMenuMessage()];
        }

        if ($this->isCancelCommand($normalized)) {
            $this->clearPendingSectionChange($conversation);

            if ($this->activeSection($conversation) === self::SectionInfo || str_starts_with($conversation->state, 'INFO_')) {
                $result = $this->informationFlowHandler->cancel();
                $this->setActiveSection($conversation, null);

                return $result;
            }

            if ($this->activeSection($conversation) === self::SectionAdvisor || str_starts_with($conversation->state, 'ADVISOR_')) {
                $result = $this->advisorFlowHandler->cancel($conversation);
                $this->setActiveSection($conversation, null);

                return $result;
            }

            if ($this->activeSection($conversation) === self::SectionParts || str_starts_with($conversation->state, 'PARTS_')) {
                $result = $this->partsFlowHandler->cancel($conversation);
                $this->setActiveSection($conversation, null);

                return $result;
            }

            if ($this->activeSection($conversation) === self::SectionEngines || str_starts_with($conversation->state, 'ENGINE_')) {
                $result = $this->engineFlowHandler->cancel($conversation);
                $this->setActiveSection($conversation, null);

                return $result;
            }

            if ($this->activeSection($conversation) === self::SectionSupport || str_starts_with($conversation->state, 'SUPPORT_')) {
                $result = $this->supportFlowHandler->cancel($conversation);
                $this->setActiveSection($conversation, null);

                return $result;
            }

            $flightRequest->update(['status' => 'cancelled']);
            $this->setActiveSection($conversation, null);

            return [
                'state' => self::MainMenu,
                'message' => "✅ Solicitud cancelada.\n\nVolvimos al menú principal.\n\n".$this->mainMenuMessage(),
            ];
        }

        $activeSection = $this->activeSection($conversation);

        if ($conversation->state === self::ConfirmSectionChange) {
            return $this->handlePendingSectionChange($conversation, $flightRequest, $normalized);
        }

        if ($conversation->state === 'START') {
            $this->setActiveSection($conversation, null);

            return ['state' => self::MainMenu, 'message' => $this->mainMenuMessage()];
        }

        if ($conversation->state === self::MainMenu && $activeSection === null) {
            return $this->routeMainMenuOption($conversation, $flightRequest, $normalized);
        }

        if ($activeSection !== null) {
            $sectionChange = $this->detectSectionChange($conversation, $normalized);

            if ($sectionChange !== null) {
                return $sectionChange;
            }
        }

        if ($activeSection === self::SectionFlight || $this->isLegacyFlightConversation($conversation, $activeSection)) {
            $this->setActiveSection($conversation, self::SectionFlight);

            return $this->flightQuoteFlowHandler->handle($conversation, $flightRequest, $message);
        }

        if ($activeSection === self::SectionParts || str_starts_with($conversation->state, 'PARTS_')) {
            $this->setActiveSection($conversation, self::SectionParts);

            return $this->partsFlowHandler->handle($conversation, $message);
        }

        if ($activeSection === self::SectionEngines || str_starts_with($conversation->state, 'ENGINE_')) {
            $this->setActiveSection($conversation, self::SectionEngines);

            return $this->engineFlowHandler->handle($conversation, $message);
        }

        if ($activeSection === self::SectionSupport || str_starts_with($conversation->state, 'SUPPORT_')) {
            $this->setActiveSection($conversation, self::SectionSupport);

            return $this->supportFlowHandler->handle($conversation, $message);
        }

        if ($activeSection === self::SectionInfo || str_starts_with($conversation->state, 'INFO_')) {
            $this->setActiveSection($conversation, self::SectionInfo);

            return $this->informationFlowHandler->handle($conversation, $message);
        }

        if ($activeSection === self::SectionAdvisor || str_starts_with($conversation->state, 'ADVISOR_')) {
            $this->setActiveSection($conversation, self::SectionAdvisor);

            return $this->advisorFlowHandler->handle($conversation, $message);
        }

        return ['state' => self::MainMenu, 'message' => $this->mainMenuMessage()];
    }

    public function mainMenuMessage(): string
    {
        return "👋 ¡Hola! Bienvenido a *Sky Group Aviation* ✈️\n\n¿En qué podemos ayudarte?\n\n✈️ 1️⃣ Cotización de vuelo\n🔧 2️⃣ Partes y refacciones\n⚙️ 3️⃣ Motores\n🎧 4️⃣ Atención / soporte\nℹ️ 5️⃣ Información\n👨‍💼 6️⃣ Hablar con un asesor\n\n👉 Responde con el número de la opción.";
    }

    private function routeMainMenuOption(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $normalized): array
    {
        if ($this->isFlightEntry($normalized)) {
            $this->setActiveSection($conversation, self::SectionFlight);
            $conversation->forceFill(['state' => 'START']);

            return $this->flightQuoteFlowHandler->handle($conversation, $flightRequest, 'cotizar vuelo');
        }

        if ($this->isPartsEntry($normalized)) {
            $this->setActiveSection($conversation, self::SectionParts);
            $conversation->forceFill(['state' => 'PARTS_START']);

            return $this->partsFlowHandler->start($conversation);
        }

        if ($this->isEnginesEntry($normalized)) {
            $this->setActiveSection($conversation, self::SectionEngines);
            $conversation->forceFill(['state' => 'ENGINE_START']);

            return $this->engineFlowHandler->start($conversation);
        }

        if ($this->isSupportEntry($normalized)) {
            $this->setActiveSection($conversation, self::SectionSupport);
            $conversation->forceFill(['state' => 'SUPPORT_START']);

            return $this->supportFlowHandler->start($conversation);
        }

        if ($this->isInfoEntry($normalized)) {
            $this->setActiveSection($conversation, self::SectionInfo);
            $conversation->forceFill(['state' => 'INFO_START']);

            return $this->informationFlowHandler->start($conversation);
        }

        if ($this->isAdvisorEntry($normalized)) {
            $this->setActiveSection($conversation, self::SectionAdvisor);
            $conversation->forceFill(['state' => 'ADVISOR_START']);

            return $this->advisorFlowHandler->start($conversation);
        }

        return ['state' => self::MainMenu, 'message' => $this->mainMenuMessage()];
    }

    private function activeSection(WhatsAppConversation $conversation): ?string
    {
        $section = $this->metadata($conversation)['active_section'] ?? null;

        return is_string($section) && $section !== '' ? $section : null;
    }

    private function setActiveSection(WhatsAppConversation $conversation, ?string $section): void
    {
        $metadata = $this->metadata($conversation);

        if ($section === null) {
            unset($metadata['active_section']);
        } else {
            $metadata['active_section'] = $section;
        }

        $conversation->update(['metadata' => $metadata === [] ? null : $metadata]);
        $conversation->forceFill(['metadata' => $metadata === [] ? null : $metadata]);
    }

    /** @return array{state:string,message:string}|null */
    private function detectSectionChange(WhatsAppConversation $conversation, string $normalized): ?array
    {
        if ($this->isAdvisorEntry($normalized)) {
            return null;
        }

        if ($this->isNumericReply($normalized)) {
            return null;
        }

        $currentSection = $this->activeSection($conversation);
        $requestedSection = $this->detectRequestedSection($normalized);

        if ($currentSection === null || $requestedSection === null || $requestedSection === $currentSection) {
            return null;
        }

        $metadata = $this->metadata($conversation);
        $metadata['pending_section_change'] = [
            'from_section' => $currentSection,
            'from_state' => $conversation->state,
            'to_section' => $requestedSection,
            'requested_at' => now()->toJSON(),
        ];

        $conversation->update([
            'state' => self::ConfirmSectionChange,
            'metadata' => $metadata,
        ]);
        $conversation->forceFill([
            'state' => self::ConfirmSectionChange,
            'metadata' => $metadata,
        ]);

        Log::info('section_change_detected', [
            'conversation_id' => $conversation->id,
            'from_section' => $currentSection,
            'from_state' => $metadata['pending_section_change']['from_state'],
            'to_section' => $requestedSection,
        ]);

        return [
            'state' => self::ConfirmSectionChange,
            'message' => $this->sectionChangePrompt($currentSection, $requestedSection),
        ];
    }

    /** @return array{state:string,message:string} */
    private function handlePendingSectionChange(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $normalized): array
    {
        $pending = $this->metadata($conversation)['pending_section_change'] ?? null;

        if (! is_array($pending)) {
            return ['state' => self::MainMenu, 'message' => $this->mainMenuMessage()];
        }

        if (in_array($normalized, ['1', 'si', 'sí', 'cambiar'], true)) {
            return $this->confirmSectionChange($conversation, $flightRequest, $pending);
        }

        if (in_array($normalized, ['2', 'no', 'continuar'], true)) {
            return $this->rejectSectionChange($conversation, $pending);
        }

        if ($this->isAdvisorEntry($normalized)) {
            $this->clearPendingSectionChange($conversation);

            return ['state' => 'TRANSFER_TO_HUMAN', 'message' => '👨‍💼 Te conectaremos con un asesor para continuar con tu solicitud.'];
        }

        return [
            'state' => self::ConfirmSectionChange,
            'message' => "⚠️ Por favor selecciona:\n\n1. Sí, cambiar\n2. No, continuar",
        ];
    }

    /**
     * @param  array{from_section?:string,from_state?:string,to_section?:string}  $pending
     * @return array{state:string,message:string}
     */
    private function confirmSectionChange(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, array $pending): array
    {
        $fromSection = (string) ($pending['from_section'] ?? '');
        $fromState = (string) ($pending['from_state'] ?? '');
        $toSection = (string) ($pending['to_section'] ?? '');

        $this->cleanupSectionBeingAbandoned($conversation, $flightRequest, $fromSection, $fromState);
        $this->clearPendingSectionChange($conversation);

        Log::info('section_change_confirmed', [
            'conversation_id' => $conversation->id,
            'from_section' => $fromSection,
            'from_state' => $fromState,
            'to_section' => $toSection,
        ]);

        return $this->startSection($conversation, $flightRequest, $toSection);
    }

    /**
     * @param  array{from_section?:string,from_state?:string,to_section?:string}  $pending
     * @return array{state:string,message:string}
     */
    private function rejectSectionChange(WhatsAppConversation $conversation, array $pending): array
    {
        $fromSection = (string) ($pending['from_section'] ?? '');
        $fromState = (string) ($pending['from_state'] ?? self::MainMenu);
        $toSection = (string) ($pending['to_section'] ?? '');
        $metadata = $this->metadata($conversation);
        unset($metadata['pending_section_change']);
        $metadata['active_section'] = $fromSection;

        $conversation->update([
            'state' => $fromState,
            'metadata' => $metadata,
        ]);
        $conversation->forceFill([
            'state' => $fromState,
            'metadata' => $metadata,
        ]);

        Log::info('section_change_rejected', [
            'conversation_id' => $conversation->id,
            'from_section' => $fromSection,
            'from_state' => $fromState,
            'to_section' => $toSection,
        ]);

        return [
            'state' => $fromState,
            'message' => '✅ Continuamos con '.$this->sectionLabel($fromSection).'.',
        ];
    }

    /** @return array{state:string,message:string} */
    private function startSection(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $section): array
    {
        return match ($section) {
            self::SectionFlight => $this->startFlightSection($conversation, $flightRequest),
            self::SectionParts => $this->startPartsSection($conversation),
            self::SectionEngines => $this->startEnginesSection($conversation),
            self::SectionSupport => $this->startSupportSection($conversation),
            self::SectionInfo => $this->startInfoSection($conversation),
            self::SectionAdvisor => $this->startAdvisorSection($conversation),
            default => ['state' => self::MainMenu, 'message' => $this->mainMenuMessage()],
        };
    }

    /** @return array{state:string,message:string} */
    private function startFlightSection(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest): array
    {
        $this->setActiveSection($conversation, self::SectionFlight);
        $conversation->forceFill(['state' => 'START']);

        return $this->flightQuoteFlowHandler->handle($conversation, $flightRequest, 'cotizar vuelo');
    }

    /** @return array{state:string,message:string} */
    private function startPartsSection(WhatsAppConversation $conversation): array
    {
        $this->setActiveSection($conversation, self::SectionParts);
        $conversation->forceFill(['state' => 'PARTS_START']);

        return $this->partsFlowHandler->start($conversation);
    }

    /** @return array{state:string,message:string} */
    private function startEnginesSection(WhatsAppConversation $conversation): array
    {
        $this->setActiveSection($conversation, self::SectionEngines);
        $conversation->forceFill(['state' => 'ENGINE_START']);

        return $this->engineFlowHandler->start($conversation);
    }

    /** @return array{state:string,message:string} */
    private function startSupportSection(WhatsAppConversation $conversation): array
    {
        $this->setActiveSection($conversation, self::SectionSupport);
        $conversation->forceFill(['state' => 'SUPPORT_START']);

        return $this->supportFlowHandler->start($conversation);
    }

    /** @return array{state:string,message:string} */
    private function startInfoSection(WhatsAppConversation $conversation): array
    {
        $this->setActiveSection($conversation, self::SectionInfo);
        $conversation->forceFill(['state' => 'INFO_START']);

        return $this->informationFlowHandler->start($conversation);
    }

    /** @return array{state:string,message:string} */
    private function startAdvisorSection(WhatsAppConversation $conversation): array
    {
        $this->setActiveSection($conversation, self::SectionAdvisor);
        $conversation->forceFill(['state' => 'ADVISOR_START']);

        return $this->advisorFlowHandler->start($conversation);
    }

    private function cleanupSectionBeingAbandoned(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $section, string $state): void
    {
        match ($section) {
            self::SectionFlight => $flightRequest->update(['status' => 'cancelled']),
            self::SectionParts => str_ends_with($state, '_COMPLETED') ? null : $this->partsFlowHandler->clearContext($conversation),
            self::SectionEngines => str_ends_with($state, '_COMPLETED') ? null : $this->engineFlowHandler->clearContext($conversation),
            self::SectionSupport => str_ends_with($state, '_COMPLETED') ? null : $this->supportFlowHandler->clearContext($conversation),
            self::SectionAdvisor => str_ends_with($state, '_COMPLETED') ? null : $this->advisorFlowHandler->clearContext($conversation),
            default => null,
        };
    }

    private function clearPendingSectionChange(WhatsAppConversation $conversation): void
    {
        $metadata = $this->metadata($conversation);
        unset($metadata['pending_section_change']);

        $conversation->update(['metadata' => $metadata === [] ? null : $metadata]);
        $conversation->forceFill(['metadata' => $metadata === [] ? null : $metadata]);
    }

    private function sectionChangePrompt(string $fromSection, string $toSection): string
    {
        return '⚠️ Tienes un proceso de '.$this->sectionLabel($fromSection)." en curso.\n\n"
            .'¿Deseas cambiar a '.$this->sectionLabel($toSection)."?\n\n"
            ."1. Sí, cambiar\n2. No, continuar donde estaba";
    }

    private function sectionLabel(string $section): string
    {
        return match ($section) {
            self::SectionFlight => 'Cotización de vuelo',
            self::SectionParts => 'Partes y refacciones',
            self::SectionEngines => 'Motores',
            self::SectionSupport => 'Atención / soporte',
            self::SectionInfo => 'Información',
            self::SectionAdvisor => 'Hablar con asesor',
            default => 'este proceso',
        };
    }

    private function isLegacyFlightConversation(WhatsAppConversation $conversation, ?string $activeSection): bool
    {
        return $activeSection === null && in_array($conversation->state, self::FlightStates, true);
    }

    private function isMenuCommand(string $message): bool
    {
        return in_array($message, ['menu', 'menú', 'inicio'], true);
    }

    private function isCancelCommand(string $message): bool
    {
        return $message === 'cancelar';
    }

    private function isFlightEntry(string $message): bool
    {
        return $message === '1'
            || str_contains($message, 'cotizacion')
            || str_contains($message, 'cotizar')
            || str_contains($message, 'vuelo');
    }

    private function isPartsEntry(string $message): bool
    {
        return $message === '2'
            || str_contains($message, 'partes')
            || str_contains($message, 'parte')
            || str_contains($message, 'refaccion')
            || str_contains($message, 'refacciones')
            || str_contains($message, 'necesito una pieza')
            || str_contains($message, 'busco una pieza')
            || str_contains($message, 'quiero cotizar una parte');
    }

    private function isEnginesEntry(string $message): bool
    {
        return $message === '3'
            || str_contains($message, 'motor')
            || str_contains($message, 'motores')
            || str_contains($message, 'necesito un motor')
            || str_contains($message, 'busco un motor')
            || str_contains($message, 'quiero vender un motor')
            || str_contains($message, 'quiero reparar un motor')
            || str_contains($message, 'exchange de motor');
    }

    private function isSupportEntry(string $message): bool
    {
        return $message === '4'
            || str_contains($message, 'soporte')
            || str_contains($message, 'atencion')
            || str_contains($message, 'ayuda')
            || str_contains($message, 'tengo un problema')
            || str_contains($message, 'necesito ayuda')
            || str_contains($message, 'problema')
            || str_contains($message, 'soporte tecnico');
    }

    private function isInfoEntry(string $message): bool
    {
        return $message === '5'
            || str_contains($message, 'informacion')
            || $message === 'info'
            || str_contains($message, 'servicios')
            || str_contains($message, 'quiero informacion')
            || str_contains($message, 'informacion de la empresa');
    }

    private function isAdvisorEntry(string $message): bool
    {
        return $message === '6'
            || str_contains($message, 'asesor')
            || str_contains($message, 'humano')
            || str_contains($message, 'persona')
            || str_contains($message, 'ejecutivo')
            || str_contains($message, 'agente')
            || str_contains($message, 'hablar con alguien')
            || str_contains($message, 'quiero hablar con asesor');
    }

    private function detectRequestedSection(string $message): ?string
    {
        if (! $this->isExplicitSectionChangeIntent($message)) {
            return null;
        }

        if ($this->matchesFlightIntent($message)) {
            return self::SectionFlight;
        }

        if ($this->matchesPartsIntent($message)) {
            return self::SectionParts;
        }

        if ($this->matchesEnginesIntent($message)) {
            return self::SectionEngines;
        }

        if ($this->matchesSupportIntent($message)) {
            return self::SectionSupport;
        }

        if ($this->matchesInfoIntent($message)) {
            return self::SectionInfo;
        }

        if ($this->isAdvisorEntry($message)) {
            return self::SectionAdvisor;
        }

        return null;
    }

    private function isExplicitSectionChangeIntent(string $message): bool
    {
        return str_contains($message, 'cambiar a')
            || str_contains($message, 'cambia a')
            || str_contains($message, 'ir a')
            || str_contains($message, 'quiero ir a')
            || str_contains($message, 'regresar a')
            || str_contains($message, 'volver a')
            || str_contains($message, 'quiero cotizar')
            || str_contains($message, 'cotizar vuelo')
            || str_contains($message, 'cotizacion de vuelo')
            || str_contains($message, 'quiero viajar')
            || str_contains($message, 'necesito un avion')
            || str_contains($message, 'necesito un avión')
            || str_contains($message, 'charter')
            || str_contains($message, 'quiero informacion')
            || str_contains($message, 'quiero información')
            || str_contains($message, 'quiero hablar con asesor')
            || str_contains($message, 'hablar con alguien')
            || str_contains($message, 'overhaul de motor')
            || str_contains($message, 'exchange de motor');
    }

    private function matchesFlightIntent(string $message): bool
    {
        return str_contains($message, 'vuelo')
            || str_contains($message, 'vuelos')
            || str_contains($message, 'viajar')
            || str_contains($message, 'avion')
            || str_contains($message, 'avión')
            || str_contains($message, 'charter');
    }

    private function matchesPartsIntent(string $message): bool
    {
        return str_contains($message, 'parte')
            || str_contains($message, 'partes')
            || str_contains($message, 'pieza')
            || str_contains($message, 'piezas')
            || str_contains($message, 'refaccion')
            || str_contains($message, 'refacciones')
            || str_contains($message, 'p/n')
            || str_contains($message, 'part number');
    }

    private function matchesEnginesIntent(string $message): bool
    {
        return str_contains($message, 'motor')
            || str_contains($message, 'motores')
            || str_contains($message, 'engine')
            || str_contains($message, 'overhaul de motor')
            || str_contains($message, 'exchange de motor');
    }

    private function matchesSupportIntent(string $message): bool
    {
        return str_contains($message, 'soporte')
            || str_contains($message, 'ayuda')
            || str_contains($message, 'problema')
            || str_contains($message, 'atencion')
            || str_contains($message, 'atención');
    }

    private function matchesInfoIntent(string $message): bool
    {
        return str_contains($message, 'informacion')
            || str_contains($message, 'información')
            || $message === 'info'
            || str_contains($message, 'servicios');
    }

    private function isNumericReply(string $message): bool
    {
        return (bool) preg_match('/^\d+$/', $message);
    }

    private function isGreeting(string $message): bool
    {
        return in_array($message, ['hola', 'buen dia', 'buenos dias', 'buenas tardes', 'buenas noches', 'hello', 'hi'], true);
    }

    private function normalize(string $message): string
    {
        return trim(Str::of($message)->lower()->ascii()->squish()->toString());
    }

    /** @return array<string, mixed> */
    private function metadata(WhatsAppConversation $conversation): array
    {
        return is_array($conversation->metadata) ? $conversation->metadata : [];
    }

    private function shouldRecoverInvalidState(WhatsAppConversation $conversation): bool
    {
        if ($conversation->state === 'TRANSFER_TO_HUMAN' || $this->activeSection($conversation) !== null) {
            return false;
        }

        return ! in_array($conversation->state, [
            'START',
            self::MainMenu,
            self::ConfirmSectionChange,
            ...self::FlightStates,
        ], true)
            && ! str_starts_with($conversation->state, 'PARTS_')
            && ! str_starts_with($conversation->state, 'ENGINE_')
            && ! str_starts_with($conversation->state, 'SUPPORT_')
            && ! str_starts_with($conversation->state, 'INFO_')
            && ! str_starts_with($conversation->state, 'ADVISOR_');
    }
}
