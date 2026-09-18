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
    public function __construct(private readonly FlightApiService $flightApiService) {}

    /**
     * @return array{state:string,message:string}
     */
    public function handleIncomingMessage(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $normalizedMessage = $this->normalize($message);

        if ($this->wantsHumanTransfer($normalizedMessage)) {
            return [
                'state' => 'TRANSFER_TO_HUMAN',
                'message' => 'Te conectaremos con un asesor para continuar con tu solicitud.',
            ];
        }

        return match ($conversation->state) {
            'START' => $this->askOrigin(),
            'ASK_ORIGIN' => $this->captureOrigin($flightRequest, $message),
            'ASK_DESTINATION' => $this->captureDestination($flightRequest, $message),
            'ASK_DEPARTURE_DATE' => $this->captureDepartureDate($flightRequest, $message),
            'ASK_DEPARTURE_TIME' => $this->captureDepartureTime($flightRequest, $message),
            'ASK_PASSENGERS' => $this->capturePassengers($flightRequest, $message),
            'ASK_TRIP_TYPE' => $this->captureTripType($flightRequest, $normalizedMessage),
            'ASK_RETURN_DATE' => $this->captureReturnDate($flightRequest, $message),
            'ASK_RETURN_TIME' => $this->captureReturnTime($flightRequest, $message),
            'SEARCH_FLIGHTS' => $this->searchFlights($flightRequest),
            'SHOW_RESULTS' => $this->showResults($flightRequest),
            'SELECT_AIRCRAFT' => $this->selectAircraft($flightRequest, $message),
            'CREATE_QUOTE' => $this->createQuote($flightRequest),
            'FINISHED' => [
                'state' => 'FINISHED',
                'message' => 'Tu cotización ya fue registrada. Un asesor puede ayudarte si necesitas hacer cambios.',
            ],
            default => $this->askOrigin(),
        };
    }

    /**
     * @return array{state:string,message:string}
     */
    public function continueAutomatedState(WhatsAppConversation $conversation, WhatsAppFlightRequest $flightRequest): array
    {
        return match ($conversation->state) {
            'SEARCH_FLIGHTS' => $this->searchFlights($flightRequest),
            'SHOW_RESULTS' => $this->showResults($flightRequest),
            'CREATE_QUOTE' => $this->createQuote($flightRequest),
            default => [
                'state' => $conversation->state,
                'message' => '',
            ],
        };
    }

    private function normalize(string $message): string
    {
        return Str::of($message)->lower()->ascii()->trim()->toString();
    }

    private function wantsHumanTransfer(string $message): bool
    {
        return in_array($message, ['asesor', 'humano', 'agente', 'ayuda', 'transferir'], true);
    }

    /**
     * @return array{state:string,message:string}
     */
    private function askOrigin(): array
    {
        return [
            'state' => 'ASK_ORIGIN',
            'message' => 'Hola. Para cotizar tu vuelo privado, dime el origen de salida.',
        ];
    }

    /**
     * @return array{state:string,message:string}
     */
    private function captureOrigin(WhatsAppFlightRequest $flightRequest, string $message): array
    {
        if (trim($message) === '') {
            return ['state' => 'ASK_ORIGIN', 'message' => 'Por favor dime el origen de salida.'];
        }

        $flightRequest->update(['origin' => trim($message)]);

        return ['state' => 'ASK_DESTINATION', 'message' => 'Gracias. Ahora dime el destino.'];
    }

    /**
     * @return array{state:string,message:string}
     */
    private function captureDestination(WhatsAppFlightRequest $flightRequest, string $message): array
    {
        if (trim($message) === '') {
            return ['state' => 'ASK_DESTINATION', 'message' => 'Por favor dime el destino.'];
        }

        $flightRequest->update(['destination' => trim($message)]);

        return ['state' => 'ASK_DEPARTURE_DATE', 'message' => 'Indica la fecha de salida en formato AAAA-MM-DD.'];
    }

    /**
     * @return array{state:string,message:string}
     */
    private function captureDepartureDate(WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $date = $this->parseDate($message);

        if (! $date) {
            return ['state' => 'ASK_DEPARTURE_DATE', 'message' => 'No pude reconocer la fecha. Usa el formato AAAA-MM-DD, por ejemplo 2026-10-15.'];
        }

        $flightRequest->update(['departure_date' => $date->toDateString()]);

        return ['state' => 'ASK_DEPARTURE_TIME', 'message' => 'Perfecto. Ahora dime la hora de salida en formato HH:MM.'];
    }

    /**
     * @return array{state:string,message:string}
     */
    private function captureDepartureTime(WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $time = $this->parseTime($message);

        if (! $time) {
            return ['state' => 'ASK_DEPARTURE_TIME', 'message' => 'No pude reconocer la hora. Usa formato HH:MM, por ejemplo 14:30.'];
        }

        $flightRequest->update(['departure_time' => $time]);

        return ['state' => 'ASK_PASSENGERS', 'message' => 'Cuantos pasajeros viajaran?'];
    }

    /**
     * @return array{state:string,message:string}
     */
    private function capturePassengers(WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $passengers = filter_var(trim($message), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);

        if (! $passengers) {
            return ['state' => 'ASK_PASSENGERS', 'message' => 'Indica el numero de pasajeros con un numero entre 1 y 99.'];
        }

        $flightRequest->update(['passengers' => $passengers]);

        return ['state' => 'ASK_TRIP_TYPE', 'message' => 'El viaje es sencillo o redondo? Responde ONE_WAY o ROUND_TRIP.'];
    }

    /**
     * @return array{state:string,message:string}
     */
    private function captureTripType(WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $tripType = match ($message) {
            'one_way', 'one way', 'sencillo', 'solo ida', 'ida' => 'ONE_WAY',
            'round_trip', 'round trip', 'redondo', 'ida y vuelta', 'vuelta' => 'ROUND_TRIP',
            default => null,
        };

        if (! $tripType) {
            return ['state' => 'ASK_TRIP_TYPE', 'message' => 'Responde ONE_WAY para sencillo o ROUND_TRIP para viaje redondo.'];
        }

        $flightRequest->update(['trip_type' => $tripType]);

        if ($tripType === 'ROUND_TRIP') {
            return ['state' => 'ASK_RETURN_DATE', 'message' => 'Indica la fecha de regreso en formato AAAA-MM-DD.'];
        }

        return ['state' => 'SEARCH_FLIGHTS', 'message' => $this->summaryMessage($flightRequest->refresh())."\n\nBuscaremos opciones disponibles."];
    }

    /**
     * @return array{state:string,message:string}
     */
    private function captureReturnDate(WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $date = $this->parseDate($message);

        if (! $date) {
            return ['state' => 'ASK_RETURN_DATE', 'message' => 'No pude reconocer la fecha de regreso. Usa AAAA-MM-DD.'];
        }

        $flightRequest->update(['return_date' => $date->toDateString()]);

        return ['state' => 'ASK_RETURN_TIME', 'message' => 'Ahora dime la hora de regreso en formato HH:MM.'];
    }

    /**
     * @return array{state:string,message:string}
     */
    private function captureReturnTime(WhatsAppFlightRequest $flightRequest, string $message): array
    {
        $time = $this->parseTime($message);

        if (! $time) {
            return ['state' => 'ASK_RETURN_TIME', 'message' => 'No pude reconocer la hora de regreso. Usa formato HH:MM.'];
        }

        $flightRequest->update(['return_time' => $time]);

        return ['state' => 'SEARCH_FLIGHTS', 'message' => $this->summaryMessage($flightRequest->refresh())."\n\nBuscaremos opciones disponibles."];
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

        return ['state' => 'SHOW_RESULTS', 'message' => 'Encontramos opciones compatibles con tu solicitud.'];
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
                'message' => 'Buscaré nuevas opciones disponibles para tu ruta.',
            ];
        }

        $options = $results
            ->take(10)
            ->map(fn (array $result, int $index): string => implode("\n", array_filter([
                'Opción '.($index + 1),
                (string) ($result['aircraft_name'] ?? 'Aeronave disponible'),
                isset($result['capacity']) ? $result['capacity'].' pasajeros' : null,
                isset($result['display_time']) ? 'Tiempo: '.$result['display_time'] : null,
                isset($result['total']) ? 'Precio: '.$this->money($result['total'], (string) ($result['currency'] ?? 'USD')) : null,
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
            return ['state' => 'SEARCH_FLIGHTS', 'message' => 'Esa opción no tiene identificador válido. Buscaré opciones actualizadas.'];
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
                'message' => 'Esa aeronave ya no se encuentra disponible. Te muestro alternativas actualizadas.',
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

        return ['state' => 'CREATE_QUOTE', 'message' => 'Prepararemos tu cotizacion con la aeronave seleccionada.'];
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

    private function parseDate(string $message): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($message))) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($message))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseTime(string $message): ?string
    {
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($message))) {
            return null;
        }

        return trim($message).':00';
    }

    private function summaryMessage(WhatsAppFlightRequest $flightRequest): string
    {
        return "Resumen:\nOrigen: {$flightRequest->origin}\nDestino: {$flightRequest->destination}\nSalida: {$flightRequest->departure_date?->toDateString()} {$flightRequest->departure_time}\nPasajeros: {$flightRequest->passengers}\nTipo: {$flightRequest->trip_type}";
    }
}
