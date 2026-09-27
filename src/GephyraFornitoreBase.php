<?php

namespace Drupal\gephyra;

use Drupal\Core\Plugin\PluginBase;

/**
 * Base dei fornitori: il nome viene dall'attributo, il resto dal fornitore.
 */
abstract class GephyraFornitoreBase extends PluginBase implements GephyraFornitoreInterface {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function scope(): string {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function autenticazioneToken(): string {
    return 'basic';
  }

  /**
   * {@inheritdoc}
   *
   * Se il fornitore non ha una chiamata di prova, basta aver ottenuto il token.
   */
  public function prova(GephyraClient $client): string {
    return (string) $this->t('Token ottenuto.');
  }

}
