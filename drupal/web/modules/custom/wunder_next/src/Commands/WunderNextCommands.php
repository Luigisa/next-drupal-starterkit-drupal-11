<?php

namespace Drupal\wunder_next\Commands;

use Consolidation\AnnotatedCommand\CommandError;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\user\Entity\User;
use Drush\Commands\DrushCommands;

/**
 * Drush command file for the wunder_next module.
 */
class WunderNextCommands extends DrushCommands {

  const API_USER_NAME = 'next-api-user';
  const API_USER_ROLE = 'next_api_role';
  const API_VIEWER_USER_NAME = 'next-viewer-user';
  const API_VIEWER_USER_ROLE = 'next_api_viewer_role';

  const API_USER_MAIL = 'next-api-user@domain.tld';
  const API_VIEWER_USER_MAIL = 'next-api-viewer-user@domain.tld';

  // This role will be associated with users that can log via the frontend:
  const FRONTEND_LOGIN_ROLE = 'frontend_login';

  /**
   * Generates users and consumers needed for Next.js to speak to Drupal.
   *
   * @command wunder_next:setup-users-and-consumers
   * @aliases wnsuac
   */
  public function setupUsersAndConsumers() {

    // We want to have two separate consumers:
    $consumers_to_create = [
      // This first consumer is the usual consumer that next-drupal expects,
      // and it is associated with a role that has elevated permissions to
      // access unpublished content. This is used when generating previews for
      // example.
      [
        'username' => self::API_USER_NAME,
        'mail' => self::API_USER_MAIL,
        'role' => self::API_USER_ROLE,
        'secret' => $_ENV['DRUPAL_CLIENT_SECRET'],
        'client_id' => $_ENV['DRUPAL_CLIENT_ID'],
      ],
      // This second consumer is associated with a role with fewer permissions,
      // and it is used when the frontend user does not need access to
      // unpublished content.
      [
        'username' => self::API_VIEWER_USER_NAME,
        'mail' => self::API_VIEWER_USER_MAIL,
        'role' => self::API_VIEWER_USER_ROLE,
        'secret' => $_ENV['DRUPAL_CLIENT_VIEWER_SECRET'],
        'additional_roles' => [self::FRONTEND_LOGIN_ROLE],
        'client_id' => $_ENV['DRUPAL_CLIENT_VIEWER_ID'],
      ],
    ];

    foreach ($consumers_to_create as $spec) {
      $scope_role_ids = isset($spec['additional_roles'])
        ? array_merge([$spec['role']], $spec['additional_roles'])
        : [$spec['role']];
      foreach ($scope_role_ids as $role_id) {
        $this->ensureOauth2ScopeForRole($role_id);
      }

      // Create a new user with the required role to be associated with
      // the consumer:
      $new_user = [
        'name' => $spec['username'],
        'pass' => '',
        'mail' => $spec['mail'],
        'access' => '0',
        'status' => 1,
        'roles' => $scope_role_ids,
      ];

      // Create the new account:
      $account = User::create($new_user);
      try {
        $violations = $account->validate();
        // Check if the account can be created:
        if ($violations->count() > 0) {
          foreach ($violations as $violation) {
            $this->logger()->error($violation->getMessage());
            return new CommandError("Could not create a new user account with the name " . $spec['username'] . ".");
          }
        }
        $account->save();
      }
      catch (EntityStorageException $e) {
        return new CommandError("Could not create a new user account with the name " . $spec['username'] . ".");
      }

      /** @var \Drupal\consumers\Entity\Consumer $consumer */
      $consumer = Consumer::create([
        'client_id' => $spec['client_id'],
        'label' => 'Next-drupal consumer: ' . $spec['role'],
        'description' => 'This consumer was created by the wunder_next:create-user-and-consumer drush command.',
        'is_default' => FALSE,
        'user_id' => $account->id(),
        'grant_types' => ['authorization_code', 'client_credentials', 'refresh_token'],
        'scopes' => $scope_role_ids,
        'secret' => $spec['secret'],
      ]);

      try {
        $violations = $consumer->validate();
        // Check if the consumer can be created:
        if ($violations->count() > 0) {
          foreach ($violations as $violation) {
            $this->logger()->error($violation->getMessage());
            return new CommandError('Could not create a new consumer.');
          }
        }

        $consumer->save();
      }
      catch (EntityStorageException $e) {
        return new CommandError('Could not create a new consumer.');
      }

    }
    // Output instructions to the user:
    $this->logger()->success(dt('Consumers created successfully.'));
  }

  /**
   * Ensures a dynamic OAuth2 scope exists with role granularity (simple_oauth 6).
   *
   * Scopes whose id matches a user role must use the "role" granularity plugin;
   * otherwise client_credentials tokens fail when resolving permissions.
   */
  private function ensureOauth2ScopeForRole(string $role_id): void {
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_scope');
    /** @var \Drupal\simple_oauth\Entity\Oauth2Scope|null $scope */
    $scope = $storage->load($role_id);
    $grant_types = [
      'authorization_code' => ['status' => TRUE],
      'client_credentials' => ['status' => TRUE],
      'refresh_token' => ['status' => TRUE],
    ];
    if (!$scope) {
      $scope = Oauth2Scope::create([
        'name' => $role_id,
        'description' => 'OAuth2 scope tied to Drupal role: ' . $role_id,
        'grant_types' => $grant_types,
        'umbrella' => FALSE,
        'granularity_id' => Oauth2ScopeInterface::GRANULARITY_ROLE,
        'granularity_configuration' => [
          'role' => $role_id,
        ],
      ]);
    }
    else {
      $scope->set('granularity_id', Oauth2ScopeInterface::GRANULARITY_ROLE);
      $scope->set('granularity_configuration', ['role' => $role_id]);
    }
    $scope->save();
  }

}
