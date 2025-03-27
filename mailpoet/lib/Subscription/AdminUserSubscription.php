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

    // Process the status field when the form is submitted - BEFORE user is created
    $this->wp->addAction('admin_action_createuser', [$this, 'processCreateUserForm'], 1);
    
    // Fallback hook - processes the form data after user creation
    $this->wp->addAction('user_register', [$this, 'processNewUserStatus'], 1, 1);
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
    
    // Generate a nonce for security
    $nonce = $this->wp->wpCreateNonce('mailpoet_subscriber_status_nonce');
    
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
          <input type="hidden" name="mailpoet_subscriber_status_nonce" value="' . esc_attr($nonce) . '">
        </td>
      </tr>
    </table>';
  }

  /**
   * Process the form submission before the user is created
   */
  public function processCreateUserForm() {
    error_log('MailPoet DEBUG: Admin - admin_action_createuser hook fired');
    
    // Only process if the status field was submitted
    if (!isset($_POST['mailpoet_subscriber_status'])) {
      error_log('MailPoet DEBUG: Admin - No mailpoet_subscriber_status in POST data, skipping');
      return;
    }
    
    // Verify nonce for security
    if (
      !isset($_POST['mailpoet_subscriber_status_nonce']) || 
      !$this->wp->wpVerifyNonce(sanitize_text_field($_POST['mailpoet_subscriber_status_nonce']), 'mailpoet_subscriber_status_nonce')
    ) {
      error_log('MailPoet DEBUG: Admin - Invalid nonce for mailpoet_subscriber_status, skipping');
      return;
    }

    $status = sanitize_text_field($_POST['mailpoet_subscriber_status']);
    error_log('MailPoet DEBUG: Admin - Status selected in form: ' . $status);
    
    // Validate status against allowed values
    $allowedStatuses = [
      SubscriberEntity::STATUS_SUBSCRIBED,
      SubscriberEntity::STATUS_UNCONFIRMED,
      SubscriberEntity::STATUS_UNSUBSCRIBED,
    ];
    
    if (!in_array($status, $allowedStatuses)) {
      error_log('MailPoet DEBUG: Admin - Invalid status: ' . $status);
      return;
    }
    
    // Store in a global variable that will be checked by synchronizeUser later
    // This is captured BEFORE the user is created
    $GLOBALS['mailpoet_new_user_status'] = $status;
    error_log('MailPoet DEBUG: Admin - Set global mailpoet_new_user_status to: ' . $status);
  }

  /**
   * Process the subscriber status when a new user is registered
   * This is called AFTER user creation and only used as backup
   * 
   * @param int $userId The newly created user ID
   * @return void
   */
  public function processNewUserStatus($userId) {
    // The admin_action_createuser hook should have already handled this
    if (isset($GLOBALS['mailpoet_new_user_status'])) {
      $status = $GLOBALS['mailpoet_new_user_status'];
      error_log('MailPoet DEBUG: Admin - Using status from global variable: ' . $status);
      
      // Store the desired status in a transient for the WP sync process
      $transientKey = 'mailpoet_new_wp_user_status_' . $userId;
      $this->wp->setTransient($transientKey, $status, 5 * MINUTE_IN_SECONDS);
      error_log('MailPoet DEBUG: Admin - Set transient ' . $transientKey . ' with status: ' . $status);
      return;
    }
    
    // Only proceed if this wasn't handled by admin_action_createuser
    if (!isset($_POST['mailpoet_subscriber_status'])) {
      error_log('MailPoet DEBUG: Admin - No mailpoet_subscriber_status in POST data, skipping');
      return;
    }
    
    // Verify nonce for security
    if (
      !isset($_POST['mailpoet_subscriber_status_nonce']) || 
      !$this->wp->wpVerifyNonce(sanitize_text_field($_POST['mailpoet_subscriber_status_nonce']), 'mailpoet_subscriber_status_nonce')
    ) {
      error_log('MailPoet DEBUG: Admin - Invalid nonce for mailpoet_subscriber_status, skipping');
      return;
    }

    $status = sanitize_text_field($_POST['mailpoet_subscriber_status']);
    error_log('MailPoet DEBUG: Admin - Status selected in form: ' . $status);
    
    // Validate status against allowed values
    $allowedStatuses = [
      SubscriberEntity::STATUS_SUBSCRIBED,
      SubscriberEntity::STATUS_UNCONFIRMED,
      SubscriberEntity::STATUS_UNSUBSCRIBED,
    ];
    
    if (!in_array($status, $allowedStatuses)) {
      error_log('MailPoet DEBUG: Admin - Invalid status: ' . $status);
      return;
    }
    
    $user = get_userdata($userId);

    if (!$user) {
      error_log('MailPoet DEBUG: Admin - User not found for ID: ' . $userId);
      return;
    }

    // Find if a subscriber already exists with this email
    $subscriber = $this->subscribersRepository->findOneBy(['email' => $user->user_email]);
    
    // Store the desired status in a transient for the WP sync process
    $transientKey = 'mailpoet_new_wp_user_status_' . $userId;
    $this->wp->setTransient($transientKey, $status, 5 * MINUTE_IN_SECONDS);
    error_log('MailPoet DEBUG: Admin - Set transient ' . $transientKey . ' with status: ' . $status);
  }
} 