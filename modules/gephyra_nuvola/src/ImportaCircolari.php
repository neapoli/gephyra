<?php

namespace Drupal\gephyra_nuvola;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileRepositoryInterface;
use Drupal\gephyra\Exception\GephyraException;
use Drupal\gephyra\GephyraClient;
use Psr\Log\LoggerInterface;

/**
 * Crea sul sito le voci nuove del feed di Nuvola, già pubblicate.
 *
 * Le voci con il titolario delle circolari diventano circolari, le altre (se
 * attivato nelle impostazioni) notizie. Dal feed vengono titolo, data e id,
 * gli allegati dalla pagina del documento. Per le circolari il numero viene
 * dalla tabella della bacheca (colonna «Numero registro») e l'anno scolastico
 * dalla data; il testo è quello predefinito del tipo di contenuto. Il resto
 * dei campi obbligatori viene dalle impostazioni di ciascun tipo.
 *
 * Ogni voce importata si ricorda per id Nuvola, così non si importa due
 * volte, nemmeno se nel frattempo la circolare è stata cancellata dal sito.
 */
class ImportaCircolari {

  use StringTranslationTrait;

  /**
   * Il sito di Nuvola: l'unico da cui si scaricano pagine e allegati.
   */
  const SITO = 'https://nuvola.madisoft.it';

  /**
   * Raccolta chiave-valore delle voci importate: id Nuvola => nid.
   */
  const IMPORTATE = 'gephyra_nuvola.importate';

  /**
   * I termini delle impostazioni, per tipo: chiave => [vocabolario, impostazione].
   */
  const TERMINI = [
    'circolare' => [
      'argomento' => ['argomenti', 'argomento'],
      'tipologia' => ['tipologia_circolare', 'tipologia'],
      'destinatari' => ['destinatari', 'destinatari'],
    ],
    'notizia' => [
      'argomento' => ['argomenti', 'notizie_argomento'],
      'tipologia' => ['tipologia_notizie', 'notizie_tipologia'],
    ],
  ];

