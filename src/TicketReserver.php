<?php

declare(strict_types=1);

namespace Horde\Coronado;

use Horde_Date;
use Horde_Db_Adapter;
use Horde\Coronado\Model\TicketRepo;
use Horde\Coronado\Model\Ticket;

class TicketReserver
{
    // remember to use UTC times here
    protected const HOUR_START = 9;
    protected const HOUR_STOP = 15;

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

    protected function getHordeDate($param): Horde_Date
    {
        return new Horde_Date($param, $this->timezone);
    }

    public function meetsRequirements(string $vacState, string $lastVaccine, Horde_Date $lastVaccination)
    {
        $now = $this->getHordeDate(time());
        if ($vacState === self::VAC_STATES[0]) {
            return true;
        } elseif (
            $vacState === self::VAC_STATES[1]
            && ($now->diff($lastVaccination) >= 28)
        ) {
            return true;
        } elseif (
            $vacState === self::VAC_STATES[2]
            && ($now->diff($lastVaccination) >= 30 * 5)
        ) {
            return true;
        } elseif (
            $lastVaccine === self::VACCINES[3]
            && ($now->diff($lastVaccination) >= 28)
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

    protected function getStartDate(): Horde_Date
    {
        $date = $this->getHordeDate(time())->add(['day' => 1]);
        $date->hour = self::HOUR_START;
        $date->min = 0;
        $date->sec = 0;
        return $date;
    }

    protected function getEndDate(): Horde_Date
    {
        $date = $this->getHordeDate(time())->add(['day' => 1]);
        $date->hour = self::HOUR_STOP;
        $date->min = 0;
        $date->sec = 0;
        return $date;
    }

    public function getNextAvailableTimeSlot(): ?Horde_Date
    {
        $date = $this->getStartDate();
        $endTs = $this->getEndDate()->timestamp();

        while ($date->timestamp() < $endTs) {
            $c = count($this->ticketRepo->find(['ticket_date' => $date->timestamp()]));
            if ($c < $this->slotsPerBlock) {
                return $date;
            }
            $date = $date->add(['min' => $this->minutesPerBlock]);
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
