<?php

namespace Horde\Coronado\Model;

use DateTime;

class TicketRepo extends TicketMapper
{
    public function getByCode(string $code): ?Ticket
    {
        return $this->findOne(['ticket_code' => $code]);
    }

    public function getByOwner(string $owner): array
    {
        $results = $this->find(['ticket_owner' => $owner]);
        $tickets = [];
        array_push($tickets, ...$results);
        return $tickets;
    }

    public function createTicket(DateTime $date, string $owner = '')
    {
        function genCode()
        {
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
            $charsCount = strlen($chars);
            $codeLength = 32;
            $code = [];
            foreach (range(1, $codeLength) as $i) {
                $code[] = $chars[random_int(0, $charsCount - 1)];
            }
            return implode($code);
        }

        $code = genCode();
        while ($this->getByCode($code)) {
            $code = genCode();
        }
        $ticket = $this->create([
            'ticket_code' => $code,
            'ticket_owner' => $owner,
            'ticket_date' => $date->getTimestamp(),
        ]);
        return $ticket;
    }
}
