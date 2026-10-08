<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

/** Le site a bougé pendant l'écriture : le moteur relit et recommence. */
final class RetryableConflict extends ConflictError
{
}
