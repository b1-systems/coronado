<?php

/**
 * Coronado Public UI
 *
 * This is the page where the anonymous user requests his ticket.
 */

declare(strict_types=1);

namespace Horde\Coronado\Ui;

use Horde\Coronado\Ui\RestBase;
use Horde\Coronado\TicketReserver;
// These are all technically not needed but may help you write code faster
use Horde\Injector\Injector;

/**
 * The standard PSR-7/PSR-15/PSR-17 fare.
 */

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Horde\Coronado\CoronadoException;
use Horde_Registry;
use Horde_Session;
use Horde\Log\Logger;
use Horde_Date;

/**
 * Controller class for Public UI
 */
class RestGetTicket extends RestBase
{
    /**
     * Returns the json encoded data as a response stream.
     */
    protected function getResponseStream(array $data): ?StreamInterface
    {
        return $this->streamFactory->createStream(json_encode($data));
    }

    /**
     * Parse the request body as json. Checks if all values are present and valid.
     * If the requester is eligible for a vaccination, reserve an available ticket and return it.
     */
    protected function buildResponseStream(ServerRequestInterface $request): ?StreamInterface
    {
        $method = $request->getMethod();
        if ($method !== "POST") {
            return $this->getResponseStream(
                ['error' => 'request method needs to be post']
            );
        }
        $body = $request->getBody()->getContents();
        $data = json_decode($body, true);
        if (is_null($data)) {
            return $this->getResponseStream(
                ['error' => 'request body is not valid json']
            );
        }
        $requiredKeys = ['firstname', 'lastname', 'vacState', 'vac', 'date', 'timezone'];

        foreach ($requiredKeys as $key) {
            if (empty($data[$key])) {
                return $this->getResponseStream(
                    ['error' => "required key $key is not set."]
                );
            }
        }
        $vacState = $data['vacState'];
        if (!in_array($vacState, TicketReserver::VAC_STATES)) {
            return $this->getResponseStream(
                ['error' => "invalid vacState: $vacState"]
            );
        }
        $vac = $data['vac'];
        if (!in_array($vac, TicketReserver::VACCINES)) {
            return $this->getResponseStream(
                ['error' => "invalid vaccine: $vac"]
            );
        }

        $date = intval($data['date']);
        if (!$date) {
            return $this->getResponseStream(
                ['error' => 'expected date to be a timestamp']
            );
        }

        $timezone = 'UTC';

        $date = new Horde_Date($date, $timezone);

        $ticketReserver = $this->injector->getInstance(TicketReserver::class);
        $ticketReserver->setTimezone($timezone);

        // not used currently
        $owner = $data['firstname'] . ' ' . $data['lastname'];


        if (!$ticketReserver->meetsRequirements($vacState, $vac, $date)) {
            return $this->getResponseStream(
                ['error' => _('You currently do not meet the requirements for a new vaccination')]
            );
        }

        $ticket = $ticketReserver->reserveTicket();
        if (!$ticket) {
            return $this->getResponseStream(
                ['error' => _('Currently no available tickets')]
            );
        }
        return $this->getResponseStream([
            'ticket' => [
                'id' => $ticket->ticket_code,
                'date' => $ticket->ticket_date,
                // TODO: should the vaccine even be decided here? Then it needs to be a field in the db.
                // Random for now
                // 'vac' => $vaccines[random_int(0, count($vaccines) - 1)],
            ],
        ]);
    }
}
