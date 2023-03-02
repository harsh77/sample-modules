<?php

namespace Drupal\cardium_multigateway\Services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Utility\Error;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use GuzzleHttp\ClientInterface;

/**
 * Provides an SDK to connect Azure gateway.
 */
class AzureConnector {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

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
   * The tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Constructs a new SDKConnector.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The factory for account objects.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The Messenger.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The Logger Factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The Client instance.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The factory for the temp store object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager,
                              ConfigFactoryInterface $config_factory,
                              AccountInterface $account,
                              MessengerInterface $messenger,
                              LoggerChannelFactoryInterface $loggerFactory,
                              ClientInterface $httpClient,
                              TimeInterface $time,
                              PrivateTempStoreFactory $temp_store_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->account = $account;
    $this->messenger = $messenger;
    $this->loggerFactory = $loggerFactory;
    $this->httpClient = $httpClient;
    $this->time = $time;
    $this->tempStoreFactory = $temp_store_factory;

    $azure_details = $this->configFactory->get('azure_sync.auth')->get('azure_details');
    $this->azureConfig = $this->getAzureConfig($azure_details);
  }

  /**
   * {@inheritdoc}
   */
  public function getAzureConfig($azure_details) {
    $azure_config = [];
    $azure_details = JSON::decode($azure_details);
    foreach ($azure_details as $key => $azure_detail) {
      $new_key = $azure_details[$key]['id'][$key];
      $azure_config[$new_key] = $azure_detail;
    }
    return $azure_config;
  }

  /**
   * {@inheritdoc}
   */
  public function createApp($user_id, $azure_apis) {
    try {
      $gateway_apis = $data = [];
      $first_name = $last_name = '';
      $user = $this->entityTypeManager->getStorage('user')->load($user_id);
      if ($user->hasField('field_first_name') && $user->hasField('field_last_name')) {
        $first_name = $user->get('field_first_name')->value;
        $last_name = $user->get('field_last_name')->value;
        $azure_user_name = $first_name . $last_name . $user_id;
      }
      else {
        $first_name = $last_name = preg_replace('/[^A-Za-z0-9\-]/', '', $user->get('name')->value);
        $azure_user_name = $user_id . '-' . $first_name;
      }

      // Group the APIs.
      foreach ($azure_apis as $azure_api) {
        $api_details = $this->entityTypeManager->getStorage('api_products')->loadByProperties(['field_sync_product_id' => $azure_api]);
        foreach ($api_details as $api_detail) {
          $api_source = $api_detail->field_api_source->value;
          $gateway_apis[$api_source][] = $azure_api;
        }
      }

      // Create APPs in respective gateways.
      foreach ($gateway_apis as $api_source => $gateway_api) {
        $azure_detail = $this->azureConfig[$api_source];

        // Create user in Azure.
        $sas_token = current(current($azure_detail['api_token'])['sas_token']);
        $sas_token_id = current(current($azure_detail['api_token'])['sas_token_id']);

        $azure_service_url = current($azure_detail['management_api_domian']) .
                        '/subscriptions/' . current($azure_detail['subscription_id']) .
                        '/resourceGroups/' . current($azure_detail['resource_group_name']) .
                        '/providers/Microsoft.ApiManagement/service/' . current($azure_detail['service_name']);

        $devloper_url = $azure_service_url . '/users/' . strtolower($azure_user_name) .
                        '?api-version=' . current($azure_detail['api_version']);

        $developer_body = Json::encode([
          "properties" => [
            'firstName' => $first_name,
            'lastName' => $last_name,
            'email' => $this->account->getEmail(),
            'confirmation' => "signup",
          ],
        ]);

        $this->httpClient->request('PUT', $devloper_url, [
          'headers' => [
            'content-type' => 'application/json',
            'Authorization' => $this->getSasToken($sas_token, $sas_token_id),
          ],
          'body' => $developer_body,
        ]);

        // Subscribe to the API Product.
        foreach ($gateway_api as $api) {
          $subscription_name = $user_id . '-' . $this->time->getRequestTime() . '-' . $api;
          $url = $azure_service_url . '/subscriptions/' . $subscription_name .
                '?notify=false&api-version=' . current($azure_detail['api_version']);

          $body = Json::encode([
            'properties' => [
              'state' => 'active',
              'scope' => '/subscriptions/' . current($azure_detail['subscription_id']) .
              '/resourceGroups/' . current($azure_detail['resource_group_name']) .
              '/providers/Microsoft.ApiManagement/service/' . current($azure_detail['service_name']) .
              '/products/' . $api,
              'displayName' => $subscription_name,
              'ownerId' => '/subscriptions/' . current($azure_detail['subscription_id']) . '/resourceGroups/' . current($azure_detail['resource_group_name']) .
              '/providers/Microsoft.ApiManagement/service/' . current($azure_detail['service_name']) . '/users/' . strtolower($azure_user_name),
            ],
          ]);

          $response = $this->httpClient->request('PUT', $url, [
            'headers' => [
              'content-type' => 'application/json',
              'Authorization' => $this->getSasToken($sas_token, $sas_token_id),
            ],
            'body' => $body,
          ]);
          $azure_data = Json::decode($response->getBody());

          $data[$api] = [
            'name' => $azure_data['name'],
            'subscription_id' => $azure_data['name'],
            'client_id' => $azure_data['properties']['primaryKey'],
            'client_secret' => $azure_data['properties']['secondaryKey'],
          ];
        }
      }
      return $data;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to create azure app.' . $e->getMessage() . ')');
      $this->loggerFactory->get('multi_gateway')->error('Failed to create azure app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateApp($user_id, $azure_apis, $nid) {
    try {
      $existing_apis = $new_apis = $deleted_apis = [];
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      $api_keys = $node->field_app_api_keys->value;
      $api_keys = JSON::decode($api_keys)['azure'];

      foreach ($api_keys as $api_key) {
        $existing_apis = array_keys($api_key);
      }
      $new_apis = array_diff($azure_apis, $existing_apis);
      $deleted_apis = array_diff($existing_apis, $azure_apis);

      // If there is no change in the gateway api selection.
      if (empty($deleted_apis) && empty($new_apis)) {
        return $api_keys[0];
      }

      if (!empty($deleted_apis)) {
        foreach ($deleted_apis as $deleted_api) {
          $delete_subscriptions[$deleted_api] = $api_keys[0][$deleted_api]['subscription_id'];
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
      return $result;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to update azure app.' . $e->getMessage() . ')');
      $this->loggerFactory->get('multi_gateway')->error('Failed to update azure app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteApp($user_id, $azure_apis) {
    try {
      $gateway_apis = [];
      foreach ($azure_apis as $api_id => $azure_api) {
        $api_details = $this->entityTypeManager->getStorage('api_products')->loadByProperties(['field_sync_product_id' => $api_id]);
        foreach ($api_details as $api_detail) {
          $api_source = $api_detail->field_api_source->value;
          $gateway_apis[$api_source][] = $azure_api;
        }
      }

      // Delete APPs in respective gateways.
      foreach ($gateway_apis as $api_source => $gateway_api) {
        $azure_detail = $this->azureConfig[$api_source];
        $sas_token = current(current($azure_detail['api_token'])['sas_token']);
        $sas_token_id = current(current($azure_detail['api_token'])['sas_token_id']);
        $azure_service_url = current($azure_detail['management_api_domian']) .
                        '/subscriptions/' . current($azure_detail['subscription_id']) .
                        '/resourceGroups/' . current($azure_detail['resource_group_name']) .
                        '/providers/Microsoft.ApiManagement/service/' . current($azure_detail['service_name']);

        // Delete subscription keya.
        foreach ($gateway_api as $api) {
          $url = $azure_service_url .
              '/subscriptions/' . $api .
              '?notify=false&api-version=' . current($azure_detail['api_version']);
          $this->httpClient->request('DELETE', $url, [
            'headers' => [
              'Authorization' => $this->getSasToken($sas_token, $sas_token_id),
            ],
          ]);
        }
      }
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to delete azure app.' . $e->getMessage() . ')');
      $this->loggerFactory->get('multi_gateway')->error('Failed to delete azure app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * Generate a Azure Sas Token.
   */
  public function generateSasToken($id, $sasKeyValue, $expiry) {
    $dataToSign = $id . "\n" . $expiry;
    $hash = hash_hmac('sha512', $dataToSign, $sasKeyValue, TRUE);
    $signature = base64_encode($hash);
    $encodedToken = "SharedAccessSignature uid=" . $id . "&ex=" . $expiry . "&sn=" . $signature;
    return $encodedToken;
  }

  /**
   * Generate access token to interact with Azure gateway.
   */
  public function getSasToken($sasKeyValue, $id) {
    $sas_token = NULL;
    $date = strtotime("+1 day");
    $expiry = date("Y-m-d", $date) . "T" . date("h:i:s", $date) . ".0000000Z";
    $expiry_for_session = date("Y-m-d", $date) . "T" . date("h:i:s", $date);

    if (empty($this->tempStoreFactory->get('azure_sync')->get('sas_token_key'))) {
      // Generating new token.
      $sas_token = $this->generateSasToken($id, $sasKeyValue, $expiry);
      $this->tempStoreFactory->get('azure_sync')->set('sas_token_key', $sas_token);
      $this->tempStoreFactory->get('azure_sync')->set('sas_token_expiry_time', $expiry_for_session);
    }
    else {
      $current_time = $this->time->getRequestTime();
      $current_time_compare = date("Y-m-d", $current_time) . "T" . date("h:i:s", $current_time);
      $time_diff = (strtotime($this->tempStoreFactory->get('azure_sync')->get('sas_token_expiry_time')) - strtotime($current_time_compare)) / 3600;
      if ($time_diff <= 1) {
        // Deleting existing token values.
        $this->tempStoreFactory->get('azure_sync')->delete('sas_token_key');
        $this->tempStoreFactory->get('azure_sync')->delete('sas_token_expiry_time');
        // Generating new token.
        $sas_token = $this->generateSasToken($id, $sasKeyValue, $expiry);
        $this->tempStoreFactory->get('azure_sync')->set('sas_token_key', $sas_token);
        $this->tempStoreFactory->get('azure_sync')->set('sas_token_expiry_time', $expiry_for_session);
      }
      else {
        $sas_token = $this->tempStoreFactory->get('azure_sync')->get('sas_token_key');
      }
    }
    return $sas_token;
  }

}
