<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

class ConflictError extends AdapterError
{
    public function __construct(?string $detail = null)
    {
        parent::__construct('conflict', 'Ce contenu a été modifié ailleurs entre-temps. Rechargez la page pour voir la version actuelle avant de recommencer.', $detail);
    }
}
