<?php

namespace Drupal\azure_sync\Services;

/**
 * Syncing the APIs from Azure and create node.
 */
class SyncAzureProducts {

  /**
   * {@inheritdoc}
   */
  public static function createEntity($products, &$context) {
    $connector = \Drupal::service('azure_sync.azure_connector');
    $threshold = '';
    $results = [];
    $results['update'] = $results['create'] = 0;
    $message = t('Syncing API Products...');

    foreach ($products as $product_id => $product) {
      $entries = \Drupal::entityTypeManager()->getStorage('api_products')
        ->loadByProperties([
          'field_sync_product_id' => $product_id,
        ]);

      if (!empty($product['limit'])) {
        $threshold = (80 / 100) * $product['limit'];
      }
      if (!empty($entries)) {
        foreach ($entries as $entry) {
          $entry->setNewRevision();
          $entry->set('title', $product['name']);
          $entry->set('field_api_source', $product['api_source']);
          $entry->set('field_rate_plan_name', $product['name'] . ' - ' . $product['limit']);
          $entry->set('field_number_of_calls', $product['limit']);
          $entry->set('field_renewal_period', $product['period']);
          $entry->set('field_threshold', $threshold);
          if (!empty($product['apis'])) {
            $entry->set('field_apis', $connector->getApiIdsFromName($product['apis']));
          }
          $entry->save();

          $results['update'] = $results['update'] + 1;
        }
      }
      else {
        $productEntity = \Drupal::entityTypeManager()->getStorage('api_products')->create(
          [
            'bundle' => 'api_products',
            'title' => $product['name'],
            'field_sync_product_id' => $product['id'],
            'field_sync_gateway' => 'azure',
            'field_api_source' => $product['api_source'],
            'field_apis' => $connector->getApiIdsFromName($product['apis']),
            'field_rate_plan_name' => $product['name'] . ' - ' . $product['limit'],
            'field_number_of_calls' => $product['limit'],
            'field_renewal_period' => $product['period'],
            'field_threshold' => $threshold,
            'status' => 1,
          ]);
        $productEntity->save();
        $results['create'] = $results['create'] + 1;
      }
    }
    $context['message'] = $message;
    $context['results'] = $results;
  }

  /**
   * {@inheritdoc}
   */
  public static function finishCreatingEntity($success, $results, $message) {
    if ($success) {
      if ($results['create'] > 0) {
        \Drupal::messenger()->addStatus(t('@create product(s) are created.', ['@create' => $results['create']]));
      }
      if ($results['update'] > 0) {
        \Drupal::messenger()->addStatus(t('@update product(s) are updated.', ['@update' => $results['update']]));
      }
    }
    else {
      \Drupal::messenger()->addError(t('Finished with an error.'));
    }
  }

}
