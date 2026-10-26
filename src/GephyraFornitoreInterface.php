<?php

namespace Drupal\gephyra;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Un fornitore di API: sa dove chiedere il token e dove chiamare i servizi.
 *
 * Le credenziali non passano da qui: le legge GephyraClient da settings.php.
 * Il fornitore dice solo di che tipo sono, se servono e come si mandano.
 */
interface GephyraFornitoreInterface extends PluginInspectionInterface {

  /**
   * Nome del fornitore.
   */
  public function label(): string;

  /**
   * Tipo di credenziali che il fornitore rilascia alla scuola.
   *
   * @return string
   *   'oauth2': client ID e secret, con cui si chiede un token (client
   *   credentials); 'token': un token fisso, usato così com'è.
   */
  public function tipoCredenziali(): string;

  /**
   * Dice se senza credenziali non si può fare niente.
   *
   * FALSE per i fornitori che hanno anche servizi pubblici (il feed delle
   * circolari di Nuvola): le credenziali servono solo per le API.
   */
  public function credenzialiObbligatorie(): bool;

  /**
   * Intestazioni con cui il token accompagna ogni chiamata alle API.
   *
   * @param string $token
   *   Il token.
   *
   * @return array<string, string>
   *   Nome e valore delle intestazioni.
   */
  public function intestazioniToken(string $token): array;

  /**
   * Indirizzo a cui chiedere il token OAuth2 (client credentials).
   *
   * Stringa vuota per i fornitori con token fisso.
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