  /**
   * Costruttore.
   *
   * @param \Drupal\gephyra_nuvola\FeedCircolari $feedCircolari
   *   Il feed delle circolari.
   * @param \Drupal\gephyra\GephyraClient $client
   *   Il client di Gephýra.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Le entità.
   * @param \Drupal\file\FileRepositoryInterface $fileRepository
   *   Per salvare gli allegati.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   Il file system.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue
   *   Dove si ricordano le voci importate.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   La configurazione.
   * @param \Psr\Log\LoggerInterface $logger
   *   Il canale di registro gephyra_nuvola.
   */
  public function __construct(
    protected FeedCircolari $feedCircolari,
    protected GephyraClient $client,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileRepositoryInterface $fileRepository,
    protected FileSystemInterface $fileSystem,
    protected KeyValueFactoryInterface $keyValue,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Importa le voci nuove del feed.
   *
   * @param bool $prova
   *   TRUE per dire solo cosa si farebbe, senza scaricare allegati né salvare.
   * @param string[]|null $tipi
   *   'circolare' e/o 'notizia'; NULL per quelli attivi nelle impostazioni.
   *
   * @return array<int, array{guid: string, tipo: string, data: string, numero: string, titolo: string, allegati: string, scadenza: string, esito: string, nid: string}>
   *   Una riga per ogni voce da trattare, dalla data di partenza del suo tipo.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   *   Se il feed non si legge.
   */
  public function importa(bool $prova = FALSE, ?array $tipi = NULL): array {
    $config = $this->configFactory->get('gephyra_nuvola.settings');
    $tipi ??= array_keys(array_filter([
      'circolare' => $config->get('importazione_attiva'),
      'notizia' => $config->get('notizie_attive'),
    ]));
    if (!$tipi) {
      return [];
    }
    $dal = [
      'circolare' => $this->data((string) $config->get('importa_dal')),
      'notizia' => $this->data((string) $config->get('notizie_dal')),
    ];
    $importate = $this->keyValue->get(self::IMPORTATE);

    $esiti = [];
    $da_importare = [];
    // Dalla più vecchia, così anche i nid seguono l'ordine di Nuvola.
    foreach (array_reverse($this->feedCircolari->voci()) as $voce) {
      $voce['tipo'] = $this->tipo($voce);
      if (!in_array($voce['tipo'], $tipi, TRUE) || $voce['guid'] === '' || !$voce['data'] || ($dal[$voce['tipo']] && $voce['data'] < $dal[$voce['tipo']])) {
        continue;
      }
      if ($importate->has($voce['guid'])) {
        $esiti[$voce['guid']] = $this->esito($voce, (string) $this->t('già importata'), NULL, $importate->get($voce['guid']));
        continue;
      }
      $da_importare[] = $voce;
    }
    if (!$da_importare) {
      return array_values($esiti);
    }

    // Se mancano i termini di un tipo si ferma solo quel tipo.
    $termini = $errori = [];
    foreach (array_unique(array_column($da_importare, 'tipo')) as $tipo) {
      try {
        $termini[$tipo] = $this->termini($tipo);
      }
      catch (GephyraException $e) {
        $errori[$tipo] = $e->getMessage();
        $this->logger->error('Importazione da Nuvola ferma: @errore', ['@errore' => $e->getMessage()]);
      }
    }
    $numeri = isset($termini['circolare']) ? $this->numeri() : [];

    foreach ($da_importare as $voce) {
      if (isset($errori[$voce['tipo']])) {
        $esiti[$voce['guid']] = $this->esito($voce, (string) $this->t('errore: @errore', ['@errore' => $errori[$voce['tipo']]]));
        continue;
      }
      try {
        $esiti[$voce['guid']] = $voce['tipo'] === 'circolare'
          ? $this->importaVoce($voce, $numeri[$voce['guid']] ?? NULL, $termini['circolare'], $prova)
          : $this->importaNotizia($voce, $termini['notizia'], $prova);
      }
      catch (GephyraException $e) {
        // Non si ricorda la voce: si riprova al prossimo giro.
        $this->logger->error('Voce Nuvola @guid (@tipo) non importata: @errore', ['@guid' => $voce['guid'], '@tipo' => $voce['tipo'], '@errore' => $e->getMessage()]);
        $esiti[$voce['guid']] = $this->esito($voce, (string) $this->t('errore: @errore', ['@errore' => $e->getMessage()]));
      }
    }
    return array_values($esiti);
  }

  /**
   * Il termine di un vocabolario con quel nome, o NULL se non c'è.
   */
  public function termine(string $vocabolario, string $nome): ?int {
    if (trim($nome) === '') {
      return NULL;
    }
    $ids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabolario)
      ->condition('name', trim($nome))
      ->range(0, 1)
      ->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

  /**
   * Importa una voce del feed.
   *
   * @param array $voce
   *   La voce, come la dà FeedCircolari::voci().
   * @param int|null $numero
   *   Il numero della circolare, se trovato nella bacheca.
   * @param array<string, int> $termini
   *   Argomento, tipologia e destinatari.
   * @param bool $prova
   *   TRUE per non salvare niente.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   */
  protected function importaVoce(array $voce, ?int $numero, array $termini, bool $prova): array {
    $nome_anno = $this->annoScolastico($voce['data']);
    $anno = $this->termine('anni_scolastici', $nome_anno);
    if ($anno === NULL) {
      throw new GephyraException((string) $this->t("Manca l'anno scolastico «@anno».", ['@anno' => $nome_anno]));
    }

    // Già sul sito, inserita a mano o migrata: si collega e non si ricrea.
    if ($numero !== NULL && ($nid = $this->circolareEsistente($numero, $anno))) {
      if (!$prova) {
        $this->keyValue->get(self::IMPORTATE)->set($voce['guid'], $nid);
      }
      return $this->esito($voce, (string) $this->t('già sul sito (stesso numero e anno)'), $numero, $nid);
    }

    $allegati = $this->documento($voce['link'])['allegati'];
    if ($prova) {
      return $this->esito($voce, (string) $this->t('da importare'), $numero, NULL, count($allegati));
    }

    if ($numero === NULL) {
      $this->logger->warning('Circolare Nuvola @guid «@titolo»: numero non trovato nella bacheca, importata senza numero.', ['@guid' => $voce['guid'], '@titolo' => $voce['titolo']]);
    }
    if (!$allegati) {
      $this->logger->warning('Circolare Nuvola @guid «@titolo»: nessun allegato trovato nella pagina del documento.', ['@guid' => $voce['guid'], '@titolo' => $voce['titolo']]);
    }

    $autore = (int) ($this->configFactory->get('gephyra_nuvola.settings')->get('autore') ?: 1);
    $file = [];
    try {
      $file = $this->salvaAllegati($allegati, $autore, 'node.circolare.field_allegati');

      $titolo = $this->maiuscolaIniziale($voce['titolo']);
      $quando = $voce['data']->getTimestamp();
      $circolare = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'circolare',
        'title' => mb_substr($titolo, 0, 255),
        'uid' => $autore,
        'status' => 1,
        'promote' => 1,
        'created' => $quando,
        'changed' => $quando,
        'field_abstract' => $titolo,
        'field_numero_circolare' => $numero,
        'field_anno_scolastico' => $anno,
        'field_argomenti' => $termini['argomento'],
        'field_tipologia_circolare' => $termini['tipologia'],
        'field_destinatari' => $termini['destinatari'],
        'field_allegati' => array_map(fn($f) => ['target_id' => $f->id(), 'display' => 1], $file),
      ]);
      $circolare->setRevisionLogMessage('Importata da Nuvola (documento ' . $voce['guid'] . ').');
      $circolare->save();
    }
    catch (\Exception $e) {
      // Niente allegati orfani se la circolare non si è salvata.
      foreach ($file as $f) {
        $f->delete();
      }
      throw $e instanceof GephyraException ? $e : new GephyraException($e->getMessage(), 0, $e);
    }

