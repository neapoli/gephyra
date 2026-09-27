<?php

namespace Drupal\gephyra;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Un fornitore di API: sa dove chiedere il token e dove chiamare i servizi.
 *
 * Le credenziali non passano da qui: le legge GephyraClient da settings.php.
 */
interface GephyraFornitoreInterface extends PluginInspectionInterface {

  /**
   * Nome del fornitore.
   */
  public function label(): string;

  /**
   * Indirizzo a cui chiedere il token OAuth2 (client credentials).
   */
  public function tokenUrl(): string;

  /**
   * Scope da chiedere con il token; stringa vuota se non serve.
   */
  public function scope(): string;

  /**
   * Come si mandano client ID e secret nella richiesta del token.
   *
   * @return string
   *   'basic' (intestazione Authorization) oppure 'post' (nel corpo).
   */
  public function autenticazioneToken(): string;

  /**
   * Indirizzo di base dei servizi, senza barra finale.
   */
  public function baseUrl(): string;

  /**
   * Chiamata innocua per provare il collegamento, dopo il token.
   *
   * @param \Drupal\gephyra\GephyraClient $client
   *   Il client, per fare le chiamate.
   *
   * @return string
   *   Esito da mostrare all'amministratore, senza dati personali.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   */
  public function prova(GephyraClient $client): string;

}
