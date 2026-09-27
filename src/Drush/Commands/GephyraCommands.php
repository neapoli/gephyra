<?php

namespace Drupal\gephyra\Drush\Commands;

use Drupal\gephyra\Exception\GephyraException;
use Drupal\gephyra\GephyraClient;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Comandi Drush del modulo Gephýra.
 */
class GephyraCommands extends DrushCommands {

  /**
   * Costruttore.
   *
   * @param \Drupal\gephyra\GephyraClient $client
   *   Il client delle API.
   */
  public function __construct(protected GephyraClient $client) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static($container->get('gephyra.client'));
  }

  /**
   * Prova il collegamento: chiede un token nuovo e fa la chiamata di prova.
   */
  #[CLI\Command(name: 'gephyra:prova', aliases: ['gephyra-prova'])]
  #[CLI\Argument(name: 'fornitore', description: 'Id del fornitore (ad esempio argo). Se manca, quello scelto nelle impostazioni.')]
  #[CLI\Usage(name: 'drush gephyra:prova', description: 'Prova il fornitore scelto nelle impostazioni.')]
  #[CLI\Usage(name: 'drush gephyra:prova argo', description: 'Prova Argo.')]
  public function prova(?string $fornitore = NULL): int {
    try {
      $esito = $this->client->prova($fornitore);
    }
    catch (GephyraException $e) {
      $this->logger()->error('Collegamento non riuscito. ' . $e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->logger()->success('Collegamento riuscito. ' . $esito);
    return self::EXIT_SUCCESS;
  }

}
