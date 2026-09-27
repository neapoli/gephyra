<?php

namespace Drupal\gephyra;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\gephyra\Attribute\GephyraFornitore;

/**
 * Trova i fornitori dichiarati dai sottomoduli (src/Plugin/GephyraFornitore).
 */
class GephyraFornitoreManager extends DefaultPluginManager {

  /**
   * Costruttore.
   *
   * @param \Traversable $namespaces
   *   I namespace dei moduli attivi.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   La cache delle definizioni.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   Il module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/GephyraFornitore',
      $namespaces,
      $module_handler,
      GephyraFornitoreInterface::class,
      GephyraFornitore::class,
    );
    $this->alterInfo('gephyra_fornitore_info');
    $this->setCacheBackend($cache_backend, 'gephyra_fornitore_plugins');
  }

}
