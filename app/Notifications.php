<?php
namespace Nanga;

use Exception;
use Monolog\Logger;
use Monolog\Handler\LogglyHandler;
use Monolog\Formatter\LogglyFormatter;
use SendGrid;
use SendGrid\ClickTracking;
use SendGrid\Content;
use SendGrid\Email;
use SendGrid\Mail;
use SendGrid\OpenTracking;
use SendGrid\Personalization;
use SendGrid\TrackingSettings;
use stdClass;
use WP_Post;
use WP_User_Query;

class Notifications
{

    protected $config;
    protected $provider;
    protected $singleRecipient;
    protected $data;
    protected $template;
    protected $post;
    protected $log;
    protected $errors = [];

    public function __construct($config)
    {
        $defaultConfig = [
            'debug'          => (get_field('notifications_debug', 'options')) ? get_field('notifications_debug', 'options') : false,
            'from'           => (get_field('notifications_from_email', 'options')) ? get_field('notifications_from_email', 'options') : get_option('admin_email'),
            'fromName'       => (get_field('notifications_from_name', 'options')) ? get_field('notifications_from_name', 'options') : get_bloginfo('description'),
            'logger'         => false,
            'postTypes'      => (get_field('notifications_post_types', 'options')) ? get_field('notifications_post_types', 'options') : ['post'],
            'provider'       => 'SendGrid',
            'providerKey'    => false,
            'recipientRoles' => (get_field('notifications_recipients', 'options')) ? get_field('notifications_recipients', 'options') : ['subscriber'],
            'tracking'       => (get_field('notifications_tracking', 'options')) ? get_field('notifications_tracking', 'options') : false,
        ];
        $this->config  = $defaultConfig;
        if ( ! empty($config)) {
            $this->config = array_replace($defaultConfig, $config);
        }
        if ($this->config['providerKey']) {
            $this->provider = new SendGrid($this->config['providerKey']);
        } else {
            $this->errors[] = 'You need to provide an API key for Notifications to work.';
        }
        add_action('publish_to_draft', [$this, 'markUnsent'], 10, 1);
        add_action('after_setup_theme', [$this, 'settingsPage'], 11, 1);
        add_action('after_setup_theme', [$this, 'settingsFields'], 11, 2);
        add_action('admin_notices', [$this, 'notice']);
        add_action('admin_notices', [$this, 'errors']);
        foreach ($this->config['postTypes'] as $postType) {
            add_action('publish_' . $postType, [$this, 'notification'], 10, 2);
        }
        $this->log = new Logger('nanga-notifications');
        $this->log->pushHandler(new LogglyHandler($this->config['logger'] . '/tag/nanga-notifications', Logger::INFO));
    }

    public function notification($postId, $postObject)
    {
        $data = [
            'subject' => $postObject->post_title,
            'content' => $postObject->post_content,
        ];
        $this->send(false, $data, false, $postId);
        //write_log('Default send action...');
    }

    public function send($singleRecipient, $data, $template, $postId)
    {
        if ($this->isSent($postId) || get_field('notifications_disable', 'options')) {
            return;
        }
        $this->singleRecipient = $singleRecipient;
        $this->data            = $data;
        $this->template        = $template;
        $this->post            = $postId;
        $mail                  = new Mail();
        $from                  = new Email($this->config['fromName'], $this->config['from']);
        $mail->setFrom($from);
        $subject = $this->data['subject'];
        $mail->setSubject($subject);
        $content = new Content('text/plain', wp_strip_all_tags($this->data['content']));
        $mail->addContent($content);
        $content = new Content('text/html', wpautop($this->data['content']));
        $mail->addContent($content);
        if ($this->singleRecipient) {
            $personalization = new Personalization();
            $to              = new Email($this->singleRecipient, $this->singleRecipient);
            $personalization->addTo($to);
            $mail->addPersonalization($personalization);
        } else {
            $recipients = apply_filters('nanga_notifications_recipients', $this->recipients());
            foreach ($recipients as $recipient) {
                $personalization = new Personalization();
                $to              = new Email($recipient->name, $recipient->email);
                $personalization->addTo($to);
                if (property_exists($recipient, 'merge')) {
                    foreach ($recipient->merge as $key => $value) {
                        $personalization->addSubstitution('-' . $key . '-', $value);
                    }
                }
                if (isset($this->data['merge'])) {
                    foreach ($this->data['merge'] as $key => $value) {
                        $personalization->addSubstitution('-' . $key . '-', $value);
                    }
                }
                $personalization->addSubstitution('-name-', $recipient->name);
                if (property_exists($recipient, 'ID')) {
                    $personalization->addCustomArg('userId', $recipient->ID);
                }
                $mail->addPersonalization($personalization);
            }
        }
        if ($this->template) {
            //if (file_exists(get_template_directory() . '/nanga-notifications/' . $this->template)) {}
            $mail->setTemplateId($template);
        } else {
            //$defaultTemplate = plugin_dir_path(__DIR__) . 'views/notification.twig';
            //$emailBody = Timber::compile($defaultTemplate, []);
            $mail->setTemplateId('076543ba-82c4-4e5a-b3a0-1c30a7452c74');
        }
        $mail->addCustomArg('type', 'notification');
        if ($this->config['tracking']) {
            $trackingSettings = new TrackingSettings();
            $clickTracking    = new ClickTracking();
            $clickTracking->setEnable(true);
            $clickTracking->setEnableText(true);
            $trackingSettings->setClickTracking($clickTracking);
            $openTracking = new OpenTracking();
            $openTracking->setEnable(true);
            $trackingSettings->setOpenTracking($openTracking);
            $mail->setTrackingSettings($trackingSettings);
        }
        if ($this->config['debug']) {
            write_log("\n" . json_encode($mail, JSON_PRETTY_PRINT));

            return;
        }
        $this->execute($mail);
    }

