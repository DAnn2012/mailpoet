<?php // phpcs:ignore SlevomatCodingStandard.TypeHints.DeclareStrictTypes.DeclareStrictTypesMissing

namespace MailPoet\Segments;

use MailPoet\Config\SubscriberChangesNotifier;
use MailPoet\DI\ContainerWrapper;
use MailPoet\Doctrine\WPDB\Connection;
use MailPoet\Entities\SegmentEntity;
use MailPoet\Entities\SubscriberEntity;
use MailPoet\Entities\SubscriberSegmentEntity;
use MailPoet\Newsletter\Scheduler\WelcomeScheduler;
use MailPoet\Services\Validator;
use MailPoet\Settings\SettingsController;
use MailPoet\Subscribers\ConfirmationEmailMailer;
use MailPoet\Subscribers\Source;
use MailPoet\Subscribers\SubscriberSegmentRepository;
use MailPoet\Subscribers\SubscribersRepository;
use MailPoet\WooCommerce\Helper as WooCommerceHelper;
use MailPoet\WP\Functions as WPFunctions;
use MailPoetVendor\Carbon\Carbon;
use MailPoetVendor\Doctrine\ORM\EntityManager;

class WP {

  /** @var WPFunctions */
  private $wp;

  /** @var WelcomeScheduler */
  private $welcomeScheduler;

  /** @var WooCommerceHelper */
  private $wooHelper;

  /** @var SubscribersRepository */
  private $subscribersRepository;

  /** @var SubscriberChangesNotifier */
  private $subscriberChangesNotifier;

  private $subscriberSegmentRepository;

  /** @var Validator */
  private $validator;

  /** @var SegmentsRepository */
  private $segmentsRepository;

  /** @var EntityManager */
  private $entityManager;

  /** @var string */
  private $subscribersTable;

  /** @var \MailPoetVendor\Doctrine\DBAL\Connection */
  private $databaseConnection;

  public function __construct(
    WPFunctions $wp,
    WelcomeScheduler $welcomeScheduler,
    WooCommerceHelper $wooHelper,
    SubscribersRepository $subscribersRepository,
    SubscriberSegmentRepository $subscriberSegmentRepository,
    SubscriberChangesNotifier $subscriberChangesNotifier,
    Validator $validator,
    SegmentsRepository $segmentsRepository,
    EntityManager $entityManager
  ) {
    $this->wp = $wp;
    $this->welcomeScheduler = $welcomeScheduler;
    $this->wooHelper = $wooHelper;
    $this->subscribersRepository = $subscribersRepository;
    $this->subscriberSegmentRepository = $subscriberSegmentRepository;
    $this->subscriberChangesNotifier = $subscriberChangesNotifier;
    $this->validator = $validator;
    $this->segmentsRepository = $segmentsRepository;
    $this->entityManager = $entityManager;
    $this->databaseConnection = $this->entityManager->getConnection();
    $this->subscribersTable = $this->entityManager->getClassMetadata(SubscriberEntity::class)->getTableName();
    
    // Add hook for manual user synchronization triggered by AdminUserSubscription
    $this->wp->addAction('mailpoet_user_sync', [$this, 'onUserSync']);
  }

  /**
   * Handle manual synchronization of a user triggered by AdminUserSubscription
   * 
   * @param int $userId The WordPress user ID to synchronize
   */
  public function onUserSync($userId) {
    error_log('MailPoet Debug: onUserSync called for user ID: ' . $userId);
    
    // Force the user to be marked as admin-created to trigger proper confirmation email
    update_user_meta($userId, 'mailpoet_admin_created', '1');
    
    // Synchronize the user
    $this->synchronizeUser($userId);
    
    // Clean up after ourselves
    delete_user_meta($userId, 'mailpoet_admin_created');
  }

  /**
   * @param int $wpUserId
   * @param array|false $oldWpUserData
   */
  public function synchronizeUser(int $wpUserId, $oldWpUserData = false): void {
    $wpUser = \get_userdata($wpUserId);
    if ($wpUser === false) return;

    $subscriber = $this->subscribersRepository->findOneBy(['wpUserId' => $wpUserId]);

    $currentFilter = $this->wp->currentFilter();
    // Delete
    if (in_array($currentFilter, ['delete_user', 'deleted_user', 'remove_user_from_blog'])) {
      if ($subscriber instanceof SubscriberEntity) {
        $this->deleteSubscriber($subscriber);
      }
      return;
    }
    $this->handleCreatingOrUpdatingSubscriber($currentFilter, $wpUser, $subscriber, $oldWpUserData);
  }

