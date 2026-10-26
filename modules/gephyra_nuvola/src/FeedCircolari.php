<?php

namespace Drupal\gephyra_nuvola;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\gephyra\Exception\GephyraException;
use Drupal\gephyra\GephyraClient;

/**
 * Il feed RSS delle circolari pubbliche di Nuvola.
 *
 * L'indirizzo è del tipo https://nuvola.madisoft.it/feed/LCIS01200Q/rss/2:
 * codice meccanografico della scuola e numero della bacheca, che cambia da
 * scuola a scuola. Il feed è pubblico e dà per ogni voce solo titolo, link
 * alla pagina del documento, data di pubblicazione, id (guid) e voce del
 * titolario (nel campo description).
 */
class FeedCircolari {

  use StringTranslationTrait;

  /**
   * Forma dell'indirizzo del feed: codice della scuola e numero della bacheca.
   */
  const FORMATO = '#^https://nuvola\.madisoft\.it/feed/([A-Za-z0-9]{10})/rss/(\d+)/?$#';

  /**
   * Costruttore.
   *
   * @param \Drupal\gephyra\GephyraClient $client
   *   Il client di Gephýra.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   La configurazione.
   */
  public function __construct(
    protected GephyraClient $client,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * L'indirizzo del feed dalle impostazioni; stringa vuota se manca.
   */
  public function indirizzo(): string {
    return (string) $this->configFactory->get('gephyra_nuvola.settings')->get('feed_circolari');
  }

  /**
   * Codice meccanografico e numero della bacheca, o NULL se non è un feed.
   *
   * @return array{codice: string, bacheca: int}|null
   *   Le parti dell'indirizzo.
   */
  public static function parti(string $indirizzo): ?array {
    if (!preg_match(self::FORMATO, trim($indirizzo), $m)) {
      return NULL;
    }
    return ['codice' => strtoupper($m[1]), 'bacheca' => (int) $m[2]];
  }

  /**
   * Le voci del titolario da trattare come circolari; vuoto per tutte.
   *
   * @return string[]
   *   Le voci.
   */
  public function titolari(): array {
    return array_values(array_filter(array_map('trim', (array) $this->configFactory->get('gephyra_nuvola.settings')->get('titolari'))));
  }

  /**
   * Legge il feed e restituisce le voci, dalla più recente.
   *
   * @return array<int, array{guid: string, titolo: string, link: string, data: \DateTimeImmutable|null, titolario: string, circolare: bool}>
   *   Le voci; «circolare» dice se la voce del titolario è fra quelle scelte.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   */
  public function voci(): array {
    $indirizzo = $this->indirizzo();
    if ($indirizzo === '') {
      throw new GephyraException((string) $this->t("Manca l'indirizzo del feed delle circolari di Nuvola nelle impostazioni."));
    }

    $xml = $this->client->pubblico($indirizzo, (string) $this->t('lettura del feed delle circolari'), 'nuvola');
    $precedente = libxml_use_internal_errors(TRUE);
    // LIBXML_NONET: niente richieste in rete durante la lettura del feed.
    $rss = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($precedente);
    if ($rss === FALSE || !isset($rss->channel)) {
      throw new GephyraException((string) $this->t('Nuvola: il feed delle circolari non è un RSS valido.'));
    }

    $titolari = array_map('mb_strtolower', $this->titolari());
    $voci = [];
    foreach ($rss->channel->item as $item) {
      $titolario = $this->pulisci((string) $item->description);
      $data = \DateTimeImmutable::createFromFormat(DATE_RSS, trim((string) $item->pubDate)) ?: NULL;
      $voci[] = [
        'guid' => trim((string) $item->guid),
        // Nuvola lascia a volte un a capo in fondo al titolo.
        'titolo' => $this->pulisci((string) $item->title),
        'link' => trim((string) $item->link),
        'data' => $data,
        'titolario' => $titolario,
        'circolare' => !$titolari || in_array(mb_strtolower($titolario), $titolari, TRUE),
      ];
    }
    usort($voci, fn($a, $b) => ($b['data']?->getTimestamp() ?? 0) <=> ($a['data']?->getTimestamp() ?? 0));
    return $voci;
  }

  /**
   * Toglie a capo e spazi doppi.
   */
  protected function pulisci(string $testo): string {
    return trim(preg_replace('/\s+/u', ' ', $testo));
  }

}
