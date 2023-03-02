<?php

namespace Drupal\cardium_multigateway\Services;

use Apigee\Edge\Client;
use Apigee\Edge\Api\Management\Controller\DeveloperAppController;
use Apigee\Edge\Api\Management\Controller\DeveloperAppCredentialController;
use Apigee\Edge\Api\Management\Entity\DeveloperApp;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Utility\Error;
use Drupal\Component\Datetime\TimeInterface;
use Http\Message\Authentication\BasicAuth;
use GuzzleHttp\ClientInterface;

/**
 * Provides an SDK to connect Apigee gateway.
 */
class ApigeeConnector {

  use StringTranslationTrait;

  /**
   * The gateway details.
   *
   * @var Variable
   */
  protected $apigeeConfig;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The account variable.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  public $account;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The Guzzle client instance.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a new SDKConnector.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The factory for account objects.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The Messenger.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The Logger Factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The Guzzle client instance.
   */
  public function __construct(ConfigFactoryInterface $config_factory,
                              EntityTypeManagerInterface $entity_type_manager,
                              AccountInterface $account,
                              MessengerInterface $messenger,
                              LoggerChannelFactoryInterface $loggerFactory,
                              TimeInterface $time,
                              ClientInterface $httpClient) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $account;
    $this->messenger = $messenger;
    $this->loggerFactory = $loggerFactory;
    $this->time = $time;
    $this->httpClient = $httpClient;