  private function deleteSubscriber(SubscriberEntity $subscriber): void {
    $this->subscribersRepository->remove($subscriber);
    $this->subscribersRepository->flush();
  }

  /**
   * @param string $currentFilter
   * @param \WP_User $wpUser
   * @param ?SubscriberEntity $subscriber
   * @param array|false $oldWpUserData
   */
  private function handleCreatingOrUpdatingSubscriber(string $currentFilter, \WP_User $wpUser, ?SubscriberEntity $subscriber = null, $oldWpUserData = false): void {
    // Debug logging
    error_log('MailPoet Debug: handleCreatingOrUpdatingSubscriber called for user ' . $wpUser->ID . ' with filter: ' . $currentFilter);
    
    // Add or update
    $wpSegment = $this->segmentsRepository->getWPUsersSegment();

    // find subscriber by email when is null
    if (is_null($subscriber)) {
      $subscriber = $this->subscribersRepository->findOneBy(['email' => $wpUser->user_email]); // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
    }

    // get first name & last name
    $firstName = html_entity_decode($wpUser->first_name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401); // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
    $lastName = html_entity_decode($wpUser->last_name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401); // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
    if (empty($wpUser->first_name) && empty($wpUser->last_name)) { // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
      $firstName = html_entity_decode($wpUser->display_name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401); // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
    }
    
    $signupConfirmationEnabled = SettingsController::getInstance()->get('signup_confirmation.enabled');
    error_log('MailPoet Debug: signupConfirmationEnabled: ' . ($signupConfirmationEnabled ? 'true' : 'false'));
    
    // Check for admin-selected status in user meta
    $adminSelectedStatus = get_user_meta($wpUser->ID, 'mailpoet_subscriber_status', true);
    error_log('MailPoet Debug: Checking user meta for status, found: ' . ($adminSelectedStatus ?: 'not found'));
    
    // Check if this is admin-created user from our special hook
    $isAdminCreated = get_user_meta($wpUser->ID, 'mailpoet_admin_created', true) === '1';
    
    // Flag to track if this is an admin-created user with a specific status
    $isAdminCreatedUser = $isAdminCreated;
    
    if (!empty($adminSelectedStatus)) {
      // Admin selected a status when creating this user
      $status = $adminSelectedStatus;
      error_log('MailPoet Debug: Using admin-selected status from user meta: ' . $status);
      
      // Clean up the user meta after use
      delete_user_meta($wpUser->ID, 'mailpoet_subscriber_status');
      
      // Set the flag to true
      $isAdminCreatedUser = true;
    } else {
      // Normal user creation flow
      $status = $signupConfirmationEnabled ? SubscriberEntity::STATUS_UNCONFIRMED : SubscriberEntity::STATUS_SUBSCRIBED;
      error_log('MailPoet Debug: Using default status: ' . $status);
      // we want to mark a new subscriber as unsubscribe when the checkbox from registration is unchecked
      if (isset($_POST['mailpoet']['subscribe_on_register_active']) && (bool)$_POST['mailpoet']['subscribe_on_register_active'] === true) {
        $status = SubscriberEntity::STATUS_UNSUBSCRIBED;
        error_log('MailPoet Debug: Checkbox unchecked, changing status to: ' . $status);
      }
    }

    // subscriber data
    $data = [
      'wp_user_id' => $wpUser->ID,
      'email' => $wpUser->user_email, // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
      'first_name' => $firstName,
      'last_name' => $lastName,
      'status' => $status,
      'source' => Source::WORDPRESS_USER,
    ];

    if (!is_null($subscriber)) {
      $data['id'] = $subscriber->getId();
      
      // If this is an admin-created user with a specific status,
      // we want to override the status even for existing subscribers
      if ($isAdminCreatedUser) {
        error_log('MailPoet Debug: Admin-created user, keeping status even for existing subscriber');
      } else {
        unset($data['status']); // don't override status for existing users in normal flow
        unset($data['source']); // don't override source for existing users in normal flow
      }
    }

    $addingNewUserToDisabledWPSegment = $wpSegment->getDeletedAt() !== null && $currentFilter === 'user_register';

    $otherActiveSegments = [];
    if ($subscriber) {
      $otherActiveSegments = array_filter($subscriber->getSegments()->toArray() ?? [], function (SegmentEntity $segment) {
          return $segment->getType() !== SegmentEntity::TYPE_WP_USERS && $segment->getDeletedAt() === null;
      });
    }
    $isWooCustomer = $this->wooHelper->isWooCommerceActive() && in_array('customer', $wpUser->roles, true);
    // When WP Segment is disabled force trashed state and unconfirmed status for new WPUsers without active segment
    // or who are not WooCommerce customers at the same time since customers are added to the WooCommerce list
    if ($addingNewUserToDisabledWPSegment && !$otherActiveSegments && !$isWooCustomer) {
      $data['deleted_at'] = Carbon::now()->millisecond(0);
      $data['status'] = SubscriberEntity::STATUS_UNCONFIRMED;
    }

    try {
      $subscriber = $this->createOrUpdateSubscriber($data, $subscriber);
      error_log('MailPoet Debug: Subscriber created/updated with status: ' . $subscriber->getStatus());
    } catch (\Exception $e) {
      error_log('MailPoet Debug: Failed to create/update subscriber: ' . $e->getMessage());
      return; // fails silently as this was the behavior of this methods before the Doctrine refactor.
    }

    // add subscriber to the WP Users segment
    $this->subscriberSegmentRepository->subscribeToSegments(
      $subscriber,
      [$wpSegment]
    );

    if (!$signupConfirmationEnabled && $subscriber->getStatus() === SubscriberEntity::STATUS_SUBSCRIBED && $currentFilter === 'user_register') {
      $subscriberSegment = $this->subscriberSegmentRepository->findOneBy([
        'subscriber' => $subscriber->getId(),
        'segment' => $wpSegment->getId(),
      ]);

      if (!is_null($subscriberSegment)) {
        $this->wp->doAction('mailpoet_segment_subscribed', $subscriberSegment);
      }
    }

    // Modified condition to check if this is an admin-created user and handle accordingly
    $subscribeOnRegisterEnabled = SettingsController::getInstance()->get('subscribe.on_register.enabled');
    error_log('MailPoet Debug: subscribeOnRegisterEnabled: ' . ($subscribeOnRegisterEnabled ? 'true' : 'false'));
    
    // Force this flag to true if this is an admin-selected status confirmation
    $adminRequestedConfirmation = get_user_meta($wpUser->ID, 'mailpoet_admin_selected_status', true) === '1';
    if ($adminRequestedConfirmation) {
      error_log('MailPoet Debug: Admin requested confirmation detected, forcing subscribeOnRegisterEnabled to true');
      $subscribeOnRegisterEnabled = true;
      
      // Clean up
      delete_user_meta($wpUser->ID, 'mailpoet_admin_selected_status');
    }
    
    // Send confirmation email only if the subscriber status is UNCONFIRMED and:
    // For admin-created users: allow sending regardless of filter
    // For regular users: only when not on profile_update and signup confirmation is enabled
    $sendConfirmationEmail = 
      $signupConfirmationEnabled &&
      $subscriber->getStatus() === SubscriberEntity::STATUS_UNCONFIRMED &&
      !$addingNewUserToDisabledWPSegment &&
      ($isAdminCreatedUser || ($subscribeOnRegisterEnabled && $currentFilter !== 'profile_update'));
    
    error_log('MailPoet Debug: sendConfirmationEmail: ' . ($sendConfirmationEmail ? 'true' : 'false'));
    error_log('MailPoet Debug: isAdminCreatedUser: ' . ($isAdminCreatedUser ? 'true' : 'false'));
    error_log('MailPoet Debug: currentFilter: ' . $currentFilter);
    error_log('MailPoet Debug: subscriber status: ' . $subscriber->getStatus());

    if ($sendConfirmationEmail) {
      /** @var ConfirmationEmailMailer $confirmationEmailMailer */
      error_log('MailPoet Debug: Attempting to send confirmation email');
      $confirmationEmailMailer = ContainerWrapper::getInstance()->get(ConfirmationEmailMailer::class);
      try {
        $result = $confirmationEmailMailer->sendConfirmationEmailOnce($subscriber);
        error_log('MailPoet Debug: Confirmation email sent result: ' . ($result ? 'true' : 'false'));
      } catch (\Exception $e) {
        error_log('MailPoet Debug: Failed to send confirmation email: ' . $e->getMessage());
        // ignore errors
      }
    }

    // welcome email
    $scheduleWelcomeNewsletter = false;
    if (in_array($currentFilter, ['profile_update', 'user_register', 'add_user_role', 'set_user_role'])) {
      $scheduleWelcomeNewsletter = true;
    }
    if ($scheduleWelcomeNewsletter === true) {
      $this->welcomeScheduler->scheduleWPUserWelcomeNotification(
        $subscriber->getId(),
        (array)$wpUser,
        (array)$oldWpUserData
      );
    }
  }

