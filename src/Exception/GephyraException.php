<?php

namespace Drupal\gephyra\Exception;

/**
 * Errore nel collegamento al fornitore.
 *
 * Il messaggio si può mostrare all'amministratore: non contiene mai
 * credenziali, token o dati restituiti dalle API.
 */
class GephyraException extends \RuntimeException {

}
