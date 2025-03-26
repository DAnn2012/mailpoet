<?php // phpcs:ignore SlevomatCodingStandard.TypeHints.DeclareStrictTypes.DeclareStrictTypesMissing

namespace MailPoet\Subscription;

use MailPoet\Entities\SubscriberEntity;
use MailPoet\Settings\SettingsController;
use MailPoet\Subscribers\ConfirmationEmailMailer;
use MailPoet\Subscribers\SubscribersRepository;
use MailPoet\WP\Functions as WPFunctions;

class AdminUserSubscription {

  /** @var SettingsController */
  private $settings;

  /** @var WPFunctions */
  private $wp;

  /** @var SubscribersRepository */
  private $subscribersRepository;

  /** @var ConfirmationEmailMailer */
  private $confirmationEmailMailer;

  public function __construct(
    SettingsController $settings,
    WPFunctions $wp,
    SubscribersRepository $subscribersRepository,
    ConfirmationEmailMailer $confirmationEmailMailer
  ) {
    $this->settings = $settings;
    $this->wp = $wp;
    $this->subscribersRepository = $subscribersRepository;
    $this->confirmationEmailMailer = $confirmationEmailMailer;
    
    // Initialize hooks on construction
    $this->initializeHooks();
  }

  private function initializeHooks(): void {
    // Add status field to the "Add New User" form
    $this->wp->addAction('user_new_form', [$this, 'addStatusFieldToNewUserForm']);

    // Process the status field when a new user is created
    $this->wp->addAction('user_register', [$this, 'processNewUserStatus'], 10, 1);
  }

  /**
   * Add a status selection field to the "Add New User" form
   * 
   * @param string $context The form context, 'add-new-user' or 'add-existing-user'
   * @return void
   */
  public function addStatusFieldToNewUserForm($context) {
    // Only show the field on add-new-user form (single site)
    if ($context !== 'add-new-user') {
      return;
    }

    $signupConfirmationEnabled = $this->settings->get('signup_confirmation.enabled');
    
    echo '<table class="form-table" role="presentation">
      <tr class="form-field">
        <th scope="row"><label for="mailpoet_subscriber_status">' . esc_html__('MailPoet Subscriber Status', 'mailpoet') . '</label></th>
        <td>
          <select name="mailpoet_subscriber_status" id="mailpoet_subscriber_status">
            <option value="' . esc_attr(SubscriberEntity::STATUS_SUBSCRIBED) . '">' . esc_html__('Subscribed', 'mailpoet') . '</option>';
            
    if ($signupConfirmationEnabled) {
      echo '<option value="' . esc_attr(SubscriberEntity::STATUS_UNCONFIRMED) . '" selected>' . 
        esc_html__('Unconfirmed (will receive a confirmation email)', 'mailpoet') . 
        '</option>';
    }
    
    echo '<option value="' . esc_attr(SubscriberEntity::STATUS_UNSUBSCRIBED) . '"';
    
    if (!$signupConfirmationEnabled) {
      echo ' selected';
    }
    
    echo '>' . esc_html__('Unsubscribed', 'mailpoet') . '</option>
          </select>
        </td>
      </tr>
    </table>';
  }

  /**
   * Process the subscriber status when a new user is registered
   * 
   * @param int $userId The newly created user ID
   * @return void
   */
  public function processNewUserStatus($userId) {
    // Only process if the status field was submitted (i.e., from WP admin)
    if (!isset($_POST['mailpoet_subscriber_status'])) {
      return;
    }

    $status = sanitize_text_field($_POST['mailpoet_subscriber_status']);
    
    // Validate status against allowed values
    $allowedStatuses = [
      SubscriberEntity::STATUS_SUBSCRIBED,
      SubscriberEntity::STATUS_UNCONFIRMED,
      SubscriberEntity::STATUS_UNSUBSCRIBED,
    ];
    
    if (!in_array($status, $allowedStatuses)) {
      return;
    }
    
    $user = get_userdata($userId);

    if (!$user) {
      return;
    }

    // Find if a subscriber already exists with this email
    $subscriber = $this->subscribersRepository->findOneBy(['email' => $user->user_email]);
    
    // Store the desired status in a transient for the WP sync process
    $transientKey = 'mailpoet_new_wp_user_status_' . $userId;
    $this->wp->setTransient($transientKey, $status, 5 * MINUTE_IN_SECONDS);
  }
} 