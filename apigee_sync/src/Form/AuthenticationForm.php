<?php

namespace Drupal\apigee_sync\Form;

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
      'apigee_sync.auth',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'apigee_sync_form';
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
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactory $loggerFactory) {
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
    $config = $this->config('apigee_sync.auth');
    $apigee_details = $config->get('apigee_details') ? JSON::decode($config->get('apigee_details')) : '';

    // Gather the number of set in the form already.
    $number_of_sets = $form_state->get('number_of_sets');

    // We have to ensure that there is at least one set of fields.
    if ($number_of_sets === NULL) {
      $number_of_sets = (is_array($apigee_details)) ? count($apigee_details) : 1;
      $form_state->set('number_of_sets', $number_of_sets);
    }

    $form['#tree'] = TRUE;

    $form['apigee_details'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Apigee connectivity details'),
      '#prefix' => '<div id="apigee-fieldset-wrapper">',
      '#suffix' => '</div>',
    ];

    for ($i = 0; $i < $number_of_sets; $i++) {
      $form['apigee_details']['set'][$i] = [
        '#type' => 'details',
        '#title' => $this->t('Apigee Details - @count_id', ['@count_id' => ($i + 1)]),
        '#open' => TRUE,
      ];

      $form['apigee_details']['set'][$i]['instance_name'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Instance Name'),
        '#description' => $this->t('Name of the instance. It should be unique.'),
        '#required' => TRUE,
        '#default_value' => $apigee_details[$i]['instance_name'][$i] ?? '',
        '#attributes' => [
          'autocomplete' => 'off',
        ],
      ];

      // The unique machine name of the instance.
      $form['apigee_details']['set'][$i]['id'][$i] = [
        '#type' => 'machine_name',
        '#title' => $this->t('Machine name'),
        '#description' => $this->t('A unique machine-readable name. Can only contain lowercase letters, numbers, and underscores.'),
        '#default_value' => $apigee_details[$i]['id'][$i] ?? '',
        '#machine_name' => [
          'exists' => [$this, 'exist'],
          'source' => ['apigee_details', 'set', $i, 'instance_name', $i],
        ],
        '#disabled' => !empty($apigee_details) && isset($apigee_details[$i]) && $apigee_details[$i]['id'][$i] ? TRUE : FALSE,
      ];

      $form['apigee_details']['set'][$i]['instance_desc'][$i] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#default_value' => $apigee_details[$i]['instance_desc'][$i] ?? '',
        '#attributes' => [
          'autocomplete' => 'off',
        ],
      ];

      $form['apigee_details']['set'][$i]['endpoint'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Apigee Edge endpoint'),
        '#description' => $this->t('Apigee Edge endpoint where the API calls are being sent. For a Private Cloud installation it is in the form: %form_a or %form_b.', [
          '%form_a' => 'http://ms_IP_or_DNS:8080/v1',
          '%form_b' => 'https://ms_IP_or_DNS:TLSport/v1',
        ]),
        '#required' => TRUE,
        '#default_value' => $apigee_details[$i]['endpoint'][$i] ?? '',
        '#attributes' => ['autocomplete' => 'off'],
      ];

      $form['apigee_details']['set'][$i]['org_name'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Organization'),
        '#description' => $this->t('Name of the organization on Apigee Edge.'),
        '#default_value' => $apigee_details[$i]['org_name'][$i] ?? '',
        '#required' => TRUE,
        '#attributes' => ['autocomplete' => 'off'],
      ];

      $form['apigee_details']['set'][$i]['username'][$i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Username'),
        '#description' => $this->t('Apigee user email address or identity provider username that is used for authenticating with the endpoint.'),
        '#required' => TRUE,
        '#default_value' => $apigee_details[$i]['username'][$i] ?? '',
        '#attributes' => [
          'autocomplete' => 'off',
        ],
      ];

      $form['apigee_details']['set'][$i]['password'][$i] = [
        '#type' => 'password',
        '#title' => $this->t('Password'),
        '#description' => $this->t('Organization password that is used for authenticating with the endpoint.'),
        '#required' => TRUE,
        '#attributes' => [
          'autocomplete' => 'off',
          'value' => $apigee_details[$i]['password'][$i] ?? '',
        ],
      ];
    }

    $form['apigee_details']['actions'] = [
      '#type' => 'actions',
    ];

    $form['apigee_details']['actions']['add_set'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add one more'),
      '#submit' => [
        '::addOne',
      ],
      '#ajax' => [
        'callback' => '::addmoreCallback',
        'wrapper' => 'apigee-fieldset-wrapper',
      ],
    ];

    // If there is more than one set, add the remove button.
    if ($number_of_sets > 1) {
      $form['apigee_details']['actions']['remove_set'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove one'),
        '#submit' => [
          '::removeCallback',
        ],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::addmoreCallback',
          'wrapper' => 'apigee-fieldset-wrapper',
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
    $config = $this->config('apigee_sync.auth');
    $apigee_details = $config->get('apigee_details') ? JSON::decode($config->get('apigee_details')) : '';
    if ((is_array($apigee_details))) {
      foreach ($apigee_details as $apigee_detail) {
        if (in_array($id, $apigee_detail['id'])) {
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
    return $form['apigee_details'];
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
    $apigee_values = $form_state->getValue([
      'apigee_details',
      'set',
    ]);
    $apigee_values = Json::encode($apigee_values);

    $this->config('apigee_sync.auth')
      ->set('apigee_details', $apigee_values)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
