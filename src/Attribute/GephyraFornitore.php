<?php

namespace Drupal\gephyra\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Dichiara un fornitore di API (Argo, Axios…).
 *
 * Ogni sottomodulo mette il proprio in src/Plugin/GephyraFornitore.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class GephyraFornitore extends Plugin {

  /**
   * Costruttore.
   *
   * @param string $id
   *   Id del fornitore. È anche la chiave delle credenziali in settings.php:
   *   $settings['gephyra'][$id].
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   Nome del fornitore.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   Breve descrizione.
   * @param class-string|null $deriver
   *   Deriver, se serve.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
