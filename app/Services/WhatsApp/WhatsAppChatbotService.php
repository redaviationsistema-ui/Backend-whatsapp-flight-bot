<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFlightRequest;
use App\Services\Flights\FlightApiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class WhatsAppChatbotService
{
    /** @var array<string, array{field:string, label:string, prompt:string, type:string}> */
    private const QUESTIONS = [
        'ASK_ORIGIN' => ['field' => 'origin', 'label' => 'Origen', 'prompt' => '¿Desde qué ciudad o aeropuerto deseas salir?', 'type' => 'location'],
        'ASK_DESTINATION' => ['field' => 'destination', 'label' => 'Destino', 'prompt' => '¿Cuál es el destino?', 'type' => 'location'],
        'ASK_DEPARTURE_DATE' => ['field' => 'departure_date', 'label' => 'Fecha de salida', 'prompt' => '¿Qué día quieres salir?', 'type' => 'date'],
        'ASK_DEPARTURE_TIME' => ['field' => 'departure_time', 'label' => 'Hora de salida', 'prompt' => '¿A qué hora te gustaría salir?', 'type' => 'time'],
        'ASK_TIME_FLEXIBILITY' => ['field' => 'is_time_flexible', 'label' => 'Horario flexible', 'prompt' => '¿Tu horario es flexible? Sí o no.', 'type' => 'boolean'],
        'ASK_PASSENGERS' => ['field' => 'passengers', 'label' => 'Pasajeros', 'prompt' => '¿Cuántas personas viajan?', 'type' => 'passengers'],
        'ASK_TRIP_TYPE' => ['field' => 'trip_type', 'label' => 'Viaje', 'prompt' => '¿Será sólo ida, ida y vuelta o multidestino?', 'type' => 'trip'],
        'ASK_RETURN_DATE' => ['field' => 'return_date', 'label' => 'Fecha de regreso', 'prompt' => '¿Qué día quieres regresar?', 'type' => 'date'],
        'ASK_RETURN_TIME' => ['field' => 'return_time', 'label' => 'Hora de regreso', 'prompt' => '¿A qué hora te gustaría regresar?', 'type' => 'time'],
        'ASK_LEGS' => ['field' => 'legs', 'label' => 'Tramos adicionales', 'prompt' => '¿Quieres agregar alguna escala o parada adicional?', 'type' => 'legs'],
        'ASK_LUGGAGE' => ['field' => 'luggage_count', 'label' => 'Cantidad de equipaje', 'prompt' => '¿Cuántas piezas de equipaje llevarán?', 'type' => 'count'],
        'ASK_LUGGAGE_DESCRIPTION' => ['field' => 'luggage_description', 'label' => 'Equipaje', 'prompt' => '¿Cómo es el equipaje?', 'type' => 'text'],
        'ASK_SPECIAL_LUGGAGE' => ['field' => 'special_luggage', 'label' => 'Equipaje especial', 'prompt' => '¿Llevas equipaje especial?', 'type' => 'text'],
        'ASK_PETS' => ['field' => 'has_pets', 'label' => 'Mascotas', 'prompt' => '¿Viajan mascotas?', 'type' => 'pets'],
        'ASK_AIRCRAFT_PREFERENCE' => ['field' => 'aircraft_preference', 'label' => 'Aeronave', 'prompt' => '¿Tienes preferencia de aeronave?', 'type' => 'text'],
        'ASK_ALTERNATE_AIRPORTS' => ['field' => 'allow_alternate_airports', 'label' => 'Aeropuertos alternos', 'prompt' => '¿Aceptas aeropuertos alternos? Sí o no.', 'type' => 'boolean'],
        'ASK_CATERING' => ['field' => 'catering_required', 'label' => 'Catering', 'prompt' => '¿Requieres catering? Sí o no.', 'type' => 'boolean'],
        'ASK_GROUND_TRANSPORT' => ['field' => 'ground_transport_required', 'label' => 'Transporte terrestre', 'prompt' => '¿Requieres transporte terrestre? Sí o no.', 'type' => 'boolean'],
        'ASK_WIFI' => ['field' => 'wifi_required', 'label' => 'Wi-Fi', 'prompt' => '¿Requieres Wi-Fi? Sí o no.', 'type' => 'boolean'],
        'ASK_OTHER_SERVICES' => ['field' => 'other_services', 'label' => 'Otros servicios', 'prompt' => '¿Necesitas otros servicios o escalas técnicas?', 'type' => 'text'],
        'ASK_NAME' => ['field' => 'client_name', 'label' => 'Nombre', 'prompt' => '¿Cuál es tu nombre completo?', 'type' => 'text'],
        'ASK_EMAIL' => ['field' => 'client_email', 'label' => 'Correo', 'prompt' => '¿Cuál es tu correo electrónico?', 'type' => 'email'],
        'ASK_COMPANY' => ['field' => 'company', 'label' => 'Empresa', 'prompt' => '¿Cotizas para alguna empresa?', 'type' => 'optional'],
        'ASK_BUDGET' => ['field' => 'budget', 'label' => 'Presupuesto', 'prompt' => '¿Tienes un presupuesto aproximado?', 'type' => 'optional'],
        'ASK_NOTES' => ['field' => 'notes', 'label' => 'Observaciones', 'prompt' => '¿Algo más que debamos saber?', 'type' => 'text'],
    ];

    public function __construct(
        private readonly FlightApiService $flightApiService,
        private readonly WhatsAppConversationService $conversationService,
    ) {}

    /** @return array{state:string,message:string} */
    public function handleIncomingMessage(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $normalized = $this->normalize($message);
        if ($this->wantsHuman($normalized)) {
            return ['state' => 'TRANSFER_TO_HUMAN', 'message' => 'Te conectaremos con un asesor para continuar con tu solicitud.'];
        }
        if ($conversation->state === 'TRANSFER_TO_HUMAN') {
            return ['state' => 'TRANSFER_TO_HUMAN', 'message' => ''];
        }
        if ($this->wantsNewQuote($normalized)) {
            $flightRequest = $this->conversationService->resetFlightRequest($conversation);
            $conversation->update(['metadata' => null]);

            return ['state' => 'ASK_ORIGIN', 'message' => 'Claro, iniciemos una nueva cotización. ¿Desde qué ciudad o aeropuerto deseas salir?'];
        }
        if ($this->asksForStatus($normalized)) {
            return $this->quoteStatus($conversation, $flightRequest);
        }
        if ($this->isGreeting($normalized)) {
            $this->conversationService->resetFlightRequest($conversation);
            $conversation->update(['metadata' => null]);

            return ['state' => 'ASK_ORIGIN', 'message' => "¡Hola! Bienvenido a Sky Group Aviation ✈️\n¿Desde qué ciudad o aeropuerto deseas salir?"];
        }
        if ($this->isHelpRequest($normalized)) {
            return $this->help($conversation, $flightRequest);
        }
        if ($directCorrection = $this->directCorrection($conversation, $flightRequest, $message, $normalized)) {
            return $directCorrection;
        }
        if ($correction = $this->correction($conversation, $flightRequest, $normalized)) {
            return $correction;
        }
        if ($this->startsQuoteIntent($normalized)) {
            return $this->continueFromMissing($conversation, $flightRequest);
        }
        if (! $flightRequest->confirmed_at && in_array($conversation->state, ['SEARCH_FLIGHTS', 'SHOW_RESULTS', 'SELECT_AIRCRAFT', 'CREATE_QUOTE'], true)) {
            return $this->showSummary($flightRequest);
        }
        if (isset(self::QUESTIONS[$conversation->state])) {
            return $this->captureAnswer($conversation, $flightRequest, trim($message));
        }

        return match ($conversation->state) {
            'START' => ['state' => 'ASK_ORIGIN', 'message' => "¡Hola! Bienvenido a Sky Group Aviation ✈️\n¿Desde qué ciudad o aeropuerto deseas salir?"],
            'SHOW_SUMMARY', 'CONFIRM_REQUEST' => $this->confirmRequest($conversation, $flightRequest, $normalized),
            'EDIT_FIELD' => $this->chooseEdit($conversation, $flightRequest, $normalized),
            'SEARCH_FLIGHTS' => $this->searchFlights($flightRequest),
            'SHOW_RESULTS' => $this->showResults($flightRequest),
            'SELECT_AIRCRAFT' => $this->selectAircraft($flightRequest, $message),
            'CREATE_QUOTE' => $this->createQuote($flightRequest),
            'FINISHED' => ['state' => 'FINISHED', 'message' => 'Tu cotización ya fue registrada. Escribe asesor si necesitas hacer cambios.'],
            'CANCELLED' => ['state' => 'CANCELLED', 'message' => 'Esta solicitud fue cancelada.'],
            default => $this->question('ASK_ORIGIN'),
        };
    }

    private function wantsHuman(string $message): bool
    {
        return in_array($message, ['asesor', 'humano', 'agente', 'transferir'], true)
            || str_contains($message, 'hablar con alguien')
            || str_contains($message, 'hablar con un asesor')
            || str_contains($message, 'quiero hablar')
            || str_contains($message, 'llamame')
            || str_contains($message, 'me llaman');
    }

    private function wantsNewQuote(string $message): bool
    {
        return str_contains($message, 'nueva cotizacion')
            || str_contains($message, 'nuevo vuelo')
            || str_contains($message, 'otra cotizacion')
            || str_contains($message, 'otro vuelo')
            || str_contains($message, 'cotizar otro');
    }

    private function asksForStatus(string $message): bool
    {
        return str_contains($message, 'estatus')
            || str_contains($message, 'estado')
            || str_contains($message, 'seguimiento')
            || str_contains($message, 'como va')
            || str_contains($message, 'mi cotizacion')
            || str_contains($message, 'mi solicitud')
            || str_contains($message, 'reserva');
    }

    private function startsQuoteIntent(string $message): bool
    {
        return str_contains($message, 'cotizar')
            || str_contains($message, 'disponibilidad')
            || str_contains($message, 'precio')
            || str_contains($message, 'cuanto cuesta')
            || str_contains($message, 'informacion');
    }

    private function isGreeting(string $message): bool
    {
        return in_array($message, ['hola', 'buen dia', 'buenos dias', 'buenas tardes', 'buenas noches'], true);
    }

    private function isHelpRequest(string $message): bool
    {
        return in_array($message, ['ayuda', 'ejemplo', 'dame un ejemplo', 'no entiendo', 'no entendi', 'como', 'como?', 'como respondo', 'como lo quieres', 'que pongo'], true);
    }

    private function normalize(string $message): string
    {
        return Str::of($message)->lower()->ascii()->trim()->squish()->toString();
    }

    /** @return array{state:string,message:string} */
    private function quoteStatus(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest): array
    {
        if (! $this->hasCapturedData($flightRequest)) {
            return ['state' => $conversation->state, 'message' => 'Aún no tengo una cotización enviada para este número. Puedo ayudarte a iniciar una.'];
        }

        if ($flightRequest->status === 'collecting') {
            return $this->continueFromMissing($conversation, $flightRequest, 'Tu solicitud quedó incompleta. Sigamos desde aquí.');
        }

        if ($flightRequest->status === 'cancelled') {
            return ['state' => $conversation->state, 'message' => 'Esta solicitud aparece cancelada. Puedo ayudarte con una nueva cotización.'];
        }

        if ($flightRequest->status === 'quoted') {
            $reference = $flightRequest->quote_reference ?: $flightRequest->accepted_quote_id;

            return [
                'state' => $conversation->state,
                'message' => $reference
                    ? "Tu cotización ya fue registrada con referencia {$reference}."
                    : 'Tu cotización ya fue registrada.',
            ];
        }

        return ['state' => $conversation->state, 'message' => 'Tu solicitud está siendo revisada. En cuanto tengamos actualización, te contactamos.'];
    }

    /** @return array{state:string,message:string} */
    private function continueFromMissing(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, ?string $prefix = null): array
    {
        $next = $this->nextMissingState($flightRequest);
        if (! $next) {
            return $this->showSummary($flightRequest);
        }

        $message = $this->prompt($next, $flightRequest);

        return ['state' => $next, 'message' => $prefix ? $prefix."\n".$message : $message];
    }

    private function nextMissingState(WhatsAppFlightRequest $flightRequest): ?string
    {
        foreach (self::QUESTIONS as $state => $question) {
            if (in_array($question['field'], ['company', 'budget'], true)) {
                continue;
            }
            if (in_array($question['field'], ['return_date', 'return_time'], true) && $flightRequest->trip_type !== 'ROUND_TRIP') {
                continue;
            }
            if ($question['field'] === 'legs' && $flightRequest->trip_type !== 'MULTI_CITY') {
                continue;
            }
            if ($flightRequest->{$question['field']} === null || $flightRequest->{$question['field']} === '') {
                return $state;
            }
        }

        return null;
    }

    private function hasCapturedData(WhatsAppFlightRequest $flightRequest): bool
    {
        foreach (['origin', 'destination', 'departure_date', 'departure_time', 'passengers', 'trip_type', 'client_name', 'client_email'] as $field) {
            if ($flightRequest->{$field}) {
                return true;
            }
        }

        return false;
    }

    /** @return array{state:string,message:string} */
    private function question(string $state, ?string $error = null, ?WhatsAppFlightRequest $flightRequest = null): array
    {
        return ['state' => $state, 'message' => ($error ? $error."\n" : '').$this->prompt($state, $flightRequest)];
    }

    private function prompt(string $state, ?WhatsAppFlightRequest $flightRequest = null): string
    {
        if (! $flightRequest) {
            return self::QUESTIONS[$state]['prompt'];
        }

        return match ($state) {
            'ASK_DESTINATION' => $flightRequest->origin
                ? "Perfecto, saliendo de {$flightRequest->origin}. ¿A dónde te gustaría volar?"
                : self::QUESTIONS[$state]['prompt'],
            'ASK_DEPARTURE_DATE' => $flightRequest->origin && $flightRequest->destination
                ? "Perfecto, {$flightRequest->origin} → {$flightRequest->destination}. ¿Para qué día tienes pensado viajar?"
                : self::QUESTIONS[$state]['prompt'],
            'ASK_LUGGAGE' => $flightRequest->passengers
                ? "Perfecto, serían {$flightRequest->passengers} pasajeros. ¿Llevarán equipaje?"
                : self::QUESTIONS[$state]['prompt'],
            default => self::QUESTIONS[$state]['prompt'],
        };
    }

    /** @return array{state:string,message:string} */
    private function help(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest): array
    {
        $state = $conversation->state;
        if (! isset(self::QUESTIONS[$state])) {
            return ['state' => $state, 'message' => 'Escribe continuar para seguir.'];
        }

        $message = match (self::QUESTIONS[$state]['type']) {
            'date' => 'Puedes decir mañana, el próximo viernes o 2026-10-02.',
            'time' => 'Puedes decir 2 pm, 14:30 o por la mañana.',
            'passengers' => 'Puedes decir 4 o somos 4.',
            'count' => 'Puedes decir 2, ninguno o no.',
            'boolean' => 'Responde sí o no.',
            'pets' => 'Puedes decir no, o sí y agregar detalles.',
            'trip' => 'Puedes decir sólo ida, ida y vuelta o multidestino.',
            'legs' => $this->legHelp($conversation, $flightRequest),
            'email' => 'Escribe tu correo, por ejemplo nombre@correo.com.',
            'optional' => 'Puedes responder el dato o decir no.',
            default => 'Respóndeme con tus palabras.',
        };

        return ['state' => $state, 'message' => $message];
    }

    private function legHelp(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest): string
    {
        $metadata = $conversation->metadata ?? [];
        $draft = $metadata['leg_capture']['draft'] ?? [];
        $from = $this->lastLegDestination($flightRequest);

        return match ($metadata['leg_capture']['step'] ?? null) {
            'date' => 'Puedes decir mañana, el próximo viernes o 2026-10-02.',
            'time' => 'Puedes decir 2 pm, 14:30 o por la mañana.',
            default => $from
                ? "Claro. Supongamos que después de {$from} quieres continuar a otra ciudad. Dime primero sólo el destino y seguimos paso a paso."
                : 'Dime primero el siguiente destino y seguimos paso a paso.',
        };
    }

    /** @return array{state:string,message:string}|null */
    private function directCorrection(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message, string $normalized): ?array
    {
        if ($conversation->state !== 'ASK_PASSENGERS' && preg_match('/\bsomos\s+(\d{1,2})\b/', $normalized, $match)) {
            $passengers = (int) $match[1];
            if ($passengers >= 1 && $passengers <= 99) {
                $flightRequest->update(['passengers' => $passengers]);

                return $this->continueFromMissing($conversation, $flightRequest, 'Perfecto, actualicé los pasajeros.');
            }
        }

        if (str_contains($normalized, 'sin mascotas')) {
            $flightRequest->update(['has_pets' => false, 'pets_description' => null]);

            return $this->continueFromMissing($conversation, $flightRequest, 'Perfecto, lo dejo sin mascotas.');
        }

        if (preg_match('/(?:cambialo|cambia|cambiar|mejor|era)\s+(?:para\s+)?(.+)/', $normalized, $match)) {
            $date = $this->parseDate($match[1]);
            if ($date?->lt(Carbon::today(config('whatsapp.timezone')))) {
                return $this->question('ASK_DEPARTURE_DATE', 'La fecha de salida debe ser futura.', $flightRequest);
            }
            if ($date) {
                $flightRequest->update(['departure_date' => $date->toDateString()]);

                return $this->continueFromMissing($conversation, $flightRequest, 'Listo, actualicé la fecha de salida.');
            }
        }

        if (preg_match('/(?:destino|a|hacia)\s+(.+)/', $message, $match) && preg_match('/(?:cambia|cambiar|mejor|era|corrige|destino)/', $normalized)) {
            $destination = $this->normalizeLocationValue($match[1]);
            if ($flightRequest->origin && $this->normalize($destination) === $this->normalize($flightRequest->origin)) {
                return $this->question('ASK_DESTINATION', 'Veo que pusiste el mismo lugar de salida y llegada. ¿A qué otro destino te gustaría viajar?', $flightRequest);
            }
            $flightRequest->update(['destination' => $destination]);

            return $this->continueFromMissing($conversation, $flightRequest, 'Perfecto, actualicé el destino.');
        }

        if (preg_match('/^(?:mejor|era)\s+(.+)/', $message, $match) && ($conversation->state === 'ASK_DESTINATION' || $flightRequest->destination)) {
            $destination = $this->normalizeLocationValue($match[1]);
            if ($flightRequest->origin && $this->normalize($destination) === $this->normalize($flightRequest->origin)) {
                return $this->question('ASK_DESTINATION', 'Veo que pusiste el mismo lugar de salida y llegada. ¿A qué otro destino te gustaría viajar?', $flightRequest);
            }
            $flightRequest->update(['destination' => $destination]);

            return $this->continueFromMissing($conversation, $flightRequest, 'Perfecto, actualicé el destino.');
        }

        return null;
    }

    /** @return array{state:string,message:string}|null */
    private function correction(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): ?array
    {
        if (! preg_match('/(?:me equivoque|cambia|cambiar|era|mejor|corrige)/', $message)) {
            return null;
        }

        $steps = match (true) {
            str_contains($message, 'origen') => ['ASK_ORIGIN'],
            str_contains($message, 'destino') => ['ASK_DESTINATION'],
            str_contains($message, 'fecha') || str_contains($message, 'dia') => ['ASK_DEPARTURE_DATE'],
            str_contains($message, 'hora') => ['ASK_DEPARTURE_TIME'],
            str_contains($message, 'pasajer') || preg_match('/somos \d{1,2}/', $message) => ['ASK_PASSENGERS'],
            default => [],
        };

        if ($steps === []) {
            return ['state' => $conversation->state, 'message' => 'Claro, ¿qué dato quieres cambiar?'];
        }

        $state = array_shift($steps);
        $conversation->update(['metadata' => [...($conversation->metadata ?? []), 'edit_steps' => $steps]]);

        return $this->question($state, null, $flightRequest);
    }

    /** @return array{state:string,message:string} */
    private function captureAnswer(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $state = $conversation->state;
        $question = self::QUESTIONS[$state];
        $field = $question['field'];
        $normalized = $this->normalize($message);
        if ($message === '' || mb_strlen($message) > 250) {
            return $this->question($state, 'Escribe una respuesta corta, por favor.', $flightRequest);
        }
        if ($question['type'] === 'legs') {
            return $this->captureLeg($conversation, $flightRequest, $message);
        }
        $message = $question['type'] === 'location' ? $this->normalizeLocationValue($message) : $message;
        $normalized = $question['type'] === 'location' ? $this->normalize($message) : $normalized;
        $parsedDate = $question['type'] === 'date' ? $this->parseDate($message) : null;
        $value = match ($question['type']) {
            'date' => $parsedDate?->toDateString(),
            'time' => $this->parseTime($message),
            'passengers', 'count' => $this->parseCount($normalized, $question['type'] === 'count'),
            'boolean', 'pets' => $this->parseBoolean($normalized, $question['type'] === 'pets'),
            'email' => filter_var($message, FILTER_VALIDATE_EMAIL) ?: null,
            'trip' => $this->parseTripType($normalized),
            default => $message,
        };
        if ($value === null || (in_array($question['type'], ['passengers', 'count'], true) && $value === false)) {
            return $this->question($state, $this->invalidMessage($question['type'], $question['label']), $flightRequest);
        }
        if ($question['type'] === 'date' && $parsedDate?->lt(Carbon::today(config('whatsapp.timezone')))) {
            return $this->question($state, $field === 'departure_date' ? 'Esa fecha ya pasó. ¿Qué otra fecha tienes en mente?' : 'Esa fecha ya pasó. ¿Qué otra fecha tienes en mente?', $flightRequest);
        }
        if ($question['type'] === 'location') {
            $other = $field === 'origin' ? $flightRequest->destination : $flightRequest->origin;
            if ($other && $normalized === $this->normalize($other)) {
                return $this->question($state, 'Veo que pusiste el mismo lugar de salida y llegada. ¿A qué otro destino te gustaría viajar?', $flightRequest);
            }
        }
        if ($field === 'return_date' && $value < $flightRequest->departure_date?->toDateString()) {
            return $this->question($state, 'El regreso no puede ser antes de la salida. ¿Qué otra fecha tienes en mente?', $flightRequest);
        }
        if ($field === 'return_time' && $flightRequest->return_date?->toDateString() === $flightRequest->departure_date?->toDateString() && $value <= $flightRequest->departure_time) {
            return $this->question($state, 'La hora de regreso debe ser después de la salida. ¿Qué hora prefieres?', $flightRequest);
        }
        if ($question['type'] === 'optional' && $this->isNegative($normalized)) {
            $value = null;
        }
        $attributes = [$field => $value];
        if ($field === 'has_pets') {
            $attributes['pets_description'] = $value ? $message : null;
        }
        if ($field === 'trip_type') {
            $attributes += ['return_date' => null, 'return_time' => null, 'legs' => null];
        }
        $flightRequest->update($attributes);

        $next = match ($state) {
            'ASK_TRIP_TYPE' => match ($value) {
                'ROUND_TRIP' => 'ASK_RETURN_DATE',
                'MULTI_CITY' => 'ASK_LEGS',
                default => 'ASK_LUGGAGE',
            },
            'ASK_RETURN_TIME' => 'ASK_LUGGAGE',
            default => array_keys(self::QUESTIONS)[array_search($state, array_keys(self::QUESTIONS), true) + 1] ?? 'SHOW_SUMMARY',
        };

        return $this->advance($conversation, $flightRequest, $next);
    }

    /** @return array{state:string,message:string} */
    private function advance(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $next): array
    {
        $metadata = $conversation->metadata ?? [];
        if (isset($metadata['edit_steps'])) {
            if ($conversation->state === 'ASK_TRIP_TYPE') {
                $metadata['edit_steps'] = match ($flightRequest->trip_type) {
                    'ROUND_TRIP' => ['ASK_RETURN_DATE', 'ASK_RETURN_TIME'],
                    'MULTI_CITY' => ['ASK_LEGS'],
                    default => [],
                };
            }
            $next = array_shift($metadata['edit_steps']) ?? 'SHOW_SUMMARY';
            if ($next === 'SHOW_SUMMARY') {
                unset($metadata['edit_steps']);
            }
            $conversation->update(['metadata' => $metadata]);
        }

        return $next === 'SHOW_SUMMARY' ? $this->showSummary($flightRequest) : $this->question($next, null, $flightRequest);
    }

    private function normalizeLocationValue(string $message): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        $value = preg_replace('/^(?:desde|de|a|hacia)\s+/iu', '', $value) ?? $value;
        $value = preg_replace('/^aeropuerto(?:\s+internacional)?\s+de\s+/iu', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B.,");

        return preg_match('/^[a-z]{3,4}$/i', $value) ? mb_strtoupper($value) : $value;
    }

    /** @return array{state:string,message:string} */
    private function captureLeg(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $legs = $flightRequest->legs ?? [];
        $normalized = $this->normalize($message);
        $metadata = $conversation->metadata ?? [];
        $capture = $metadata['leg_capture'] ?? null;

        if ($this->isFinished($normalized) && ($legs !== [] || ! $capture)) {
            unset($metadata['leg_capture']);
            $conversation->update(['metadata' => $metadata]);

            return $this->advance($conversation, $flightRequest, 'ASK_LUGGAGE');
        }

        if (! $capture) {
            if ($this->isNegative($normalized)) {
                return $this->advance($conversation, $flightRequest, 'ASK_LUGGAGE');
            }
            if ($this->isAffirmative($normalized)) {
                $metadata['leg_capture'] = ['step' => 'destination', 'draft' => []];
                $conversation->update(['metadata' => $metadata]);

                return ['state' => 'ASK_LEGS', 'message' => 'Claro. ¿Cuál sería el siguiente destino?'];
            }

            $capture = ['step' => 'destination', 'draft' => []];
        }

        $step = $capture['step'] ?? 'destination';
        $draft = $capture['draft'] ?? [];
        $previous = $this->previousLeg($flightRequest);

        if ($step === 'destination') {
            if ($message === '' || $this->normalize($message) === $this->normalize($previous['destination'])) {
                return ['state' => 'ASK_LEGS', 'message' => 'Necesito un destino diferente para ese tramo.'];
            }
            $draft['destination'] = $message;
            $metadata['leg_capture'] = ['step' => 'date', 'draft' => $draft];
            $conversation->update(['metadata' => $metadata]);

            return ['state' => 'ASK_LEGS', 'message' => "Perfecto, hacia {$message}. ¿Para qué día sería ese tramo?"];
        }

        if ($step === 'date') {
            $date = $this->parseDate($message);
            if (! $date) {
                return ['state' => 'ASK_LEGS', 'message' => "No alcancé a identificar la fecha. ¿Para qué día sería {$draft['destination']}?"];
            }
            if ($date->lt(Carbon::today(config('whatsapp.timezone'))) || $date->toDateString() < $previous['departure_date']) {
                return ['state' => 'ASK_LEGS', 'message' => 'Esa fecha ya pasó o queda antes del tramo anterior. ¿Qué otra fecha tienes en mente?'];
            }
            $draft['departure_date'] = $date->toDateString();
            $metadata['leg_capture'] = ['step' => 'time', 'draft' => $draft];
            $conversation->update(['metadata' => $metadata]);

            return ['state' => 'ASK_LEGS', 'message' => '¿A qué hora aproximadamente?'];
        }

        $time = $this->parseTime($message);
        if (! $time) {
            return ['state' => 'ASK_LEGS', 'message' => 'No estoy seguro de la hora. ¿Sería, por ejemplo, 8:00 am o 2:30 pm?'];
        }
        if ($draft['departure_date'].' '.$time <= $previous['departure_date'].' '.$previous['departure_time']) {
            return ['state' => 'ASK_LEGS', 'message' => 'Ese tramo debe salir después del tramo anterior. ¿Qué hora prefieres?'];
        }
        if (count($legs) >= 6) {
            unset($metadata['leg_capture']);
            $conversation->update(['metadata' => $metadata]);

            return ['state' => 'ASK_LEGS', 'message' => 'Ya tengo el máximo de paradas. Continuemos con el equipaje.'];
        }

        $legs[] = ['origin' => $previous['destination'], 'destination' => $draft['destination'], 'departure_date' => $draft['departure_date'], 'departure_time' => $time];
        $flightRequest->update(['legs' => $legs]);
        unset($metadata['leg_capture']);
        $conversation->update(['metadata' => $metadata]);

        return ['state' => 'ASK_LEGS', 'message' => "Perfecto, agregué ese tramo.\n{$this->routeSummary($flightRequest->refresh())}\n¿Quieres agregar otra parada o continuamos?"];
    }

    /** @return array{destination:?string,departure_date:?string,departure_time:?string} */
    private function previousLeg(WhatsAppFlightRequest $flightRequest): array
    {
        $legs = $flightRequest->legs ?? [];

        return $legs === []
            ? ['destination' => $flightRequest->destination, 'departure_date' => $flightRequest->departure_date?->toDateString(), 'departure_time' => $flightRequest->departure_time]
            : $legs[array_key_last($legs)];
    }

    private function lastLegDestination(WhatsAppFlightRequest $flightRequest): ?string
    {
        return $this->previousLeg($flightRequest)['destination'] ?? null;
    }

    private function routeSummary(WhatsAppFlightRequest $flightRequest): string
    {
        return 'Tu ruta va quedando así: '.$this->routeLine($flightRequest);
    }

    private function routeLine(WhatsAppFlightRequest $flightRequest): string
    {
        return collect([$flightRequest->origin, $flightRequest->destination])
            ->merge(collect($flightRequest->legs ?? [])->pluck('destination'))
            ->filter()
            ->implode(' → ');
    }

    /** @return array{state:string,message:string} */
    private function chooseEdit(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $choice): array
    {
        $steps = match ($choice) {
            '1', 'origen' => ['ASK_ORIGIN'],
            '2', 'destino' => ['ASK_DESTINATION'],
            '3', 'fecha' => $flightRequest->trip_type === 'ROUND_TRIP' ? ['ASK_DEPARTURE_DATE', 'ASK_RETURN_DATE'] : ['ASK_DEPARTURE_DATE'],
            '4', 'hora' => $flightRequest->trip_type === 'ROUND_TRIP' ? ['ASK_DEPARTURE_TIME', 'ASK_RETURN_TIME'] : ['ASK_DEPARTURE_TIME'],
            '5', 'pasajeros' => ['ASK_PASSENGERS'],
            '6', 'viaje' => ['ASK_TRIP_TYPE'],
            '7', 'equipaje' => ['ASK_LUGGAGE', 'ASK_LUGGAGE_DESCRIPTION', 'ASK_SPECIAL_LUGGAGE'],
            '8', 'mascotas' => ['ASK_PETS'],
            '9', 'aeronave' => ['ASK_AIRCRAFT_PREFERENCE'],
            '10', 'datos personales' => ['ASK_NAME', 'ASK_EMAIL', 'ASK_COMPANY'],
            '11', 'servicios' => ['ASK_TIME_FLEXIBILITY', 'ASK_ALTERNATE_AIRPORTS', 'ASK_CATERING', 'ASK_GROUND_TRANSPORT', 'ASK_WIFI', 'ASK_OTHER_SERVICES'],
            '12', 'observaciones' => ['ASK_NOTES'],
            '13', 'presupuesto' => ['ASK_BUDGET'],
            default => [],
        };
        if ($steps === []) {
            return $this->editMenu();
        }
        $state = array_shift($steps);
        $conversation->update(['metadata' => [...($conversation->metadata ?? []), 'edit_steps' => $steps]]);

        return $this->question($state);
    }

    /** @return array{state:string,message:string} */
    private function editMenu(): array
    {
        return ['state' => 'EDIT_FIELD', 'message' => "¿Qué deseas modificar?\n1. Origen\n2. Destino\n3. Fecha\n4. Hora\n5. Pasajeros\n6. Viaje (incluye regreso/tramos)\n7. Equipaje\n8. Mascotas\n9. Aeronave\n10. Datos personales\n11. Servicios y flexibilidad\n12. Observaciones\n13. Presupuesto"];
    }

    /** @return array{state:string,message:string} */
    private function confirmRequest(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $choice): array
    {
        if (in_array($choice, ['2', 'modificar', 'modificar informacion', 'cambiar', 'hay un error', 'quiero modificar algo', 'quiero cambiar algo'], true)) {
            return $this->editMenu();
        }
        if (in_array($choice, ['3', 'cancelar'], true)) {
            $flightRequest->update(['status' => 'cancelled']);

            return ['state' => 'CANCELLED', 'message' => 'Solicitud cancelada. Escribe de nuevo si deseas iniciar otra cotización.'];
        }
        if (in_array($choice, ['1', 'si', 'si, solicitar cotizacion', 'solicitar cotizacion', 'confirmar', 'correcto', 'todo bien', 'adelante', 'enviala', 'enviar'], true)) {
            $invalidState = $this->invalidState($flightRequest);
            if ($invalidState) {
                $conversation->update(['metadata' => [...($conversation->metadata ?? []), 'edit_steps' => []]]);

                return $this->question($invalidState, 'Revisa este dato antes de confirmar.');
            }
            $flightRequest->update(['status' => 'confirmed', 'confirmed_at' => $flightRequest->confirmed_at ?? now()]);

            return ['state' => 'SEARCH_FLIGHTS', 'message' => 'Solicitud confirmada. Responde continuar para buscar opciones disponibles.'];
        }

        return $this->showSummary($flightRequest);
    }

    private function invalidState(WhatsAppFlightRequest $flightRequest): ?string
    {
        foreach (self::QUESTIONS as $state => $question) {
            if (in_array($question['field'], ['company', 'budget', 'legs', 'return_date', 'return_time'], true)) {
                continue;
            }
            if ($flightRequest->{$question['field']} === null || $flightRequest->{$question['field']} === '') {
                return $state;
            }
        }
        if (! $this->parseDate($flightRequest->departure_date?->toDateString() ?? '') || $flightRequest->departure_date?->lt(Carbon::today(config('whatsapp.timezone')))) {
            return 'ASK_DEPARTURE_DATE';
        }
        if ($this->normalize($flightRequest->origin) === $this->normalize($flightRequest->destination)) {
            return 'ASK_DESTINATION';
        }
        if ($flightRequest->trip_type === 'ROUND_TRIP') {
            if (! $flightRequest->return_date || $flightRequest->return_date->lt($flightRequest->departure_date)) {
                return 'ASK_RETURN_DATE';
            }
            if (! $flightRequest->return_time || $flightRequest->return_date->toDateString().' '.$flightRequest->return_time <= $flightRequest->departure_date->toDateString().' '.$flightRequest->departure_time) {
                return 'ASK_RETURN_TIME';
            }
        }
        if ($flightRequest->trip_type === 'MULTI_CITY') {
            $previous = $flightRequest->departure_date->toDateString().' '.$flightRequest->departure_time;
            $origin = $flightRequest->destination;
            if (! $flightRequest->legs) {
                return 'ASK_LEGS';
            }
            foreach ($flightRequest->legs as $leg) {
                if ($leg['origin'] !== $origin || $previous >= $leg['departure_date'].' '.$leg['departure_time']) {
                    $flightRequest->update(['legs' => null]);

                    return 'ASK_LEGS';
                }
                $previous = $leg['departure_date'].' '.$leg['departure_time'];
                $origin = $leg['destination'];
            }
        }

        return null;
    }

    /** @return array{state:string,message:string} */
    private function showSummary(WhatsAppFlightRequest $flightRequest): array
    {
        return ['state' => 'SHOW_SUMMARY', 'message' => $this->summaryMessage($flightRequest)."\n\n¿Todo está correcto para solicitar la cotización?"];
    }

    public function summaryMessage(WhatsAppFlightRequest $flightRequest): string
    {
        $lines = ['Perfecto, esto es lo que tengo hasta ahora:', ''];
        $lines[] = '✈️ '.$this->routeLine($flightRequest);
        if ($flightRequest->departure_date || $flightRequest->departure_time) {
            $lines[] = '📅 '.trim(implode(' a las ', array_filter([
                $this->displayDate($flightRequest->departure_date),
                $this->displayTime($flightRequest->departure_time),
            ])));
        }
        if ($flightRequest->passengers) {
            $lines[] = '👥 '.$flightRequest->passengers.' pasajeros';
        }
        if ($flightRequest->luggage_count !== null) {
            $lines[] = '🧳 '.$flightRequest->luggage_count.' piezas de equipaje';
        }
        if ($flightRequest->has_pets !== null) {
            $lines[] = '🐾 '.($flightRequest->has_pets ? Str::limit((string) ($flightRequest->pets_description ?: 'Viajan mascotas'), 80) : 'Sin mascotas');
        }
        if ($flightRequest->is_time_flexible !== null) {
            $lines[] = '🕐 '.($flightRequest->is_time_flexible ? 'Horario flexible' : 'Horario fijo');
        }
        if ($flightRequest->client_name) {
            $lines[] = '';
            $lines[] = 'A nombre de '.$flightRequest->client_name;
        }
        if ($flightRequest->client_email) {
            $lines[] = $flightRequest->client_email;
        }
        if ($flightRequest->notes) {
            $lines[] = 'Notas: '.Str::limit($flightRequest->notes, 120);
        }

        return implode("\n", $lines);
    }

    private function displayDate(mixed $date): ?string
    {
        if (! $date) {
            return null;
        }
        $date = $date instanceof Carbon ? $date : Carbon::parse($date, config('whatsapp.timezone'));

        return $date->translatedFormat('j \d\e F');
    }

    private function displayTime(?string $time): ?string
    {
        if (! $time) {
            return null;
        }

        return Carbon::createFromFormat('H:i:s', $time, config('whatsapp.timezone'))->format('g:i a');
    }

    private function parseBoolean(string $message, bool $allowDetails = false): ?bool
    {
        if ($this->isAffirmative($message) || ($allowDetails && preg_match('/^si[ ,:]/', $message))) {
            return true;
        }

        return $this->isNegative($message) ? false : null;
    }

    private function isAffirmative(string $message): bool
    {
        return in_array($message, ['si', 's', 'yes', '1', 'claro', 'por supuesto', 'correcto', 'afirmativo', 'ok', 'va', 'dale'], true);
    }

    private function isNegative(string $message): bool
    {
        return in_array($message, ['no', 'n', '2', '0', 'ninguno', 'ninguna', 'nada', 'sin', 'omitir', 'terminar', 'ya esta', 'ya está'], true);
    }

    private function isFinished(string $message): bool
    {
        return in_array($message, ['listo', 'terminar', 'continuar', 'continuemos', 'seguir', 'ya esta', 'ya está'], true);
    }

    private function parseCount(string $message, bool $allowZero): int|false
    {
        if ($allowZero && $this->isNegative($message)) {
            return 0;
        }
        if (preg_match('/\b(\d{1,2})\b/', $message, $match)) {
            $count = (int) $match[1];

            return $count >= ($allowZero ? 0 : 1) && $count <= 99 ? $count : false;
        }

        return false;
    }

    private function parseTripType(string $message): ?string
    {
        return match (true) {
            in_array($message, ['1', 'one_way', 'one way', 'sencillo', 'solo ida', 'ida', 'solo de ida'], true) => 'ONE_WAY',
            in_array($message, ['2', 'round_trip', 'round trip', 'redondo', 'ida y vuelta', 'ida y regreso', 'regreso'], true) => 'ROUND_TRIP',
            in_array($message, ['3', 'multi_city', 'multi city', 'multidestino', 'multi destino', 'varios destinos'], true) => 'MULTI_CITY',
            default => null,
        };
    }

    private function invalidMessage(string $type, string $label): string
    {
        return match ($type) {
            'date' => 'No entendí la fecha.',
            'time' => 'No entendí la hora.',
            'passengers' => 'Necesito cuántas personas viajan.',
            'count' => 'Necesito un número para '.$this->normalize($label).'.',
            'boolean', 'pets' => 'Necesito una respuesta de sí o no.',
            'email' => 'Ese correo no parece válido.',
            'trip' => 'Necesito saber si es sólo ida, ida y vuelta o multidestino.',
            default => 'No entendí ese dato.',
        };
    }

    private function parseDate(string $message): ?Carbon
    {
        $message = $this->normalize($message);
        $today = Carbon::today(config('whatsapp.timezone'));
        if (in_array($message, ['hoy', 'manana', 'mañana', 'pasado manana', 'pasado mañana'], true)) {
            return $today->addDays(['hoy' => 0, 'manana' => 1, 'mañana' => 1, 'pasado manana' => 2, 'pasado mañana' => 2][$message]);
        }
        if (preg_match('/^(?:este |el |proximo )?(lunes|martes|miercoles|jueves|viernes|sabado|domingo)$/', $message, $match)) {
            $day = array_search($match[1], ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'], true);

            return $today->addDays(($day - $today->dayOfWeek + 7) % 7);
        }
        if (preg_match('/^(\d{1,2}) de (enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)(?: de (\d{4}))?$/', $message, $match)) {
            $month = array_search($match[2], ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'], true) + 1;
            $year = (int) ($match[3] ?? $today->year);
            if (! isset($match[3]) && sprintf('%04d-%02d-%02d', $year, $month, $match[1]) < $today->toDateString()) {
                $year++;
            }
            $message = sprintf('%04d-%02d-%02d', $year, $month, $match[1]);
        }
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $message, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }
        $date = Carbon::createFromFormat('!Y-m-d', $message, config('whatsapp.timezone'));

        return $date ?: null;
    }

    private function parseTime(string $message): ?string
    {
        $message = $this->normalize($message);
        $message = preg_replace('/^(?:a las?|como a las?|sobre las?) /', '', $message) ?? $message;
        if (in_array($message, ['manana', 'por la manana', 'en la manana'], true)) {
            return '09:00:00';
        }
        if (in_array($message, ['mediodia', 'medio dia'], true)) {
            return '12:00:00';
        }
        if (in_array($message, ['tarde', 'por la tarde', 'en la tarde'], true)) {
            return '15:00:00';
        }
        if (in_array($message, ['noche', 'por la noche', 'en la noche'], true)) {
            return '20:00:00';
        }
        if (preg_match('/^(0?[1-9]|1[0-2])(?::([0-5]\d))?\s*(?:de la )?(manana|tarde|noche|am|pm)$/', $message, $match)) {
            $hour = (int) $match[1] % 12;
            if (in_array($match[3], ['tarde', 'noche', 'pm'], true)) {
                $hour += 12;
            }

            return sprintf('%02d:%02d:00', $hour, (int) ($match[2] ?: 0));
        }

        return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::00)?$/', $message, $match) ? sprintf('%02d:%02d:00', $match[1], $match[2]) : null;
    }

    /**
     * @return array{state:string,message:string}
     */
    private function searchFlights(WhatsAppFlightRequest $flightRequest): array
    {
        try {
            $results = $this->flightApiService->searchFlights($flightRequest);
        } catch (RuntimeException $exception) {
            report($exception);

            return [
                'state' => 'TRANSFER_TO_HUMAN',
                'message' => 'No pude consultar disponibilidad en este momento. Te conectaremos con un asesor para continuar.',
            ];
        }

        if ($results === []) {
            $flightRequest->update([
                'search_results' => [],
                'status' => 'no_aircraft_available',
            ]);

            return [
                'state' => 'TRANSFER_TO_HUMAN',
                'message' => 'No encontré aeronaves disponibles para esas fechas. Te conectaremos con un asesor para revisar alternativas.',
            ];
        }

        $flightRequest->update([
            'search_results' => $results,
            'status' => 'searched',
        ]);

        return ['state' => 'SHOW_RESULTS', 'message' => 'Encontramos opciones compatibles con tu solicitud. Responde continuar para verlas.'];
    }

    /**
     * @return array{state:string,message:string}
     */
    private function showResults(WhatsAppFlightRequest $flightRequest): array
    {
        $results = collect($flightRequest->search_results ?? []);

        if ($results->isEmpty()) {
            return [
                'state' => 'SEARCH_FLIGHTS',
                'message' => 'Responde continuar para buscar nuevas opciones disponibles para tu ruta.',
            ];
        }

        $options = $results
            ->take(10)
            ->map(fn (array $result, int $index): string => implode("\n", array_filter([
                'Opción '.($index + 1),
                (string) ($result['aircraft_name'] ?? 'Aeronave disponible'),
                isset($result['capacity']) ? $result['capacity'].' pasajeros' : null,
                isset($result['display_time']) ? 'Tiempo: '.$result['display_time'] : null,
                isset($result['total']) ? 'Precio: '.$this->money($result['total'], (string) ($result['currency'] ?? 'Moneda no especificada')) : null,
            ])))
            ->implode("\n\n");

        return [
            'state' => 'SELECT_AIRCRAFT',
            'message' => "Opciones disponibles:\n{$options}\n\nResponde con el numero de la aeronave que prefieres.",
        ];
    }

    /**
     * @return array{state:string,message:string}
     */
    private function selectAircraft(WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $selectedIndex = filter_var(trim($message), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $results = array_values($flightRequest->search_results ?? []);

        if (! $selectedIndex || ! isset($results[$selectedIndex - 1])) {
            return ['state' => 'SELECT_AIRCRAFT', 'message' => 'Selecciona una opcion valida respondiendo con el numero de la aeronave.'];
        }

        $selected = $results[$selectedIndex - 1];
        $aircraftId = (int) ($selected['aircraft_id'] ?? 0);

        if ($aircraftId <= 0) {
            return ['state' => 'SEARCH_FLIGHTS', 'message' => 'Esa opción no tiene identificador válido. Responde continuar para buscar opciones actualizadas.'];
        }

        try {
            $freshSelection = $this->flightApiService->checkAvailability($flightRequest, $aircraftId);
        } catch (RuntimeException $exception) {
            report($exception);

            return [
                'state' => 'TRANSFER_TO_HUMAN',
                'message' => 'No pude revalidar la disponibilidad en este momento. Te conectaremos con un asesor.',
            ];
        }

        if (! $freshSelection) {
            try {
                $flightRequest->update([
                    'search_results' => $this->flightApiService->searchFlights($flightRequest),
                    'status' => 'searched',
                ]);
            } catch (RuntimeException $exception) {
                report($exception);
            }

            return [
                'state' => 'SHOW_RESULTS',
                'message' => 'Esa aeronave ya no se encuentra disponible. Responde continuar para ver alternativas actualizadas.',
            ];
        }

        $flightRequest->update([
            'selected_aircraft' => $freshSelection['aircraft_name'] ?? null,
            'selected_aircraft_id' => $aircraftId,
            'selected_provider_id' => $freshSelection['provider_id'] ?? null,
            'selected_match_id' => $freshSelection['match_id'] ?? null,
            'official_quote_payload' => $freshSelection,
            'status' => 'aircraft_selected',
        ]);

        return ['state' => 'CREATE_QUOTE', 'message' => 'Responde continuar para preparar tu cotización con la aeronave seleccionada.'];
    }

    /**
     * @return array{state:string,message:string}
     */
    private function createQuote(WhatsAppFlightRequest $flightRequest): array
    {
        try {
            $response = $this->flightApiService->createFlightRequest($flightRequest);
        } catch (RuntimeException $exception) {
            report($exception);

            return [
                'state' => 'TRANSFER_TO_HUMAN',
                'message' => 'No pude completar la cotización en este momento. Podemos intentar nuevamente o comunicarte con un asesor.',
            ];
        }

        $backendFlightRequestId = data_get($response, 'flight_request.id');
        $acceptedQuoteId = data_get($response, 'accepted_quote.id');

        $flightRequest->update([
            'quote_reference' => $acceptedQuoteId ? 'QUOTE-'.$acceptedQuoteId : null,
            'backend_flight_request_id' => $backendFlightRequestId,
            'accepted_quote_id' => $acceptedQuoteId,
            'status' => 'quoted',
        ]);

        return [
            'state' => 'FINISHED',
            'message' => $acceptedQuoteId
                ? "Listo. Registramos tu cotización oficial con ID {$acceptedQuoteId}."
                : 'Listo. Registramos tu solicitud de vuelo en el backend oficial.',
        ];
    }

    private function money(mixed $amount, string $currency): string
    {
        return '$'.number_format((float) $amount, 0).' '.$currency;
    }
}