  private function createOrUpdateSubscriber(array $data, ?SubscriberEntity $subscriber = null): SubscriberEntity {
    if (is_null($subscriber)) {
      $subscriber = new SubscriberEntity();
    }

    $subscriber->setWpUserId($data['wp_user_id']);
    $subscriber->setEmail($data['email']);
    $subscriber->setFirstName($data['first_name']);
    $subscriber->setLastName($data['last_name']);

    if (isset($data['status'])) {
      $subscriber->setStatus($data['status']);
    }

    if (isset($data['source'])) {
      $subscriber->setSource($data['source']);
    }

    if (isset($data['deleted_at'])) {
      $subscriber->setDeletedAt($data['deleted_at']);
    }

    $this->subscribersRepository->persist($subscriber);
    $this->subscribersRepository->flush();

    return $subscriber;
  }

  public function synchronizeUsers(): bool {
    // Temporarily skip synchronization in WP Playground.
    // Some of the queries are not yet supported by the SQLite integration.
    if (Connection::isSQLite()) {
      return true;
    }

    // Save timestamp about changes and update before insert
    $this->subscriberChangesNotifier->subscribersBatchCreate();
    $this->subscriberChangesNotifier->subscribersBatchUpdate();

    $updatedUsersEmails = $this->updateSubscribersEmails();
    $insertedUsersEmails = $this->insertSubscribers();
    $this->removeUpdatedSubscribersWithInvalidEmail(array_merge($updatedUsersEmails, $insertedUsersEmails));
    // There is high chance that an update will be made
    $this->subscriberChangesNotifier->subscribersBatchUpdate();
    unset($updatedUsersEmails);
    unset($insertedUsersEmails);
    $this->updateFirstNames();
    $this->updateLastNames();
    $this->updateFirstNameIfMissing();
    $this->insertUsersToSegment();
    $this->removeOrphanedSubscribers();
    $this->subscribersRepository->invalidateTotalSubscribersCache();
    $this->subscribersRepository->refreshAll();

    return true;
  }

