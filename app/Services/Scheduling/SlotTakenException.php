<?php

namespace App\Services\Scheduling;

/**
 * The time asked for was booked by someone else between being shown and
 * being booked; the client picks another.
 */
class SlotTakenException extends SchedulingException {}
