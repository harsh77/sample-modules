<?php

namespace Drupal\apigee_sync\Services;

/**
 * Syncing the APIs from APIGEE and create node.
 */
class SyncApigeeProducts {

  /**
   * {@inheritdoc}
   */
  public static function createEntity($products, &$context) {
    $connector = \Drupal::service('apigee_sync.apigee_connector');
    $period = $threshold = '';
    $results = [];
    $results['update'] = $results['create'] = 0;
    $message = t('Syncing API Products...');

    foreach ($products as $product_id => $product) {
      $entries = \Drupal::entityTypeManager()->getStorage('api_products')->loadByProperties([
        'field_sync_product_id' => $product_id,
        'field_api_source' => $product['api_source'],
        'field_sync_gateway' => $product['gateway'],
      ]);

      if (!empty($product['limit'])) {
        $threshold = (80 / 100) * $product['limit'];
      }
      if (!empty($product['period'])) {
        $period = $connector->getPeriod($product['period']);
      }
      if (!empty($entries)) {
        foreach ($entries as $entry) {
          $entry->setNewRevision();
          $entry->set('title', $product['name']);
          $entry->set('field_api_source', $product['api_source']);
          $entry->set('field_rate_plan_name', $product['rate_plans']);
          $entry->set('field_number_of_calls', $product['limit']);
          $entry->set('field_renewal_period', $period);
          $entry->set('field_threshold', $threshold);
          if (!empty($product['apis'])) {
            $entry->set('field_apis', $connector->getApiIdsFromName($product['apis']));
          }
          $entry->set('changed', \Drupal::time()->getRequestTime());
          $entry->save();

          $results['update'] = $results['update'] + 1;
        }
      }
      else {
        $productEntity = \Drupal::entityTypeManager()->getStorage('api_products')->create(
          [
            'bundle' => 'api_products',
            'title' => $product['name'],
            'field_sync_product_id' => $product_id,
            'field_sync_gateway' => 'apigee',
            'field_api_source' => $product['api_source'],
            'field_apis' => $connector->getApiIdsFromName($product['apis']),
            'field_rate_plan_name' => $product['rate_plans'],
            'field_number_of_calls' => $product['limit'],
            'field_renewal_period' => $period,
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