  private function removeUpdatedSubscribersWithInvalidEmail(array $updatedEmails): void {
    $invalidWpUserIds = array_map(function($item) {
      return $item['id'];
    },
    array_filter($updatedEmails, function($updatedEmail) {
      return !$this->validator->validateEmail($updatedEmail['email']) && $updatedEmail['id'] !== null;
    }));
    if (!$invalidWpUserIds) {
      return;
    }

    $this->subscribersRepository->removeByWpUserIds($invalidWpUserIds);
  }

  private function updateSubscribersEmails(): array {
    global $wpdb;

    $stmt = $this->databaseConnection->executeQuery('SELECT NOW();');
    $startTime = $stmt->fetchOne();

    if (!is_string($startTime)) {
      throw new \RuntimeException("Failed to fetch the current time.");
    }

    $updateSql =
      "UPDATE IGNORE {$this->subscribersTable} s
        INNER JOIN {$wpdb->users} as wu ON s.wp_user_id = wu.id
        SET s.email = wu.user_email";
    $this->databaseConnection->executeStatement($updateSql);

    $selectSql =
      "SELECT wp_user_id as id, email FROM {$this->subscribersTable}
        WHERE updated_at >= '{$startTime}'";
    $updatedEmails = $this->databaseConnection->fetchAllAssociative($selectSql);

    return $updatedEmails;
  }

