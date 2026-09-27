<?php

namespace Drupal\gephyra;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\gephyra\Exception\GephyraException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Chiamate alle API del fornitore: token OAuth2, richieste, errori.
 *
 * Le credenziali stanno solo in settings.php:
 * @code
 * $settings['gephyra']['argo'] = [
 *   'client_id' => '…',
 *   'client_secret' => '…',
 * ];
 * @endcode
 * Nel registro vanno solo fornitore, operazione e codice HTTP: mai
 * credenziali, token o dati restituiti, che sono dati personali.
 */
class GephyraClient {

  use StringTranslationTrait;

  /**
   * Secondi tolti alla durata del token, per non usarlo mentre scade.
   */
  const MARGINE_TOKEN = 60;

  /**
   * Costruttore.
   *
   * @param \Drupal\gephyra\GephyraFornitoreManager $fornitoreManager
   *   I fornitori dichiarati dai sottomoduli.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   La configurazione.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   Il client HTTP.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   La cache, per il token.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   L'ora.
   * @param \Psr\Log\LoggerInterface $logger
   *   Il canale di registro gephyra.
   */
  public function __construct(
    protected GephyraFornitoreManager $fornitoreManager,
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Il fornitore indicato, oppure quello scelto nelle impostazioni.
   *
   * @param string|null $id
   *   Id del fornitore; NULL per quello attivo.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   *   Se non ce n'è uno scelto o il suo sottomodulo non è attivo.
   */
  public function fornitore(?string $id = NULL): GephyraFornitoreInterface {
    $id ??= (string) $this->configFactory->get('gephyra.settings')->get('fornitore');
    if ($id === '') {
      throw new GephyraException((string) $this->t('Nessun fornitore scelto nelle impostazioni di Gephýra.'));
    }
    if (!$this->fornitoreManager->hasDefinition($id)) {
      throw new GephyraException((string) $this->t('Il fornitore «@id» non è disponibile: il suo sottomodulo non è attivo.', ['@id' => $id]));
    }
    return $this->fornitoreManager->createInstance($id);
  }

  /**
   * Dice se in settings.php ci sono client ID e secret del fornitore.
   */
  public function haCredenziali(string $id): bool {
    return $this->credenziali($id) !== NULL;
  }

  /**
   * Token valido per il fornitore, dalla cache o chiesto di nuovo.
   *
   * @param \Drupal\gephyra\GephyraFornitoreInterface $fornitore
   *   Il fornitore.
   * @param bool $rinnova
   *   TRUE per chiederne uno nuovo anche se in cache ce n'è uno.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   */
  public function token(GephyraFornitoreInterface $fornitore, bool $rinnova = FALSE): string {
    $id = $fornitore->getPluginId();
    $credenziali = $this->credenziali($id);
    if ($credenziali === NULL) {
      throw new GephyraException((string) $this->t('Mancano le credenziali di @fornitore in settings.php.', ['@fornitore' => $fornitore->label()]));
    }

    // L'impronta delle credenziali nella chiave fa sì che, cambiandole in
    // settings.php, il token vecchio non venga più usato.
    $cid = 'gephyra:token:' . $id . ':' . substr(hash('sha256', $credenziali['client_id'] . "\0" . $credenziali['client_secret']), 0, 16);
    if (!$rinnova && ($cache = $this->cache->get($cid))) {
      return $cache->data;
    }

    $form = ['grant_type' => 'client_credentials'];
    if ($fornitore->scope() !== '') {
      $form['scope'] = $fornitore->scope();
    }
    $opzioni = ['timeout' => $this->timeout()];
    if ($fornitore->autenticazioneToken() === 'post') {
      $form['client_id'] = $credenziali['client_id'];
      $form['client_secret'] = $credenziali['client_secret'];
    }
    else {
      // OAuth2 (RFC 6749, 2.3.1) vuole client ID e secret codificati come in
      // un modulo prima di comporre l'intestazione Basic.
      $opzioni['auth'] = [urlencode($credenziali['client_id']), urlencode($credenziali['client_secret'])];
    }
    $opzioni['form_params'] = $form;

    $risposta = $this->invia($fornitore, 'POST', $fornitore->tokenUrl(), $opzioni, (string) $this->t('richiesta del token'));
    $dati = json_decode((string) $risposta->getBody(), TRUE);
    if (!is_array($dati) || empty($dati['access_token']) || !is_string($dati['access_token'])) {
      $this->logger->error('@fornitore: la risposta alla richiesta del token non contiene un token.', ['@fornitore' => $fornitore->label()]);
      throw new GephyraException((string) $this->t('@fornitore non ha restituito un token.', ['@fornitore' => $fornitore->label()]));
    }

    $durata = isset($dati['expires_in']) ? (int) $dati['expires_in'] : 300;
    if ($durata > self::MARGINE_TOKEN) {
      $this->cache->set($cid, $dati['access_token'], $this->time->getRequestTime() + $durata - self::MARGINE_TOKEN);
    }
    return $dati['access_token'];
  }

  /**
   * Chiama un servizio del fornitore e restituisce la risposta decodificata.
   *
   * @param string $metodo
   *   'GET' o 'POST'.
   * @param string $percorso
   *   Percorso del servizio, relativo all'indirizzo di base del fornitore.
   * @param array $dati
   *   Per GET i parametri dell'indirizzo, per POST il corpo JSON.
   * @param string|null $id
   *   Id del fornitore; NULL per quello attivo.
   *
   * @return mixed
   *   La risposta JSON decodificata, oppure il testo se non è JSON.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   */
  public function richiesta(string $metodo, string $percorso, array $dati = [], ?string $id = NULL): mixed {
    $fornitore = $this->fornitore($id);
    $url = $fornitore->baseUrl() . '/' . ltrim($percorso, '/');
    $metodo = strtoupper($metodo);

    $opzioni = [
      'timeout' => $this->timeout(),
      // Preferisce JSON, ma alcuni servizi (la versione di Argo) rispondono
      // solo in testo e con un Accept troppo stretto danno 406.
      'headers' => ['Accept' => 'application/json, text/plain;q=0.9, */*;q=0.8'],
    ];
    if ($metodo === 'GET') {
      if ($dati) {
        $opzioni['query'] = $dati;
      }
    }
    else {
      $opzioni['json'] = $dati ?: new \stdClass();
    }

    // Con un token scaduto prima del previsto il fornitore risponde 401: in
    // quel caso si chiede un token nuovo e si riprova una volta sola.
    foreach ([FALSE, TRUE] as $rinnova) {
      $opzioni['headers']['Authorization'] = 'Bearer ' . $this->token($fornitore, $rinnova);
      $risposta = $this->invia($fornitore, $metodo, $url, $opzioni, $percorso, $rinnova ? [] : [401]);
      if ($risposta->getStatusCode() !== 401) {
        break;
      }
    }

    $corpo = (string) $risposta->getBody();
    $json = json_decode($corpo, TRUE);
    return json_last_error() === JSON_ERROR_NONE ? $json : $corpo;
  }

  /**
   * Prova il collegamento: token, poi la chiamata di prova del fornitore.
   *
   * @param string|null $id
   *   Id del fornitore; NULL per quello attivo.
   *
   * @return string
   *   Esito da mostrare.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   */
  public function prova(?string $id = NULL): string {
    $fornitore = $this->fornitore($id);
    $this->token($fornitore, TRUE);
    return $fornitore->prova($this);
  }

  /**
   * Client ID e secret del fornitore da settings.php, o NULL se mancano.
   *
   * @return array{client_id: string, client_secret: string}|null
   *   Le credenziali.
   */
  protected function credenziali(string $id): ?array {
    $tutte = Settings::get('gephyra', []);
    $client_id = $tutte[$id]['client_id'] ?? '';
    $client_secret = $tutte[$id]['client_secret'] ?? '';
    if (!is_string($client_id) || !is_string($client_secret) || $client_id === '' || $client_secret === '') {
      return NULL;
    }
    return ['client_id' => $client_id, 'client_secret' => $client_secret];
  }

  /**
   * Manda la richiesta e trasforma ogni errore in una GephyraException.
   *
   * @param \Drupal\gephyra\GephyraFornitoreInterface $fornitore
   *   Il fornitore, per il registro.
   * @param string $metodo
   *   Il metodo HTTP.
   * @param string $url
   *   L'indirizzo.
   * @param array $opzioni
   *   Le opzioni di Guzzle.
   * @param string $operazione
   *   Cosa si sta facendo, per il registro e il messaggio.
   * @param int[] $codici_ammessi
   *   Codici di errore da restituire invece di sollevare l'eccezione.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   */
  protected function invia(GephyraFornitoreInterface $fornitore, string $metodo, string $url, array $opzioni, string $operazione, array $codici_ammessi = []) {
    try {
      return $this->httpClient->request($metodo, $url, $opzioni);
    }
    catch (RequestException $e) {
      $codice = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
      if ($codice && in_array($codice, $codici_ammessi, TRUE)) {
        return $e->getResponse();
      }
      // Il messaggio di Guzzle riporta parte della risposta: non si registra.
      $this->logger->error('@fornitore: @operazione non riuscita (HTTP @codice).', [
        '@fornitore' => $fornitore->label(),
        '@operazione' => $operazione,
        '@codice' => $codice ?: '-',
      ]);
      throw new GephyraException((string) $this->t('@fornitore: @operazione non riuscita (HTTP @codice).', [
        '@fornitore' => $fornitore->label(),
        '@operazione' => $operazione,
        '@codice' => $codice ?: '-',
      ]), $codice, $e);
    }
    catch (ConnectException $e) {
      $this->logger->error('@fornitore: @operazione, server non raggiungibile.', [
        '@fornitore' => $fornitore->label(),
        '@operazione' => $operazione,
      ]);
      throw new GephyraException((string) $this->t('@fornitore: @operazione, server non raggiungibile.', [
        '@fornitore' => $fornitore->label(),
        '@operazione' => $operazione,
      ]), 0, $e);
    }
    catch (GuzzleException $e) {
      $this->logger->error('@fornitore: @operazione non riuscita.', [
        '@fornitore' => $fornitore->label(),
        '@operazione' => $operazione,
      ]);
      throw new GephyraException((string) $this->t('@fornitore: @operazione non riuscita.', [
        '@fornitore' => $fornitore->label(),
        '@operazione' => $operazione,
      ]), 0, $e);
    }
  }

  /**
   * Tempo massimo di attesa delle chiamate, in secondi.
   */
  protected function timeout(): int {
    return max(5, (int) ($this->configFactory->get('gephyra.settings')->get('timeout') ?: 30));
  }

}