    $apigee_details = $this->configFactory->get('apigee_sync.auth')->get('apigee_details');
    $this->apigeeConfig = $this->getApigeeConfig($apigee_details);
  }

  /**
   * {@inheritdoc}
   */
  public function getApigeeConfig($apigee_details) {
    $apigee_config = [];
    $apigee_details = JSON::decode($apigee_details);
    foreach ($apigee_details as $key => $apigee_detail) {
      $new_key = $apigee_details[$key]['id'][$key];
      $apigee_config[$new_key] = $apigee_detail;
    }
    return $apigee_config;
  }

  /**
   * Fetch apigee developer detail from the gateway on the basis of email id.
   */
  public function getDeveloper($endpoint, $username, $password, $organizationName, $email) {
    try {
      $developer = $response = NULL;
      $url = $endpoint . '/organizations/' . $organizationName . '/developers/' . $email;
      $response = $this->httpClient->request('GET', $url, [
        'auth' => [$username, $password],
      ]);

      if ($response->getStatusCode() == '200') {
        $developer = JSON::decode($response->getBody());
        return $developer['developerId'];
      }
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError($this->t('Unable to fetch apigee developer details: @error', ['@error' => $e->getMessage()]));
      $this->loggerFactory->get('apigee_sync')->error('Unable to fetch apigee developer details - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * Function to create an Apigee Developer.
   */
  public function createDeveloper($username, $email, $fname, $lname, $organizationName, $endpoint, $client_username, $client_password) {
    try {
      $url = $endpoint . '/organizations/' . $organizationName . '/developers';
      $developer = JSON::encode([
        'email' => $email,
        'firstName' => $fname,
        'lastName' => $lname,
        'userName' => $username,
      ]);
      $response = $this->httpClient->request('POST', $url, [
        'headers' => [
          'content-type' => 'application/json',
        ],
        'auth' => [$client_username, $client_password],
        'body' => $developer,
      ]);

      if ($response->getStatusCode() == 400) {
        $this->messenger->addError($this->t('This developer already exists in the apigee gateway.'));
        $this->loggerFactory->get('multi_gateway')->error('This developer already exists in the apigee gateway.');
      }
      elseif ($response->getStatusCode() == '200') {
        $developer = JSON::decode($response->getBody());
        return $developer['developerId'];
      }
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError($this->t('Unable to create apigee developer: @error', ['@error' => $e->getMessage()]));
      $this->loggerFactory->get('apigee_sync')->error('Unable to create apigee developer - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * Create an app using apigee api products.
   */
  public function createApp($user_id, $apigee_apis) {
    try {
      $gateway_apis = $data = $api_products = [];
      $user = $this->entityTypeManager->getStorage('user')->load($user_id);
      $username = $email = $user->get('mail')->value;
      // Group the APIs.
      foreach ($apigee_apis as $apigee_api) {
        $api_details = $this->entityTypeManager->getStorage('api_products')->loadByProperties([
          'field_sync_product_id' => $apigee_api,
        ]);
        foreach ($api_details as $api_detail) {
          $api_source = $api_detail->field_api_source->value;
          $gateway_apis[$api_source][] = $apigee_api;
          $apigee_api_details[$apigee_api] = $api_detail->title->value;
        }
      }

      // Create APPs in respective gateways.
      foreach ($gateway_apis as $api_source => $gateway_api) {
        $apigee_detail = $this->apigeeConfig[$api_source];
        $key = key($apigee_detail['instance_name']);
        $endpoint = $apigee_detail['endpoint'][$key];
        $client_username = $apigee_detail['username'][$key];
        $client_password = $apigee_detail['password'][$key];

        $client = new Client(new BasicAuth($client_username, $client_password), $endpoint);
        // Create API key.
        foreach ($gateway_api as $api) {
          $app_name = $user_id . '-' . $this->time->getRequestTime() . '-' . $api;
          // Create user in Apigee.
          $developer_id = $this->getDeveloper($endpoint, $client_username, $client_password, $apigee_detail['org_name'][$key], $email);
          if ($developer_id == FALSE) {
            if ($user->hasField('field_first_name') && $user->hasField('field_last_name')) {
              $fname = $user->get('field_first_name')->value;
              $lname = $user->get('field_last_name')->value;
            }
            else {
              $fname = $lname = preg_replace('/[^A-Za-z0-9\-]/', '', $user->get('name')->value);
            }
            $developer_id = $this->createDeveloper($username, $email, $fname, $lname, $apigee_detail['org_name'][$key], $endpoint, $client_username, $client_password);
          }
          $api_products = $this->getApiProductList($endpoint, $client_username, $client_password, $apigee_detail['org_name'][$key], $api);
          $developerApp = new DeveloperApp(['name' => $app_name]);
          $dac = new DeveloperAppController($apigee_detail['org_name'][$key], $developer_id, $client);
          $dac->create($developerApp);

          $dacc = new DeveloperAppCredentialController($apigee_detail['org_name'][$key], $developer_id, $app_name, $client);
          // Add products, attributes, and scopes to the auto-generated
          // credential that was created along with the app.
          $credentials = $developerApp->getCredentials();
          $credential = reset($credentials);
          if (is_string($credential->id()) && is_array($api_products)) {
            $dacc->addProducts($credential->id(), $api_products);
          }
          $data[$api] = [
            'name' => $developerApp->getName(),
            'subscription_id' => $developerApp->getAppId(),
            'client_id' => $credential->getConsumerKey(),
            'client_secret' => $credential->getConsumerSecret(),
          ];
        }
      }
      return $data;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError($this->t('Failed to create apigee app: @error', ['@error' => $e->getMessage()]));
      $this->loggerFactory->get('multi_gateway')->error('Failed to create apigee app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * Update an app using apigee api products.
   */
  public function updateApp($user_id, $apigee_apis, $nid) {
    try {
      $existing_apis = $new_apis = $deleted_apis = $result = [];
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      $api_keys = $node->field_app_api_keys->value;
      $json_data = JSON::decode($api_keys);
      $api_keys = $json_data['apigee'] ?? [];

      if (is_array($api_keys) && !empty($api_keys)) {
        foreach ($api_keys as $api_key) {
          if (is_array($api_key) && !empty($api_key)) {
            $existing_apis = array_keys($api_key);
          }
        }
      }
      $new_apis = array_diff($apigee_apis, $existing_apis);
      $deleted_apis = array_diff($existing_apis, $apigee_apis);

      // If there is no change in the gateway api selection.
      if (empty($deleted_apis) && empty($new_apis)) {
        return $api_keys[0];
      }

      if (!empty($deleted_apis)) {
        foreach ($deleted_apis as $deleted_api) {
          $delete_subscriptions[$deleted_api] = $api_keys[0][$deleted_api]['subscription_id'];
          $delete_app_name = $api_keys[0][$deleted_api]['name'];
          unset($api_keys[0][$deleted_api]);
        }
        $result = $api_keys[0];
        $this->deleteApp($user_id, $delete_subscriptions, $delete_app_name);
      }
      if (!empty($new_apis)) {
        $keys = $this->createApp($user_id, $new_apis);
        if (isset($api_keys[0]) && $api_keys[0] == NULL) {
          $api_keys[0] = [];
        }
        if (isset($api_keys[0]) && is_array($api_keys[0]) && is_array($keys)) {
          $result = array_merge($api_keys[0], $keys);
        }
        else {
          $result = array_merge($api_keys, $keys);
        }
      }
      return $result;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError($this->t('Failed to update apigee app: @error', ['@error' => $e->getMessage()]));
      $this->loggerFactory->get('multi_gateway')->error('Failed to update apigee app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * Delete an app using apigee api products.
   */
  public function deleteApp($user_id, $apigee_apis, $app_name) {
    try {
      $gateway_apis = [];
      $user = $this->entityTypeManager->getStorage('user')->load($user_id);
      $email = $user->get('mail')->value;
      foreach ($apigee_apis as $api_id => $apigee_api) {
        $api_details = $this->entityTypeManager->getStorage('api_products')->loadByProperties([
          'field_sync_product_id' => $api_id,
        ]);
        foreach ($api_details as $api_detail) {
          $api_source = $api_detail->field_api_source->value;
          $gateway_apis[$api_source][] = $apigee_api;
        }
      }

      // Delete APPs in respective gateways.
      foreach ($gateway_apis as $api_source => $gateway_api) {
        $apigee_detail = $this->apigeeConfig[$api_source];
        $key = key($apigee_detail['instance_name']);
        $endpoint = $apigee_detail['endpoint'][$key];
        $client_username = $apigee_detail['username'][$key];
        $client_password = $apigee_detail['password'][$key];

        $client = new Client(new BasicAuth($client_username, $client_password), $endpoint);
        // Create user in Apigee.
        $developer_id = $this->getDeveloper($endpoint, $client_username, $client_password, $apigee_detail['org_name'][$key], $email);
        if ($developer_id == FALSE) {
          if ($user->hasField('field_first_name') && $user->hasField('field_last_name')) {
            $fname = $user->get('field_first_name')->value;
            $lname = $user->get('field_last_name')->value;
          }
          else {
            $fname = $lname = preg_replace('/[^A-Za-z0-9\-]/', '', $user->get('name')->value);
          }
          $developer_id = $this->createDeveloper($username, $email, $fname, $lname, $apigee_detail['org_name'][$key], $endpoint, $client_username, $client_password);
        }
        // Delete APP in the gateway.
        $dac = new DeveloperAppController($apigee_detail['org_name'][$key], $developer_id, $client);
        $dac->delete(urldecode($app_name));
      }
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError($this->t('Failed to delete apigee app: @error', ['@error' => $e->getMessage()]));
      $this->loggerFactory->get('multi_gateway')->error('Failed to delete apigee app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * Fetch list of api products based on organization name & api package id .
   */
  public function getApiProductList($endpoint, $username, $password, $organizationName, $packageId) {
    $api_products = [];
    try {
      $api_package_details = $response = NULL;
      $url = $endpoint . '/mint/organizations/' . $organizationName . '/monetization-packages/' . $packageId;
      $response = $this->httpClient->request('GET', $url, [
        'auth' => [$username, $password],
      ]);

      if ($response->getStatusCode() == '200') {
        $api_package_details = JSON::decode($response->getBody());
        if (!empty($api_package_details)) {
          foreach ($api_package_details['product'] as $j => $api_product) {
            $api_products[$j] = $api_product['name'];
          }
        }
        return $api_products;
      }
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError($this->t('Failed to delete apigee app: @error', ['@error' => $e->getMessage()]));
      $this->loggerFactory->get('multi_gateway')->error('Failed to delete apigee app - ' . $context['@message']);
      return FALSE;
    }
  }

}
