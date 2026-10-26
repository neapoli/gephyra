<?php

namespace Drupal\gephyra_nuvola\Plugin\GephyraFornitore;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\gephyra\Attribute\GephyraFornitore;
use Drupal\gephyra\GephyraClient;
use Drupal\gephyra\GephyraFornitoreBase;
use Drupal\gephyra_nuvola\FeedCircolari;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Nuvola di Madisoft: registro elettronico e segreteria digitale.
 *
 * Le circolari pubbliche si leggono dal feed RSS della bacheca, senza
 * credenziali. Le API (area personale, area alunni, amministrazione digitale,
 * sito web) vogliono invece un token rilasciato da Madisoft alla scuola, da
 * mandare nell'intestazione «token»: è facoltativo finché non si usano.
 * Documentazione: https://supporto.madisoft.it/portal/it/kb/articles/nuvola-api
 */
#[GephyraFornitore(
  id: 'nuvola',
  label: new TranslatableMarkup('Nuvola'),
  description: new TranslatableMarkup('Nuvola di Madisoft: registro elettronico e segreteria digitale.'),
)]
class Nuvola extends GephyraFornitoreBase implements ContainerFactoryPluginInterface {

  /**
   * Costruttore.
   *
   * @param array $configuration
   *   La configurazione del plugin.
   * @param string $plugin_id
   *   L'id del plugin.
   * @param mixed $plugin_definition
   *   La definizione del plugin.
   * @param \Drupal\gephyra_nuvola\FeedCircolari $feedCircolari
   *   Il feed delle circolari.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected FeedCircolari $feedCircolari) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('gephyra_nuvola.feed_circolari'));
  }

  /**
   * {@inheritdoc}
   */
  public function tipoCredenziali(): string {
    return 'token';
  }

  /**
   * {@inheritdoc}
   */
  public function credenzialiObbligatorie(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function intestazioniToken(string $token): array {
    return ['token' => $token];
  }

  /**
   * {@inheritdoc}
   *
   * Ogni area ha poi il proprio percorso e il codice della scuola (tenant),
   * ad esempio area-alunni/{tenant}/get-classi.
   */
  public function baseUrl(): string {
    return 'https://nuvola.madisoft.it/api';
  }

  /**
   * {@inheritdoc}
   *
   * Legge il feed delle circolari, se impostato. Le API non si provano
   * ancora: non si sa quale servizio risponda senza dati della scuola.
   */
  public function prova(GephyraClient $client): string {
    $esito = [];
    if ($client->haCredenziali($this->getPluginId())) {
      $esito[] = (string) $this->t('Token presente (le API non sono ancora usate).');
    }
    if ($this->feedCircolari->indirizzo() === '') {
      $esito[] = (string) $this->t("Feed delle circolari non impostato.");
      return implode(' ', $esito);
    }
    $voci = $this->feedCircolari->voci();
    $circolari = count(array_filter($voci, fn($voce) => $voce['circolare']));
    $esito[] = (string) $this->t('Feed delle circolari letto: @voci voci, di cui @circolari circolari secondo il titolario.', [
      '@voci' => count($voci),
      '@circolari' => $circolari,
    ]);
    if ($voci && $voci[0]['data']) {
      $esito[] = (string) $this->t('La più recente è del @data.', ['@data' => $voci[0]['data']->format('d/m/Y')]);
    }
    return implode(' ', $esito);
  }

}
