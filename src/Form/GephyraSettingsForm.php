<?php

namespace Drupal\gephyra\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\gephyra\Exception\GephyraException;
use Drupal\gephyra\GephyraClient;
use Drupal\gephyra\GephyraFornitoreManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Impostazioni di Gephýra: fornitore, attesa, prova del collegamento.
 *
 * Le credenziali non si inseriscono qui: stanno in settings.php, e la pagina
 * dice solo se ci sono.
 */
class GephyraSettingsForm extends ConfigFormBase {

  /**
   * Costruttore.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   La configurazione.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   La configurazione tipizzata.
   * @param \Drupal\gephyra\GephyraFornitoreManager $fornitoreManager
   *   I fornitori disponibili.
   * @param \Drupal\gephyra\GephyraClient $client
   *   Il client delle API.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected GephyraFornitoreManager $fornitoreManager,
    protected GephyraClient $client,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('plugin.manager.gephyra_fornitore'),
      $container->get('gephyra.client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'gephyra_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['gephyra.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('gephyra.settings');

    $opzioni = [];
    foreach ($this->fornitoreManager->getDefinitions() as $id => $definizione) {
      $opzioni[$id] = $definizione['label'];
    }
    asort($opzioni);

    if (!$opzioni) {
      $this->messenger()->addWarning($this->t('Nessun fornitore disponibile: attivare il sottomodulo del gestionale usato dalla scuola (ad esempio Gephýra - Argo).'));
    }

    $form['fornitore'] = [
      '#type' => 'select',
      '#title' => $this->t('Fornitore'),
      '#description' => $this->t('Il gestionale della segreteria a cui collegarsi. Compaiono solo i fornitori il cui sottomodulo è attivo.'),
      '#options' => $opzioni,
      '#empty_option' => $this->t('- Nessuno -'),
      '#default_value' => $config->get('fornitore') ?: '',
    ];

    $form['credenziali'] = [
      '#type' => 'details',
      '#title' => $this->t('Credenziali'),
      '#open' => TRUE,
    ];
    $righe = [];
    foreach ($opzioni as $id => $etichetta) {
      $fornitore = $this->fornitoreManager->createInstance($id);
      if ($this->client->haCredenziali($id)) {
        $stato = $this->t('presenti');
      }
      else {
        $stato = $fornitore->credenzialiObbligatorie() ? $this->t('mancanti') : $this->t('mancanti (facoltative: servono solo per le API)');
      }
      $righe[] = [
        $etichetta,
        $fornitore->tipoCredenziali() === 'token' ? $this->t('token') : $this->t('client ID e secret'),
        $stato,
      ];
    }
    $form['credenziali']['stato'] = [
      '#type' => 'table',
      '#header' => [$this->t('Fornitore'), $this->t('Tipo'), $this->t('Credenziali in settings.php')],
      '#rows' => $righe,
      '#empty' => $this->t('Nessun fornitore disponibile.'),
    ];
    $form['credenziali']['spiegazione'] = [
      '#type' => 'item',
      '#markup' => $this->t('Le credenziali (client ID e secret, oppure un token) sono rilasciate dal fornitore alla scuola e danno accesso ai dati personali di alunni e personale: non si inseriscono da questa pagina ma nel file <em>settings.php</em> del sito, che non va mai in Git:') .
      '<pre>$settings[\'gephyra\'][\'argo\'] = [
  \'client_id\' =&gt; \'…\',
  \'client_secret\' =&gt; \'…\',
];
$settings[\'gephyra\'][\'nuvola\'] = [
  \'token\' =&gt; \'…\',
];</pre>',
    ];

    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Tempo massimo di attesa'),
      '#description' => $this->t('Secondi dopo i quali una chiamata al fornitore viene abbandonata.'),
      '#field_suffix' => $this->t('secondi'),
      '#min' => 5,
      '#max' => 300,
      '#default_value' => $config->get('timeout') ?: 30,
    ];

    $form = parent::buildForm($form, $form_state);

    $form['actions']['prova'] = [
      '#type' => 'submit',
      '#value' => $this->t('Prova collegamento'),
      '#submit' => ['::prova'],
      '#weight' => 10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('gephyra.settings')
      ->set('fornitore', (string) $form_state->getValue('fornitore'))
      ->set('timeout', (int) $form_state->getValue('timeout'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Prova il collegamento con il fornitore scelto nel modulo.
   *
   * Non salva: prova quello che è selezionato in quel momento.
   */
  public function prova(array &$form, FormStateInterface $form_state) {
    $id = (string) $form_state->getValue('fornitore');
    if ($id === '') {
      $this->messenger()->addError($this->t('Scegliere prima un fornitore.'));
      return;
    }
    try {
      $esito = $this->client->prova($id);
      $this->messenger()->addStatus($this->t('Collegamento riuscito. @esito', ['@esito' => $esito]));
    }
    catch (GephyraException $e) {
      $this->messenger()->addError($this->t('Collegamento non riuscito. @errore', ['@errore' => $e->getMessage()]));
    }
    $form_state->setRebuild();
  }

}
