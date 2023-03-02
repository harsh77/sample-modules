<?php

namespace Drupal\cardium_multigateway\Services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Utility\Error;
use GuzzleHttp\ClientInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Provides an SDK to connect Kong gateway.
 */
class KongConnector {

  use StringTranslationTrait;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

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
   * Guzzle\Client instance.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs a new SDKConnector.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The Messenger.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The Logger Factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The Guzzle\Client instance.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager,
                             ConfigFactoryInterface $config_factory,
                             MessengerInterface $messenger,
                             LoggerChannelFactoryInterface $loggerFactory,
                             ClientInterface $httpClient,
                             TimeInterface $time) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;

    $kong_details = $this->configFactory->get('kong_sync.auth')->get('kong_credentials');
    $this->kongConfig = $this->getKongConfig($kong_details);
    $this->messenger = $messenger;
    $this->loggerFactory = $loggerFactory;
    $this->httpClient = $httpClient;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public function getKongConfig($kong_details) {
    $kong_config = [];
    $kong_details = JSON::decode($kong_details);
    foreach ($kong_details as $key => $kong_detail) {
      $new_key = $kong_details[$key]['id'][$key];
      $kong_config[$new_key] = $kong_detail;
    }
    return $kong_config;
  }

  /**
   * {@inheritdoc}
   */
  public function checkDataExist($id, $type, $kong_detail) {
    try {
      $kongServiceUrl = current($kong_detail['endpoint_url']) . current($kong_detail['workspace']);
      $kongHeader = [
        'headers' => [
          'Authorization' => current($kong_detail['auth_key']),
        ],
      ];
      if ($type == 'user') {
        $user_endpoint_url = $kongServiceUrl . '/consumers/' . $id;
        $user_response = $this->httpClient->request('GET', $user_endpoint_url, $kongHeader);
        $data = Json::decode($user_response->getBody());
        if (!empty($data)) {
          return $data['id'];
        }
        else {
          return 0;
        }
      }
      elseif ($type == 'rate_limit') {
        $endpoint_url = $kongServiceUrl . '/consumers/' . $id . '/plugins';
        $response = $this->httpClient->request('GET', $endpoint_url, $kongHeader);
        $data = Json::decode($response->getBody())['data'];
        if (!empty($data)) {
          return $data[0]['id'];
        }
        else {
          return 0;
        }
      }
    }
    catch (\Exception $exception) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setKongRateLimitByApi($api) {
    $api_product = $this->entityTypeManager->getStorage('api_products')->loadByProperties(['field_sync_product_id' => $api]);
    if (!empty($api_product->field_number_of_days)) {
      $no_of_days = $api_product->field_number_of_days->value;
    }
    else {
      $no_of_days = 0;
    }
    $config = [];
    if (!empty($api_product->field_number_of_calls)) {
      $number_of_calls = intval($api_product->field_number_of_calls);
    }
    else {
      $number_of_calls = 0;
    }
    switch ($no_of_days) {
      case '946080000':
        $config['year'] = $number_of_calls;
        break;

      case '2592000':
        $config['month'] = $number_of_calls;
        break;

      case '86400':
        $config['day'] = $number_of_calls;
        break;

      case '3600':
        $config['hour'] = $number_of_calls;
        break;

      case '60':
        $config['minute'] = $number_of_calls;
        break;

      default:
        if ($number_of_calls == 0) {
          // Set minimum call to 100 incase nothing is set.
          $number_of_calls += 100;
        }
        $config['second'] = $number_of_calls;
    }
    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function createApp($user_id, $kong_apis) {
    try {
      $gateway_apis = $data = [];
      // Group the APIs.
      foreach ($kong_apis as $kong_api) {
        $api_details = $this->entityTypeManager->getStorage('api_products')->loadByProperties(['field_sync_product_id' => $kong_api]);

        foreach ($api_details as $api_detail) {
          $api_source = $api_detail->field_api_source->value;
          $gateway_apis[$api_source][] = $kong_api;
          $kong_api_details[$kong_api] = $api_detail->title->value;
        }
      }
      // Create APPs in respective gateways.
      foreach ($gateway_apis as $api_source => $gateway_api) {
        $kong_detail = $this->kongConfig[$api_source];
        foreach ($gateway_api as $api) {
          // Create user in Kong.
          $service_details['service_name'] = $kong_api_details[$api];
          $username = $user_id . '-' . $service_details['service_name'] . '-' . $this->time->getRequestTime();
          $user_exist = $this->checkDataExist($username, 'user', $kong_detail);
          $kongServiceUrl = current($kong_detail['endpoint_url']) . current($kong_detail['workspace']);
          $auth_key = current($kong_detail['auth_key']);
          if (!$user_exist) {
            $user_endpoint_url = $kongServiceUrl . '/consumers';

            $user_body = Json::encode([
              'username' => $username,
            ]);
            $this->httpClient->request('POST', $user_endpoint_url, [
              'headers' => [
                'content-type' => 'application/json',
                'Authorization' => $auth_key,
              ],
              'body' => $user_body,
            ]);
          }
          // Create Customer app to generate client Id and secret.
          $endpoint_url = $kongServiceUrl . '/consumers/' . $username . '/oauth2/';
          $service_body = Json::encode([
            'name' => $user_id . '-' . $service_details['service_name'] . '-' . $this->time->getRequestTime(),
            'hash_secret' => FALSE,
          ]);
          $response = $this->httpClient->request('POST', $endpoint_url, [
            'headers' => [
              'content-type' => 'application/json',
              'Authorization' => $auth_key,
            ],
            'body' => $service_body,
          ]);

          $response_data = Json::decode($response->getBody());
          // Subscribe to a rate limit.
          $rate_limit_exist = $this->checkDataExist($username, 'rate_limit', $kong_detail);
          if (!$rate_limit_exist) {
            $rate_limit_url = $kongServiceUrl . '/consumers/' . $username . '/plugins';

            $rate_limit_body = Json::encode([
              'name' => 'rate-limiting',
              'service' => [
                'name' => $service_details['service_name'],
              ],
              'config' => $this->setKongRateLimitByApi($api),
            ]);

            $rate_response = $this->httpClient->request('POST', $rate_limit_url, [
              'headers' => [
                'content-type' => 'application/json',
                'Authorization' => $auth_key,
              ],
              'body' => $rate_limit_body,
            ]);
            $rate_data = Json::decode($rate_response->getBody());
          }

          else {
            $rate_data['id'] = '';
            $this->messenger->addError($this->t('Subscription with the same name is already exist.'));
          }
          $data[$api] = [
            'name' => $response_data['name'],
            'subscription_id' => $rate_data['id'],
            'client_id' => $response_data['client_id'],
            'client_secret' => $response_data['client_secret'],
          ];
          $data[$api]['api_source'] = $api_source;
        }
      }
      return $data;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to create kong app.' . $e->getMessage() . ')');
      $this->loggerFactory->get('multi_gateway')->error('Failed to create kong app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateApp($user_id, $kong_apis, $nid) {
    try {
      $existing_apis = $new_apis = $deleted_apis = [];
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      $api_keys = $node->field_app_api_keys->value;
      $api_keys = JSON::decode($api_keys)['kong'];
      foreach ($api_keys as $api_key) {
        $existing_apis = array_keys($api_key);
      }

      $new_apis = array_diff($kong_apis, $existing_apis);
      $deleted_apis = array_diff($existing_apis, $kong_apis);

      if (!empty($deleted_apis)) {
        foreach ($deleted_apis as $deleted_api) {
          $delete_subscriptions[$deleted_api] = $api_keys[0][$deleted_api];
          unset($api_keys[0][$deleted_api]);
        }
        $result = $api_keys[0];
        $this->deleteApp($user_id, $delete_subscriptions);
      }
      if (!empty($new_apis)) {
        $keys = $this->createApp($user_id, $new_apis);
        if ($api_keys[0] == NULL) {
          $api_keys[0] = [];
        }
        $result = array_merge($api_keys[0], $keys);
      }
      if (empty($deleted_apis) && empty($new_apis)) {
        $result = $api_keys[0];
      }
      return $result;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to update Kong app.' . $e->getMessage() . ')');
      $this->loggerFactory->get('multi_gateway')->error('Failed to update Kong app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteApp($user_id, $delete_kong_apis) {
    try {

      // Delete APPs in respective gateways.
      foreach ($delete_kong_apis as $deleted_api) {
        $api_source = $deleted_api['api_source'];
        $kong_detail = $this->kongConfig[$api_source];
        $kongServiceUrl = current($kong_detail['endpoint_url']) . current($kong_detail['workspace']);
        $kongHeader = [
          'headers' => [
            'Authorization' => current($kong_detail['auth_key']),
          ],
        ];
        $url = $kongServiceUrl . '/plugins/' . $deleted_api['subscription_id'];
        $this->httpClient->request('DELETE', $url, $kongHeader);

        // Delete Consumer (developer).
        $consumer_url = $kongServiceUrl . '/consumers/' . $deleted_api['name'];
        $this->httpClient->request('DELETE', $consumer_url, $kongHeader);
      }
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to delete Kong app.' . $e->getMessage() . ')');
      $this->loggerFactory->get('multi_gateway')->error('Failed to delete Kong app - ' . $context['@message']);
      return FALSE;
    }
  }

}
