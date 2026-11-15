<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters\Files;

/**
 * Empreinte de l'état des fichiers d'un site (dernier commit, dates des fichiers…). Elle change dès que le site est
 * modifié ailleurs que dans le portail (par le développeur sur GitHub, par FTP…) : le portail relit alors le site.
 */
interface Fingerprinted
{
    /** Empreinte courte, ou null si elle ne peut pas être obtenue. */
    public function fingerprint(): ?string;
}