    $this->keyValue->get(self::IMPORTATE)->set($voce['guid'], (int) $circolare->id());
    $this->logger->notice('Importata da Nuvola la circolare @numero «@titolo» (nodo @nid).', [
      '@numero' => $numero ?? '-',
      '@titolo' => $titolo,
      '@nid' => $circolare->id(),
    ]);
    return $this->esito($voce, (string) $this->t('importata'), $numero, (int) $circolare->id(), count($file));
  }

  /**
   * Importa una voce del feed come notizia.
   *
   * La notizia non ha un campo per gli allegati: vanno in un paragrafo
   * «Allegati» delle informazioni aggiuntive.
   *
   * @param array $voce
   *   La voce, come la dà FeedCircolari::voci().
   * @param array<string, int> $termini
   *   Argomento e tipologia.
   * @param bool $prova
   *   TRUE per non salvare niente.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   */
  protected function importaNotizia(array $voce, array $termini, bool $prova): array {
    $config = $this->configFactory->get('gephyra_nuvola.settings');
    $documento = $this->documento($voce['link']);
    $allegati = $documento['allegati'];
    // La data di archiviazione di Nuvola diventa la spubblicazione di
    // Scheduler: si vede e si cambia nel contenuto, e la applica il suo cron.
    $scadenza = $config->get('notizie_spubblica') && $this->spubblicazioneProgrammabile() ? $documento['archiviazione'] : NULL;
    if ($prova) {
      return $this->esito($voce, (string) $this->t('da importare'), NULL, NULL, count($allegati), $scadenza);
    }
    if (!$allegati) {
      $this->logger->warning('Notizia Nuvola @guid «@titolo»: nessun allegato trovato nella pagina del documento.', ['@guid' => $voce['guid'], '@titolo' => $voce['titolo']]);
    }
    if ($config->get('notizie_spubblica')) {
      if (!$this->spubblicazioneProgrammabile()) {
        $this->logger->warning('Notizia Nuvola @guid: spubblicazione non programmata, perché Scheduler non è attivo o non è abilitato a spubblicare le notizie.', ['@guid' => $voce['guid']]);
      }
      elseif (!$scadenza) {
        $this->logger->warning('Notizia Nuvola @guid «@titolo»: data di archiviazione non trovata, importata senza spubblicazione programmata.', ['@guid' => $voce['guid'], '@titolo' => $voce['titolo']]);
      }
    }

    $autore = (int) ($config->get('notizie_autore') ?: 1);
    $file = [];
    try {
      $file = $this->salvaAllegati($allegati, $autore, 'paragraph.attachments.field_file');
      $paragrafi = [];
      if ($file) {
        $paragrafi[] = $this->entityTypeManager->getStorage('paragraph')->create([
          'type' => 'attachments',
          'field_title' => (string) $this->t('Allegati'),
          // Il nome leggibile è anche il testo del link.
          'field_file' => array_map(fn($f) => ['target_id' => $f->id(), 'display' => 1, 'description' => $f->getFilename()], $file),
          'field_settings' => $this->entityTypeManager->getStorage('paragraph')->create(['type' => 'settings']),
        ]);
      }

      $titolo = $this->maiuscolaIniziale($voce['titolo']);
      $quando = $voce['data']->getTimestamp();
      $notizia = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'article',
        'title' => mb_substr($titolo, 0, 255),
        'uid' => $autore,
        'status' => 1,
        'promote' => 1,
        'created' => $quando,
        'changed' => $quando,
        'body' => [
          'value' => '<p>' . Html::escape((string) $config->get('notizie_testo')) . '</p>',
          'format' => filter_default_format($this->entityTypeManager->getStorage('user')->load($autore)),
        ],
        'field_abstract' => $titolo,
        'field_argomenti' => $termini['argomento'],
        'field_tipologia_notizia' => $termini['tipologia'],
        'field_extra_info' => $paragrafi,
        'unpublish_on' => $scadenza?->getTimestamp(),
      ]);
      $notizia->setRevisionLogMessage('Importata da Nuvola (documento ' . $voce['guid'] . ').');
      $notizia->save();
    }
    catch (\Exception $e) {
      foreach ($file as $f) {
        $f->delete();
      }
      throw $e instanceof GephyraException ? $e : new GephyraException($e->getMessage(), 0, $e);
    }

    $this->keyValue->get(self::IMPORTATE)->set($voce['guid'], (int) $notizia->id());
    $this->logger->notice('Importata da Nuvola la notizia «@titolo» (nodo @nid).', [
      '@titolo' => $titolo,
      '@nid' => $notizia->id(),
    ]);
    return $this->esito($voce, (string) $this->t('importata'), NULL, (int) $notizia->id(), count($file), $scadenza);
  }

  /**
   * Dice se Scheduler è attivo e può spubblicare le notizie.
   *
   * Scheduler non è una dipendenza: senza, le notizie restano pubblicate.
   */
  protected function spubblicazioneProgrammabile(): bool {
    return \Drupal::hasService('scheduler.manager')
      && in_array('article', \Drupal::service('scheduler.manager')->getEnabledTypes('node', 'unpublish'), TRUE);
  }

  /**
   * Circolare, notizia o NULL (da non importare) secondo il titolario.
   */
  protected function tipo(array $voce): ?string {
    if ($voce['circolare']) {
      return 'circolare';
    }
    $escluse = array_map('mb_strtolower', array_map('trim', (array) $this->configFactory->get('gephyra_nuvola.settings')->get('notizie_escluse')));
    return in_array(mb_strtolower($voce['titolario']), $escluse, TRUE) ? NULL : 'notizia';
  }

  /**
   * I termini delle impostazioni di un tipo (argomento, tipologia…).
   *
   * @param string $tipo
   *   'circolare' o 'notizia'.
   *
   * @return array<string, int>
   *   Id dei termini.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   *   Se uno manca: meglio non importare che creare contenuti incompleti.
   */
  protected function termini(string $tipo): array {
    $config = $this->configFactory->get('gephyra_nuvola.settings');
    $termini = [];
    foreach (self::TERMINI[$tipo] as $chiave => [$vocabolario, $impostazione]) {
      $nome = (string) $config->get($impostazione);
      $termini[$chiave] = $this->termine($vocabolario, $nome);
      if ($termini[$chiave] === NULL) {
        throw new GephyraException((string) $this->t('Nel vocabolario «@vocabolario» manca il termine «@nome» indicato nelle impostazioni di Nuvola.', ['@vocabolario' => $vocabolario, '@nome' => $nome]));
      }
    }
    return $termini;
  }

  /**
   * I numeri delle circolari dalla bacheca: id Nuvola => numero.
   *
   * Se la pagina non si legge si va avanti senza numeri: le circolari si
   * importano lo stesso e il numero si aggiunge a mano.
   *
   * @return array<string, int>
   *   I numeri trovati.
   */
  protected function numeri(): array {
    $parti = FeedCircolari::parti($this->feedCircolari->indirizzo());
    try {
      $html = $this->client->pubblico(self::SITO . '/bacheca-digitale/bacheca/' . $parti['codice'] . '/' . $parti['bacheca'] . '/IN_PUBBLICAZIONE/0/show', (string) $this->t('lettura della bacheca'), 'nuvola');
    }
    catch (GephyraException $e) {
      $this->logger->warning('Bacheca di Nuvola non letta: le circolari si importano senza numero. @errore', ['@errore' => $e->getMessage()]);
      return [];
    }
    $xpath = $this->xpath($html);

    // La colonna si cerca per intestazione, non per posizione.
    $colonna = NULL;
    foreach ($xpath->query('//table//thead//tr[1]/th') as $i => $th) {
      if (mb_strtolower(trim(preg_replace('/\s+/u', ' ', $th->textContent))) === 'numero registro') {
        $colonna = $i;
        break;
      }
    }
    if ($colonna === NULL) {
      $this->logger->warning('Bacheca di Nuvola: colonna «Numero registro» non trovata, le circolari si importano senza numero.');
      return [];
    }

    $numeri = [];
    foreach ($xpath->query('//table//tbody/tr') as $riga) {
      $link = $xpath->query('.//a[contains(@href, "/documento/")]/@href', $riga)->item(0);
      $cella = $xpath->query('./td', $riga)->item($colonna);
      if ($link && $cella && preg_match('#/bacheca-digitale/(\d+)/documento/#', $link->nodeValue, $m) && preg_match('/^\d+$/', trim($cella->textContent))) {
        $numeri[$m[1]] = (int) trim($cella->textContent);
      }
    }
    return $numeri;
  }

  /**
   * Allegati e data di archiviazione dalla pagina del documento.
   *
   * @return array{allegati: array<int, array{url: string, nome: string}>, archiviazione: \DateTimeImmutable|null}
   *   Indirizzo e nome leggibile di ogni allegato; giorno in cui Nuvola
   *   archivia il documento, se c'è.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   *   Se la pagina non si legge: si riproverà al prossimo giro.
   */
  protected function documento(string $link): array {
    if (!str_starts_with($link, self::SITO . '/')) {
      throw new GephyraException((string) $this->t('Il link del documento non porta a Nuvola.'));
    }
    $xpath = $this->xpath($this->client->pubblico($link, (string) $this->t('lettura del documento'), 'nuvola'));
    $allegati = [];
    foreach ($xpath->query('//a[contains(@href, "/file/api/public-file-preview/")]') as $a) {
      $url = $a->getAttribute('href');
      $url = str_starts_with($url, '/') ? self::SITO . $url : $url;
      if (!str_starts_with($url, self::SITO . '/') || isset($allegati[$url])) {
        continue;
      }
      $nome = $xpath->query('.//*[contains(@class, "file-name")]//*[@title]/@title', $a)->item(0)?->nodeValue
        ?? trim($a->textContent);
      $allegati[$url] = ['url' => $url, 'nome' => trim($nome)];
    }

    $archiviazione = NULL;
    $valore = $xpath->query('//div[contains(@class, "field-container")][normalize-space(div[contains(@class, "field-name")]) = "Data archiviazione"]/div[contains(@class, "field-value")]')->item(0);
    if ($valore) {
      $archiviazione = \DateTimeImmutable::createFromFormat('!d/m/Y', trim($valore->textContent), new \DateTimeZone(date_default_timezone_get())) ?: NULL;
    }
    return ['allegati' => array_values($allegati), 'archiviazione' => $archiviazione];
  }

  /**
   * Scarica gli allegati e li salva nella cartella del campo indicato.
   *
   * @param array $allegati
   *   Gli allegati, come li dà documento().
   * @param int $autore
   *   Il proprietario dei file.
   * @param string $campo
   *   Id del campo file (entità.bundle.campo), per cartella ed estensioni.
   *
   * @return \Drupal\file\FileInterface[]
   *   I file salvati; quelli già salvati si cancellano se uno non riesce.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   */
  protected function salvaAllegati(array $allegati, int $autore, string $campo): array {
    $file = [];
    try {
      foreach ($allegati as $allegato) {
        $file[] = $this->salvaAllegato($allegato, $autore, $campo);
      }
    }
    catch (GephyraException $e) {
      foreach (array_filter($file) as $f) {
        $f->delete();
      }
      throw $e;
    }
    return array_values(array_filter($file));
  }

  /**
   * Scarica un allegato e lo salva nella cartella del campo.
   *
   * @return \Drupal\file\FileInterface|null
   *   Il file, o NULL se l'estensione non è fra quelle ammesse dal campo.
   *
   * @throws \Drupal\gephyra\Exception\GephyraException
   */
  protected function salvaAllegato(array $allegato, int $autore, string $id_campo) {
    $campo = $this->entityTypeManager->getStorage('field_config')->load($id_campo);
    $ammesse = preg_split('/\s+/', strtolower((string) $campo->getSetting('file_extensions')), -1, PREG_SPLIT_NO_EMPTY);

    // Nome leggibile, senza caratteri che diano noia negli indirizzi.
    $nome = preg_replace('/[^\w.\-]+/u', '_', basename($allegato['nome'])) ?: 'allegato.pdf';
    $estensione = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    if ($ammesse && !in_array($estensione, $ammesse, TRUE)) {
      $this->logger->warning('Allegato «@nome» non salvato: estensione non ammessa dal campo allegati.', ['@nome' => $nome]);
      return NULL;
    }

    $cartella = $campo->getFieldStorageDefinition()->getSetting('uri_scheme') . '://' . trim((string) $campo->getSetting('file_directory'), '/');
    if (!$this->fileSystem->prepareDirectory($cartella, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new GephyraException((string) $this->t('Cartella degli allegati non scrivibile.'));
    }
    $contenuto = $this->client->pubblico($allegato['url'], (string) $this->t("scaricamento dell'allegato"), 'nuvola');
    $file = $this->fileRepository->writeData($contenuto, $cartella . '/' . $nome, FileExists::Rename);
    $file->setOwnerId($autore);
    $file->save();
    return $file;
  }

  /**
   * Solo la prima lettera maiuscola, il resto minuscolo, per uniformità.
   *
   * Su Nuvola gli oggetti sono scritti in modi diversi, anche tutti in
   * maiuscolo.
   */
  protected function maiuscolaIniziale(string $testo): string {
    $testo = mb_strtolower($testo);
    return mb_strtoupper(mb_substr($testo, 0, 1)) . mb_substr($testo, 1);
  }

  /**
   * Una circolare con quel numero nello stesso anno scolastico, se c'è.
   */
  protected function circolareEsistente(int $numero, int $anno): ?int {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'circolare')
      ->condition('field_numero_circolare', $numero)
      ->condition('field_anno_scolastico', $anno)
      ->range(0, 1)
      ->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

  /**
   * L'anno scolastico di una data, come nel vocabolario: «2026/2027».
   *
   * L'anno scolastico comincia a settembre.
   */
  protected function annoScolastico(\DateTimeImmutable $data): string {
    $data = $data->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    $inizio = (int) $data->format('n') >= 9 ? (int) $data->format('Y') : (int) $data->format('Y') - 1;
    return $inizio . '/' . ($inizio + 1);
  }

  /**
   * Una data AAAA-MM-GG all'inizio della giornata; NULL se non è valida.
   */
  protected function data(string $dal): ?\DateTimeImmutable {
    $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $dal, new \DateTimeZone(date_default_timezone_get()));
    return $data ?: NULL;
  }

  /**
   * Una pagina HTML di Nuvola pronta per le interrogazioni XPath.
   */
  protected function xpath(string $html): \DOMXPath {
    $documento = new \DOMDocument();
    $precedente = libxml_use_internal_errors(TRUE);
    // La dichiarazione davanti fa leggere la pagina come UTF-8.
    $documento->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($precedente);
    return new \DOMXPath($documento);
  }

  /**
   * Una riga del resoconto.
   */
  protected function esito(array $voce, string $esito, ?int $numero = NULL, ?int $nid = NULL, ?int $allegati = NULL, ?\DateTimeImmutable $scadenza = NULL): array {
    return [
      'guid' => $voce['guid'],
      'tipo' => $voce['tipo'] ?? '',
      'data' => $voce['data']?->format('d/m/Y') ?? '',
      'numero' => $numero === NULL ? '' : (string) $numero,
      'titolo' => $this->maiuscolaIniziale($voce['titolo']),
      'allegati' => $allegati === NULL ? '' : (string) $allegati,
      'scadenza' => $scadenza?->format('d/m/Y') ?? '',
      'esito' => $esito,
      'nid' => $nid === NULL ? '' : (string) $nid,
    ];
  }

}
