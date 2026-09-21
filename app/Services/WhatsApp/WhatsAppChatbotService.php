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
        'ASK_DEPARTURE_DATE' => ['field' => 'departure_date', 'label' => 'Fecha de salida', 'prompt' => 'Indica la fecha de salida (AAAA-MM-DD o una fecha relativa).', 'type' => 'date'],
        'ASK_DEPARTURE_TIME' => ['field' => 'departure_time', 'label' => 'Hora de salida', 'prompt' => '¿A qué hora deseas salir? Usa el formato HH:MM.', 'type' => 'time'],
        'ASK_TIME_FLEXIBILITY' => ['field' => 'is_time_flexible', 'label' => 'Horario flexible', 'prompt' => '¿Tu horario es flexible? Sí o no.', 'type' => 'boolean'],
        'ASK_PASSENGERS' => ['field' => 'passengers', 'label' => 'Pasajeros', 'prompt' => '¿Cuántos pasajeros viajarán? (1 a 99)', 'type' => 'passengers'],
        'ASK_TRIP_TYPE' => ['field' => 'trip_type', 'label' => 'Viaje', 'prompt' => "¿Qué tipo de viaje necesitas?\n1. Solo ida\n2. Ida y vuelta\n3. Multidestino", 'type' => 'trip'],
        'ASK_RETURN_DATE' => ['field' => 'return_date', 'label' => 'Fecha de regreso', 'prompt' => 'Indica la fecha de regreso.', 'type' => 'date'],
        'ASK_RETURN_TIME' => ['field' => 'return_time', 'label' => 'Hora de regreso', 'prompt' => 'Indica la hora de regreso (HH:MM).', 'type' => 'time'],
        'ASK_LEGS' => ['field' => 'legs', 'label' => 'Tramos adicionales', 'prompt' => 'Agrega el siguiente tramo desde tu último destino: destino | fecha (AAAA-MM-DD) | hora (HH:MM). Después escribe listo. Máximo 6 tramos adicionales.', 'type' => 'legs'],
        'ASK_LUGGAGE' => ['field' => 'luggage_count', 'label' => 'Cantidad de equipaje', 'prompt' => '¿Cuántas piezas de equipaje llevarán? Escribe un número, incluso 0.', 'type' => 'count'],
        'ASK_LUGGAGE_DESCRIPTION' => ['field' => 'luggage_description', 'label' => 'Equipaje', 'prompt' => 'Describe el equipaje (tamaño/peso aproximado), o escribe ninguno.', 'type' => 'text'],
        'ASK_SPECIAL_LUGGAGE' => ['field' => 'special_luggage', 'label' => 'Equipaje especial', 'prompt' => '¿Llevas equipaje especial? Descríbelo o escribe ninguno.', 'type' => 'text'],
        'ASK_PETS' => ['field' => 'has_pets', 'label' => 'Mascotas', 'prompt' => '¿Viajan mascotas? Sí o no; si sí, puedes añadir especie, cantidad y peso.', 'type' => 'pets'],
        'ASK_AIRCRAFT_PREFERENCE' => ['field' => 'aircraft_preference', 'label' => 'Aeronave', 'prompt' => '¿Tienes preferencia de aeronave? Puedes escribir sin preferencia.', 'type' => 'text'],
        'ASK_ALTERNATE_AIRPORTS' => ['field' => 'allow_alternate_airports', 'label' => 'Aeropuertos alternos', 'prompt' => '¿Aceptas aeropuertos alternos? Sí o no.', 'type' => 'boolean'],
        'ASK_CATERING' => ['field' => 'catering_required', 'label' => 'Catering', 'prompt' => '¿Requieres catering? Sí o no.', 'type' => 'boolean'],
        'ASK_GROUND_TRANSPORT' => ['field' => 'ground_transport_required', 'label' => 'Transporte terrestre', 'prompt' => '¿Requieres transporte terrestre? Sí o no.', 'type' => 'boolean'],
        'ASK_WIFI' => ['field' => 'wifi_required', 'label' => 'Wi-Fi', 'prompt' => '¿Requieres Wi-Fi? Sí o no.', 'type' => 'boolean'],
        'ASK_OTHER_SERVICES' => ['field' => 'other_services', 'label' => 'Otros servicios', 'prompt' => '¿Necesitas otros servicios o escalas técnicas? Descríbelos o escribe ninguno.', 'type' => 'text'],
        'ASK_NAME' => ['field' => 'client_name', 'label' => 'Nombre', 'prompt' => '¿Cuál es tu nombre completo?', 'type' => 'text'],
        'ASK_EMAIL' => ['field' => 'client_email', 'label' => 'Correo', 'prompt' => '¿Cuál es tu correo electrónico?', 'type' => 'email'],
        'ASK_COMPANY' => ['field' => 'company', 'label' => 'Empresa', 'prompt' => 'Empresa (opcional). Escribe omitir si no aplica.', 'type' => 'optional'],
        'ASK_BUDGET' => ['field' => 'budget', 'label' => 'Presupuesto', 'prompt' => 'Presupuesto aproximado y moneda (opcional). Puedes escribir omitir.', 'type' => 'optional'],
        'ASK_NOTES' => ['field' => 'notes', 'label' => 'Observaciones', 'prompt' => '¿Tienes observaciones adicionales? Puedes escribir ninguna.', 'type' => 'text'],
    ];

    public function __construct(private readonly FlightApiService $flightApiService) {}

    /** @return array{state:string,message:string} */
    public function handleIncomingMessage(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $normalized = $this->normalize($message);
        if (in_array($normalized, ['asesor', 'humano', 'agente', 'ayuda', 'transferir'], true)) {
            return ['state' => 'TRANSFER_TO_HUMAN', 'message' => 'Te conectaremos con un asesor para continuar con tu solicitud.'];
        }
        if ($conversation->state === 'TRANSFER_TO_HUMAN') {
            return ['state' => 'TRANSFER_TO_HUMAN', 'message' => ''];
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

    private function normalize(string $message): string
    {
        return Str::of($message)->lower()->ascii()->trim()->squish()->toString();
    }

    /** @return array{state:string,message:string} */
    private function question(string $state, ?string $error = null): array
    {
        return ['state' => $state, 'message' => ($error ? $error."\n" : '').self::QUESTIONS[$state]['prompt']];
    }

    /** @return array{state:string,message:string} */
    private function captureAnswer(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $state = $conversation->state;
        $question = self::QUESTIONS[$state];
        $field = $question['field'];
        $normalized = $this->normalize($message);
        if ($message === '' || mb_strlen($message) > 250) {
            return $this->question($state, 'Escribe una respuesta de 1 a 250 caracteres.');
        }
        if ($question['type'] === 'legs') {
            return $this->captureLeg($conversation, $flightRequest, $message);
        }
        $value = match ($question['type']) {
            'date' => $this->parseDate($message)?->toDateString(),
            'time' => $this->parseTime($message),
            'passengers', 'count' => filter_var($message, FILTER_VALIDATE_INT, ['options' => ['min_range' => $question['type'] === 'count' ? 0 : 1, 'max_range' => 99]]),
            'boolean', 'pets' => $this->parseBoolean($normalized, $question['type'] === 'pets'),
            'email' => filter_var($message, FILTER_VALIDATE_EMAIL) ?: null,
            'trip' => match ($normalized) {
                '1', 'one_way', 'one way', 'sencillo', 'solo ida', 'ida' => 'ONE_WAY',
                '2', 'round_trip', 'round trip', 'redondo', 'ida y vuelta' => 'ROUND_TRIP',
                '3', 'multi_city', 'multi city', 'multidestino', 'multi destino' => 'MULTI_CITY',
                default => null,
            },
            default => $message,
        };
        if ($value === null || (in_array($question['type'], ['passengers', 'count'], true) && $value === false)) {
            return $this->question($state, 'No pude reconocer ese dato.');
        }
        if ($question['type'] === 'location') {
            $other = $field === 'origin' ? $flightRequest->destination : $flightRequest->origin;
            if ($other && $normalized === $this->normalize($other)) {
                return $this->question($state, 'Origen y destino deben ser diferentes.');
            }
        }
        if ($field === 'return_date' && $value < $flightRequest->departure_date?->toDateString()) {
            return $this->question($state, 'El regreso no puede ser anterior a la salida.');
        }
        if ($field === 'return_time' && $flightRequest->return_date?->toDateString() === $flightRequest->departure_date?->toDateString() && $value <= $flightRequest->departure_time) {
            return $this->question($state, 'El regreso debe ser posterior a la salida.');
        }
        if ($question['type'] === 'optional' && in_array($normalized, ['omitir', 'no', 'ninguna', 'ninguno'], true)) {
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

        return $next === 'SHOW_SUMMARY' ? $this->showSummary($flightRequest) : $this->question($next);
    }

    /** @return array{state:string,message:string} */
    private function captureLeg(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $legs = $flightRequest->legs ?? [];
        if ($this->normalize($message) === 'listo' && count($legs) > 0) {
            return $this->advance($conversation, $flightRequest, 'ASK_LUGGAGE');
        }
        $parts = array_map('trim', explode('|', $message));
        $previous = $legs === [] ? ['destination' => $flightRequest->destination, 'departure_date' => $flightRequest->departure_date?->toDateString(), 'departure_time' => $flightRequest->departure_time] : $legs[array_key_last($legs)];
        $date = isset($parts[1]) ? $this->parseDate($parts[1]) : null;
        $time = isset($parts[2]) ? $this->parseTime($parts[2]) : null;
        if (count($parts) !== 3 || count($legs) >= 6 || $parts[0] === '' || ! $date || ! $time || $this->normalize($parts[0]) === $this->normalize($previous['destination']) || $date->toDateString().' '.$time <= $previous['departure_date'].' '.$previous['departure_time']) {
            return $this->question('ASK_LEGS', 'Agrega un destino diferente, con fecha/hora posterior al tramo anterior; o escribe listo si ya agregaste tramos.');
        }
        $legs[] = ['origin' => $previous['destination'], 'destination' => $parts[0], 'departure_date' => $date->toDateString(), 'departure_time' => $time];
        $flightRequest->update(['legs' => $legs]);

        return $this->question('ASK_LEGS', 'Tramo guardado.');
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
        if (in_array($choice, ['2', 'modificar', 'modificar informacion'], true)) {
            return $this->editMenu();
        }
        if (in_array($choice, ['3', 'cancelar'], true)) {
            $flightRequest->update(['status' => 'cancelled']);

            return ['state' => 'CANCELLED', 'message' => 'Solicitud cancelada. Escribe de nuevo si deseas iniciar otra cotización.'];
        }
        if (in_array($choice, ['1', 'si', 'si, solicitar cotizacion', 'solicitar cotizacion', 'confirmar'], true)) {
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
        if (! $this->parseDate($flightRequest->departure_date?->toDateString() ?? '')) {
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
        return ['state' => 'SHOW_SUMMARY', 'message' => $this->summaryMessage($flightRequest)."\n\n¿Deseas solicitar la cotización?\n1. Sí, solicitar cotización\n2. Modificar información\n3. Cancelar"];
    }

    public function summaryMessage(WhatsAppFlightRequest $flightRequest): string
    {
        $lines = ['✈️ Resumen de solicitud'];
        foreach (self::QUESTIONS as $question) {
            $field = $question['field'];
            if ($field === 'legs' || (str_starts_with($field, 'return_') && $flightRequest->trip_type !== 'ROUND_TRIP')) {
                continue;
            }
            $value = $flightRequest->{$field};
            if ($value instanceof Carbon) {
                $value = $value->toDateString();
            } elseif (is_bool($value)) {
                $value = $value ? 'Sí' : 'No';
            } elseif ($field === 'trip_type') {
                $value = ['ONE_WAY' => 'Solo ida', 'ROUND_TRIP' => 'Ida y vuelta', 'MULTI_CITY' => 'Multidestino'][$value] ?? $value;
            }
            $lines[] = $question['label'].': '.Str::limit((string) ($value ?? 'Sin indicar'), 80);
        }
        if ($flightRequest->has_pets && $flightRequest->pets_description) {
            $lines[] = 'Detalle mascotas: '.Str::limit($flightRequest->pets_description, 80);
        }
        foreach ($flightRequest->legs ?? [] as $index => $leg) {
            $lines[] = 'Tramo '.($index + 2).': '.Str::limit($leg['origin'], 40).' → '.Str::limit($leg['destination'], 40).' '.$leg['departure_date'].' '.$leg['departure_time'];
        }

        return implode("\n", $lines);
    }

    private function parseBoolean(string $message, bool $allowDetails = false): ?bool
    {
        if (in_array($message, ['si', 's', 'yes', '1', 'claro', 'por supuesto'], true) || ($allowDetails && preg_match('/^si[ ,:]/', $message))) {
            return true;
        }

        return in_array($message, ['no', 'n', '2', '0', 'ninguno', 'ninguna'], true) ? false : null;
    }

    private function parseDate(string $message): ?Carbon
    {
        $message = $this->normalize($message);
        $today = Carbon::today(config('whatsapp.timezone'));
        if (in_array($message, ['hoy', 'manana', 'pasado manana'], true)) {
            return $today->addDays(['hoy' => 0, 'manana' => 1, 'pasado manana' => 2][$message]);
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

        return $date && $date->gte($today) ? $date : null;
    }

    private function parseTime(string $message): ?string
    {
        $message = $this->normalize($message);
        if (preg_match('/^(0?[1-9]|1[0-2])(?::([0-5]\d))? (?:de la )?(manana|tarde|noche|am|pm)$/', $message, $match)) {
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