    private function isSent($postId)
    {
        if (get_post_meta($postId, 'notification_sent', true)) {
            return true;
        }

        return false;
    }

    private function recipients()
    {
        $users      = new WP_User_Query([
            'role__in' => $this->config['recipientRoles'],
            'fields'   => ['ID', 'display_name', 'user_email'],
        ]);
        $recipients = $users->get_results();
        if ( ! empty($recipients)) {
            return $this->recipientsNormalize($recipients);
        }

        return false;
    }

    private function recipientsNormalize($recipients)
    {
        $recipientsNormalized = [];
        foreach ($recipients as $recipient) {
            $recipientNormalized        = new stdClass;
            $recipientNormalized->ID    = $recipient->ID;
            $recipientNormalized->email = $recipient->user_email;
            $recipientNormalized->name  = $recipient->display_name;
            $recipientsNormalized[]     = $recipientNormalized;
        }

        return $recipientsNormalized;
    }

    private function execute($requestBody)
    {
        try {
            $response = $this->provider->client->mail()->send()->post($requestBody);
            $status   = $response->statusCode();
            if ($status >= 200 && $status <= 299) {
                $this->markSent($this->post);
            } else {
                $responseBody = json_decode($response->body());
                $errors       = $responseBody->errors;
                foreach ($errors as $error) {
                    $this->errors[] = $error->message;
                    $this->log->error($error->message);
                }
            }
        } catch (Exception $e) {
            $this->log->error($e->getMessage());
        }
    }

    private function markSent($postId)
    {
        update_post_meta($postId, 'notification_sent', true);
    }

    public function markUnsent(WP_Post $postObject)
    {
        if (in_array($postObject->post_type, $this->config['postTypes']) && get_post_meta($postObject->ID, 'notification_sent', true)) {
            delete_post_meta($postObject->ID, 'notification_sent');
        }
    }

    public function settingsPage()
    {
        if (function_exists('acf_add_options_page')) {
            acf_add_options_page([
                'capability' => 'manage_options',
                'icon_url'   => 'dashicons-admin-generic',
                'menu_slug'  => 'notifications-settings',
                'menu_title' => 'Notifications',
                'page_title' => 'Notifications',
                'position'   => false,
                'redirect'   => false,
            ]);
        }
    }

