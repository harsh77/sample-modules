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
 * Provides an SDK to connect Mulesoft gateway.
 */
class MulesoftConnector {

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

    $mulesoft_details = $this->configFactory->get('mulesoft_sync.auth')->get('mulesoft_details');
    $this->mulesoftConfig = $this->getMulesoftConfig($mulesoft_details);
    $this->messenger = $messenger;
    $this->loggerFactory = $loggerFactory;
    $this->httpClient = $httpClient;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public function getMulesoftConfig($mulesoft_details) {
    $mulesoft_config = [];
    $mulesoft_details = JSON::decode($mulesoft_details);
    foreach ($mulesoft_details as $key => $mulesoft_detail) {
      $new_key = $mulesoft_details[$key]['id'][$key];
      $mulesoft_config[$new_key] = $mulesoft_detail;
    }
    return $mulesoft_config;
  }

  /**
   * {@inheritdoc}
   */
  public function createApp($user_id, $mulesoft_apis) {
    try {
      $gateway_apis = $data = [];
      // Group the APIs.
      foreach ($mulesoft_apis as $mulesoft_api) {
        $api_details = $this->entityTypeManager->getStorage('api_products')->loadByProperties(['field_sync_product_id' => $mulesoft_api]);

        foreach ($api_details as $api_detail) {
          $api_source = $api_detail->field_api_source->value;
          $gateway_apis[$api_source][] = $mulesoft_api;
          $mulesoft_api_details[$mulesoft_api] = $api_detail->title->value;
        }
      }
      // Create APPs in respective gateways.
      foreach ($gateway_apis as $api_source => $gateway_api) {
        $mulesoft_detail = $this->mulesoftConfig[$api_source];

        foreach ($gateway_api as $api) {

          $service_details['service_name'] = $mulesoft_api_details[$api];

          $serialized_body = JSON::encode([
            'name' => $user_id . '-' . $service_details['service_name'] . '-' . $this->time->getRequestTime(),
          ]);

          $baseurl = reset($mulesoft_detail['base_url']);
          $org_id = reset($mulesoft_detail['org_id']);
          $username = reset($mulesoft_detail['username']);
          $password = reset($mulesoft_detail['password']);

          $access_token = $this->getaccesstoken($username, $password, $baseurl);

          $response = $this->httpClient->post($baseurl . '/apiplatform/repository/v2/organizations/' . $org_id . '/applications', [
            'body' => $serialized_body,
            'headers' => [
              'Content-Type' => 'application/json',
              'Authorization' => 'Bearer ' . $access_token,
            ],
          ]);

          $response_data = JSON::decode($response->getBody());

          $data[$api] = [
            'name' => $response_data['name'],
            'subscription_id' => $response_data['id'],
            'client_id' => $response_data['clientId'],
            'client_secret' => $response_data['clientSecret'],
          ];
          $data[$api]['api_source'] = $api_source;
        }
      }
      return $data;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to create Mulesoft app.' . $e->getMessage() . ')');
      $this->loggerFactory->get('multi_gateway')->error('Failed to create Mulesoft app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateApp($user_id, $mulesoft_apis, $nid) {
    try {
      $existing_apis = $new_apis = $deleted_apis = [];
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      $api_keys = $node->field_app_api_keys->value;
      $api_keys = JSON::decode($api_keys)['mulesoft'];
      foreach ($api_keys as $api_key) {
        $existing_apis = array_keys($api_key);
      }

      $new_apis = array_diff($mulesoft_apis, $existing_apis);
      $deleted_apis = array_diff($existing_apis, $mulesoft_apis);

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
        $result = $api_keys[0] + $keys;
      }
      if (empty($deleted_apis) && empty($new_apis)) {
        $result = $api_keys[0];
      }
      return $result;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to update Mulesoft app.' . $e->getMessage() . ')');
      $this->loggerFactory->get('multi_gateway')->error('Failed to update Mulesoft app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteApp($user_id, $delete_mulesoft_apis) {
    try {
      // Delete APPs in respective gateways.
      foreach ($delete_mulesoft_apis as $deleted_api) {
        $api_source = $deleted_api['api_source'];
        $mulesoft_detail = $this->mulesoftConfig[$api_source];

        $baseurl = reset($mulesoft_detail['base_url']);
        $org_id = reset($mulesoft_detail['org_id']);
        $username = reset($mulesoft_detail['username']);
        $password = reset($mulesoft_detail['password']);

        $access_token = $this->getaccesstoken($username, $password, $baseurl);

        $host = $baseurl . '/apiplatform/repository/v2/organizations/' . $org_id . '/applications/' . $deleted_api['subscription_id'];

        $this->httpClient->delete($host, [
          'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $access_token,
          ],
        ]);
      }
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to delete Mulesoft app.' . $e->getMessage() . ')');
      $this->loggerFactory->get('multi_gateway')->error('Failed to delete Mulesoft app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getaccessToken($name, $password, $baseurl) {
    try {
      // Generate new access token.
      $url = $baseurl . '/accounts/login';
      $response = $this->httpClient->request('post', $url, [
        'form_params' => ['username' => $name, 'password' => $password],
        'headers' => [
          'Content-type' => 'application/x-www-form-urlencoded',
        ],
      ]);
      $result = Json::decode($response->getBody());
      return $result['access_token'];
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to generate access token.' . $e->getMessage() . ')');
      $this->loggerFactory->get('mulesoft_sync')->error('Failed to generate access token - ' . $context['@message']);
      return FALSE;
    }
  }

}
