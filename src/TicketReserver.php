<?php

declare(strict_types=1);

namespace Horde\Coronado;

use DateTime;
use DateTimeZone;
use Horde_Db_Adapter;
use Horde\Coronado\Model\TicketRepo;
use Horde\Coronado\Model\Ticket;

class TicketReserver
{
    protected const HOUR_START = 10;
    protected const HOUR_STOP = 16;
    protected const BLOCK_PER_HOUR = 2;
    protected const MAX_SLOTS = 300;
    public const VAC_STATES = ['ungeimpft', 'erste Impfung erhalten', 'durchgeimpft'];
    public const VACCINES = [
        'BioNTech',
        'Moderna',
        'AstraZeneca',
        'Johnson&Johnson',
    ];

    protected Horde_Db_Adapter $dba;
    protected TicketRepo $ticketRepo;

    protected $blocks;
    protected $slotsPerBlock;
    protected $minutesPerBlock;
    protected $hoursTotal;
    protected $timezone = 'UTC';

    public function __construct(
        Horde_Db_Adapter $dba,
        TicketRepo $ticketRepo
    ) {
        $this->dba = $dba;
        $this->ticketRepo = $ticketRepo;
        $this->hoursTotal = self::HOUR_STOP - self::HOUR_START;
        $this->blocks = $this->hoursTotal * self::BLOCK_PER_HOUR;
        $this->slotsPerBlock = intval(self::MAX_SLOTS / $this->blocks);
        $this->minutesPerBlock = intval(60 / self::BLOCK_PER_HOUR);
    }

    public function setTimezone(string $timezone)
    {
        $this->timezone = $timezone;
    }

    protected function getDate($param = 'now'): DateTime
    {
        $zone = new DateTimeZone($this->timezone);
        $d = new DateTime($param, $zone);
        return $d;
    }

    public function meetsRequirements(string $vacState, string $lastVaccine, DateTime $lastVaccination)
    {
        $now = $this->getDate();
        $diffDays = $now->diff($lastVaccination)->days;
        if ($vacState === self::VAC_STATES[0]) {
            return true;
        } elseif (
            $vacState === self::VAC_STATES[1]
            && ($diffDays >= 28)
        ) {
            return true;
        } elseif (
            $vacState === self::VAC_STATES[2]
            && ($diffDays >= 30 * 5)
        ) {
            return true;
        } elseif (
            $lastVaccine === self::VACCINES[3]
            && ($diffDays >= 28)
        ) {
            return true;
        }
        return false;
    }

    public function getReserved($owner): ?Ticket
    {
        $ts = time();
        $tickets = $this->ticketRepo->getByOwner($owner);

        if (!$tickets) {
            return null;
        }
        usort($tickets, function ($a, $b) {
            return $b->ticket_date - $a->ticket_date;
        });
        $ticket = $tickets[0];
        if ($ticket->ticket_date < $ts) {
            return null;
        }
        return $ticket;
    }

    protected function getStartDate(): DateTime
    {
        $date = $this->getDate()->modify('+ 1 day');
        $date->setTime(self::HOUR_START, 0, 0);
        return $date;
    }

    protected function getEndDate(): DateTime
    {
        $date = $this->getDate()->modify('+ 1 day');
        $date->setTime(self::HOUR_STOP, 0, 0);
        return $date;
    }

    public function getNextAvailableTimeSlot(): ?DateTime
    {
        $date = $this->getStartDate();
        $endTs = $this->getEndDate()->getTimestamp();

        while ($date->getTimestamp() < $endTs) {
            $c = count($this->ticketRepo->find(['ticket_date' => $date->getTimestamp()]));
            if ($c < $this->slotsPerBlock) {
                return $date;
            }
            $date = $date->modify("+ $this->minutesPerBlock minutes");
        }
        return null;
    }

    public function reserveTicket(): ?Ticket
    {
        $date = $this->getNextAvailableTimeSlot();
        if (is_null($date)) {
            return null;
        }
        return $this->ticketRepo->createTicket($date);
    }
}
