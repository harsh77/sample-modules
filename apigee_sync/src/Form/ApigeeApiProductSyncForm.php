<?php

namespace Drupal\apigee_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\apigee_sync\Services\ApigeeConnector;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for saving the error page title and content.
 */
class ApigeeApiProductSyncForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'apigee_products_sync_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'apigee_sync.apigee_api_product_sync',
    ];
  }

  /**
   * The api product list.
   *
   * @var Variable
   */
  protected $productList;

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
  public function __construct(ApigeeConnector $connector, MessengerInterface $messenger) {
    $this->sdkConnector = $connector;
    $this->messenger = $messenger;

    $this->productList = $this->sdkConnector->getProductBundles();
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
      '#markup' => $this->t('Select one or more API products from the below list to be synced.'),
    ];

    $header = [
      'id' => $this->t('ID'),
      'name' => $this->t('Name'),
      'apis' => $this->t('API ID(s)'),
      'quota' => $this->t('Quota'),
      'rate_plans' => $this->t('Rateplans'),
      'source' => $this->t('Source'),
    ];

    $form['api_products_table'] = [
      '#type' => 'tableselect',
      '#responsive' => TRUE,
      '#sticky' => TRUE,
      '#header' => $header,
      '#options' => $this->productList ?? [],
      '#attributes' => [
        'class' => [
          'sync-datatable',
          'row-border',
        ],
      ],
      '#empty' => $this->t('No API Product(s) found.'),
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sync selected Product(s)'),
      '#button_type' => 'primary',
      '#submit' => [
        [$this, 'syncSelected'],
      ],
      '#attributes' => [
        'class' => [
          'selected-product-submit',
        ],
      ],
    ];

    $form['actions']['sync_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sync all Products'),
      '#button_type' => 'primary',
      '#submit' => [
        [$this, 'syncAll'],
      ],
      '#attributes' => [
        'class' => ['sync-all-apis-products'],
        'data-modal-title' => $this->t('Sync all Products'),
        'data-modal-message' => $this->t('Are you sure you want to sync all Products?'),
      ],
    ];

    $form['#attached']['library'][] = 'cardium_sync/datatable';
    $form['#attached']['library'][] = 'cardium_sync/sync_all';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function syncSelected(array &$form, FormStateInterface $form_state) {
    $product_list = [];
    $values = $form_state->getValue([
      'api_products_table',
    ]);
    $values = array_filter($values);
    if (empty($values)) {
      $this->messenger->addError($this->t('Please select minimum one API Product.'));
      return;
    }

    // Fetch selected API product values.
    foreach ($values as $value) {
      $product_list[$value] = $this->productList[$value];
      $api_synced = isset($this->productList[$value]['apis']) ? $this->sdkConnector->getApiIdsFromName($this->productList[$value]['apis']) : NULL;
      if (!empty($api_synced)) {
        foreach ($api_synced as $api_status) {
          if (empty($api_status)) {
            $this->messenger->addError($this->t('API with id %id is not yet synced. Please sync the API and try again.', ['%id' => $this->productList[$value]['id']]));
            return;
          }
        }
      }
    }
    $this->syncApiProducts($product_list);
  }

  /**
   * {@inheritdoc}
   */
  public function syncAll(array &$form, FormStateInterface $form_state) {
    // Fetch selected API product values.
    foreach ($this->productList as $value) {
      $api_synced = $this->sdkConnector->getApiIdsFromName($value['apis']);
      if (!empty($api_synced)) {
        foreach ($api_synced as $api_status) {
          if (empty($api_status)) {
            $this->messenger->addError($this->t('API with id @id is not yet synced. Please sync the API and try again.', ['@id' => $value['id']]));
            return;
          }
        }
      }
    }
    $this->syncApiProducts($this->productList);
  }

  /**
   * {@inheritdoc}
   */
  public function syncApiProducts($product_list) {
    try {
      $batch = [
        'title' => $this->t('Syncing API Product(s)...'),
        'operations' => [
          [
            '\Drupal\apigee_sync\Services\SyncApigeeProducts::createEntity',
            [$product_list],
          ],
        ],
        'finished' => '\Drupal\apigee_sync\Services\SyncApigeeProducts::finishCreatingEntity',
      ];
      batch_set($batch);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}
