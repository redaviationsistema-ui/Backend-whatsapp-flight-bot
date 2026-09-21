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
        'ASK_TRIP_TYPE' => ['field' => 'trip_type', 'label' => 'Viaje', 'prompt' => '¿Será sólo ida, ida y vuelta o multidestino?', 'type' => 'trip'],
        'ASK_RETURN_DATE' => ['field' => 'return_date', 'label' => 'Fecha de regreso', 'prompt' => '¿Qué día quieres regresar?', 'type' => 'date'],
        'ASK_RETURN_TIME' => ['field' => 'return_time', 'label' => 'Hora de regreso', 'prompt' => '¿A qué hora te gustaría regresar?', 'type' => 'time'],
        'ASK_LEGS' => ['field' => 'legs', 'label' => 'Tramos adicionales', 'prompt' => '¿Quieres agregar alguna escala o parada adicional?', 'type' => 'legs'],
        'ASK_PASSENGERS' => ['field' => 'passengers', 'label' => 'Pasajeros', 'prompt' => '¿Cuántas personas viajan?', 'type' => 'passengers'],
        'ASK_AIRCRAFT_PREFERENCE' => ['field' => 'aircraft_preference', 'label' => 'Aeronave', 'prompt' => '¿Tienes preferencia de aeronave?', 'type' => 'text'],
        'ASK_TIME_FLEXIBILITY' => ['field' => 'is_time_flexible', 'label' => 'Horario flexible', 'prompt' => '¿Tu horario es flexible? Sí o no.', 'type' => 'boolean'],
        'ASK_ALTERNATE_AIRPORTS' => ['field' => 'allow_alternate_airports', 'label' => 'Aeropuertos alternos', 'prompt' => '¿Aceptas aeropuertos alternos? Sí o no.', 'type' => 'boolean'],
        'ASK_OTHER_SERVICES' => ['field' => 'other_services', 'label' => 'Otros servicios', 'prompt' => '¿Necesitas otros servicios o escalas técnicas?', 'type' => 'text'],
        'ASK_NAME' => ['field' => 'client_name', 'label' => 'Nombre', 'prompt' => '¿Cuál es tu nombre completo?', 'type' => 'text'],
        'ASK_EMAIL' => ['field' => 'client_email', 'label' => 'Correo', 'prompt' => '¿Cuál es tu correo electrónico?', 'type' => 'email'],
        'ASK_COMPANY' => ['field' => 'company', 'label' => 'Empresa', 'prompt' => '¿Cotizas para alguna empresa?', 'type' => 'optional'],
        'ASK_BUDGET' => ['field' => 'budget', 'label' => 'Presupuesto', 'prompt' => '¿Tienes un presupuesto aproximado?', 'type' => 'money'],
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
        if ($outOfScopeFollowUp = $this->handleOutOfScopeFollowUp($conversation, $normalized)) {
            return $outOfScopeFollowUp;
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
        if ($this->isUnsupportedIntent($normalized)) {
            $conversation->update(['metadata' => [...($conversation->metadata ?? []), 'last_bot_intent' => 'unsupported_offer']]);

            return [
                'state' => $conversation->state === 'START' ? 'START' : $conversation->state,
                'message' => 'Gracias por escribirnos. Este canal está enfocado exclusivamente en renta y cotización de vuelos privados. Si deseas cotizar un vuelo, con gusto te ayudo.',
            ];
        }
        if ($directCorrection = $this->directCorrection($conversation, $flightRequest, $message, $normalized)) {
            return $directCorrection;
        }
        if ($correction = $this->correction($conversation, $flightRequest, $normalized)) {
            return $correction;
        }
        $details = $this->extractFlightDetails($message);
        if (isset($details['origin'], $details['destination'])) {
            return $this->applyExtractedDetails($conversation, $flightRequest, $details);
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

    /** @return array{state:string,message:string}|null */
    private function handleOutOfScopeFollowUp(WhatsAppConversation $conversation, string $message): ?array
    {
        if ((($conversation->metadata ?? [])['last_bot_intent'] ?? null) !== 'unsupported_offer') {
            return null;
        }

        if ($this->isAffirmative($message)) {
            $this->conversationService->resetFlightRequest($conversation);
            $conversation->update(['metadata' => null]);

            return ['state' => 'ASK_ORIGIN', 'message' => 'Perfecto. ¿Desde qué ciudad o aeropuerto deseas salir?'];
        }

        if ($this->isNegative($message) || preg_match('/\b(?:no gracias|no quiero volar|no necesito un vuelo|no estoy buscando renta|no busco renta)\b/', $message) === 1) {
            $this->conversationService->resetFlightRequest($conversation);
            $conversation->update(['metadata' => null]);

            return ['state' => 'START', 'message' => 'Entendido. Si más adelante necesitas cotizar un vuelo privado, aquí estaremos para ayudarte.'];
        }

        return null;
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
            || str_contains($message, 'rentar')
            || str_contains($message, 'renta')
            || str_contains($message, 'vuelo privado')
            || str_contains($message, 'jet')
            || str_contains($message, 'cuanto cuesta un vuelo')
            || str_contains($message, 'necesito un avion')
            || str_contains($message, 'informacion');
    }

    private function isUnsupportedIntent(string $message): bool
    {
        if ($this->looksLikeFlightRentalMessage($message)) {
            return false;
        }

        return str_contains($message, 'comprar')
            || str_contains($message, 'comprarlo')
            || str_contains($message, 'venta')
            || str_contains($message, 'vender')
            || str_contains($message, 'publicacion')
            || str_contains($message, 'cessna')
            || str_contains($message, 'cuanto cuesta el avion')
            || str_contains($message, 'refaccion')
            || str_contains($message, 'refacciones')
            || str_contains($message, 'pieza')
            || str_contains($message, 'piezas')
            || str_contains($message, 'motor')
            || str_contains($message, 'motores')
            || str_contains($message, 'empleo')
            || str_contains($message, 'mantenimiento')
            || str_contains($message, 'certificacion');
    }

    private function looksLikeFlightRentalMessage(string $message): bool
    {
        return str_contains($message, 'cotizar')
            || str_contains($message, 'vuelo')
            || str_contains($message, 'salida')
            || str_contains($message, 'pasajer')
            || str_contains($message, 'rentar')
            || str_contains($message, 'renta')
            || preg_match('/\b(?:de|desde)\s+[\pL .]{2,60}\s+(?:a|hacia)\s+[\pL .]{2,60}/iu', $message) === 1;
    }

    private function isGreeting(string $message): bool
    {
        return in_array($message, ['hola', 'buen dia', 'buenos dias', 'buenas tardes', 'buenas noches'], true);
    }

    private function isHelpRequest(string $message): bool
    {
        return in_array($message, ['ayuda', 'ejemplo', 'dame un ejemplo', 'no entiendo', 'no entendi', 'como', 'como?', 'como respondo', 'como lo quieres', 'que pongo', 'que necesitas'], true);
    }

    private function normalize(string $message): string
    {
        $message = preg_replace('/[^\pL\pN@._:+,\-\/\s]/u', ' ', $message) ?? $message;

        return Str::of($message)->lower()->ascii()->trim()->trim('.;,')->squish()->toString();
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
            if ($question['field'] === 'legs' && $this->nextIncompleteLeg($flightRequest)) {
                return $state;
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
            'ASK_LEGS' => $this->nextIncompleteLeg($flightRequest)
                ? $this->incompleteLegPrompt($flightRequest)
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
            'time' => 'Puedes decirme algo como 8 de la noche, 8 pm o 20:00.',
            'passengers' => 'Puedes decir 4, somos 4 o cuatro pasajeros.',
            'boolean' => 'Responde sí o no.',
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

    /** @return array{index:int,leg:array<string, mixed>}|null */
    private function nextIncompleteLeg(WhatsAppFlightRequest $flightRequest): ?array
    {
        foreach (($flightRequest->legs ?? []) as $index => $leg) {
            if (empty($leg['departure_date']) || empty($leg['departure_time'])) {
                return ['index' => $index, 'leg' => $leg];
            }
        }

        return null;
    }

    private function incompleteLegPrompt(WhatsAppFlightRequest $flightRequest): string
    {
        $incomplete = $this->nextIncompleteLeg($flightRequest);
        if (! $incomplete) {
            return self::QUESTIONS['ASK_LEGS']['prompt'];
        }

        $leg = $incomplete['leg'];
        $route = ($leg['origin'] ?? '').' → '.($leg['destination'] ?? '');

        return empty($leg['departure_date'])
            ? "Para el tramo {$route}, ¿qué día quieres salir?"
            : "Para el tramo {$route}, ¿a qué hora te gustaría salir?";
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
        if ($question['type'] === 'location' && ! $this->isPlausibleLocation($message)) {
            return $this->question($state, $this->invalidMessage('location', $question['label']), $flightRequest);
        }
        $parsedDate = $question['type'] === 'date' ? $this->parseDate($this->extractDatePhrase($message) ?? $message, $field === 'return_date' ? $flightRequest->departure_date : null) : null;
        $parsedTime = $this->parseTime($this->extractTimePhrase($message) ?? $message);
        $value = match ($question['type']) {
            'date' => $parsedDate?->toDateString(),
            'time' => $parsedTime,
            'passengers', 'count' => $this->parseCount($normalized, $question['type'] === 'count'),
            'boolean' => $this->parseBoolean($normalized),
            'email' => filter_var($this->extractEmail($message) ?? $message, FILTER_VALIDATE_EMAIL) ?: null,
            'trip' => $this->parseTripType($normalized),
            'money' => $this->parseMoney($normalized),
            default => $this->cleanTextAnswer($message, $field),
        };
        if (($value === null && $question['type'] !== 'money') || ($value === false && in_array($question['type'], ['passengers', 'count', 'money'], true))) {
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
        if ($field === 'return_date' && $value === null) {
            return $this->question($state, $this->invalidMessage($question['type'], $question['label']), $flightRequest);
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
        if ($question['type'] === 'text' && $this->isNegative($normalized)) {
            $value = null;
        }
        $attributes = [$field => $value];
        if ($field === 'trip_type') {
            $attributes += ['return_date' => null, 'return_time' => null, 'legs' => null];
        }
        if ($field === 'departure_date' && $parsedTime) {
            $attributes['departure_time'] = $parsedTime;
        }
        if ($field === 'return_date' && $parsedTime) {
            $attributes['return_time'] = $parsedTime;
        }
        $flightRequest->update($attributes);
        $flightRequest->refresh();

        $next = $this->nextStateAfter($state, $flightRequest) ?? 'SHOW_SUMMARY';

        return $this->advance($conversation, $flightRequest, $next);
    }

    private function nextStateAfter(string $state, WhatsAppFlightRequest $flightRequest): ?string
    {
        $states = array_keys(self::QUESTIONS);
        $currentIndex = array_search($state, $states, true);
        if ($currentIndex === false) {
            return $this->nextMissingState($flightRequest);
        }

        foreach (array_slice($states, $currentIndex + 1) as $nextState) {
            $question = self::QUESTIONS[$nextState];
            if (in_array($question['field'], ['return_date', 'return_time'], true) && $flightRequest->trip_type !== 'ROUND_TRIP') {
                continue;
            }
            if ($question['field'] === 'legs' && $flightRequest->trip_type !== 'MULTI_CITY') {
                continue;
            }
            if ($question['field'] === 'legs') {
                if ($flightRequest->legs === null || $flightRequest->legs === '' || $this->nextIncompleteLeg($flightRequest)) {
                    return $nextState;
                }

                continue;
            }
            if ($flightRequest->{$question['field']} === null || $flightRequest->{$question['field']} === '') {
                return $nextState;
            }
        }

        return null;
    }

    /**
     * @param  array{origin?:string,destination?:string,departure_date?:string,passengers?:int,trip_type?:string}  $details
     * @return array{state:string,message:string}
     */
    private function applyExtractedDetails(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, array $details): array
    {
        if ($details === []) {
            return $this->continueFromMissing($conversation, $flightRequest);
        }

        $flightRequest->update($details);
        $flightRequest->refresh();
        $next = $this->nextMissingState($flightRequest);

        if (! $next) {
            return $this->showSummary($flightRequest);
        }

        return [
            'state' => $next,
            'message' => $this->extractedSummary($flightRequest)."\n".$this->prompt($next, $flightRequest),
        ];
    }

    /**
     * @return array{origin?:string,destination?:string,departure_date?:string,departure_time?:string,passengers?:int,trip_type?:string,legs?:array<int, array{origin:string,destination:string,departure_date:null,departure_time:null}>}
     */
    private function extractFlightDetails(string $message): array
    {
        $normalized = $this->normalize($message);
        $details = [];

        $route = $this->extractRouteSequence($message);
        if (count($route) >= 3) {
            $details['origin'] = $route[0];
            $details['destination'] = $route[1];
            $details['trip_type'] = 'MULTI_CITY';
            $details['legs'] = [];
            for ($index = 2; $index < count($route); $index++) {
                $details['legs'][] = [
                    'origin' => $route[$index - 1],
                    'destination' => $route[$index],
                    'departure_date' => null,
                    'departure_time' => null,
                ];
            }
        } elseif (preg_match('/(?:salida es de|salgo de|salimos de|saliendo de|salir de|desde|de)\s+([\pL .]{2,60}?)\s+\b(?:(?:a(?!\s+las?\b))|hacia|para|vamos a|voy a)\b\s+([\pL .]{2,60}?)(?=\s+(?:el|este|para|con|somos|únicamente|unicamente|solo|sólo|como|a las|\d|$))/iu', $message, $match)) {
            $origin = $this->normalizeLocationValue($match[1]);
            $destination = $this->normalizeLocationValue($match[2]);
            if ($this->isPlausibleLocation($origin) && $this->isPlausibleLocation($destination) && $this->normalize($origin) !== $this->normalize($destination)) {
                $details['origin'] = $origin;
                $details['destination'] = $destination;
            }
        }
        if (! isset($details['origin'], $details['destination'])
            && preg_match('/(?:salgo de|salimos de|saliendo de|salir de|desde|de)\s+([\pL .]{2,60}?)(?=\s+(?:el|este|proximo|próximo|como|a las|somos|voy|vamos|$))/iu', $message, $originMatch)
            && preg_match('/\b(?:voy a|vamos a|(?:a(?!\s+las?\b))|hacia|para)\b\s+([\pL .]{2,60}?)(?=\s+(?:el|este|como|a las|somos|solo|sólo|$))/iu', $message, $destinationMatch)) {
            $origin = $this->normalizeLocationValue($originMatch[1]);
            $destination = $this->normalizeLocationValue($destinationMatch[1]);
            if ($this->isPlausibleLocation($origin) && $this->isPlausibleLocation($destination) && $this->normalize($origin) !== $this->normalize($destination)) {
                $details['origin'] = $origin;
                $details['destination'] = $destination;
            }
        }

        if ($datePhrase = $this->extractDatePhrase($message)) {
            $date = $this->parseDate($datePhrase);
            if ($date && ! $date->lt(Carbon::today(config('whatsapp.timezone')))) {
                $details['departure_date'] = $date->toDateString();
            }
        }

        $time = $this->parseTime($this->extractTimePhrase($message) ?? $message);
        if ($time) {
            $details['departure_time'] = $time;
        }

        $passengers = false;
        if (preg_match('/\b(?:(?:somos|viajamos|seriamos|serian|para|con)\s+)?((?:\d{1,2}|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce)(?:\s+(?:adultos?|ni(?:n|ñ)os?))?(?:\s+y\s+(?:\d{1,2}|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce)\s+(?:adultos?|ni(?:n|ñ)os?))?)\s*(?:pasajeros|personas|pax|adultos|ninos|niños)\b/', $normalized, $passengerMatch)) {
            $passengers = $this->parseCount($passengerMatch[1], false);
        } elseif (preg_match('/\bsomos\s+(\d{1,2}|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce)\b/', $normalized, $passengerMatch)) {
            $passengers = $this->parseCount($passengerMatch[1], false);
        }
        if ($passengers !== false) {
            $details['passengers'] = $passengers;
        }

        if (preg_match('/\b(?:solo|sólo|unicamente|únicamente)\s+ida\b/u', $normalized)) {
            $details['trip_type'] = 'ONE_WAY';
        } elseif (str_contains($normalized, 'ida y vuelta') || str_contains($normalized, 'ida y regreso')) {
            $details['trip_type'] = 'ROUND_TRIP';
        } elseif (str_contains($normalized, 'multidestino') || str_contains($normalized, 'multi destino')) {
            $details['trip_type'] = 'MULTI_CITY';
        }

        return $details;
    }

    /** @return array<int, string> */
    private function extractRouteSequence(string $message): array
    {
        $routeText = preg_replace('/\b(?:quiero|necesito|busco|un|una|vuelo|privado|salida es|la salida es|salir|salgo|salimos|saliendo|desde|de|ir|quiero ir)\b/iu', ' ', $message) ?? $message;
        $routeText = preg_replace('/\b(?:voy|vamos)\s+a\b/iu', ' a ', $routeText) ?? $routeText;
        $routeText = preg_replace('/\b(?:luego|despues|después|y despues|y después|termino en|terminar en|para|hacia)\b/iu', ' a ', $routeText) ?? $routeText;
        $routeText = str_replace(['→', '->', '-', ',', ';'], ' a ', $routeText);
        $parts = preg_split('/\s+\ba\b\s+/iu', $routeText) ?: [];
        $locations = [];

        foreach ($parts as $part) {
            $location = $this->normalizeLocationValue($part);
            if (! $this->isPlausibleLocation($location)) {
                continue;
            }
            if ($locations !== [] && $this->normalize(end($locations)) === $this->normalize($location)) {
                continue;
            }
            $locations[] = $location;
        }

        return $locations;
    }

    private function extractedSummary(WhatsAppFlightRequest $flightRequest): string
    {
        $parts = array_filter([
            $flightRequest->origin && $flightRequest->destination ? $this->routeLine($flightRequest) : null,
            $flightRequest->trip_type === 'ONE_WAY' ? 'solo ida' : null,
            $flightRequest->trip_type === 'MULTI_CITY' ? 'ruta multidestino' : null,
            $flightRequest->passengers ? "para {$flightRequest->passengers} pasajeros" : null,
            $flightRequest->departure_date ? 'el '.$this->displayDate($flightRequest->departure_date) : null,
            $flightRequest->departure_time ? 'a las '.$this->displayTime($flightRequest->departure_time) : null,
        ]);

        return $parts === []
            ? 'Perfecto, sigamos con tu cotización.'
            : 'Perfecto, tengo '.implode(', ', $parts).'.';
    }

    private function extractTimePhrase(string $message): ?string
    {
        return preg_match('/(?:como\s+|alrededor de\s+|sobre\s+)?(?:a\s+las?\s+((?:\d{1,2}|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce)(?::[0-5]\d)?(?:\s*(?:am|pm|de la manana|de la mañana|de la tarde|de la noche))?)|((?:\d{1,2}|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce)(?::[0-5]\d)?\s*(?:am|pm|de la manana|de la mañana|de la tarde|de la noche)|mediod[ií]a|medianoche))/iu', $message, $match)
            ? $match[1]
                ?: $match[2]
            : null;
    }

    private function extractDatePhrase(string $message): ?string
    {
        return preg_match('/\b(\d{1,2}\s+(?:de\s+)?(?:ene|enero|feb|febrero|mar|marzo|abr|abril|may|mayo|jun|junio|jul|julio|ago|agosto|sep|sept|septiembre|oct|octubre|nov|noviembre|dic|diciembre)(?:\s+de\s+\d{4})?|\d{1,2}\/\d{1,2}\/\d{4}|\d{4}-\d{2}-\d{2}|(?:el\s+|este\s+|proximo\s+|pr[oó]ximo\s+)?(?:lunes|martes|miercoles|miércoles|jueves|viernes|sabado|sábado|domingo)|hoy|mañana|manana|pasado mañana|pasado manana)\b/iu', $message, $match)
            ? $match[1]
            : null;
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
        $value = preg_replace('/^(?:la salida es de|salgo de|salimos de|saliendo de|quiero salir de|salir de|desde|de|voy a|vamos a|a|hacia|para)\s+/iu', '', $value) ?? $value;
        $value = preg_replace('/^aeropuerto(?:\s+internacional)?\s+de\s+/iu', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B.,");

        return preg_match('/^[a-z]{3,4}$/i', $value) ? mb_strtoupper($value) : $value;
    }

    private function isPlausibleLocation(string $message): bool
    {
        $value = trim($message);
        $normalized = $this->normalize($value);

        if ($value === '' || mb_strlen($value) > 80) {
            return false;
        }
        if (preg_match('/^[A-Z]{3,4}$/', $value) === 1) {
            return true;
        }
        if (preg_match('/\d|[@|]/', $value) === 1) {
            return false;
        }
        if (preg_match('/\b(?:interesado|comprar|comprarlo|venta|publicacion|refaccion|refacciones|pieza|piezas|precio|cuesta|avion|lunes|martes|miercoles|jueves|viernes|sabado|domingo|manana|tarde|noche|somos|pasajeros|personas)\b/', $normalized) === 1) {
            return false;
        }

        return preg_match('/^[\pL][\pL .\'-]{1,79}$/u', $value) === 1;
    }

    private function extractEmail(string $message): ?string
    {
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $match) ? mb_strtolower($match[0]) : null;
    }

    private function cleanTextAnswer(string $message, string $field): string
    {
        $value = trim($message);

        if ($field === 'client_name') {
            $value = preg_replace('/^(?:soy|mi nombre es|me llamo|a nombre de)\s+/iu', '', $value) ?? $value;
        }
        if ($field === 'company') {
            $value = preg_replace('/^(?:empresa|cotizo para|es para)\s+/iu', '', $value) ?? $value;
        }

        return trim($value, " \t\n\r\0\x0B.,");
    }

    /** @return array{state:string,message:string} */
    private function captureLeg(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $legs = $flightRequest->legs ?? [];
        $normalized = $this->normalize($message);
        $metadata = $conversation->metadata ?? [];
        $capture = $metadata['leg_capture'] ?? null;

        if ($incomplete = $this->nextIncompleteLeg($flightRequest)) {
            return $this->captureIncompleteLeg($conversation, $flightRequest, $message, $incomplete['index']);
        }

        if ($this->isFinished($normalized) && ($legs !== [] || ! $capture)) {
            unset($metadata['leg_capture']);
            $conversation->update(['metadata' => $metadata]);

            return $this->continueFromMissing($conversation, $flightRequest);
        }

        if (! $capture) {
            if ($this->isNegative($normalized)) {
                return $this->continueFromMissing($conversation, $flightRequest);
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

            return ['state' => 'ASK_LEGS', 'message' => 'Ya tengo el máximo de paradas. Continuemos con los demás datos de la cotización.'];
        }

        $legs[] = ['origin' => $previous['destination'], 'destination' => $draft['destination'], 'departure_date' => $draft['departure_date'], 'departure_time' => $time];
        $flightRequest->update(['legs' => $legs]);
        unset($metadata['leg_capture']);
        $conversation->update(['metadata' => $metadata]);

        return ['state' => 'ASK_LEGS', 'message' => "Perfecto, agregué ese tramo.\n{$this->routeSummary($flightRequest->refresh())}\n¿Quieres agregar otra parada o continuamos?"];
    }

    /** @return array{state:string,message:string} */
    private function captureIncompleteLeg(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message, int $index): array
    {
        $legs = $flightRequest->legs ?? [];
        $leg = $legs[$index] ?? null;
        if (! $leg) {
            return $this->continueFromMissing($conversation, $flightRequest);
        }

        $previous = $index === 0
            ? ['departure_date' => $flightRequest->departure_date?->toDateString(), 'departure_time' => $flightRequest->departure_time]
            : $legs[$index - 1];

        if (empty($leg['departure_date'])) {
            $date = $this->parseDate($this->extractDatePhrase($message) ?? $message, $previous['departure_date'] ?? null);
            if (! $date) {
                return ['state' => 'ASK_LEGS', 'message' => 'No alcancé a identificar la fecha. '.$this->incompleteLegPrompt($flightRequest)];
            }
            if ($date->lt(Carbon::today(config('whatsapp.timezone'))) || $date->toDateString() < ($previous['departure_date'] ?? '')) {
                return ['state' => 'ASK_LEGS', 'message' => 'Esa fecha ya pasó o queda antes del tramo anterior. ¿Qué otra fecha tienes en mente?'];
            }
            $leg['departure_date'] = $date->toDateString();
            if ($time = $this->parseTime($this->extractTimePhrase($message) ?? $message)) {
                $leg['departure_time'] = $time;
            }
        } elseif (empty($leg['departure_time'])) {
            $time = $this->parseTime($this->extractTimePhrase($message) ?? $message);
            if (! $time) {
                return ['state' => 'ASK_LEGS', 'message' => 'No estoy seguro de la hora. ¿Sería, por ejemplo, 8:00 am o 2:30 pm?'];
            }
            $leg['departure_time'] = $time;
        }

        if (! empty($leg['departure_date']) && ! empty($leg['departure_time']) && ($leg['departure_date'].' '.$leg['departure_time'] <= ($previous['departure_date'] ?? '').' '.($previous['departure_time'] ?? ''))) {
            return ['state' => 'ASK_LEGS', 'message' => 'Ese tramo debe salir después del tramo anterior. ¿Qué horario prefieres?'];
        }

        $legs[$index] = $leg;
        $flightRequest->update(['legs' => $legs]);
        $flightRequest->refresh();

        return $this->continueFromMissing($conversation, $flightRequest, 'Perfecto, actualicé ese tramo.');
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
            '7', 'aeronave' => ['ASK_AIRCRAFT_PREFERENCE'],
            '8', 'servicios' => ['ASK_TIME_FLEXIBILITY', 'ASK_ALTERNATE_AIRPORTS', 'ASK_OTHER_SERVICES'],
            '9', 'datos personales' => ['ASK_NAME', 'ASK_EMAIL', 'ASK_COMPANY'],
            '10', 'presupuesto' => ['ASK_BUDGET'],
            '11', 'observaciones' => ['ASK_NOTES'],
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
        return ['state' => 'EDIT_FIELD', 'message' => "¿Qué deseas modificar?\n1. Origen\n2. Destino\n3. Fecha\n4. Hora\n5. Pasajeros\n6. Viaje (incluye regreso/tramos)\n7. Aeronave\n8. Servicios y flexibilidad\n9. Datos personales\n10. Presupuesto\n11. Observaciones"];
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
            if (in_array($question['field'], ['company', 'budget', 'legs', 'return_date', 'return_time', 'aircraft_preference', 'other_services', 'notes'], true)) {
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
        if ($flightRequest->trip_type) {
            $lines[] = '➡️ '.$this->tripTypeLabel($flightRequest->trip_type);
        }
        if ($flightRequest->departure_date || $flightRequest->departure_time) {
            $lines[] = '📅 '.$this->dateTimeLine($flightRequest->departure_date, $flightRequest->departure_time);
        }
        if ($flightRequest->trip_type === 'ROUND_TRIP' && ($flightRequest->return_date || $flightRequest->return_time)) {
            $lines[] = '🔁 Regreso: '.$this->dateTimeLine($flightRequest->return_date, $flightRequest->return_time);
        }
        foreach ($flightRequest->legs ?? [] as $index => $leg) {
            $lines[] = sprintf(
                '🛫 Tramo %d: %s → %s%s',
                $index + 2,
                $leg['origin'] ?? '',
                $leg['destination'] ?? '',
                isset($leg['departure_date'], $leg['departure_time']) ? ', '.$this->dateTimeLine($leg['departure_date'], $leg['departure_time']) : '',
            );
        }
        if ($flightRequest->passengers) {
            $lines[] = '👥 '.$flightRequest->passengers.' pasajeros';
        }
        if ($flightRequest->aircraft_preference) {
            $lines[] = '🛩️ Preferencia de aeronave: '.Str::limit($flightRequest->aircraft_preference, 100);
        }
        if ($flightRequest->is_time_flexible !== null) {
            $lines[] = '🕐 '.($flightRequest->is_time_flexible ? 'Horario flexible' : 'Horario fijo');
        }
        if ($flightRequest->other_services) {
            $lines[] = '➕ Otros servicios: '.Str::limit($flightRequest->other_services, 120);
        }
        if ($flightRequest->client_name) {
            $lines[] = '';
            $lines[] = 'A nombre de '.$flightRequest->client_name;
        }
        if ($flightRequest->client_email) {
            $lines[] = $flightRequest->client_email;
        }
        if ($flightRequest->company) {
            $lines[] = '🏢 Cotización para '.$flightRequest->company;
        }
        if ($flightRequest->budget !== null && $flightRequest->budget !== '') {
            $lines[] = '💰 Presupuesto aproximado: '.number_format((float) $flightRequest->budget, 0).' USD';
        }
        if ($flightRequest->notes) {
            $lines[] = 'Notas: '.Str::limit($flightRequest->notes, 120);
        }

        return implode("\n", $lines);
    }

    private function tripTypeLabel(string $tripType): string
    {
        return match ($tripType) {
            'ROUND_TRIP' => 'Ida y vuelta',
            'MULTI_CITY' => 'Multidestino',
            default => 'Solo ida',
        };
    }

    private function dateTimeLine(mixed $date, ?string $time): string
    {
        return trim(implode(' a las ', array_filter([
            $this->displayDate($date),
            $this->displayTime($time),
        ])));
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'Sí' : 'No';
    }

    private function displayDate(mixed $date): ?string
    {
        if (! $date) {
            return null;
        }
        $date = $date instanceof Carbon ? $date : Carbon::parse($date, config('whatsapp.timezone'));
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $date->day.' de '.$months[$date->month - 1];
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
        if ($this->isAffirmative($message) || str_contains($message, 'por favor') || str_contains($message, 'si necesitamos') || ($allowDetails && preg_match('/^si[ ,:]/', $message))) {
            return true;
        }

        if ($this->isNegative($message)
            || str_contains($message, 'no gracias')
            || str_contains($message, 'por el momento no')
            || str_contains($message, 'no necesito')
            || str_starts_with($message, 'sin ')
        ) {
            return false;
        }

        return null;
    }

    private function isAffirmative(string $message): bool
    {
        return in_array($message, ['si', 's', 'yes', '1', 'claro', 'por supuesto', 'correcto', 'afirmativo', 'ok', 'va', 'dale'], true);
    }

    private function isNegative(string $message): bool
    {
        return in_array($message, ['no', 'n', '2', '0', 'ninguno', 'ninguna', 'nada', 'sin', 'omitir', 'terminar', 'ya esta', 'ya está', 'no tengo', 'aun no', 'aún no', 'no se', 'no sé', 'flexible', 'sin presupuesto', 'sin presupuesto definido'], true);
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
        $total = 0;
        if (preg_match_all('/(?<![-.\d])(\d{1,2})(?![.\d])\s*(?:adultos?|ni(?:n|ñ)os?|pasajeros?|personas?|pax)?/', $message, $matches)) {
            foreach ($matches[1] as $match) {
                $total += (int) $match;
            }
            if ($total > 0) {
                return $total >= ($allowZero ? 0 : 1) && $total <= 99 ? $total : false;
            }
        }
        $words = $this->numberWords();
        $wordTotal = 0;
        foreach ($words as $word => $number) {
            if (preg_match('/\b'.$word.'\b/', $message)) {
                $wordTotal += $number;
            }
        }
        if ($wordTotal > 0) {
            return $wordTotal <= 99 ? $wordTotal : false;
        }
        if (preg_match('/(?<![-.\d])(\d{1,2})(?![.\d])/', $message, $match)) {
            $count = (int) $match[1];

            return $count >= ($allowZero ? 0 : 1) && $count <= 99 ? $count : false;
        }

        return false;
    }

    private function parseMoney(string $message): int|false|null
    {
        if ($this->isNegative($message)) {
            return null;
        }

        $clean = str_replace([',', '$'], '', $message);
        $clean = preg_replace('/\b(?:aprox|aproximadamente|unos|como|alrededor de|usd|dolares|dolares americanos|mxn|pesos)\b/', ' ', $clean) ?? $clean;
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);

        if (preg_match('/(\d+(?:\.\d+)?)\s*k\b/', $clean, $match)) {
            return (int) round(((float) $match[1]) * 1000);
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*mil\b/', $clean, $match)) {
            return (int) round(((float) $match[1]) * 1000);
        }

        if (preg_match('/\b(\d{3,9})(?:\.\d{1,2})?\b/', $clean, $match)) {
            return (int) $match[1];
        }

        return false;
    }

    private function parseTripType(string $message): ?string
    {
        return match (true) {
            in_array($message, ['1', 'one_way', 'one way', 'sencillo', 'solo ida', 'ida', 'solo de ida', 'sin regreso', 'ow'], true) => 'ONE_WAY',
            in_array($message, ['2', 'round_trip', 'round trip', 'redondo', 'viaje redondo', 'ida y vuelta', 'ida y regreso', 'regreso', 'rt'], true) => 'ROUND_TRIP',
            in_array($message, ['3', 'multi_city', 'multi city', 'multidestino', 'multi destino', 'varios destinos'], true) => 'MULTI_CITY',
            default => null,
        };
    }

    private function invalidMessage(string $type, string $label): string
    {
        return match ($type) {
            'date' => 'No entendí la fecha.',
            'time' => 'No entendí la hora.',
            'location' => 'No alcancé a identificar una ciudad o aeropuerto. ¿Me lo compartes nuevamente?',
            'passengers' => 'Necesito cuántas personas viajan.',
            'count' => 'Necesito un número para '.$this->normalize($label).'.',
            'boolean' => 'Necesito una respuesta de sí o no.',
            'email' => 'Ese correo no parece válido.',
            'trip' => 'Necesito saber si es sólo ida, ida y vuelta o multidestino.',
            'money' => "No alcancé a identificar un presupuesto.\n¿Me puedes dar un monto aproximado? Por ejemplo: 20,000 USD.\nSi todavía no tienes uno, puedes decirme sin presupuesto definido.",
            default => 'No entendí ese dato.',
        };
    }

    private function parseDate(string $message, mixed $baseDate = null): ?Carbon
    {
        $message = $this->normalize($message);
        $today = $baseDate ? ($baseDate instanceof Carbon ? $baseDate->copy() : Carbon::parse($baseDate, config('whatsapp.timezone'))) : Carbon::today(config('whatsapp.timezone'));
        if (in_array($message, ['hoy', 'manana', 'mañana', 'pasado manana', 'pasado mañana'], true)) {
            return $today->addDays(['hoy' => 0, 'manana' => 1, 'mañana' => 1, 'pasado manana' => 2, 'pasado mañana' => 2][$message]);
        }
        if (preg_match('/^(?:(?:este|el|proximo|pr[oó]ximo)\s+)*(lunes|martes|miercoles|jueves|viernes|sabado|domingo)$/', $message, $match)) {
            $day = array_search($match[1], ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'], true);
            $days = ($day - $today->dayOfWeek + 7) % 7;
            if (str_contains($message, 'proximo') || str_contains($message, 'proximo') || $days === 0) {
                $days = $days === 0 ? 7 : $days;
            }

            return $today->addDays($days);
        }
        if (preg_match('/^(\d{1,2})(?: de)? (ene|enero|feb|febrero|mar|marzo|abr|abril|may|mayo|jun|junio|jul|julio|ago|agosto|sep|sept|septiembre|oct|octubre|nov|noviembre|dic|diciembre)(?: de (\d{4}))?$/', $message, $match)) {
            $month = $this->spanishMonthNumber($match[2]);
            $year = (int) ($match[3] ?? $today->year);
            if (! isset($match[3]) && sprintf('%04d-%02d-%02d', $year, $month, $match[1]) < $today->toDateString()) {
                $year++;
            }
            $message = sprintf('%04d-%02d-%02d', $year, $month, $match[1]);
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $message, $match)) {
            $message = sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]);
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
        if (in_array($message, ['mediodia', 'medio dia'], true)) {
            return '12:00:00';
        }
        if (in_array($message, ['medianoche', 'media noche'], true)) {
            return '00:00:00';
        }
        if (in_array($message, ['manana', 'por la manana', 'en la manana', 'tarde', 'por la tarde', 'en la tarde', 'noche', 'por la noche', 'en la noche', 'temprano'], true)) {
            return null;
        }
        $words = $this->numberWords();
        foreach ($words as $word => $number) {
            $message = preg_replace('/\b'.$word.'\b/', (string) $number, $message) ?? $message;
        }
        if (preg_match('/^(0?[1-9]|1[0-2])\s*(am|pm)$/', $message, $match)) {
            $hour = (int) $match[1] % 12;
            if ($match[2] === 'pm') {
                $hour += 12;
            }

            return sprintf('%02d:00:00', $hour);
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

    /** @return array<string, int> */
    private function numberWords(): array
    {
        return [
            'un' => 1, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5,
            'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'once' => 11, 'doce' => 12,
        ];
    }

    private function spanishMonthNumber(string $month): int
    {
        return [
            'ene' => 1, 'enero' => 1, 'feb' => 2, 'febrero' => 2, 'mar' => 3, 'marzo' => 3,
            'abr' => 4, 'abril' => 4, 'may' => 5, 'mayo' => 5, 'jun' => 6, 'junio' => 6,
            'jul' => 7, 'julio' => 7, 'ago' => 8, 'agosto' => 8, 'sep' => 9, 'sept' => 9,
            'septiembre' => 9, 'oct' => 10, 'octubre' => 10, 'nov' => 11, 'noviembre' => 11,
            'dic' => 12, 'diciembre' => 12,
        ][$month];
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
