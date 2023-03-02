<?php

namespace Drupal\azure_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\azure_sync\Services\AzureConnector;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Listing all apis and sync specific or all apis.
 */
class AzureApiSyncForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'azure_sync_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'azure_sync.azure_api_sync',
    ];
  }

  /**
   * The sdk connector object.
   *
   * @var \Drupal\azure_sync\Services\AzureConnector
   */
  protected $sdkConnector;

  /**
   * Messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new Constructor.
   *
   * @param \Drupal\azure_sync\Services\AzureConnector $connector
   *   The sdk connector object.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The Messenger.
   */
  public function __construct(AzureConnector $connector,
                              MessengerInterface $messenger) {
    $this->sdkConnector = $connector;
    $this->messenger = $messenger;
    $this->api_list = $this->sdkConnector->getAllApis();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('azure_sync.azure_connector'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Select one or more API(s) from the below list to be synced.'),
    ];

    $header = [
      'api_id' => $this->t('API ID'),
      'api_name' => $this->t('API Name'),
      'service_url' => $this->t('Service URL'),
      'source' => $this->t('Source'),
    ];

    $form['apis_table'] = [
      '#type' => 'tableselect',
      '#responsive' => TRUE,
      '#sticky' => TRUE,
      '#header' => $header,
      '#options' => $this->api_list,
      '#attributes' => [
        'class' => [
          'sync-datatable',
          'row-border',
        ],
      ],
      '#empty' => $this->t('No API(s) found.'),
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sync selected API(s)'),
      '#button_type' => 'primary',
      '#submit' => [
        [$this, 'syncSelected'],
      ],
    ];

    $form['actions']['sync_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sync all APIs'),
      '#button_type' => 'primary',
      '#submit' => [
        [$this, 'syncAll'],
      ],
    ];

    $form['#attached']['library'][] = 'cardium_sync/datatable';

    return $form;
  }

  /**
   * Sync the selected APIs.
   */
  public function syncSelected(array &$form, FormStateInterface $form_state) {
    $api_list = [];
    $values = $form_state->getValue([
      'apis_table',
    ]);
    $values = array_filter($values);
    if (empty($values)) {
      $this->messenger->addMessage($this->t('Please select minimum one API.'), 'error');
      return;
    }

    // Fetch selected APIs values.
    foreach ($values as $value) {
      $api_list[$value] = $this->api_list[$value];
    }
    $this->syncApis($api_list);
  }

  /**
   * Sync all Apis.
   */
  public function syncAll(array &$form, FormStateInterface $form_state) {
    $this->syncApis($this->api_list);
  }

  /**
   * {@inheritdoc}
   */
  public function syncApis($api_list) {
    try {
      $batch = [
        'title' => $this->t('Syncing APIs...'),
        'operations' => [
          [
            '\Drupal\azure_sync\Services\SyncAzureApi::createnode',
            [$api_list],
          ],
        ],
        'finished' => '\Drupal\azure_sync\Services\SyncAzureApi::finishcreatingnode',
      ];
      batch_set($batch);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}