  private function insertSubscribers(): array {
    global $wpdb;
    $wpSegment = $this->segmentsRepository->getWPUsersSegment();

    if ($wpSegment->getDeletedAt() !== null) {
      $subscriberStatus = SubscriberEntity::STATUS_UNCONFIRMED;
      $deletedAt = 'CURRENT_TIMESTAMP()';
    } else {
      $signupConfirmationEnabled = SettingsController::getInstance()->get('signup_confirmation.enabled');
      $subscriberStatus = $signupConfirmationEnabled ? SubscriberEntity::STATUS_UNCONFIRMED : SubscriberEntity::STATUS_SUBSCRIBED;
      $deletedAt = 'null';
    }

    // Fetch users that are not in the subscribers table
    $selectSql =
      "SELECT u.id, u.user_email as email
        FROM {$wpdb->users} u
        LEFT JOIN {$this->subscribersTable} AS s ON s.wp_user_id = u.id
        WHERE s.wp_user_id IS NULL AND u.user_email != ''";
    $insertedUserIds = $this->databaseConnection->fetchAllAssociative($selectSql);

    // Insert new users into the subscribers table
    $insertSql =
      "INSERT IGNORE INTO {$this->subscribersTable} (wp_user_id, email, status, created_at, `source`, deleted_at)
        SELECT wu.id, wu.user_email, :subscriberStatus, CURRENT_TIMESTAMP(), :source, {$deletedAt}
        FROM {$wpdb->users} wu
        LEFT JOIN {$this->subscribersTable} s ON wu.id = s.wp_user_id
        WHERE s.wp_user_id IS NULL AND wu.user_email != ''
        ON DUPLICATE KEY UPDATE wp_user_id = wu.id";
    $stmt = $this->databaseConnection->prepare($insertSql);
    $stmt->bindValue('subscriberStatus', $subscriberStatus);
    $stmt->bindValue('source', Source::WORDPRESS_USER);
    $stmt->executeStatement();

    return $insertedUserIds;
  }

  private function updateFirstNames(): void {
    global $wpdb;

    $sql =
      "UPDATE {$this->subscribersTable} s
        JOIN {$wpdb->usermeta} as wpum ON s.wp_user_id = wpum.user_id AND wpum.meta_key = 'first_name'
        SET s.first_name = SUBSTRING(wpum.meta_value, 1, 255)
        WHERE s.first_name = ''
        AND s.wp_user_id IS NOT NULL
        AND wpum.meta_value IS NOT NULL";

    $this->databaseConnection->executeStatement($sql);
  }

  private function updateLastNames(): void {
    global $wpdb;

    $sql =
      "UPDATE {$this->subscribersTable} s
        JOIN {$wpdb->usermeta} as wpum ON s.wp_user_id = wpum.user_id AND wpum.meta_key = 'last_name'
        SET s.last_name = SUBSTRING(wpum.meta_value, 1, 255)
        WHERE s.last_name = ''
        AND s.wp_user_id IS NOT NULL
        AND wpum.meta_value IS NOT NULL";

    $this->databaseConnection->executeStatement($sql);
  }

  private function updateFirstNameIfMissing(): void {
    global $wpdb;

    $sql =
      "UPDATE {$this->subscribersTable} s
        JOIN {$wpdb->users} wu ON s.wp_user_id = wu.id
        SET s.first_name = wu.display_name
        WHERE s.first_name = ''
        AND s.wp_user_id IS NOT NULL";

    $this->databaseConnection->executeStatement($sql);
  }

  private function insertUsersToSegment(): void {
    $wpSegment = $this->segmentsRepository->getWPUsersSegment();
    $subscribersSegmentTable = $this->entityManager->getClassMetadata(SubscriberSegmentEntity::class)->getTableName();

    $sql =
      "INSERT IGNORE INTO {$subscribersSegmentTable} (subscriber_id, segment_id, created_at)
        SELECT s.id, '{$wpSegment->getId()}', CURRENT_TIMESTAMP() FROM {$this->subscribersTable} s
        WHERE s.wp_user_id > 0";

    $this->databaseConnection->executeStatement($sql);
  }

  private function removeOrphanedSubscribers(): void {
    $this->subscribersRepository->removeOrphanedSubscribersFromWpSegment();
  }
}
