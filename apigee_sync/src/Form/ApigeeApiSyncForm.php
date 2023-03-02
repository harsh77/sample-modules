<?php

namespace Drupal\apigee_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\apigee_sync\Services\ApigeeConnector;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Provides a form for saving the error page title and content.
 */
class ApigeeApiSyncForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'apigee_sync_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'apigee_sync.apigee_api_sync',
    ];
  }

  /**
   * The api list.
   *
   * @var Variable
   */
  protected $apiList;

  /**
   * The sdk connector object.
   *
   * @var \Drupal\apigee_sync\Services\ApigeeConnector
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
   * @param \Drupal\apigee_sync\Services\ApigeeConnector $connector
   *   The sdk connector object.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The Messenger.
   */
  public function __construct(ApigeeConnector $connector,
                              MessengerInterface $messenger) {
    $this->sdkConnector = $connector;
    $this->messenger = $messenger;
    $this->apiList = $this->sdkConnector->getAllApis();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('apigee_sync.apigee_connector'),
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
      'source' => $this->t('Source'),
    ];

    $form['apis_table'] = [
      '#type' => 'tableselect',
      '#responsive' => TRUE,
      '#sticky' => TRUE,
      '#header' => $header,
      '#options' => $this->apiList ?? [],
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
      '#attributes' => [
        'class' => [
          'selected-api-submit',
        ],
      ],
    ];

    $form['actions']['sync_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sync all APIs'),
      '#button_type' => 'primary',
      '#submit' => [
        [$this, 'syncAll'],
      ],
      '#attributes' => [
        'class' => ['sync-all-apis-products'],
        'data-modal-title' => $this->t('Sync all APIs'),
        'data-modal-message' => $this->t('Are you sure you want to sync all APIs?'),
      ],
    ];

    $form['#attached']['library'][] = 'cardium_sync/datatable';
    $form['#attached']['library'][] = 'cardium_sync/sync_all';

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
      $this->messenger->addError($this->t('Please select minimum one API.'));
      return;
    }

    // Fetch selected APIs values.
    foreach ($values as $value) {
      $api_list[$value] = $this->apiList[$value];
    }
    $this->syncApis($api_list);
  }

  /**
   * Sync all Apis.
   */
  public function syncAll(array &$form, FormStateInterface $form_state) {
    $this->syncApis($this->apiList);
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
            '\Drupal\apigee_sync\Services\SyncApigeeApi::createnode',
            [$api_list],
          ],
        ],
        'finished' => '\Drupal\apigee_sync\Services\SyncApigeeApi::finishcreatingnode',
      ];
      batch_set($batch);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}
