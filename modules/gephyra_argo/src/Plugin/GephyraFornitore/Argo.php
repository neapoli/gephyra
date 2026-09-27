<?php

namespace Drupal\gephyra_argo\Plugin\GephyraFornitore;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\gephyra\Attribute\GephyraFornitore;
use Drupal\gephyra\GephyraClient;
use Drupal\gephyra\GephyraFornitoreBase;

/**
 * Argo Software: API di interoperabilità secondo il modello AgID.
 *
 * Credenziali OAuth2 (client credentials) rilasciate da Argo alla scuola su
 * richiesta; senza lo scope «agid» il token non dà accesso alle API. Il client
 * è configurato da Argo per l'intestazione Basic (client_secret_basic): il
 * secret nel corpo viene rifiutato.
 * Documentazione: https://www.portaleargo.it/argo-api/rest/
 */
#[GephyraFornitore(
  id: 'argo',
  label: new TranslatableMarkup('Argo'),
  description: new TranslatableMarkup('Argo Software: segreteria, didattica, personale.'),
)]
class Argo extends GephyraFornitoreBase {

  /**
   * {@inheritdoc}
   */
  public function tokenUrl(): string {
    return 'https://auth.portaleargo.it/oauth2/token';
  }

  /**
   * {@inheritdoc}
   */
  public function scope(): string {
    return 'agid';
  }

  /**
   * {@inheritdoc}
   */
  public function baseUrl(): string {
    return 'https://www.portaleargo.it/argo-api/rest';
  }

  /**
   * {@inheritdoc}
   *
   * La versione delle API: l'unico servizio che non restituisce dati della
   * scuola.
   */
  public function prova(GephyraClient $client): string {
    $risposta = $client->richiesta('GET', 'version', [], $this->getPluginId());
    // Risponde con un testo del tipo «{version=2.0.2}».
    $versione = is_string($risposta) && preg_match('/version=([\w.\-]+)/', $risposta, $m) ? $m[1] : '?';
    return (string) $this->t('Token ottenuto; API di Argo versione @versione.', ['@versione' => $versione]);
  }

}
