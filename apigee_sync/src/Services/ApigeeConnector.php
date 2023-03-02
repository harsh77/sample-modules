<?php

namespace Drupal\apigee_sync\Services;

use Apigee\Edge\Client;
use Apigee\Edge\Api\Management\Controller\ApiProductController;
use Apigee\Edge\Api\Monetization\Controller\ApiProductController as MonitApiProductController;
use Apigee\Edge\Api\Monetization\Controller\ApiPackageController;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Error;
use Drupal\Core\Url;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use GuzzleHttp\ClientInterface;
use Http\Message\Authentication\BasicAuth;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Provides an SDK to connect Apigee gateway.
 */
class ApigeeConnector {

  use StringTranslationTrait;

  /**
   * The config.
   *
   * @var Variable
   */
  protected $config;

  /**
   * The apigee gateway variable.
   *
   * @var Variable
   */
  protected $apigeeDetails;

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
   * Guzzle\Client instance.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructs a new SDKConnector.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $loggerFactory
   *   The Logger Factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The Messenger.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager service.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The factory for account objects.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The Client instance.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache service.
   */
  public function __construct(ConfigFactoryInterface $config_factory,
                              LoggerChannelFactory $loggerFactory,
                              MessengerInterface $messenger,
                              EntityTypeManagerInterface $entity_type_manager,
                              AccountInterface $account,
                              ClientInterface $httpClient,
                              CacheBackendInterface $cache) {
    $this->configFactory = $config_factory;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $account;
    $this->httpClient = $httpClient;
    $this->cache = $cache;

    $this->config = $this->configFactory->get('apigee_sync.auth')->get('apigee_details');
    if (empty($this->config)) {
      $this->messenger->addError($this->t('Minimum 1 connection needs to be configured for API Sync.'));
      $response = new RedirectResponse(Url::fromRoute('apigee_sync.settings')->toString());
      return $response->send();
    }
    else {
      $this->apigeeDetails = JSON::decode($this->config);
    }
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
        $developer_id = $developer['developerId'] ?? '';
        return $developer_id;
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
        $developer_id = $developer['developerId'] ?? '';
        return $developer_id;
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
   * {@inheritdoc}
   */
  public function getAllApis() {
    try {
      $cid = 'apigee_sync:all_apis';
      if ($cache = $this->cache->get($cid)) {
        return $cache->data;
      }
      $apis_list = $aggregated_api_list = $list = [];
      if (!empty($this->apigeeDetails)) {
        foreach ($this->apigeeDetails as $key => $apigee_detail) {
          $endpoint = $apigee_detail['endpoint'][$key];
          $username = $apigee_detail['username'][$key];
          $password = $apigee_detail['password'][$key];

          $client = new Client(new BasicAuth($username, $password), $endpoint);
          $monitized = $this->isMonit($endpoint, $username, $password, $apigee_detail['org_name'][$key]);
          if ($monitized == TRUE) {
            $api_products = new MonitApiProductController($apigee_detail['org_name'][$key], $client);
          }
          else {
            $api_products = new ApiProductController($apigee_detail['org_name'][$key], $client);
          }

          if (!empty($api_products->getEntities())) {
            foreach ($api_products->getEntities() as $product) {
              $list[$product->getName()] = [
                'api_id' => str_replace(' ', '_', strtolower($product->getName())),
                'api_name' => $product->getDisplayName(),
                'source' => $apigee_detail['instance_name'][$key],
                'api_source' => $apigee_detail['id'][$key],
              ];
            }
          }
          $apis_list[] = $list;
        }
      }

      if (!empty($apis_list)) {
        foreach ($apis_list as $j => $apis) {
          foreach ($apis as $id => $api) {
            $id = str_replace(' ', '_', strtolower($id));
            $id = str_replace('-', '_', $id);
            $aggregated_api_list[$id . '_' . $j] = $api;
          }
        }
      }
      $this->cache->set($cid, $aggregated_api_list, Cache::PERMANENT);
      return $aggregated_api_list;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError($this->t('Failed to fetch Apigee apis: @message', ['@message' => $e->getMessage()]));
      $this->loggerFactory->get('apigee_sync')->error('Failed to fetch Apigee apis - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * Fetch a list of all api products of a non-monit orgasation.
   */
  public function getAllApiProducts() {
    try {
      $api_product_list = [];
      if (!empty($this->apigeeDetails)) {
        foreach ($this->apigeeDetails as $key => $apigee_detail) {
          $endpoint = $apigee_detail['endpoint'][$key];
          $username = $apigee_detail['username'][$key];
          $password = $apigee_detail['password'][$key];

          $client = new Client(new BasicAuth($username, $password), $endpoint);
          $api_products = new ApiProductController($apigee_detail['org_name'][$key], $client);

          if (!empty($api_products->getEntities())) {
            foreach ($api_products->getEntities() as $product) {
              $api_product_list[str_replace(' ', '_', strtolower($product->getName()))] = [
                'id' => str_replace(' ', '_', strtolower($product->getName())),
                'name' => $product->getName(),
                'quota' => $this->getQuota($product->getQuota(), $product->getQuotaTimeUnit()),
                'limit' => $product->getQuota() ?? '',
                'period' => $product->getQuotaTimeUnit() ?? '',
                'source' => $apigee_detail['instance_name'][$key],
                'api_source' => $apigee_detail['id'][$key],
                'apis' => str_replace(' ', '_', strtolower($product->getName())),
                'rate_plans' => '',
              ];
            }
          }
        }
      }
      return $api_product_list;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError($this->t('Failed to fetch Apigee api products: @message', [
        '@message' => $e->getMessage(),
      ]));
      $this->loggerFactory->get('apigee_sync')->error('Failed to fetch Apigee api products - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getQuota($quota = NULL, $period = NULL) {
    $quotaValue = '';
    if (!empty($quota)) {
      $quotaValue = $this->t(':limit calls per @period', [
        ':limit' => $quota,
        '@period' => $period ? strtoupper($period) : '',
      ]);
    }
    return $quotaValue;
  }

  /**
   * Fetch api ids from apigee api.
   */
  public function getApiIdsFromName($apis) {
    $nids = [];
    $apis = explode(', ', $apis);
    foreach ($apis as $api) {
      $node = $this->entityTypeManager->getStorage('node')
        ->loadByProperties([
          'type' => 'apis',
          'field_sync_api_id' => str_replace(' ', '_', strtolower($api)),
        ]);
      $nids[] = key($node);
    }
    return $nids;
  }

  /**
   * {@inheritdoc}
   */
  public function getPeriod($api_period) {
    switch ($api_period) {
      case 'year':
        $period = 946080000;
        break;

      case 'month':
        $period = 2592000;
        break;

      case 'day':
        $period = 86400;
        break;

      default:
        $period = 86400;
        break;
    }
    return $period;
  }

  /**
   * Fetch rate plans linked with an api package.
   */
  public function getProductBundleRatePlans($endpoint, $username, $password, $organizationName, $packageId) {
    try {
      $rateplans = $response = $period = NULL;
      $limit = [];
      $rateplanlist = $rateplannames = [];
      $url = $endpoint . '/mint/organizations/' . $organizationName . '/monetization-packages/' . $packageId . '/rate-plans';
      $response = $this->httpClient->request('GET', $url, [
        'auth' => [$username, $password],
      ]);

      if ($response->getStatusCode() == '200') {
        $rateplans = JSON::decode($response->getBody());
        if (!empty($rateplans)) {
          foreach ($rateplans['ratePlan'] as $j => $rateplan) {
            $rateplannames[$j] = $rateplan['name'];
            $period = $rateplan['ratePlanDetails'][0]['durationType'] ?? NULL;
            $limit[] = $rateplan['ratePlanDetails'][0]['ratePlanRates'][0]['endUnit'] ?? 0;
          }
        }
        $rateplanlist['names'] = implode(', ', $rateplannames);
        $rateplanlist['period'] = $period;
        $rateplanlist['limit'] = array_sum($limit);
        return $rateplanlist;
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
   * Fetch all api packages for a monit organisation.
   */
  public function getProductBundles() {
    try {
      $cid = 'apigee_sync:apis_product';
      if ($cache = $this->cache->get($cid)) {
        return $cache->data;
      }
      $developer_id = NULL;
      $api_product_list = [];
      $user = $this->entityTypeManager->getStorage('user')->load($this->account->id());
      $username = $email = $user->get('mail')->value;
      if (!empty($this->apigeeDetails)) {
        foreach ($this->apigeeDetails as $key => $apigee_detail) {
          $endpoint = $apigee_detail['endpoint'][$key];
          $client_username = $apigee_detail['username'][$key];
          $client_password = $apigee_detail['password'][$key];

          $client = new Client(new BasicAuth($client_username, $client_password), $endpoint);
          $monitized = $this->isMonit($endpoint, $client_username, $client_password, $apigee_detail['org_name'][$key]);
          if ($monitized == FALSE) {
            continue;
          }

          $api_packages = new ApiPackageController($apigee_detail['org_name'][$key], $client);

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
          if (!empty($developer_id)) {
            foreach ($api_packages->getAvailableApiPackagesByDeveloper($developer_id) as $package) {
              $rateplanlist = $this->getProductBundleRatePlans($endpoint, $client_username, $client_password, $apigee_detail['org_name'][$key], $package->getId());
              $api_product_list[$package->getId()] = [
                'id' => $package->getId(),
                'name' => $package->getName(),
                'source' => $apigee_detail['instance_name'][$key],
                'api_source' => $apigee_detail['id'][$key],
                'quota' => $this->getQuota($rateplanlist['limit'], $rateplanlist['period']),
                'rate_plans' => $rateplanlist['names'],
                'limit' => $rateplanlist['limit'],
                'period' => $rateplanlist['period'],
                'apis' => $this->getApiId($package->getApiProducts()),
                'gateway' => 'apigee',
              ];
            }
          }
        }
      }
      $this->cache->set($cid, $api_product_list, Cache::PERMANENT);
      return $api_product_list;
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError($this->t('Failed to fetch Monetized Apigee api products: @message', [
        '@message' => $e->getMessage(),
      ]));
      $this->loggerFactory->get('apigee_sync')->error('Failed to fetch Monetized Apigee api products - ' . $context['@message']);
      return FALSE;
    }
  }

  /**
   * Fetch api product names using an api product.
   */
  public function getApiProductNames(array $apiProducts) {
    $api_ids = NULL;
    $products = [];

    foreach ($apiProducts as $p => $apiProduct) {
      $products[$p] = $apiProduct->getName();
    }
    $api_ids = implode(', ', $products);
    return $api_ids;
  }

  /**
   * Fetch api id's using an api product.
   */
  public function getApiId(array $apiProducts) {
    $api_id = NULL;
    $number = 0;
    if (is_countable($apiProducts) & count($apiProducts) > 0) {
      $count = count($apiProducts);
    }

    foreach ($apiProducts as $apiProduct) {
      $api_id .= $apiProduct->getName();
      $number = $number + 1;
      if ($number < $count) {
        $api_id = $api_id . ', ';
      }
    }
    $api_id = trim($api_id);
    return $api_id;
  }

  /**
   * Function to determine whether an organisation is monitized/not.
   */
  public function isMonit($endpoint, $username, $password, $organizationName) {
    try {
      $response = NULL;
      $monitized = FALSE;
      $url = $endpoint . '/organizations/' . urlencode($organizationName);
      $response = $this->httpClient->request('GET', $url, [
        'auth' => [$username, $password],
        'http_errors' => FALSE,
      ]);

      if ($response->getStatusCode() == '200') {
        $org_details = JSON::decode($response->getBody());
        if (!empty($org_details['properties']['property'])) {
          foreach ($org_details['properties']['property'] as $property) {
            if ($property['name'] == 'features.isMonetizationEnabled' && $property['value'] == TRUE) {
              $monitized = TRUE;
            }
          }
        }
        return $monitized;
      }
    }
    catch (\Exception $e) {
      $context = Error::decodeException($e);
      $this->messenger->addError($this->t('Unable to fetch details of @name organisation details: @error', [
        '@name' => $organizationName,
        '@error' => $e->getMessage(),
      ]));
      $this->loggerFactory->get('apigee_sync')->error('Unable to fetch details of ' . $organizationName . ' organisation details - ' . $context['@message']);
      return FALSE;
    }
  }

}
