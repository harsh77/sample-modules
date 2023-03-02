<?php

namespace Drupal\cardium_multigateway\EventSubscriber;

use Drupal\hook_event_dispatcher\HookEventDispatcherInterface;
use Drupal\core_event_dispatcher\Event\Entity\EntityPredeleteEvent;
use Drupal\core_event_dispatcher\Event\Entity\EntityDeleteEvent;
use Drupal\core_event_dispatcher\Event\Entity\EntityPresaveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\cardium_multigateway\Services\AzureConnector;
use Drupal\cardium_multigateway\Services\ApigeeConnector;
use Drupal\cardium_multigateway\Services\AwsConnector;
use Drupal\cardium_multigateway\Services\KongConnector;
use Drupal\cardium_multigateway\Services\MulesoftConnector;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Serialization\Json;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Class EntityTypeSubscriber.
 *
 * @package Drupal\cardium_multigateway\EventSubscriber
 */
class CreateAppEventsSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

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
   * The tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager,
                              AccountInterface $account,
                              PrivateTempStoreFactory $temp_store_factory,
                              AzureConnector $azureConnector,
                              ApigeeConnector $apigeeConnector,
                              AwsConnector $awsConnector,
                              KongConnector $kongConnector,
                              MulesoftConnector $mulesoftConnector
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $account;
    $this->tempStoreFactory = $temp_store_factory;
    $this->azureConnector = $azureConnector;
    $this->apigeeConnector = $apigeeConnector;
    $this->awsConnector = $awsConnector;
    $this->kongConnector = $kongConnector;
    $this->mulesoftConnector = $mulesoftConnector;

    $this->user_id = $this->account->id();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      HookEventDispatcherInterface::ENTITY_PRE_SAVE => 'entityPreSave',
      HookEventDispatcherInterface::ENTITY_PRE_DELETE => 'entityPreDelete',
      HookEventDispatcherInterface::ENTITY_DELETE => 'entityDelete',
    ];
  }

  /**
   * Entity preSave.
   *
   * @param \Drupal\core_event_dispatcher\Event\Entity\EntityPresaveEvent $event
   *   The event.
   */
  public function entityPreSave(EntityPresaveEvent $event): void {
    $api_keys = [];
    $entity = $event->getEntity();
    if (!$entity || (!$entity instanceof NodeInterface) || $entity->getType() != 'apps') {
      return;
    }

    if ($entity->hasField('field_app_apis')) {
      $aws_apis = $apigee_apis = $azure_apis = $kong_apis = $mulesoft_apis = [];

      $selected_apis = $entity->get('field_app_apis')->referencedEntities();
      if (!empty($selected_apis)) {
        foreach ($selected_apis as $selected_api) {
          $gateway = $selected_api->field_sync_gateway->value;
          switch ($gateway) {
            case 'aws':
              $aws_apis[] = $selected_api->field_sync_product_id->value;
              break;

            case 'azure':
              $azure_apis[] = $selected_api->field_sync_product_id->value;
              break;

            case 'apigee':
              $apigee_apis[] = $selected_api->field_sync_product_id->value;
              break;

            case 'kong':
              $kong_apis[] = $selected_api->field_sync_product_id->value;
              break;

            case 'mulesoft':
              $mulesoft_apis[] = $selected_api->field_sync_product_id->value;
              break;
          }
        }

        // Creating APPs in the respective gateways.
        if ($entity->isNew()) {
          if (!empty($aws_apis)) {
            $api_keys['aws'][] = $this->awsConnector->createApp($this->user_id, $aws_apis);
          }
          if (!empty($azure_apis)) {
            $api_keys['azure'][] = $this->azureConnector->createApp($this->user_id, $azure_apis);
          }
          if (!empty($kong_apis)) {
            $api_keys['kong'][] = $this->kongConnector->createApp($this->user_id, $kong_apis);
          }
          if (!empty($mulesoft_apis)) {
            $api_keys['mulesoft'][] = $this->mulesoftConnector->createApp($this->user_id, $mulesoft_apis);
          }
          if (!empty($apigee_apis)) {
            $api_keys['apigee'][] = $this->apigeeConnector->createApp($this->user_id, $apigee_apis);
          }
        }
        else {
          $nid = $entity->id();
          if (!empty($aws_apis)) {
            $api_keys['aws'][] = $this->awsConnector->updateApp($this->user_id, $aws_apis, $nid);
          }
          if (!empty($azure_apis)) {
            $api_keys['azure'][] = $this->azureConnector->updateApp($this->user_id, $azure_apis, $nid);
          }
          if (!empty($kong_apis)) {
            $api_keys['kong'][] = $this->kongConnector->updateApp($this->user_id, $kong_apis, $nid);
          }
          if (!empty($mulesoft_apis)) {
            $api_keys['mulesoft'][] = $this->mulesoftConnector->updateApp($this->user_id, $mulesoft_apis, $nid);
          }
          if (!empty($apigee_apis)) {
            $api_keys['apigee'][] = $this->apigeeConnector->updateApp($this->user_id, $apigee_apis, $nid);
          }
        }
        $entity->set('field_app_api_keys', JSON::encode($api_keys));

        // Delete the subscription list from tempstore.
        $tempstore = $this->tempStoreFactory->get('subscription_list');
        if (!empty($tempstore->get('product_id'))) {
          $tempstore->delete('product_id');
        }
      }
    }
  }

  /**
   * App preDelete.
   *
   * @param \Drupal\core_event_dispatcher\Event\Entity\EntityPredeleteEvent $event
   *   The event.
   */
  public function entityPreDelete(EntityPredeleteEvent $event): void {
    $api_keys = [];
    $entity = $event->getEntity();
    if (!$entity || (!$entity instanceof NodeInterface) || $entity->getType() != 'apps') {
      return;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($entity->id());
    $api_keys = $node->field_app_api_keys->value;
    $api_keys = JSON::decode($api_keys);

    if (!empty($api_keys)) {
      // Delete APIs from AWS.
      if (array_key_exists("aws", $api_keys)) {
        $aws_api_keys = $api_keys['aws'];
        foreach ($aws_api_keys as $aws_api_key) {
          $existing_aws_apis = array_keys($aws_api_key);
        }

        foreach ($existing_aws_apis as $deleted_aws_api) {
          $delete_aws_subscriptions[$deleted_aws_api] = $aws_api_keys[0][$deleted_aws_api]['subscription_id'];
        }
        $this->awsConnector->deleteApp($this->user_id, $delete_aws_subscriptions);
      }

      // Delete APIs from Azure.
      if (array_key_exists("azure", $api_keys)) {
        $azure_api_keys = $api_keys['azure'];
        foreach ($azure_api_keys as $azure_api_key) {
          $existing_azure_apis = array_keys($azure_api_key);
        }

        foreach ($existing_azure_apis as $deleted_azure_api) {
          $delete_azure_subscriptions[$deleted_azure_api] = $azure_api_keys[0][$deleted_azure_api]['subscription_id'];
        }
        $this->azureConnector->deleteApp($this->user_id, $delete_azure_subscriptions);
      }

      // Delete APIs from Apigee.
      if (array_key_exists("apigee", $api_keys)) {
        $apigee_api_keys = $api_keys['apigee'];
        foreach ($apigee_api_keys as $apigee_api_key) {
          $existing_apis = array_keys($apigee_api_key);
        }

        foreach ($existing_apis as $deleted_api) {
          $delete_subscriptions[$deleted_api] = $apigee_api_keys[0][$deleted_api];
          $delete_app_name = $apigee_api_keys[0][$deleted_api]['name'];
          $this->apigeeConnector->deleteApp($this->user_id, $delete_subscriptions, $delete_app_name);
        }
      }

      // Delete APIs from Kong.
      if (array_key_exists("kong", $api_keys)) {
        $kong_api_keys = $api_keys['kong'];
        foreach ($kong_api_keys as $kong_api_key) {
          $existing_kong_apis = array_keys($kong_api_key);
        }
        foreach ($existing_kong_apis as $deleted_kong_api) {
          $delete_kong_subscriptions[$deleted_kong_api] = $kong_api_key[$deleted_kong_api];
        }
        $this->kongConnector->deleteApp($this->user_id, $delete_kong_subscriptions);
      }

      // Delete APIs from Mulesoft.
      if (array_key_exists("mulesoft", $api_keys)) {
        $api_keys = $api_keys['mulesoft'];
        foreach ($api_keys as $api_key) {
          $existing_apis = array_keys($api_key);
        }

        foreach ($existing_apis as $deleted_api) {
          $delete_subscriptions[$deleted_api] = $api_keys[0][$deleted_api];
        }
        $this->mulesoftConnector->deleteApp($this->user_id, $delete_subscriptions);
      }
    }
  }

  /**
   * Entity delete.
   *
   * @param \Drupal\core_event_dispatcher\Event\Entity\EntityDeleteEvent $event
   *   The event.
   */
  public function entityDelete(EntityDeleteEvent $event): void {
    $entity = $event->getEntity();
    if (!$entity || (!$entity instanceof NodeInterface) || $entity->getType() != 'apps') {
      return;
    }
    $url = Url::fromRoute('view.app_list.app_list');
    $response = new RedirectResponse($url->toString());
    $response->send();
  }

}
