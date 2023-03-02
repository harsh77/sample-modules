<?php

namespace Drupal\azure_sync\Services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Error;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

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
   * Logger Factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $loggerFactory;

  /**
   * Messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

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
   * @param \Drupal\Core\Logger\LoggerChannelFactory $loggerFactory
   *   The Logger Factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The Messenger.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The Client instance.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The factory for the temp store object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager,
                              ConfigFactoryInterface $config_factory,
                              LoggerChannelFactory $loggerFactory,
                              MessengerInterface $messenger,
                              ClientInterface $httpClient,
                              TimeInterface $time,
                              PrivateTempStoreFactory $temp_store_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->httpClient = $httpClient;
    $this->time = $time;
    $this->tempStoreFactory = $temp_store_factory;

    $this->config = $this->configFactory->get('azure_sync.auth')->get('azure_details');
    if (empty($this->config)) {
      $this->messenger->addMessage($this->t('Minimum 1 connection needs to be configured for API Sync.'), 'error');
      $response = new RedirectResponse(Url::fromRoute('azure_sync.settings')->toString());
      return $response->send();
    }
    else {
      $this->azure_details = JSON::decode($this->config);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAllApis() {
    try {
      $key = 0;
      $api_list = [];
      foreach ($this->azure_details as $azure_detail) {
        $serviceUrl = $azure_detail['management_api_domian'][$key] .
                      '/subscriptions/' . $azure_detail['subscription_id'][$key] .
                      '/resourceGroups/' . $azure_detail['resource_group_name'][$key] .
                      '/providers/Microsoft.ApiManagement/service/' . $azure_detail['service_name'][$key] .
                      '/apis?api-version=' . $azure_detail['api_version'][$key];

        $sas_token = $azure_detail['api_token'][$key]['sas_token'][$key];
        $sas_token_id = $azure_detail['api_token'][$key]['sas_token_id'][$key];
        $header = [
          'headers' => [
            'Authorization' => $this->getSasToken($sas_token, $sas_token_id),
          ],
        ];
        $response = $this->httpClient->request('GET', $serviceUrl, $header);
        $apis = JSON::decode($response->getBody())['value'];

        foreach ($apis as $api) {
          $api_list[$api['name']] = [
            'api_id' => $api['name'],
            'api_name' => $api['properties']['displayName'],
            'service_url' => $api['properties']['serviceUrl'] ?? '',
            'source' => $azure_detail['instance_name'][$key],
            'api_source_id' => $azure_detail['id'][$key],
          ];
        }
        $key++;
      }
      return $api_list;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to fetch azure apis.' . $e->getMessage() . ')');
      $this->loggerFactory->get('azure_sync')->error('Failed to fetch azure apis - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAllApiProducts() {
    try {
      $key = 0;
      $product_list = [];
      foreach ($this->azure_details as $azure_detail) {
        $serviceUrl = $azure_detail['management_api_domian'][$key] .
                      '/subscriptions/' . $azure_detail['subscription_id'][$key] .
                      '/resourceGroups/' . $azure_detail['resource_group_name'][$key] .
                      '/providers/Microsoft.ApiManagement/service/' .
                      $azure_detail['service_name'][$key];
        $api_version = '?api-version=' . $azure_detail['api_version'][$key];
        $endpoint_url = $serviceUrl . '/products' . $api_version;

        $sas_token = $azure_detail['api_token'][$key]['sas_token'][$key];
        $sas_token_id = $azure_detail['api_token'][$key]['sas_token_id'][$key];
        $header = [
          'headers' => [
            'Authorization' => $this->getSasToken($sas_token, $sas_token_id),
          ],
        ];
        $response = $this->httpClient->request('GET', $endpoint_url, $header);
        $products = JSON::decode($response->getBody())['value'];

        foreach ($products as $product) {
          $policies = $this->getProductPolicies($serviceUrl, $header, $api_version, $product['name']);

          $product_list[$product['name']] = [
            'id' => $product['name'],
            'name' => $product['properties']['displayName'],
            'api_source' => $azure_detail['id'][$key],
            'apis' => $this->getProductApis($serviceUrl, $header, $api_version, $product['name']),
            'quota' => (isset($policies['quota'])) ? $policies['quota'] : '',
            'limit' => (isset($policies['limit'])) ? $policies['limit'] : '',
            'period' => (isset($policies['period'])) ? $policies['period'] : '',
            'source' => $azure_detail['instance_name'][$key],
          ];
        }
        $key++;
      }
      return $product_list;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to fetch azure apis.' . $e->getMessage() . ')');
      $this->loggerFactory->get('azure_sync')->error('Failed to fetch azure apis - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProductApis($serviceUrl, $header, $api_version, $product_name) {
    try {
      $api_list = [];
      $url = $serviceUrl . "/products/$product_name/apis" . $api_version;
      $response = $this->httpClient->request('GET', $url, $header);
      $apis = JSON::decode($response->getBody())['value'];
      foreach ($apis as $api) {
        $api_list[] = $api['name'];
      }
      return implode(', ', $api_list);
    }
    catch (\Exception $exception) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to fetch azure product apis.' . $e->getMessage() . ')');
      $this->loggerFactory->get('azure_sync')->error('Failed to fetch azure product apis - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProductPolicies($serviceUrl, $header, $api_version, $product_name) {
    try {
      $url = $serviceUrl . "/products/$product_name/policies" . $api_version;
      $response = $this->httpClient->request('GET', $url, $header);

      $data = json_decode($response->getBody());
      $policies = [];

      if (!empty($data->value)) {
        $data = json_decode($response->getBody())->value[0]->properties->value;
        $xml = simplexml_load_string($data, "SimpleXMLElement", LIBXML_NOCDATA);
        $policies_data = json_decode(json_encode($xml), TRUE);

        if ($policies_data) {
          $inbound = $policies_data['inbound'];
          if (isset($inbound['quota-by-key'])) {
            $limit = $inbound['quota-by-key']['@attributes']['calls'];
            $period = $inbound['quota-by-key']['@attributes']['renewal-period'];
            $policies = [
              'limit' => $limit,
              'period' => $period,
              'quota' => $this->getQuota($limit, $period),
            ];
          }
        }
      }
      return $policies;
    }
    catch (\Exception $exception) {
      $context = Error::decodeException($e);
      $this->messenger->addError('Failed to fetch azure product policies.' . $e->getMessage() . ')');
      $this->loggerFactory->get('azure_sync')->error('Failed to fetch azure product policies - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getQuota($limit, $period) {
    $quota = '';
    if (!empty($limit) && !empty($period)) {
      $quota = $limit . ' calls per ' . $this->getPeriod($period);
    }
    return $quota;
  }

  /**
   * {@inheritdoc}
   */
  public function getPeriod($api_period) {
    switch ($api_period) {
      case '946080000':
        $period = 'year';
        break;

      case '2592000':
        $period = 'Month';
        break;

      case '86400':
        $period = 'Day';
        break;

      default:
        $period = 'Day';
        break;
    }
    return $period;
  }

  /**
   * {@inheritdoc}
   */
  public function getApiIdsFromName($apis) {
    $nids = [];
    $apis = explode(', ', $apis);
    foreach ($apis as $api) {
      $node = $this->entityTypeManager->getStorage('node')
        ->loadByProperties([
          'type' => 'apis',
          'field_sync_api_id' => $api,
        ]);
      $nids[] = key($node);
    }
    return $nids;
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
