<?php

namespace Drupal\cardium_multigateway\Services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Aws\Credentials\Credentials;
use Aws\ApiGateway\ApiGatewayClient;
use Drupal\Core\Utility\Error;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Provides an SDK to connect Aws gateway.
 */
class AwsConnector {

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
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The factory for account objects.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The Messenger.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The Logger Factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager,
                              ConfigFactoryInterface $config_factory,
                              AccountInterface $account,
                              MessengerInterface $messenger,
                              LoggerChannelFactoryInterface $loggerFactory,
                              TimeInterface $time) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->account = $account;
    $this->messenger = $messenger;
    $this->loggerFactory = $loggerFactory;
    $this->time = $time;

    $aws_details = $this->configFactory->get('aws_sync.auth')->get('aws_details');
    $this->awsConfig = $this->getAwsConfig($aws_details);
  }

  /**
   * {@inheritdoc}
   */
  public function getAwsConfig($aws_details) {
    $aws_config = [];
    $aws_details = JSON::decode($aws_details);
    foreach ($aws_details as $key => $aws_detail) {
      $new_key = $aws_details[$key]['id'][$key];
      $aws_config[$new_key] = $aws_detail;
    }
    return $aws_config;
  }

  /**
   * {@inheritdoc}
   */
  public function createApp($user_id, $aws_apis) {
    try {
      $gateway_apis = $data = [];
      // Group the APIs.
      foreach ($aws_apis as $aws_api) {
        $api_details = $this->entityTypeManager->getStorage('api_products')->loadByProperties(['field_sync_product_id' => $aws_api]);
        foreach ($api_details as $api_detail) {
          $api_source = $api_detail->field_api_source->value;
          $gateway_apis[$api_source][] = $aws_api;
        }
      }

      // Create APPs in respective gateways.
      foreach ($gateway_apis as $api_source => $gateway_api) {
        $aws_detail = $this->awsConfig[$api_source];

        $credentials = new Credentials(
          current($aws_detail['access_key_id']),
          current($aws_detail['secret_key'])
        );

        $aws_client = new ApiGatewayClient([
          'version' => current($aws_detail['version']),
          'region' => current($aws_detail['region']),
          'credentials' => $credentials,
          'http' => $this->httpClientConfiguration(),
        ]);

        $stage_keys = $this->getAwsApiStageKeys($gateway_api, current($aws_detail['stage_name']));

        // Create API key.
        foreach ($gateway_api as $api) {
          $app_name = $user_id . '-' . $this->time->getRequestTime() . '-' . $api;
          $aws_api_key = $aws_client->createApiKey([
            'customerId' => $this->account->getEmail(),
            'enabled' => TRUE,
            'generateDistinctId' => TRUE,
            'name' => $app_name,
            'stageKeys' => $stage_keys,
          ]);

          // Add API key to Usage plan.
          $aws_client->createUsagePlanKey([
            'keyId' => $aws_api_key['id'],
            'keyType' => 'API_KEY',
            'usagePlanId' => $api,
          ]);
          $data[$api] = [
            'name' => $aws_api_key['name'],
            'subscription_id' => $aws_api_key['id'],
            'client_id' => $aws_api_key['value'],
            'client_secret' => '',
          ];
        }
      }
      return $data;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to create aws app.' . $e->getMessage() . ')');
      $this->loggerFactory->get('multi_gateway')->error('Failed to create aws app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateApp($user_id, $aws_apis, $nid) {
    try {
      $existing_apis = $new_apis = $deleted_apis = [];
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      $api_keys = $node->field_app_api_keys->value;
      $api_keys = JSON::decode($api_keys)['aws'];

      foreach ($api_keys as $api_key) {
        $existing_apis = array_keys($api_key);
      }
      $new_apis = array_diff($aws_apis, $existing_apis);
      $deleted_apis = array_diff($existing_apis, $aws_apis);

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
      $this->messenger->addError('Failed to update aws app.' . $e->getMessage() . ')');
      $this->loggerFactory->get('multi_gateway')->error('Failed to update aws app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteApp($user_id, $aws_apis) {
    try {
      $gateway_apis = [];
      foreach ($aws_apis as $api_id => $aws_api) {
        $api_details = $this->entityTypeManager->getStorage('api_products')->loadByProperties(['field_sync_product_id' => $api_id]);
        foreach ($api_details as $api_detail) {
          $api_source = $api_detail->field_api_source->value;
          $gateway_apis[$api_source][] = $aws_api;
        }
      }

      // Delete APPs in respective gateways.
      foreach ($gateway_apis as $api_source => $gateway_api) {
        $aws_detail = $this->awsConfig[$api_source];

        $credentials = new Credentials(
          current($aws_detail['access_key_id']),
          current($aws_detail['secret_key'])
        );

        $aws_client = new ApiGatewayClient([
          'version' => current($aws_detail['version']),
          'region' => current($aws_detail['region']),
          'credentials' => $credentials,
          'http' => $this->httpClientConfiguration(),
        ]);

        // Delete API key.
        foreach ($gateway_api as $api) {
          // Delete API Key in the gateway.
          $aws_client->deleteApiKey([
            'apiKey' => $api,
          ]);
        }
      }
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to delete aws app.' . $e->getMessage() . ')');
      $this->loggerFactory->get('multi_gateway')->error('Failed to delete aws app - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAwsApiStageKeys($gateway_api, $stage) {
    $stage_keys = [];
    foreach ($gateway_api as $api_id) {
      $api_products = $this->entityTypeManager->getStorage('api_products')
        ->loadByProperties([
          'field_sync_product_id' => $api_id,
        ]);

      foreach ($api_products as $api_product) {
        $apis = $api_product->field_apis->getValue();
        if (!empty($apis)) {
          foreach ($apis as $api) {
            $node = $this->entityTypeManager->getStorage('node')->load($api['target_id']);
            $stage_keys[] = [
              'restApiId' => $node->field_sync_api_id->value,
              'stageName' => $stage,
            ];
          }
        }
      }
    }
    return $stage_keys;
  }

  /**
   * Get HTTP client overrides for AWS API client.
   *
   * Allows to override some configuration of the http client built by the
   * factory for the API client.
   *
   * @return array
   *   Associative array of configuration settings.
   *
   * @see http://docs.guzzlephp.org/en/stable/request-options.html
   */
  protected function httpClientConfiguration(): array {
    return [
      'connect_timeout' => 30,
      'timeout' => 30,
    ];
  }

}
