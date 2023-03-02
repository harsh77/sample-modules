<?php

namespace Drupal\azure_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\azure_sync\Services\AzureConnector;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Listing all api products and sync specific api product or all api products.
 */
class AzureApiProductsSyncForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'azure_products_sync_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'azure_sync.azure_api_product_sync',
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
    $this->product_list = $this->sdkConnector->getAllApiProducts();
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
      '#markup' => $this->t('Select one or more API products from the below list to be synced.'),
    ];

    $header = [
      'id' => $this->t('ID'),
      'name' => $this->t('Name'),
      'apis' => $this->t('API ID(s)'),
      'quota' => $this->t('Quota'),
    ];

    $form['api_products_table'] = [
      '#type' => 'tableselect',
      '#responsive' => TRUE,
      '#sticky' => TRUE,
      '#header' => $header,
      '#options' => $this->product_list,
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
    ];

    $form['actions']['sync_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sync all Products'),
      '#button_type' => 'primary',
      '#submit' => [
        [$this, 'syncAll'],
      ],
    ];

    $form['#attached']['library'][] = 'cardium_sync/datatable';

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
      $this->messenger->addMessage($this->t('Please select minimum one API Product.'), 'error');
      return;
    }

    // Fetch selected API product values.
    foreach ($values as $value) {
      $product_list[$value] = $this->product_list[$value];
      $api_synced = $this->sdkConnector->getApiIdsFromName($this->product_list[$value]['apis']);
      foreach ($api_synced as $api_status) {
        if (empty($api_status)) {
          $this->messenger->addMessage($this->t('API with id @id is not yet synced. Please sync the API  and try again.', ['@id' => $this->product_list[$value]['apis']]), 'error');
          return;
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
    foreach ($this->product_list as $value) {
      $api_synced = $this->sdkConnector->getApiIdsFromName($value['apis']);
      foreach ($api_synced as $api_status) {
        if (empty($api_status)) {
          $this->messenger->addMessage($this->t('API with id @id is not yet synced. Please sync the API  and try again.', ['@id' => $value['apis']]), 'error');
          return;
        }
      }
    }
    $this->syncApiProducts($this->product_list);
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
            '\Drupal\azure_sync\Services\SyncAzureProducts::createEntity',
            [$product_list],
          ],
        ],
        'finished' => '\Drupal\azure_sync\Services\SyncAzureProducts::finishCreatingEntity',
      ];
      batch_set($batch);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}
