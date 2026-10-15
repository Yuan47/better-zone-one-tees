<?php

namespace App\Exceptions;

use RuntimeException;

/** The delivery-order POST was never attempted, so the booking claim can be released. */
class DeliveryBookingNotStarted extends RuntimeException {}
