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

  /** @var ConfirmationEmailMailer */
  private $confirmationEmailMailer;

  /** @var array */
  private $processedUserIds = [];

  public function __construct(
    WPFunctions $wp,
    WelcomeScheduler $welcomeScheduler,
    WooCommerceHelper $wooHelper,
    SubscribersRepository $subscribersRepository,
    SubscriberSegmentRepository $subscriberSegmentRepository,
    SubscriberChangesNotifier $subscriberChangesNotifier,
    Validator $validator,
    SegmentsRepository $segmentsRepository,
    EntityManager $entityManager,
    ConfirmationEmailMailer $confirmationEmailMailer
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
    $this->confirmationEmailMailer = $confirmationEmailMailer;
  }

  /**
   * @param int $wpUserId
   * @param array|false $oldWpUserData
   */
  public function synchronizeUser(int $wpUserId, $oldWpUserData = false): void {
    $wpUser = \get_userdata($wpUserId);
    if ($wpUser === false) return;

    // Track current filter to debug multiple invocations
    $currentFilter = $this->wp->currentFilter();
    error_log('MailPoet DEBUG: synchronizeUser called for user ID ' . $wpUserId . ' from filter: ' . $currentFilter);

    // First, check for the global variable that may have been set during admin form submission
    if (isset($GLOBALS['mailpoet_new_user_status'])) {
      $status = $GLOBALS['mailpoet_new_user_status'];
      error_log('MailPoet DEBUG: Found global mailpoet_new_user_status: ' . $status);
      
      // Set the transient with this status
      $transientKey = 'mailpoet_new_wp_user_status_' . $wpUserId;
      $this->wp->setTransient($transientKey, $status, 5 * MINUTE_IN_SECONDS);
      error_log('MailPoet DEBUG: Set transient from global variable: ' . $transientKey . ' with status: ' . $status);
    }

    // If this is a 'user_register' event, check if we've already processed this user
    $transientKey = 'mailpoet_new_wp_user_status_' . $wpUserId;
    if ($currentFilter === 'user_register') {
      $transientValue = $this->wp->getTransient($transientKey);
      // If we have a transient value but have already processed this user, it means another hook already handled it
      if ($transientValue && isset($this->processedUserIds[$wpUserId])) {
        error_log('MailPoet DEBUG: Already processed user ID ' . $wpUserId . ' with admin-selected status');
        return;
      }
    }

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
    
    // Mark this user as processed to avoid duplicate processing
    $this->processedUserIds[$wpUserId] = true;
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
    
    // Store the transient status for the new user
    $transientKey = 'mailpoet_new_wp_user_status_' . $wpUser->ID;
    $status = $this->wp->getTransient($transientKey);
    
    // Log the status right after retrieving from transient
    error_log('MailPoet DEBUG: Status from transient for user ID ' . $wpUser->ID . ': ' . ($status ?: 'not set'));
    
    // If we have a status from the admin form, use it (and delete the transient)
    if ($status) {
      $this->wp->deleteTransient($transientKey);
      error_log('MailPoet DEBUG: Using admin-selected status: ' . $status);
      
      // If this is an existing subscriber, force updating the status
      if (!is_null($subscriber)) {
        $subscriber->setStatus($status);
        $this->subscribersRepository->persist($subscriber);
        $this->subscribersRepository->flush();
        error_log('MailPoet DEBUG: Updated existing subscriber status to: ' . $status);
      }
    } else {
      // ONLY use default status logic if no transient exists (status was not explicitly set by admin)
      $status = $signupConfirmationEnabled ? SubscriberEntity::STATUS_UNCONFIRMED : SubscriberEntity::STATUS_SUBSCRIBED;
      // we want to mark a new subscriber as unsubscribe when the checkbox from registration is unchecked
      if (isset($_POST['mailpoet']['subscribe_on_register_active']) && (bool)$_POST['mailpoet']['subscribe_on_register_active'] === true) {
        $status = SubscriberEntity::STATUS_UNSUBSCRIBED;
      }
      error_log('MailPoet DEBUG: Using default status: ' . $status);
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

    // For existing subscribers
    if (!is_null($subscriber)) {
      $data['id'] = $subscriber->getId();
      
      // Don't override status for existing subscribers if a transient wasn't set specifically
      if (!$this->wp->getTransient($transientKey)) {
        unset($data['status']);
      }
      
      unset($data['source']); // don't override source for existing users
    }
    
    // CRITICAL: Always respect the status from the transient if it was explicitly set
    // This is needed even for existing subscribers
    if ($status && isset($data['status']) && $status !== $data['status']) {
      $data['status'] = $status;
    } else if ($status && !isset($data['status'])) {
      $data['status'] = $status;
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

    // First, create or update subscriber with the desired status
    error_log('MailPoet DEBUG: Before creating subscriber - status in data: ' . $data['status']);
    try {
      $subscriber = $this->createOrUpdateSubscriber($data, $subscriber);
      error_log('MailPoet DEBUG: After creating subscriber - status: ' . $subscriber->getStatus());
    } catch (\Exception $e) {
      error_log('MailPoet DEBUG: Error creating subscriber: ' . $e->getMessage());
      return; // fails silently as this was the behavior of this methods before the Doctrine refactor.
    }

    // Add subscriber to the WP Users segment
    $this->subscriberSegmentRepository->subscribeToSegments(
      $subscriber,
      [$wpSegment]
    );

    // Only now check if we need to send a confirmation email
    // Add debug logging
    error_log('MailPoet DEBUG: Checking confirmation email conditions');
    error_log('MailPoet DEBUG: signupConfirmationEnabled = ' . ($signupConfirmationEnabled ? 'true' : 'false'));
    error_log('MailPoet DEBUG: subscriber status = ' . $subscriber->getStatus());
    error_log('MailPoet DEBUG: currentFilter = ' . $currentFilter);
    error_log('MailPoet DEBUG: addingNewUserToDisabledWPSegment = ' . ($addingNewUserToDisabledWPSegment ? 'true' : 'false'));

    $sendConfirmationEmail =
      $signupConfirmationEnabled
      && $subscriber->getStatus() === SubscriberEntity::STATUS_UNCONFIRMED
      && $currentFilter !== 'profile_update'
      && !$addingNewUserToDisabledWPSegment;

    error_log('MailPoet DEBUG: Decision to send confirmation email: ' . ($sendConfirmationEmail ? 'YES' : 'NO'));

    if ($sendConfirmationEmail) {
      try {
        error_log('MailPoet DEBUG: Attempting to send confirmation email');
        $this->confirmationEmailMailer->sendConfirmationEmailOnce($subscriber);
        error_log('MailPoet DEBUG: Confirmation email sent successfully');
      } catch (\Exception $e) {
        // Log error instead of silently ignoring
        error_log('MailPoet: Failed to send confirmation email: ' . $e->getMessage());
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
      error_log('MailPoet DEBUG: Creating new subscriber');
    } else {
      error_log('MailPoet DEBUG: Updating existing subscriber with status: ' . $subscriber->getStatus());
    }

    $subscriber->setWpUserId($data['wp_user_id']);
    $subscriber->setEmail($data['email']);
    $subscriber->setFirstName($data['first_name']);
    $subscriber->setLastName($data['last_name']);

    if (isset($data['status'])) {
      error_log('MailPoet DEBUG: Setting subscriber status to: ' . $data['status']);
      $subscriber->setStatus($data['status']);
    } else {
      error_log('MailPoet DEBUG: No status in data, keeping existing: ' . $subscriber->getStatus());
    }

    if (isset($data['source'])) {
      $subscriber->setSource($data['source']);
    }

    if (isset($data['deleted_at'])) {
      $subscriber->setDeletedAt($data['deleted_at']);
    }

    error_log('MailPoet DEBUG: About to persist subscriber with status: ' . $subscriber->getStatus());
    $this->subscribersRepository->persist($subscriber);
    $this->subscribersRepository->flush();
    error_log('MailPoet DEBUG: After persisting, subscriber status: ' . $subscriber->getStatus());

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
