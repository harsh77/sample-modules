<?php

namespace Drupal\apigee_sync\Services;

use Drupal\node\Entity\Node;

/**
 * Syncing the APIs from Apigee and create node.
 */
class SyncApigeeApi {

  /**
   * {@inheritdoc}
   */
  public static function createnode($apis, &$context) {
    $results = [];
    $results['update'] = $results['create'] = 0;
    $message = t('Syncing APIs...');

    foreach ($apis as $api) {
      $values = \Drupal::entityQuery('node')->condition('field_sync_api_id', $api['api_id'])->condition('field_api_source_id', $api['api_source'])->execute();
      if (!empty($values)) {
        $nid = current($values);
        $node = Node::load($nid);
        $node->set('title', $api['api_name']);
        $node->set('field_api_source_id', $api['api_source']);
        $node->set('changed', \Drupal::time()->getRequestTime());
        $node->save();
        $results['update'] = $results['update'] + 1;
      }
      else {
        Node::create([
          'type' => 'apis',
          'title' => $api['api_name'],
          'field_sync_api_id' => $api['api_id'],
          'field_sync_gateway' => 'apigee',
          'field_api_source_id' => $api['api_source'],
          'uid' => \Drupal::currentUser()->id(),
          'status' => 1,
        ])->save();
        $results['create'] = $results['create'] + 1;
      }
    }
    $context['message'] = $message;
    $context['results'] = $results;
  }

  /**
   * {@inheritdoc}
   */
  public static function finishcreatingnode($success, $results, $message) {
    if ($success) {
      if ($results['create'] > 0) {
        \Drupal::messenger()->addStatus(t('@create API(s) are created.', ['@create' => $results['create']]));
      }
      if ($results['update'] > 0) {
        \Drupal::messenger()->addStatus(t('@update API(s) are updated.', ['@update' => $results['update']]));
      }
    }
    else {
      \Drupal::messenger()->addError(t('Finished with an error.'));
    }
  }

}
