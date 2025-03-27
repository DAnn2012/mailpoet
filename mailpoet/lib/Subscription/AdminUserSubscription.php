<?php // phpcs:ignore SlevomatCodingStandard.TypeHints.DeclareStrictTypes.DeclareStrictTypesMissing

namespace MailPoet\Subscription;

use MailPoet\Entities\SubscriberEntity;
use MailPoet\Settings\SettingsController;
use MailPoet\WP\Functions as WPFunctions;

class AdminUserSubscription {
  /** @var WPFunctions */
  private $wp;

  /** @var SettingsController */
  private $settings;

  const META_KEY = 'mailpoet_subscriber_status';

  public function __construct(
    WPFunctions $wp,
    SettingsController $settings
  ) {
    $this->wp = $wp;
    $this->settings = $settings;

    // Set up hooks for the Add New User form
    // The WordPress user_new_form action is fired with 'add-new-user' as the parameter
    $this->wp->addAction('user_new_form', [$this, 'displaySubscriberStatusField'], 10, 1);
    
    // This hook is specifically for users created via the WordPress admin
    $this->wp->addAction('edit_user_created_user', [$this, 'processNewUserStatus'], 10, 1);
    
    // Keep the user_register hook as a fallback, with lower priority
    $this->wp->addAction('user_register', [$this, 'processNewUserStatus'], 20, 1);
    
    // Hook to synchronize user that intercepts WP class handling
    $this->wp->addAction('mailpoet_subscriber_wp_user_synchronize', [$this, 'synchronizeSubscriberStatus'], 5, 2);
  }

  /**
   * Display the subscriber status field on the Add New User form
   * 
   * @param string $type The form context, 'add-new-user' for single site and network admin
   */
  public function displaySubscriberStatusField($type) {
    // According to WordPress docs, the parameter is 'add-new-user' for single site and network admin
    if ($type !== 'add-new-user') {
      return;
    }

    $confirmationEnabled = (bool)$this->settings->get('signup_confirmation.enabled', false);
    $defaultStatus = $confirmationEnabled ? 
      SubscriberEntity::STATUS_UNCONFIRMED : 
      SubscriberEntity::STATUS_UNSUBSCRIBED;

    // Add newlines for better formatting in HTML output
    echo "\n";
    ?>
    <table class="form-table">
      <tr>
        <th scope="row">
          <label for="mailpoet_subscriber_status">
            <?php echo esc_html__('MailPoet Subscriber Status', 'mailpoet'); ?>
          </label>
        </th>
        <td>
          <select name="mailpoet_subscriber_status" id="mailpoet_subscriber_status">
            <option value="<?php echo esc_attr(SubscriberEntity::STATUS_SUBSCRIBED); ?>">
              <?php echo esc_html__('Subscribed', 'mailpoet'); ?>
            </option>

            <?php if ($confirmationEnabled): ?>
            <option value="<?php echo esc_attr(SubscriberEntity::STATUS_UNCONFIRMED); ?>" selected="selected">
              <?php echo esc_html__('Unconfirmed (will receive a confirmation email)', 'mailpoet'); ?>
            </option>
            <?php endif; ?>

            <option value="<?php echo esc_attr(SubscriberEntity::STATUS_UNSUBSCRIBED); ?>" <?php if (!$confirmationEnabled): ?>selected="selected"<?php endif; ?>>
              <?php echo esc_html__('Unsubscribed', 'mailpoet'); ?>
            </option>
          </select>
        </td>
      </tr>
    </table>
    <?php
    echo "\n";
  }

  /**
   * Process the selected status for the new user
   * 
   * @param int $userId The ID of the new user
   */
  public function processNewUserStatus($userId) {
    error_log('MailPoet Debug: processNewUserStatus called for user ID: ' . $userId);
    
    // Check if our field was submitted
    if (!isset($_POST['mailpoet_subscriber_status'])) {
      error_log('MailPoet Debug: mailpoet_subscriber_status not in POST data');
      return;
    }

    $status = sanitize_text_field($_POST['mailpoet_subscriber_status']);
    error_log('MailPoet Debug: mailpoet_subscriber_status value: ' . $status);
    
    // Validate the status value
    $validStatuses = [
      SubscriberEntity::STATUS_SUBSCRIBED,
      SubscriberEntity::STATUS_UNCONFIRMED,
      SubscriberEntity::STATUS_UNSUBSCRIBED,
    ];
    
    if (!in_array($status, $validStatuses)) {
      error_log('MailPoet Debug: Invalid status value');
      return;
    }

    // Store the status in user meta
    $result = update_user_meta($userId, self::META_KEY, $status);
    error_log('MailPoet Debug: Stored status in user meta, result: ' . ($result ? 'true' : 'false'));
    
    // Signal that this user needs to be processed with our special handling
    $this->wp->doAction('mailpoet_subscriber_wp_user_synchronize', $userId, $status);
  }
  
  /**
   * Apply the subscriber status for a WordPress user
   * 
   * @param int $userId The WordPress user ID
   * @param string $status The subscriber status to apply
   */
  public function synchronizeSubscriberStatus($userId, $status) {
    error_log('MailPoet Debug: synchronizeSubscriberStatus called for user ID: ' . $userId . ', status: ' . $status);
    
    // Get the user data
    $user = get_userdata($userId);
    if (!$user) {
      error_log('MailPoet Debug: User not found');
      return;
    }
    
    global $wpdb;
    
    // Check if there's already a subscriber for this user
    $subscribersTable = $wpdb->prefix . 'mailpoet_subscribers';
    $subscriber = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$subscribersTable} WHERE wp_user_id = %d OR email = %s",
        $userId,
        $user->user_email
      )
    );
    
    if ($subscriber) {
      // Update existing subscriber
      error_log('MailPoet Debug: Updating existing subscriber with ID: ' . $subscriber->id);
      $wpdb->update(
        $subscribersTable,
        ['status' => $status],
        ['id' => $subscriber->id],
        ['%s'],
        ['%d']
      );
    } else {
      // This will be handled by the normal synchronization process in Segments/WP.php
      error_log('MailPoet Debug: No existing subscriber found, will be created by normal sync process');
    }
    
    // Set a flag in user meta to indicate this was selected by admin
    update_user_meta($userId, 'mailpoet_status', $status);
    update_user_meta($userId, 'mailpoet_admin_selected_status', '1');
    
    // If status is unconfirmed, we'll need to get this subscriber to receive a confirmation email
    if ($status === SubscriberEntity::STATUS_UNCONFIRMED) {
      error_log('MailPoet Debug: Status is unconfirmed, preparing to trigger confirmation email');
      
      // Force a synchronization of this user to ensure the confirmation email is sent
      // This is done by directly calling the WP class's synchronizeUser method via a hook
      // which will pick up our admin_selected_status flag
      $this->wp->doAction('mailpoet_user_sync', $userId);
    }
  }
} 