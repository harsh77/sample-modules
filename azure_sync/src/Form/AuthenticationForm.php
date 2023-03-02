<?php

namespace Drupal\azure_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Serialization\Json;

/**
 * Provides a form for changing connection related settings.
 */
class AuthenticationForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'azure_sync.auth',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'azure_sync_form';
  }

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Logger Factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $loggerFactory;

  /**
   * Constructs a new Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $loggerFactory
   *   The Logger Factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory,
                              LoggerChannelFactory $loggerFactory) {
    $this->configFactory = $config_factory;
    $this->loggerFactory = $loggerFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('azure_sync.auth');
    $azure_details = $config->get('azure_details') ? JSON::decode($config->get('azure_details')) : '';

    // Gather the number of set in the form already.
    $number_of_sets = $form_state->get('number_of_sets');

    // We have to ensure that there is at least one set of fields.
    if ($number_of_sets === NULL) {
      $number_of_sets = (is_array($azure_details)) ? count($azure_details) : 1;
      $form_state->set('number_of_sets', $number_of_sets);
    }

    $form['#tree'] = TRUE;

    $form['azure_details'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Azure connectivity details'),
      '#prefix' => '<div id="azure-fieldset-wrapper">',
      '#suffix' => '</div>',
    ];

    for ($i = 0; $i < $number_of_sets; $i++) {
      $form['azure_details']['set'][$i] = [
        '#type' => 'details',
        '#title' => $this->t('Azure Details - @count_id', ['@count_id' => ($i + 1)]),
        '#open' => TRUE,
      ];

      $form['azure_details']['set'][$i]['instance_name'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Instance Name'),
        '#description' => $this->t('Name of the instance. It should be unique.'),
        '#required' => TRUE,
        '#default_value' => $azure_details[$i]['instance_name'][$i] ?? '',
        '#attributes' => [
          'autocomplete' => 'off',
        ],
      ];

      // The unique machine name of the instance.
      $form['azure_details']['set'][$i]['id'][$i] = [
        '#type' => 'machine_name',
        '#title' => $this->t('Machine name'),
        '#description' => $this->t('A unique machine-readable name. Can only contain lowercase letters, numbers, and underscores.'),
        '#default_value' => $azure_details[$i]['id'][$i] ?? '',
        '#machine_name' => [
          'exists' => [$this, 'exist'],
          'source' => ['azure_details', 'set', $i, 'instance_name', $i],
        ],
        '#disabled' => $azure_details[$i]['id'][$i] ? TRUE : FALSE,
      ];

      $form['azure_details']['set'][$i]['instance_desc'][$i] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#default_value' => $azure_details[$i]['instance_desc'][$i] ?? '',
        '#attributes' => [
          'autocomplete' => 'off',
        ],
      ];

      $form['azure_details']['set'][$i]['subscription_id'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subscription ID'),
        '#description' => $this->t('Subscription id of the user.'),
        '#required' => TRUE,
        '#default_value' => $azure_details[$i]['subscription_id'][$i] ?? '',
        '#attributes' => [
          'autocomplete' => 'off',
        ],
      ];

      $form['azure_details']['set'][$i]['resource_group_name'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Resource Group Name'),
        '#description' => $this->t('Resource Group Name.'),
        '#required' => TRUE,
        '#default_value' => $azure_details[$i]['resource_group_name'][$i] ?? '',
        '#attributes' => ['autocomplete' => 'off'],
      ];

      $form['azure_details']['set'][$i]['service_name'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Service Name'),
        '#description' => $this->t('Name of the service. Service contains resource group name'),
        '#required' => TRUE,
        '#default_value' => $azure_details[$i]['service_name'][$i] ?? '',
        '#attributes' => ['autocomplete' => 'off'],
      ];

      $form['azure_details']['set'][$i]['api_version'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Api Version - Year'),
        '#description' => $this->t('API version.'),
        '#required' => TRUE,
        '#default_value' => $azure_details[$i]['api_version'][$i] ?? '2020-12-01',
        '#attributes' => ['autocomplete' => 'off'],
      ];

      $form['azure_details']['set'][$i]['management_api_domian'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Domain'),
        '#description' => $this->t('Azure Domain name.'),
        '#required' => TRUE,
        '#default_value' => $azure_details[$i]['management_api_domian'][$i] ?? '',
        '#attributes' => ['autocomplete' => 'off'],
      ];

      $form['azure_details']['set'][$i]['api_token'][$i] = [
        '#type' => 'details',
        '#title' => $this->t('Azure Token'),
      ];

      $form['azure_details']['set'][$i]['api_token'][$i]['sas_token'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Sas Token'),
        '#required' => TRUE,
        '#default_value' => $azure_details[$i]['api_token'][$i]['sas_token'][$i] ?? '',
      ];

      $form['azure_details']['set'][$i]['api_token'][$i]['sas_token_id'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Sas Token ID'),
        '#required' => TRUE,
        '#default_value' => $azure_details[$i]['api_token'][$i]['sas_token_id'][$i] ?? '',
      ];
    }

    $form['azure_details']['actions'] = [
      '#type' => 'actions',
    ];

    $form['azure_details']['actions']['add_set'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add one more'),
      '#submit' => [
        '::addOne',
      ],
      '#ajax' => [
        'callback' => '::addmoreCallback',
        'wrapper' => 'azure-fieldset-wrapper',
      ],
    ];

    // If there is more than one set, add the remove button.
    if ($number_of_sets > 1) {
      $form['azure_details']['actions']['remove_set'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove one'),
        '#submit' => [
          '::removeCallback',
        ],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::addmoreCallback',
          'wrapper' => 'azure-fieldset-wrapper',
        ],
      ];
    }
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];
    return $form;
  }

  /**
   * Helper function to check whether the machine name exists.
   */
  public function exist($id) {
    $exist = 0;
    $config = $this->config('azure_sync.auth');
    $azure_details = $config->get('azure_details') ? JSON::decode($config->get('azure_details')) : '';
    if (is_array($azure_details)) {
      foreach ($azure_details as $azure_detail) {
        if (in_array($id, $azure_detail['id'])) {
          $exist = 1;
        }
      }
    }
    return (bool) $exist;
  }

  /**
   * Callback for both ajax-enabled buttons.
   *
   * Selects and returns the fieldset with the fields in it.
   */
  public function addmoreCallback(array &$form, FormStateInterface $form_state) {
    return $form['azure_details'];
  }

  /**
   * Submit handler for the "add-one-more" button.
   *
   * Increments the max counter and causes a rebuild.
   */
  public function addOne(array &$form, FormStateInterface $form_state) {
    $number_of_sets = $form_state->get('number_of_sets');
    $add_button = $number_of_sets + 1;
    $form_state->set('number_of_sets', $add_button);

    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "remove one" button.
   *
   * Decrements the max counter and causes a form rebuild.
   */
  public function removeCallback(array &$form, FormStateInterface $form_state) {
    $number_of_sets = $form_state->get('number_of_sets');
    if ($number_of_sets > 1) {
      $remove_button = $number_of_sets - 1;
      $form_state->set('number_of_sets', $remove_button);
    }

    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $azure_values = $form_state->getValue([
      'azure_details',
      'set',
    ]);
    $azure_values = Json::encode($azure_values);

    $this->config('azure_sync.auth')
      ->set('azure_details', $azure_values)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
