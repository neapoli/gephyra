<?php

namespace Drupal\gephyra_nuvola\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\gephyra\Exception\GephyraException;
use Drupal\gephyra_nuvola\FeedCircolari;
use Drupal\gephyra_nuvola\ImportaCircolari;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Comandi Drush di Gephýra - Nuvola.
 */
class GephyraNuvolaCommands extends DrushCommands {

  /**
   * Costruttore.
   *
   * @param \Drupal\gephyra_nuvola\FeedCircolari $feedCircolari
   *   Il feed delle circolari.
   * @param \Drupal\gephyra_nuvola\ImportaCircolari $importaCircolari
   *   L'importazione delle circolari.
   */
  public function __construct(
    protected FeedCircolari $feedCircolari,
    protected ImportaCircolari $importaCircolari,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('gephyra_nuvola.feed_circolari'),
      $container->get('gephyra_nuvola.importa_circolari'),
    );
  }

  /**
   * Elenca le voci del feed delle circolari di Nuvola, senza importarle.
   */
  #[CLI\Command(name: 'gephyra-nuvola:feed', aliases: ['gephyra-nuvola-feed'])]
  #[CLI\Option(name: 'tutte', description: 'Mostra anche le voci con un titolario diverso da quelli delle circolari.')]
  #[CLI\FieldLabels(labels: [
    'guid' => 'Id',
    'data' => 'Data',
    'titolario' => 'Titolario',
    'circolare' => 'Circolare',
    'titolo' => 'Titolo',
    'link' => 'Link',
  ])]
  #[CLI\DefaultTableFields(fields: ['guid', 'data', 'titolario', 'circolare', 'titolo'])]
  #[CLI\Usage(name: 'drush gephyra-nuvola:feed', description: 'Le circolari del feed.')]
  #[CLI\Usage(name: 'drush gephyra-nuvola:feed --tutte', description: 'Tutte le voci del feed, anche avvisi e bandi.')]
  public function feed(array $options = ['tutte' => FALSE, 'format' => 'table']): RowsOfFields|int {
    try {
      $voci = $this->feedCircolari->voci();
    }
    catch (GephyraException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $righe = [];
    foreach ($voci as $voce) {
      if (!$options['tutte'] && !$voce['circolare']) {
        continue;
      }
      $righe[] = [
        'guid' => $voce['guid'],
        'data' => $voce['data']?->format('d/m/Y H:i') ?? '',
        'titolario' => $voce['titolario'],
        'circolare' => $voce['circolare'] ? 'sì' : 'no',
        'titolo' => $voce['titolo'],
        'link' => $voce['link'],
      ];
    }
    $this->logger()->notice(sprintf('%d voci nel feed, %d mostrate.', count($voci), count($righe)));
    return new RowsOfFields($righe);
  }

  /**
   * Importa circolari e notizie nuove del feed di Nuvola, come fa il cron.
   *
   * Senza --solo tratta i tipi attivi nelle impostazioni; con --solo quel
   * tipo, anche se è spento (utile con --prova prima di attivarlo).
   */
  #[CLI\Command(name: 'gephyra-nuvola:importa', aliases: ['gephyra-nuvola-importa'])]
  #[CLI\Option(name: 'prova', description: 'Mostra cosa verrebbe importato, senza scaricare allegati né salvare.')]
  #[CLI\Option(name: 'solo', description: 'Solo un tipo: circolari o notizie, anche se spento nelle impostazioni.')]
  #[CLI\FieldLabels(labels: [
    'guid' => 'Id',
    'tipo' => 'Tipo',
    'data' => 'Data',
    'numero' => 'Numero',
    'titolo' => 'Titolo',
    'allegati' => 'Allegati',
    'scadenza' => 'Spubblica il',
    'esito' => 'Esito',
    'nid' => 'Nodo',
  ])]
  #[CLI\DefaultTableFields(fields: ['guid', 'tipo', 'data', 'numero', 'titolo', 'allegati', 'scadenza', 'esito', 'nid'])]
  #[CLI\Usage(name: 'drush gephyra-nuvola:importa --prova', description: 'Cosa verrebbe importato.')]
  #[CLI\Usage(name: 'drush gephyra-nuvola:importa', description: 'Importa circolari e notizie nuove, secondo le impostazioni.')]
  #[CLI\Usage(name: 'drush gephyra-nuvola:importa --prova --solo=notizie', description: 'Quali notizie verrebbero importate, anche con le notizie spente.')]
  public function importa(array $options = ['prova' => FALSE, 'solo' => self::REQ, 'format' => 'table']): RowsOfFields|int {
    $tipi = match ($options['solo']) {
      NULL => NULL,
      'circolari' => ['circolare'],
      'notizie' => ['notizia'],
      default => FALSE,
    };
    if ($tipi === FALSE) {
      $this->logger()->error('--solo vuole «circolari» o «notizie».');
      return self::EXIT_FAILURE;
    }
    try {
      $esiti = $this->importaCircolari->importa((bool) $options['prova'], $tipi);
    }
    catch (GephyraException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    if (!$esiti) {
      $this->logger()->notice('Niente da importare: nessuna voce dalla data di partenza in poi, oppure importazioni spente nelle impostazioni.');
    }
    return new RowsOfFields($esiti);
  }

}