    public function settingsFields()
    {
        if (function_exists('acf_add_local_field_group')) {
            acf_add_local_field_group([
                'key'                   => 'group_58a7e4c29b0e6',
                'title'                 => 'Notifications Configuration',
                'fields'                => [
                    [
                        'key'               => 'field_58a7e5e798d07',
                        'label'             => 'API Key',
                        'name'              => 'notifications_api_key',
                        'type'              => 'text',
                        'instructions'      => '',
                        'required'          => 0,
                        'conditional_logic' => 0,
                        'wrapper'           => [
                            'width' => '',
                            'class' => '',
                            'id'    => '',
                        ],
                        'default_value'     => '',
                        'placeholder'       => '',
                        'prepend'           => '',
                        'append'            => '',
                        'maxlength'         => '',
                    ],
                    [
                        'key'               => 'field_58a7f44b370ed',
                        'label'             => 'Post Types',
                        'name'              => 'notifications_post_types',
                        'type'              => 'select',
                        'instructions'      => 'Send an automatic email notification when these post types are published.',
                        'required'          => 0,
                        'conditional_logic' => 0,
                        'wrapper'           => [
                            'width' => '',
                            'class' => '',
                            'id'    => '',
                        ],
                        'choices'           => [],
                        'default_value'     => [],
                        'allow_null'        => 0,
                        'multiple'          => 1,
                        'ui'                => 1,
                        'ajax'              => 1,
                        'return_format'     => 'value',
                        'placeholder'       => '',
                    ],
                    [
                        'key'               => 'field_58a7e558a2248',
                        'label'             => 'From: Name',
                        'name'              => 'notifications_from_name',
                        'type'              => 'text',
                        'instructions'      => '',
                        'required'          => 0,
                        'conditional_logic' => 0,
                        'wrapper'           => [
                            'width' => '50',
                            'class' => '',
                            'id'    => '',
                        ],
                        'default_value'     => '',
                        'placeholder'       => '',
                        'prepend'           => '',
                        'append'            => '',
                        'maxlength'         => '',
                    ],
                    [
                        'key'               => 'field_58a7e585a2249',
                        'label'             => 'From: Email',
                        'name'              => 'notifications_from_email',
                        'type'              => 'email',
                        'instructions'      => '',
                        'required'          => 0,
                        'conditional_logic' => 0,
                        'wrapper'           => [
                            'width' => '50',
                            'class' => '',
                            'id'    => '',
                        ],
                        'default_value'     => '',
                        'placeholder'       => '',
                        'prepend'           => '',
                        'append'            => '',
                    ],
                    [
                        'key'               => 'field_58a7e62c98d08',
                        'label'             => 'Recipients',
                        'name'              => 'notifications_recipients',
                        'type'              => 'select',
                        'instructions'      => '',
                        'required'          => 0,
                        'conditional_logic' => 0,
                        'wrapper'           => [
                            'width' => '',
                            'class' => '',
                            'id'    => '',
                        ],
                        'choices'           => [
                            'administrator' => 'Administrators',
                            'editor'        => 'Editors',
                            'subscriber'    => 'Subscribers',
                        ],
                        'default_value'     => [],
                        'allow_null'        => 0,
                        'multiple'          => 1,
                        'ui'                => 1,
                        'ajax'              => 0,
                        'return_format'     => 'value',
                        'placeholder'       => '',
                    ],
                    [
                        'key'               => 'field_notifications_disable',
                        'label'             => 'Disable',
                        'name'              => 'notifications_disable',
                        'type'              => 'true_false',
                        'instructions'      => '',
                        'required'          => 0,
                        'conditional_logic' => 0,
                        'wrapper'           => [
                            'width' => '33.3333',
                            'class' => '',
                            'id'    => '',
                        ],
                        'message'           => '',
                        'default_value'     => 0,
                        'ui'                => 1,
                        'ui_on_text'        => 'Yes',
                        'ui_off_text'       => 'No',
                    ],
                    [
                        'key'               => 'field_58a7e5c15a312',
                        'label'             => 'Tracking',
                        'name'              => 'notifications_tracking',
                        'type'              => 'true_false',
                        'instructions'      => '',
                        'required'          => 0,
                        'conditional_logic' => 0,
                        'wrapper'           => [
                            'width' => '33.3333',
                            'class' => '',
                            'id'    => '',
                        ],
                        'message'           => '',
                        'default_value'     => 0,
                        'ui'                => 1,
                        'ui_on_text'        => 'On',
                        'ui_off_text'       => 'Off',
                    ],
                    [
                        'key'               => 'field_58a7e4e8a2247',
                        'label'             => 'Debug',
                        'name'              => 'notifications_debug',
                        'type'              => 'true_false',
                        'instructions'      => '',
                        'required'          => 0,
                        'conditional_logic' => 0,
                        'wrapper'           => [
                            'width' => '33.3333',
                            'class' => '',
                            'id'    => '',
                        ],
                        'message'           => '',
                        'default_value'     => 0,
                        'ui'                => 1,
                        'ui_on_text'        => 'On',
                        'ui_off_text'       => 'Off',
                    ],
                ],
                'location'              => [
                    [
                        [
                            'param'    => 'options_page',
                            'operator' => '==',
                            'value'    => 'notifications-settings',
                        ],
                    ],
                ],
                'menu_order'            => 0,
                'position'              => 'normal',
                'style'                 => 'default',
                'label_placement'       => 'top',
                'instruction_placement' => 'label',
                'hide_on_screen'        => '',
                'active'                => 1,
                'description'           => '',
            ]);
        }
        add_filter('acf/load_field/name=notifications_post_types', function ($field) {
            $field['choices'] = [];
            $postTypes        = get_post_types(['public' => true], 'names');
            foreach ($postTypes as $postType) {
                $field['choices'][$postType] = ucwords($postType);
            }

            return $field;
        }, 10, 3);
    }

    public function notice()
    {
        $screen = get_current_screen();
        if ( ! in_array($screen->id, $this->config['postTypes'])) {
            return;
        }
        global $post;
        if ( ! $this->isSent($post->ID)) {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>Notification has been sent for this ' . $screen->id . '.</p></div>';
    }

    public function errors()
    {
        if (empty($this->errors)) {
            return;
        }
        $screen = get_current_screen();
        if ( ! in_array($screen->id, $this->config['postTypes'])) {
            return;
        }
        foreach ($this->errors as $error) {
            echo '<div class="notice notice-error"><p><strong>' . $error . '</strong></p></div>';
        }
    }
}
